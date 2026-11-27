<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_import_blue_reports()
{
	global $src_db, $db, $convert;
	$prefix = $convert->src_table_prefix;
	if (!(new \phpbb\db\tools\factory())->get($src_db)->sql_column_exists($prefix . 'posts', 'post_bluecard')) { return; }

	$last = 0;
	do
	{
		// These are the same parent and text rows required to open a Blue Card in Plus.
		$result = $src_db->sql_query_limit("SELECT p.post_id,p.topic_id,p.forum_id,p.post_bluecard
			FROM {$prefix}posts p JOIN {$prefix}posts_text x ON x.post_id=p.post_id
			JOIN {$prefix}topics t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id
			JOIN {$prefix}forums f ON f.forum_id=p.forum_id
			WHERE p.post_id>$last AND p.post_deleted=0 AND p.post_bluecard>0 AND t.topic_moved_id=0
			ORDER BY p.post_id", 200);
		$rows = $src_db->sql_fetchrowset($result);
		$src_db->sql_freeresult($result);
		if (!$rows) { break; }
		$last = (int) end($rows)['post_id'];
		plus_store_blue_reports($db, array_column($rows, null, 'post_id'));
	}
	while (count($rows) === 200);
}

/** Import from a source snapshot. Native closure or deletion is final even if the wizard resumes. */
function plus_store_blue_reports($db, array $source)
{
	if (!$source) { return; }
	$db->sql_transaction('begin');
	try
	{
		$result = $db->sql_query('SELECT p.post_id,p.topic_id,p.forum_id FROM ' . POSTS_TABLE . ' p
			JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id
			JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id WHERE ' . $db->sql_in_set('p.post_id', array_keys($source)) . '
			AND p.plus_blue_imported=0 AND p.plus_legacy_erased=0 AND t.plus_legacy_removed=0
			AND p.post_visibility=' . ITEM_APPROVED . ' AND t.topic_visibility=' . ITEM_APPROVED . '
			AND t.topic_moved_id=0 AND t.topic_status<>' . ITEM_MOVED . ' AND f.forum_type=' . FORUM_POST . ' ORDER BY p.post_id FOR UPDATE');
		$posts = $db->sql_fetchrowset($result);
		$db->sql_freeresult($result);
		$existing_reports = array();
		if ($posts)
		{
			$result = $db->sql_query('SELECT post_id,report_id,report_closed,plus_blue_count FROM ' . REPORTS_TABLE . ' WHERE ' .
				$db->sql_in_set('post_id', array_column($posts, 'post_id')) . " AND pm_id=0 AND (plus_blue_count<>'' OR report_closed=0)
				ORDER BY CASE WHEN plus_blue_count<>'' THEN 0 ELSE 1 END,report_id FOR UPDATE");
			while ($row = $db->sql_fetchrow($result))
			{
				if (!isset($existing_reports[$row['post_id']])) { $existing_reports[$row['post_id']] = $row; }
			}
			$db->sql_freeresult($result);
		}
		$seen = $open = $topics = array();
		$reason = null;
		foreach ($posts as $post)
		{
			$id = (int) $post['post_id'];
			$old = $source[$id];
			$count = (string) $old['post_bluecard'];
			// Custom source schemas accept unsigned BIGINT. Preserve every digit even on 32-bit PHP.
			if ((int) $post['topic_id'] !== (int) $old['topic_id'] || (int) $post['forum_id'] !== (int) $old['forum_id'] ||
				!preg_match('/^[1-9][0-9]{0,19}$/D', $count)) { continue; }
			$seen[] = $id;
			$existing = $existing_reports[$id] ?? null;
			if ($existing && $existing['report_closed']) { continue; }
			if ($existing)
			{
				// There can be just one open native case per post. Keep its reporter, date, reason and snapshot.
				if ($existing['plus_blue_count'] === '')
				{
					$db->sql_query('UPDATE ' . REPORTS_TABLE . " SET plus_blue_count='$count' WHERE report_id=" . (int) $existing['report_id']);
				}
			}
			else
			{
				if ($reason === null)
				{
					$result = $db->sql_query('SELECT reason_id FROM ' . REPORTS_REASONS_TABLE . " WHERE LOWER(reason_title)='other'");
					$reason = (int) $db->sql_fetchfield('reason_id');
					$db->sql_freeresult($result);
					if (!$reason) { throw new \RuntimeException('The native Other report reason is missing. Restore it and resume the conversion.'); }
				}
				// The MCP joins the users table. ANONYMOUS is its unowned row, never evidence of a guest report.
				$record = array('reason_id' => $reason, 'post_id' => $id, 'pm_id' => 0, 'user_id' => ANONYMOUS,
					'user_notify' => 0, 'report_closed' => 0, 'report_time' => 0, 'report_text' => '',
					'reported_post_text' => '', 'reported_post_uid' => '', 'reported_post_bitfield' => '',
					'reported_post_enable_bbcode' => 0, 'reported_post_enable_smilies' => 0, 'reported_post_enable_magic_url' => 0,
					'plus_blue_count' => $count, 'plus_blue_only' => 1);
				$db->sql_query('INSERT INTO ' . REPORTS_TABLE . ' ' . $db->sql_build_array('INSERT', $record));
			}
			$open[] = $id;
			$topics[] = (int) $post['topic_id'];
		}
		if ($seen) { $db->sql_query('UPDATE ' . POSTS_TABLE . ' SET plus_blue_imported=1 WHERE ' . $db->sql_in_set('post_id', $seen)); }
		if ($open)
		{
			$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET post_reported=1 WHERE ' . $db->sql_in_set('post_id', $open));
			$topics = array_unique($topics);
			$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_reported=1 WHERE ' . $db->sql_in_set('topic_id', $topics) .
				' OR ' . $db->sql_in_set('topic_moved_id', $topics));
		}
		$db->sql_transaction('commit');
	}
	catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
}
