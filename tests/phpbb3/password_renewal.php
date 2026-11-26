<?php
// Set the age of one synthetic account on the disposable, loopback-only board.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$where = "user_id = 141 AND plus_legacy_id = 141 AND username = 'RenewalMember'";
$result = $db->sql_query('SELECT user_passchg, plus_pw_renewal_seen FROM ' . USERS_TABLE . ' WHERE ' . $where);
$member = $db->sql_fetchrow($result);
$db->sql_freeresult($result);
if (!$member) { throw new RuntimeException('Missing migrated password renewal fixture'); }
$phase = $argv[2];
if (in_array($phase, array('fresh','two-days','last-day','expired','long-window','before-window'), true))
{
	$ages = array('fresh' => 0, 'two-days' => 5, 'last-day' => 6, 'expired' => 7, 'long-window' => 16, 'before-window' => 15);
	$changed = time() - 86400 * $ages[$phase];
	$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_passchg = $changed, plus_pw_renewal_seen = 0 WHERE $where");
}
elseif ($phase === 'unknown' || $phase === 'sentinel')
{
	$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_passchg = 0, plus_pw_renewal_seen = ' . (int) ($phase === 'sentinel') . ' WHERE ' . $where);
}
elseif ($phase === 'consumed')
{
	if ((int) $member['user_passchg'] !== 0 || (int) $member['plus_pw_renewal_seen'] !== 1)
	{ throw new RuntimeException('The first explicit renewal prompt did not survive the session'); }
}
elseif ($phase === 'restricted' || $phase === 'released')
{
	$result = $db->sql_query("SELECT user_passchg, plus_pw_renewal_seen FROM " . USERS_TABLE . " WHERE user_id = 63 AND username = 'ImportedFounderFixture'");
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$row || (int) $row['user_passchg'] !== 0 || (int) $row['plus_pw_renewal_seen'] !== (int) ($phase === 'released'))
	{ throw new RuntimeException('Unexpected first-renewal state on the ' . $phase . ' fixture account'); }
}
elseif ($phase === 'expire-session')
{
	$db->sql_query('DELETE FROM ' . SESSIONS_TABLE . ' WHERE session_user_id = 141 AND session_autologin = 1');
	if ((int) $db->sql_affectedrows() !== 1) { throw new RuntimeException('Expected one remembered renewal session'); }
}
elseif (preg_match('/^mail-on:([0-9]{4,5})$/D', $phase, $match))
{
	$port = (int) $match[1];
	if ($port < 1024 || $port > 65535 || $config['email_enable'] || !$config['allow_password_reset'] || $config['smtp_username'] !== '')
	{ throw new RuntimeException('Unexpected starting mail transport on the loopback fixture'); }
	$settings = array('smtp_delivery' => 1, 'smtp_host' => '127.0.0.1', 'smtp_port' => $port, 'email_package_size' => 0,
		'board_email' => 'renewal-board@example.invalid', 'board_contact' => 'renewal-board@example.invalid', 'email_enable' => 1);
	$previous = array();
	foreach ($settings as $key => $value) { $previous[$key] = $config[$key]; }
	$phpbb_container->get('config_text')->set('plus_test_renewal_mail', json_encode($previous));
	foreach ($settings as $key => $value) { $config->set($key, $value); }
}
elseif ($phase === 'mail-off')
{
	$store = $phpbb_container->get('config_text');
	$previous = $store->get('plus_test_renewal_mail');
	if ($previous !== null)
	{
		foreach (json_decode($previous, true, 512, JSON_THROW_ON_ERROR) as $key => $value) { $config->set($key, $value); }
		$store->delete('plus_test_renewal_mail');
	}
}
else { exit(2); }
