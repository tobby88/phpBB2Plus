<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_poll_receipt_schema($prefix)
{
	return array('target' => $prefix . 'plus_poll_receipts',
		'order_by' => 'vote_voters.vote_id, vote_voters.vote_user_id, vote_voters.vote_user_ip',
		array('source_id', 'vote_voters.vote_id', 'intval'), array('topic_id', 'vote_desc.topic_id', 'intval'),
		array('user_id', 'users.user_id AS verified_voter', 'plus_poll_voter'), array('vote_ip', 'vote_voters.vote_user_ip', 'plus_registration_ip'),
		'where' => 'vote_voters.vote_id = vote_desc.vote_id',
		'left_join' => 'vote_voters LEFT JOIN users ON users.user_id=vote_voters.vote_user_id');
}

function plus_poll_voter($verified)
{
	return (int) $verified > 0 ? phpbb_user_id($verified) : ANONYMOUS;
}

/** The Plus maintenance panel left ambiguous ballots for review. */
function plus_poll_issue($topic, $headers, $options, $title, $start, $length)
{
	if (!$topic) { return 'missing-topic'; }
	if ((int) $topic['topic_moved_id']) { return 'moved-topic'; }
	if ($headers > 1) { return 'multiple-polls'; }
	if (!(int) $options['rows_total']) { return 'no-options'; }
	if ($options['ids_present'] != $options['rows_total'] || $options['first_id'] < 1 || $options['last_id'] > 255) { return 'invalid-option-id'; }
	if ($options['distinct_ids'] != $options['rows_total']) { return 'duplicate-option-id'; }
	// phpBB uses positive signed TINYINT IDs and reserves 127 for converted receipts.
	if ((int) $options['rows_total'] >= VOTE_CONVERTED) { return 'native-option-limit'; }
	if ($options['totals_present'] != $options['rows_total'] || $options['lowest_total'] < 0) { return 'invalid-total'; }
	if ($start < 0 || $length < 0 || ($start === 0 && $length === 1)) { return 'invalid-schedule'; }
	if (utf8_strlen($title) > 2550) { return 'question-too-long'; }
	return '';
}

function plus_import_poll($source_id, $topic_id, $title, $start, $length)
{
	global $db, $src_db, $convert, $table_prefix;
	$id = (int) $source_id;
	$topic_id = (int) $topic_id;
	$start = (int) $start;
	$length = (int) $length;
	$p = $convert->src_table_prefix;
	$result = $db->sql_query('SELECT topic_moved_id FROM ' . TOPICS_TABLE . ' WHERE topic_id=' . $topic_id);
	$topic = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	$result = $src_db->sql_query("SELECT COUNT(*) AS headers FROM {$p}vote_desc WHERE topic_id=$topic_id");
	$headers = (int) $src_db->sql_fetchfield('headers');
	$src_db->sql_freeresult($result);
	$result = $src_db->sql_query("SELECT COUNT(*) AS rows_total, COUNT(vote_option_id) AS ids_present,
		COUNT(DISTINCT vote_option_id) AS distinct_ids, MIN(vote_option_id) AS first_id, MAX(vote_option_id) AS last_id,
		COUNT(vote_result) AS totals_present, MIN(vote_result) AS lowest_total FROM {$p}vote_results WHERE vote_id=$id");
	$summary = $src_db->sql_fetchrow($result);
	$src_db->sql_freeresult($result);
	$native_title = plus_convert_byline($title);
	$reason = plus_poll_issue($topic, $headers, $summary, $native_title, $start, $length);
	$review = $table_prefix . 'plus_poll_review';
	$choices = $table_prefix . 'plus_poll_choices';
	$db->sql_transaction('begin');
	try
	{
		// Replaying this wizard row replaces its complete record. A held poll
		// cannot change the topic or publish any of its options.
		$db->sql_query("DELETE FROM $review WHERE source_id=$id");
		$db->sql_query("DELETE FROM $choices WHERE source_id=$id");
		if ($reason === '')
		{
			// Native phpBB treats timestamp zero as “no poll”. Preserve the original
			// marker and the exact closing time, including an unlimited duration.
			$fields = array('poll_title' => $native_title, 'poll_start' => max(1, $start),
				'poll_length' => $start === 0 && $length > 0 ? $length - 1 : $length, 'plus_poll_epoch' => (int) ($start === 0));
			$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $fields) . ' WHERE topic_id=' . $topic_id);
			$db->sql_query('DELETE FROM ' . POLL_OPTIONS_TABLE . ' WHERE topic_id=' . $topic_id);
		}
		else
		{
			$row = array('source_id' => $id, 'topic_id' => $topic_id, 'reason' => $reason,
				'header_json' => json_encode(array('question' => $title, 'start' => $start, 'duration' => $length), JSON_THROW_ON_ERROR));
			$db->sql_query("INSERT INTO $review " . $db->sql_build_array('INSERT', $row));
		}
		$result = $src_db->sql_query("SELECT vote_option_id, vote_option_text, vote_result FROM {$p}vote_results
			WHERE vote_id=$id ORDER BY vote_option_id, BINARY vote_option_text, vote_result");
		try
		{
			$position = 0;
			$batch = array();
			$destination = $reason === '' ? POLL_OPTIONS_TABLE : $choices;
			while ($option = $src_db->sql_fetchrow($result))
			{
				$position++;
				if ($reason === '')
				{
					$batch[] = array('topic_id' => $topic_id, 'poll_option_id' => $position, 'plus_source_option' => (int) $option['vote_option_id'],
						'poll_option_text' => plus_convert_byline($option['vote_option_text']), 'poll_option_total' => (int) $option['vote_result']);
				}
				else
				{
					$batch[] = array('source_id' => $id, 'position' => $position, 'choice_json' => json_encode($option, JSON_THROW_ON_ERROR));
				}
				if (count($batch) === 100) { $db->sql_multi_insert($destination, $batch); $batch = array(); }
			}
			if ($batch) { $db->sql_multi_insert($destination, $batch); }
		}
		finally
		{
			$src_db->sql_freeresult($result);
		}
		$db->sql_transaction('commit');
	}
	catch (\Throwable $error)
	{
		$db->sql_transaction('rollback');
		throw $error;
	}
}

function plus_publish_poll_receipts()
{
	global $db, $table_prefix;
	$stage = $table_prefix . 'plus_poll_receipts';
	$review = $table_prefix . 'plus_poll_review';
	$db->sql_transaction('begin');
	try
	{
		// The original records participation, never the individual's choice.
		// Normalized duplicates cannot create extra results or native receipts.
		$db->sql_query('INSERT INTO ' . POLL_VOTES_TABLE . ' (topic_id, poll_option_id, vote_user_id, vote_user_ip)
			SELECT DISTINCT r.topic_id, ' . VOTE_CONVERTED . ", r.user_id, r.vote_ip FROM $stage r
			JOIN " . TOPICS_TABLE . " t ON t.topic_id=r.topic_id AND t.poll_start>0
			LEFT JOIN $review h ON h.source_id=r.source_id WHERE h.source_id IS NULL");
		$db->sql_query("DELETE r FROM $stage r LEFT JOIN $review h ON h.source_id=r.source_id WHERE h.source_id IS NULL");
		$db->sql_transaction('commit');
	}
	catch (\Throwable $error)
	{
		$db->sql_transaction('rollback');
		throw $error;
	}
}
