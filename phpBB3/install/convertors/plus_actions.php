<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_action_operation($action)
{
	$known = array('delete','move','lock','unlock','split','merge','edit','announce','sticky','normal');
	return 'LOG_PLUS_ARCHIVE_' . (in_array($action, $known, true) ? strtoupper($action) : 'OTHER');
}

function plus_action_address($raw)
{
	$ip = plus_registration_ip($raw);
	return $ip === '' ? '' : inet_ntop(inet_pton($ip));
}

function plus_action_date($time) { return max(0, (int) $time); }

function plus_action_details($topic, $user, $id, $action, $ip, $time, $name = '')
{
	// Keep the event-time spelling separately from the current native account.
	// The native log formats these arguments as HTML, including unknown actions.
	$html = static function ($value) { return utf8_encode_ucr(utf8_htmlspecialchars((string) $value)); };
	$recorded_name = html_entity_decode((string) $name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	return serialize(array('topic' => (int) $topic, 'name' => $html($recorded_name), 'account' => (int) $user, 'entry' => (int) $id,
		'action' => $html($action), 'source_ip' => $html($ip), 'source_time' => (string) (int) $time));
}

function plus_action_schema($source_db, $source_prefix, $prefix)
{
	$tools = (new \phpbb\db\tools\factory())->get($source_db);
	$table = $source_prefix . 'logs';
	if (!$tools->sql_table_exists($table)) { return false; }
	$fields = array('logs.topic_id','logs.user_id','logs.id_log','logs.mode','logs.user_ip','logs.log_time');
	if ($tools->sql_column_exists($table, 'username')) { $fields[] = 'logs.username'; }
	return array('target' => $prefix . 'plus_action_import', 'primary' => 'logs.id_log',
		array('source_id', 'logs.id_log', 'intval'), array('actor_id', 'logs.user_id', 'plus_existing_author'),
		array('topic_id', 'logs.topic_id', 'intval'), array('event_time', 'logs.log_time', 'plus_action_date'),
		array('client_ip', 'logs.user_ip', 'plus_action_address'), array('operation', 'logs.mode', 'plus_action_operation'),
		array('details', $fields, 'plus_action_details'));
}

function plus_finish_action_log()
{
	global $db, $table_prefix;
	$stage = $table_prefix . 'plus_action_import';
	$db->sql_transaction('begin');
	try
	{
		// Serialize finishers and consume the staged pages in the same commit.
		// Native log IDs remain free to coexist with events already written by phpBB.
		$db->sql_query("UPDATE $stage SET actor_id=actor_id");
		$db->sql_query('INSERT INTO ' . LOG_TABLE . ' (log_type,user_id,forum_id,topic_id,log_time,log_ip,log_operation,log_data)
			SELECT ' . LOG_ADMIN . ',s.actor_id,COALESCE(t.forum_id,0),COALESCE(t.topic_id,0),s.event_time,s.client_ip,s.operation,s.details
			FROM ' . $stage . ' s LEFT JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=s.topic_id ORDER BY s.source_id');
		$db->sql_query("DELETE FROM $stage");
		$db->sql_transaction('commit');
	}
	catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }
}
