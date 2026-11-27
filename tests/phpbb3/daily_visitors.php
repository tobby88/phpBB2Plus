<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
$phase = $argv[2];
if ($phase === 'source' || $phase === 'source-missing')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$old = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	if ($phase === 'source-missing')
	{
		$old->query("DELETE FROM phpbb_plus WHERE config_name='show_last_visit'");
		$old->query('ALTER TABLE phpbb_users DROP COLUMN user_display_viewonline');
		$old->query("UPDATE phpbb_config SET config_value='5.5' WHERE config_name='board_timezone'");
	}
	else
	{
		$old->query("UPDATE phpbb_plus SET config_value='2' WHERE config_name='show_last_visit'");
		$old->query('UPDATE phpbb_users SET user_display_viewonline=1 WHERE user_id=1');
	}
	$old->query("UPDATE phpbb_config SET config_value='1' WHERE config_name='display_viewonline'");
	$old->query('UPDATE phpbb_users SET user_session_time=987640 WHERE user_id=87');
	$old->query("UPDATE phpbb_users SET user_session_time=0,user_lastvisit=960000 WHERE username='ModerationFixture'");
	return;
}
define('IN_PHPBB', true); $phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
function daily_expect($test, $message) { if (!$test) { throw new RuntimeException($message); } }
function daily_rows($sql)
{
	global $db;
	$result = $db->sql_query($sql); $rows = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
	return $rows;
}
$table = $table_prefix . 'plus_day_visits';
$users = USERS_TABLE;
$storage = $phpbb_container->get('config_text'); $key = 'plus_test_daily_visitor_state';
$visitors = $phpbb_container->get('phpbb2plus.community.daily_visitors');
$cron = $phpbb_container->get('phpbb2plus.community.daily_visitor_cleanup');
if ($phase === 'older' || $phase === 'upgraded')
{
	$tools = (new \phpbb\db\tools\factory())->get($db);
	daily_expect($tools->sql_table_exists($table) === ($phase === 'upgraded') && $tools->sql_column_exists($users, 'plus_daily_scope') === ($phase === 'upgraded'), 'Unexpected history or user preference schema');
	if ($phase === 'upgraded')
	{
		daily_expect(!$visitors->mode() && !daily_rows("SELECT visitor FROM $table") && !daily_rows("SELECT user_id FROM $users WHERE plus_daily_scope<>-1"), 'A source-free upgrade enabled tracking or invented preferences');
	}
	return;
}
if ($phase === 'setup' || $phase === 'setup-missing')
{
	$settings = array('board_timezone' => 'Europe/Berlin', 'plus_daily_mode' => 2, 'plus_daily_scope' => 2,
		'plus_daily_force' => 0, 'load_online' => 1, 'load_online_time' => 5, 'load_online_guests' => 1);
	$saved = array();
	foreach (array_merge(array_keys($settings), array('plus_daily_gc')) as $name) { $saved[$name] = $config[$name]; }
	if ($phase === 'setup-missing')
	{
		daily_expect($visitors->midnight(strtotime('2024-03-21 01:00 UTC')) === strtotime('2024-03-20 18:30 UTC'), 'The display ignored the converted source board time zone');
	}
	$member = daily_rows("SELECT user_id,plus_daily_scope,plus_login_hidden,plus_last_login FROM $users WHERE username='HistoricMember'")[0];
	$converted = daily_rows("SELECT user_last_active FROM $users WHERE username='DepartingFixture'")[0];
	daily_expect((int) $saved['plus_daily_mode'] === ($phase === 'setup' ? 2 : 0) && (int) $member['plus_daily_scope'] === ($phase === 'setup' ? 1 : -1)
		&& (int) $saved['plus_daily_scope'] === 1 && (int) $converted['user_last_active'] === 987640 &&
		(int) daily_rows("SELECT user_last_active FROM $users WHERE username='ModerationFixture'")[0]['user_last_active'] === 960000, 'The converted activity date or settings do not match the source');
	$people = daily_rows("SELECT user_id,username,user_allow_viewonline,user_last_active,plus_login_hidden FROM $users WHERE user_type=" . USER_NORMAL .
		" AND user_id<>" . ANONYMOUS . " AND username NOT IN ('HistoricMember','ModernMember') ORDER BY user_id LIMIT 3");
	daily_expect(count($people) === 3, 'Missing synthetic accounts');
	$storage->set($key, json_encode(array($saved, $member, $people, daily_rows("SELECT * FROM $table"))));
	foreach ($settings as $name => $value) { $config->set($name, $value); }
	$db->sql_query("DELETE FROM $table");
	$db->sql_query("UPDATE $users SET plus_daily_scope=1 WHERE user_id=" . (int) $member['user_id']);
	$now = strtotime('2024-10-27 04:30:00 +01:00');
	$start = $visitors->midnight($now);
	daily_expect($start === strtotime('2024-10-26 22:00:00 UTC') &&
		$visitors->midnight(strtotime('2024-10-28 12:00 UTC')) - $start === 90000 &&
		$visitors->midnight(strtotime('2024-04-01 11:00 UTC')) - $visitors->midnight(strtotime('2024-03-31 11:00 UTC')) === 82800, 'The day followed elapsed hours instead of the board calendar');
	$stamps = array($start, $now-3600, $start-1);
	foreach ($people as $i => $row)
	{
		$db->sql_query("UPDATE $users SET user_allow_viewonline=1,plus_login_hidden=0,user_last_active=" . $stamps[$i] . ' WHERE user_id=' . (int) $row['user_id']);
	}
	$visitors->remember((int) $people[1]['user_id'], true, '', $now-3600);
	$visitors->remember(ANONYMOUS, false, '192.0.2.77', $start);
	$visitors->remember(ANONYMOUS, false, '192.0.2.77', $now-1300);
	$visitors->remember(ANONYMOUS, false, '2001:db8::7', $start);
	$visitors->remember(ANONYMOUS, false, '2001:0db8:0000:0000:0000:0000:0000:0007', $start+10);
	$visitors->remember(ANONYMOUS, false, '192.0.2.77', $start-1);
	$read = $visitors->read($now, true, false);
	daily_expect($read['visible'] === 1 && $read['hidden'] === 1 && $read['guests'] === 2 && $read['recent'] === 2 &&
		array_column($read['names'], 'username') === array($people[0]['username']), 'The board-day totals, hidden names, deduplication or last hour differ');
	daily_expect(count($visitors->read($now, true, true)['names']) === 2, 'Authorised hidden name missing');
	$keys = daily_rows("SELECT visitor FROM $table WHERE is_guest=1");
	daily_expect(count($keys) === 3 && count(array_filter(array_column($keys, 'visitor'), static function ($v) { return preg_match('/^[a-f0-9]{64}$/D', $v); })) === 3, 'Guest history retained addresses or joined different days');
	$config->set('plus_daily_gc', '', false);
	daily_expect($cron->should_run(), 'Scheduled retention did not become due'); $cron->run();
	daily_expect(!$cron->should_run() && !daily_rows("SELECT visitor FROM $table"), 'Expired history survived without a page view');
	$now = time();
	foreach ($people as $i => $row)
	{
		$stamp = $i === 2 ? $visitors->midnight($now)-1 : $now;
		$db->sql_query("UPDATE $users SET user_last_active=$stamp WHERE user_id=" . (int) $row['user_id']);
	}
	$visitors->remember((int) $people[1]['user_id'], true, '', $now);
	$cat = daily_rows('SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_type=' . FORUM_CAT . ' AND plus_legacy_category>0 ORDER BY left_id LIMIT 1');
	echo json_encode(array('names' => array_column($people, 'username'), 'category' => (int) $cat[0]['forum_id']));
}
elseif ($phase === 'hidden-login')
{
	$member = daily_rows("SELECT user_id FROM $users WHERE username='HistoricMember'")[0];
	daily_expect((int) daily_rows("SELECT COUNT(*) AS n FROM $table WHERE is_guest=1")[0]['n'] === 1, 'Two actual guest sessions on loopback were not counted as one visitor');
	daily_expect(count(daily_rows("SELECT visitor FROM $table WHERE visitor='u" . (int) $member['user_id'] . "' AND is_guest=0")) === 1,
		'A real hidden session left no protection for its account after native session expiry');
}
elseif ($phase === 'native-guests-off' || $phase === 'force')
{
	daily_expect(!daily_rows("SELECT visitor FROM $table WHERE is_guest=1"), 'The active privacy setting retained a guest history');
	if ($phase === 'native-guests-off') { $config->set('load_online_guests', 0); $config->set('plus_daily_mode', 2); }
	else { $config->set('plus_daily_scope', 0); $config->set('plus_daily_force', 1); }
}
elseif ($phase === 'restore')
{
	$state = json_decode((string) $storage->get($key), true);
	if (!$state) { return; }
	list($settings, $member, $people, $visits) = $state;
	foreach ($people as $row)
	{
		$id = (int) $row['user_id']; unset($row['user_id'], $row['username']);
		$db->sql_query("UPDATE $users SET " . $db->sql_build_array('UPDATE', $row) . " WHERE user_id=$id");
	}
	$id = (int) $member['user_id']; unset($member['user_id']);
	$db->sql_query("UPDATE $users SET " . $db->sql_build_array('UPDATE', $member) . " WHERE user_id=$id");
	$db->sql_query("DELETE FROM $table"); if ($visits) { $db->sql_multi_insert($table, $visits); }
	foreach ($settings as $name => $value) { $config->set($name, $value, $name !== 'plus_daily_gc'); }
	$storage->delete($key);
}
else { exit(2); }
