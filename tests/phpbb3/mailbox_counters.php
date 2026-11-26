<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$rows = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
};
$id = (int) $rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='arcadecategoryjunior'")[0]['user_id'];
$where = ' WHERE user_id=' . $id;
$sample = $where . ' AND msg_id BETWEEN 110 AND 113';
$backups = $phpbb_container->get('config_text');
$key = 'plus_test_mailbox_counts';
$fields = 'user_new_privmsg, user_unread_privmsg';
$state = $rows('SELECT ' . $fields . ' FROM ' . USERS_TABLE . $where)[0];
$audit = (int) $rows('SELECT COUNT(*) AS n FROM ' . LOG_TABLE . " WHERE log_operation='LOG_PLUS_MAILBOX_COUNTERS'")[0]['n'];
$fingerprint = static function () use ($rows, $where) {
	return hash('sha256', serialize($rows('SELECT * FROM ' . PRIVMSGS_TO_TABLE . $where . ' ORDER BY msg_id, folder_id')));
};
if ($argv[2] === 'seed')
{
	if (!$id || $backups->get($key) || $rows('SELECT msg_id FROM ' . PRIVMSGS_TO_TABLE . $where)) { throw new RuntimeException('Expected an empty test mailbox'); }
	if (count($rows('SELECT msg_id FROM ' . PRIVMSGS_TABLE . ' WHERE msg_id BETWEEN 110 AND 113')) !== 4) { throw new RuntimeException('Missing converted test messages'); }
	$backups->set($key, json_encode($state));
	$backups->set($key . '_audit', (string) $audit);
	foreach (array(110 => PRIVMSGS_NO_BOX, 111 => PRIVMSGS_HOLD_BOX, 112 => PRIVMSGS_INBOX, 113 => PRIVMSGS_OUTBOX) as $msg => $folder)
	{
		$db->sql_query('INSERT INTO ' . PRIVMSGS_TO_TABLE . ' ' . $db->sql_build_array('INSERT',
			array('user_id' => $id, 'msg_id' => $msg, 'author_id' => 2, 'folder_id' => $folder, 'pm_unread' => 1, 'pm_new' => 1)));
	}
	$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_new_privmsg=45, user_unread_privmsg=47' . $where);
}
elseif ($argv[2] === 'read')
{
	$db->sql_query('UPDATE ' . PRIVMSGS_TO_TABLE . ' SET pm_unread=0' . $where . ' AND msg_id=112');
	$backups->set($key . '_rows', $fingerprint());
}
elseif ($argv[2] === 'stale' || $argv[2] === 'correct' || $argv[2] === 'empty')
{
	$expect = array('stale'=>array(45,47), 'correct'=>array(2,2), 'empty'=>array(0,0));
	$logged = array('stale'=>0, 'correct'=>1, 'empty'=>2);
	if (array_map('intval', array_values($state)) !== $expect[$argv[2]] || ($argv[2] !== 'empty' && $fingerprint() !== $backups->get($key . '_rows')))
	{ throw new RuntimeException('Repair changed a mailbox row or used a stale counter'); }
	if ($audit !== (int) $backups->get($key . '_audit') + $logged[$argv[2]]) { throw new RuntimeException('Mailbox repair audit mismatch'); }
}
elseif ($argv[2] === 'remove-mappings' || $argv[2] === 'clean')
{
	$db->sql_query('DELETE FROM ' . PRIVMSGS_TO_TABLE . $sample);
	if ($argv[2] === 'clean')
	{
		$saved = json_decode($backups->get($key), true);
		$db->sql_query('UPDATE ' . USERS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $saved) . $where);
		$backups->delete($key);
		$backups->delete($key . '_rows');
		$backups->delete($key . '_audit');
	}
}
else { exit(2); }
