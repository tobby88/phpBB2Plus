<?php
if (!defined('IN_PHPBB')) { exit; }

use phpbb2plus\community\service\removed_topics;

function plus_erased_post($value) { return (int) ((string) $value === '1'); }

function plus_removed_topics()
{
	global $src_db, $db, $convert, $table_prefix, $config;
	$prefix = $convert->src_table_prefix;
	$last = 0;
	do
	{
		$result = $src_db->sql_query_limit("SELECT t.topic_id FROM {$prefix}topics t WHERE t.topic_id>$last
			AND t.topic_status=" . ITEM_LOCKED . " AND t.topic_moved_id=0 AND t.topic_desc='' AND " .
			$src_db->sql_in_set('t.topic_title', removed_topics::TITLES) . "
			AND EXISTS (SELECT 1 FROM {$prefix}posts p WHERE p.topic_id=t.topic_id AND p.forum_id=t.forum_id)
			AND NOT EXISTS (SELECT 1 FROM {$prefix}posts p WHERE p.topic_id=t.topic_id AND (p.forum_id<>t.forum_id OR COALESCE(p.post_deleted,0)<>1))
			ORDER BY t.topic_id", 200);
		$ids = array_map('intval', array_column($src_db->sql_fetchrowset($result), 'topic_id'));
		$src_db->sql_freeresult($result);
		if (!$ids) { break; }
		$db->sql_transaction('begin');
		try
		{
			$scope = $db->sql_in_set('t.topic_id', $ids);
			removed_topics::classify($db, $table_prefix, $scope, true);
			$db->sql_transaction('commit');
		}
		catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
		$last = end($ids);
	}
	while (count($ids) === 200);
	if (!removed_topics::clear_posts($db, $table_prefix)) { return; }
	// The Plus delete flow also erased the poll when its opening post was erased.
	// Only a fresh conversion has the explicit source flag for mixed topics.
	$topics = TOPICS_TABLE;
	$erased = '(t.plus_legacy_removed=1 OR EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' p WHERE p.post_id=t.topic_first_post_id
		AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id AND p.plus_legacy_erased=1 AND p.post_visibility=' . ITEM_DELETED . '))';
	$db->sql_transaction('begin');
	try
	{
		foreach (array(POLL_OPTIONS_TABLE, POLL_VOTES_TABLE) as $poll_table)
		{
			$db->sql_query("DELETE v FROM $poll_table v JOIN $topics t ON t.topic_id=v.topic_id WHERE $erased");
		}
		$db->sql_query("UPDATE $topics t SET t.poll_title='',t.poll_start=0,t.poll_length=0,t.poll_last_vote=0,t.poll_max_options=1,t.poll_vote_change=0
			WHERE (t.poll_title<>'' OR t.poll_start<>0 OR t.poll_length<>0 OR t.poll_last_vote<>0 OR t.poll_vote_change<>0 OR t.poll_max_options<>1) AND $erased");
		\phpbb2plus\community\service\removed_topic_shadows::hide($db, 0, false);
		$db->sql_transaction('commit');
	}
	catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
	$config->set('plus_removed_recount_due', 1);
}
