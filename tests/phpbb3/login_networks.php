<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$history = $phpbb_container->get('phpbb2plus.community.account_logins');
$result = $db->sql_query("SELECT user_id FROM " . USERS_TABLE . " WHERE plus_legacy_id=1 AND username='HistoricMember'");
$id = (int) $db->sql_fetchfield('user_id');
$db->sql_freeresult($result);
if ($id <= ANONYMOUS) { throw new RuntimeException('Missing remapped login history fixture'); }
if ($argv[2] === 'expire-remembered-session')
{
	// Native session garbage collection removes the row. The browser retains
	// its previous session ID, per-device key and account cookies until it returns.
	$db->sql_query('DELETE FROM ' . SESSIONS_TABLE . " WHERE session_user_id=$id AND session_autologin=1");
	if ((int) $db->sql_affectedrows() !== 1) { throw new RuntimeException('Expected one persistent fixture session to expire'); }
	return;
}
if ($argv[2] === 'disabled')
{
	$result = $db->sql_query('SELECT COUNT(*) AS total FROM ' . $table_prefix . 'plus_account_logins');
	$count = (int) $db->sql_fetchfield('total');
	$db->sql_freeresult($result);
	if ($count !== 206) { throw new RuntimeException('A disabled board recorded a new login'); }
	return;
}
$samples = array('network-change' => '2001:db8:45::5', 'another-network' => '192.0.2.32', 'network-only' => '203.0.113.40', 'history-only' => '198.51.100.41');
if (!isset($samples[$argv[2]])) { exit(2); }
foreach (array(array('192.0.2.4','::ffff:192.0.3.17'), array('2001:db8:ab::1','2001:0db8:00ab:ffff::19'), array('0.0.0.0','192.0.2.1')) as $pair)
{
	if ($history->notice(array('plus_login_warn'=>1, 'plus_login_previous'=>$pair[0], 'plus_login_current'=>$pair[1])))
	{
		throw new RuntimeException('Equivalent or unknown source networks produced a login notice');
	}
}
$history->record($id, $samples[$argv[2]], time());
if (in_array($argv[2], array('network-only','history-only'), true))
{
	$result = $db->sql_query('SELECT login_ip FROM ' . $table_prefix . "plus_account_logins WHERE user_id=$id ORDER BY login_time DESC,entry_id DESC");
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	$result = $db->sql_query('SELECT plus_login_current FROM ' . USERS_TABLE . " WHERE user_id=$id");
	$current = $db->sql_fetchfield('plus_login_current');
	$db->sql_freeresult($result);
	$latest = $argv[2] === 'network-only' ? $samples['another-network'] : $samples['history-only'];
	if (count($rows) !== 3 || $rows[0]['login_ip'] !== $latest || $current !== $samples['network-only'])
	{
		throw new RuntimeException('The independent board controls did not limit the stored data');
	}
}
