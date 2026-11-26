<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_topic_readers_schema($target)
{
	return array('target' => $target,
		'order_by' => 'topic_view.topic_id, topic_view.user_id, topic_view.view_time, topic_view.view_count',
		'where' => 'topics.topic_id=topic_view.topic_id AND topics.topic_moved_id=0 AND users.user_id=topic_view.user_id
			AND (users.user_id=-1 OR users.user_id>0)',
		array('topic_id', 'topic_view.topic_id', 'intval'), array('user_id', 'topic_view.user_id', 'phpbb_user_id'),
		array('last_read', 'topic_view.view_time', 'plus_reader_nonnegative'), array('visit_count', 'topic_view.view_count', 'plus_reader_nonnegative'));
}

function plus_reader_nonnegative($value) { return max(0, (int) $value); }

function plus_publish_topic_readers()
{
	global $db, $config, $table_prefix;
	$stage = $table_prefix . 'plus_readers_import';
	$table = $table_prefix . 'plus_topic_readers';
	$db->sql_transaction('begin');
	try
	{
		$db->sql_query("INSERT INTO $table (topic_id,user_id,forum_id,last_read,visit_count)
			SELECT r.topic_id,r.user_id,t.forum_id,MAX(r.last_read),LEAST(2147483647,SUM(r.visit_count)) FROM $stage r
			JOIN " . TOPICS_TABLE . ' t ON t.topic_id=r.topic_id AND t.topic_moved_id=0 AND t.plus_legacy_removed=0
			JOIN ' . USERS_TABLE . ' u ON u.user_id=r.user_id GROUP BY r.topic_id,r.user_id,t.forum_id');
		$db->sql_query("DELETE FROM $stage");
		$config->set('plus_topic_readers', 1);
		$db->sql_transaction('commit');
	}
	catch (\Throwable $error)
	{
		$db->sql_transaction('rollback');
		throw $error;
	}
}
