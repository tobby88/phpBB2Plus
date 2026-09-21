<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__DIR__) . '/attach_mod/includes/functions_mutation.php';
require_once __DIR__ . '/functions_signature.php';

class PhpbbSignatureException extends RuntimeException {}
function phpbb_signature_error($key = 'Signature_save_failed')
{
	global $lang;
	throw new PhpbbSignatureException(isset($lang[$key]) ? $lang[$key] : $key);
}
class PhpbbSignatureDatabase
{
	var $connection;
	function __construct($connection) { $this->connection = $connection; }
	function query($sql)
	{
		$result = $this->connection->sql_query($sql);
		if (!$result) { phpbb_signature_error(); } return $result;
	}
	function rows($sql)
	{
		$r = $this->query($sql); $rows = $this->connection->sql_fetchrowset($r); $this->connection->sql_freeresult($r);
		if (!is_array($rows)) { phpbb_signature_error(); } return $rows;
	}
	function escape($value) { return $this->connection->sql_escape((string)$value); }
}
function phpbb_signature_policy_keys()
{
	return array('max_sig_chars','allow_html','allow_html_tags','allow_bbcode','allow_smilies');
}

// A signature is a separate editor: own only its account/session/ban/policy
// participants, not unrelated quotas, avatars or other profile modules.
function phpbb_signature_save($database, $text, $sid)
{
	global $userdata, $board_config;
	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($text)
		|| empty($userdata['session_logged_in']) || !isset($userdata['user_id'], $userdata['session_id'])
		|| !is_string($sid) || $sid === '' || !is_string($userdata['session_id']) || !hash_equals($userdata['session_id'], $sid)
		|| !(is_int($userdata['user_id']) || is_string($userdata['user_id'])) || !preg_match('/^[1-9][0-9]{0,7}$/D', (string)$userdata['user_id'])
		|| (int)$userdata['user_id'] > 8388607) { phpbb_signature_error('Session_invalid'); }
	$id = (int)$userdata['user_id']; $transactional = false;
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_signature_error('Attachment_storage_busy'); }
	try
	{
		$writer = new PhpbbSignatureDatabase($lock->connection);
		$writer->query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$writer->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
		$writer->query('START TRANSACTION'); $transactional = true;
		foreach (array(USERS_TABLE, SESSIONS_TABLE, CONFIG_TABLE, BANLIST_TABLE) as $table)
		{
			$r = $writer->query('SELECT * FROM ' . $table . ' LIMIT 0'); $lock->connection->sql_freeresult($r);
			$name = $writer->escape($table);
			$rows = $writer->rows("SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name'"
				. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name' AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci'))");
			if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { phpbb_signature_error('Public_profile_storage_upgrade'); }
		}
		$token = $writer->escape($sid);
		$session = $writer->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$token' AND HEX(session_id)=HEX('$token') AND session_user_id=$id AND session_logged_in=1 LOCK IN SHARE MODE");
		$users = $writer->rows('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . ' FOR UPDATE');
		if (count($session) !== 1 || count($users) !== 1 || (int)$users[0]['user_active'] !== 1) { phpbb_signature_error('Public_profile_changed'); }
		$user = $users[0];
		foreach (array('user_password','user_allowhtml','user_allowbbcode','user_allowsmile','user_sig','user_sig_bbcode_uid') as $key)
		{
			// A concurrent reset, changed formatting preference or signature edit
			// invalidates the request snapshot rather than silently overwriting it.
			if (!array_key_exists($key, $userdata) || !array_key_exists($key, $user) || (string)$userdata[$key] !== (string)$user[$key]) { phpbb_signature_error('Public_profile_changed'); }
		}
		if ($writer->rows('SELECT ban_id FROM ' . BANLIST_TABLE . ' WHERE ban_userid=' . $id . ' LOCK IN SHARE MODE')) { phpbb_signature_error('Public_profile_changed'); }
		$keys = phpbb_signature_policy_keys();
		$policy = $writer->rows('SELECT config_name,config_value FROM ' . CONFIG_TABLE . " WHERE config_name IN ('" . implode("','", $keys) . "') LOCK IN SHARE MODE");
		if (count($policy) !== count($keys)) { phpbb_signature_error('Public_profile_storage_upgrade'); }
		foreach ($policy as $row)
		{ if (!array_key_exists($row['config_name'], $board_config) || (string)$row['config_value'] !== (string)$board_config[$row['config_name']]) { phpbb_signature_error('Public_profile_changed'); } }
		if (strlen($text) > (int)$board_config['max_sig_chars']) { phpbb_signature_error('Signature_too_long'); }
		$html = !empty($user['user_allowhtml']) && !empty($board_config['allow_html']);
		$bbcode = !empty($user['user_allowbbcode']) && !empty($board_config['allow_bbcode']);
		$smilies = !empty($user['user_allowsmile']) && !empty($board_config['allow_smilies']);
		$uid = $bbcode ? make_bbcode_uid() : '';
		// Preserve the fixed raw/legacy HTML boundary, followed by driver SQL
		// escaping. Formatting happens only after current policy is held.
		$text = phpbb_signature_prepare($text, $html, $bbcode, $smilies, $uid);
		$writer->query('UPDATE ' . USERS_TABLE . " SET user_sig='" . $writer->escape($text) . "',user_sig_bbcode_uid='" . $writer->escape($uid) . "' WHERE user_id=$id");
		$writer->query('COMMIT'); $transactional = false;
		return array('text'=>$text, 'uid'=>$uid);
	}
	finally
	{
		// A failed acknowledgement may still mean the single row was committed.
		// Do not publish success or retry automatically; ask the member to reload.
		if ($transactional) { try { $lock->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {} }
		$lock->release();
	}
}
