<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
if ($argv[2] === 'source' || $argv[2] === 'source-off')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$s = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	if ($argv[2] === 'source-off')
	{
		$s->query('ALTER TABLE phpbb_users DROP COLUMN user_totaltime, DROP COLUMN user_totallogon, DROP COLUMN user_totalpages, DROP COLUMN user_lastlogon');
		return;
	}
	$s->query('UPDATE phpbb_users SET user_totaltime=32317261,user_totallogon=29,user_totalpages=2147483647,user_session_time=user_lastlogon+3661 WHERE user_id=87');
	$s->query("UPDATE phpbb_users SET user_level=2 WHERE user_id=89 AND username='ModerationFixture'");
	$s->query('UPDATE phpbb_users SET user_totaltime=-8,user_totallogon=NULL,user_totalpages=-1 WHERE user_id=1');
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
use phpbb2plus\community\profile\visits;
$rows = static function ($sql) use ($db) { $r=$db->sql_query($sql); $out=$db->sql_fetchrowset($r); $db->sql_freeresult($r); return $out; };
$expect = static function ($condition, $why) { if (!$condition) { throw new RuntimeException($why); } };
$values = static function ($id) use ($rows) {
	return $rows('SELECT plus_visit_seconds,plus_visit_logins,plus_visit_pages,plus_visit_until,plus_last_login,plus_login_hidden FROM ' . USERS_TABLE . ' WHERE user_id=' . (int) $id)[0];
};
$key = 'test_profile_visits';
$text = $phpbb_container->get('config_text');
$junior = 91;
switch ($argv[2])
{
	case 'fresh':
	case 'missing':
		$expect((int) $config['plus_profile_visits_ready'] === 1 && !$rows('SELECT user_id FROM ' . USERS_TABLE .
			' WHERE plus_visit_seconds<>0 OR plus_visit_logins<>0 OR plus_visit_pages<>0'), 'A previous or reduced board acquired invented visit totals');
		break;
	case 'converted':
		$row = $values(87);
		$expect($row['plus_visit_seconds'] === '32317261' && $row['plus_visit_logins'] === '29' && $row['plus_visit_pages'] === '2147483647' &&
			(int)$row['plus_last_login'] > 0 && (int)$row['plus_visit_until'] - (int)$row['plus_last_login'] === 3661, 'Existing Plus totals or last visit duration changed');
		$member = $rows('SELECT plus_visit_seconds,plus_visit_logins,plus_visit_pages FROM ' . USERS_TABLE . " WHERE username='HistoricMember'")[0];
		$expect(array_sum($member) === 0 && visits::count('009223372036854775808') === visits::MAX_COUNT, 'Invalid or overflowing source statistics survived');
		break;
	case 'prepare':
		$expect($text->get($key) === null, 'A visit test is already active');
		$option = $rows('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='u_plus_profile_visits'")[0]['auth_option_id'];
		$prior = $rows('SELECT auth_setting FROM ' . ACL_USERS_TABLE . " WHERE user_id=89 AND forum_id=0 AND auth_option_id=" . (int)$option);
		$expect(count($prior) === 1 && (int)$prior[0]['auth_setting'] === ACL_YES, 'An original moderator lost the native visit permission');
		$state = array('target'=>$values(87), 'junior'=>$values($junior),
			'option'=>(int)$option,'language'=>$rows('SELECT user_lang FROM ' . USERS_TABLE . ' WHERE user_id=89')[0]['user_lang']);
		$text->set($key, json_encode($state));
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_lang='en' WHERE user_id=89");
		// Other suites can already have logged in the shared member. Establish a stable record to read.
		$db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_visit_seconds=32317261,plus_visit_logins=29,plus_visit_pages=2147483647,
			plus_last_login=100000,plus_visit_until=103661 WHERE user_id=87');
		echo json_encode(array('logins'=>(int) $state['junior']['plus_visit_logins']));
		break;
	case 'deny':
		$state = json_decode($text->get($key), true);
		$db->sql_query('UPDATE ' . ACL_USERS_TABLE . ' SET auth_setting=' . ACL_NEVER . ' WHERE user_id=89 AND forum_id=0 AND auth_option_id=' . (int)$state['option']);
		$auth->acl_clear_prefetch(89);
		break;
	case 'overlap':
		$stamp = time() - 90;
		$expect(count($rows('SELECT session_user_id FROM ' . SESSIONS_TABLE . " WHERE session_user_id=$junior")) >= 2, 'Two real member sessions are required');
		$db->sql_query('UPDATE ' . SESSIONS_TABLE . " SET session_time=$stamp WHERE session_user_id=$junior");
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_visit_seconds=0,plus_visit_pages=2147483647,plus_visit_until=$stamp WHERE user_id=$junior");
		echo json_encode(array('anchor'=>$stamp, 'logins'=>(int) $values($junior)['plus_visit_logins']));
		break;
	case 'activity':
		$row = $values($junior);
		echo json_encode(array('until'=>(int) $row['plus_visit_until'], 'seconds'=>(int) $row['plus_visit_seconds'], 'pages'=>$row['plus_visit_pages']));
		break;
	case 'limit':
		$stamp = time() - 90;
		$db->sql_query('UPDATE ' . SESSIONS_TABLE . " SET session_time=$stamp WHERE session_user_id=$junior");
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_visit_until=$stamp,plus_visit_seconds=9223372036854775806,
			plus_visit_logins=9223372036854775807,plus_visit_pages=9223372036854775807 WHERE user_id=$junior");
		break;
	case 'limits':
		$row = $values($junior);
		foreach (array('plus_visit_seconds','plus_visit_logins','plus_visit_pages') as $key_name)
		{ $expect($row[$key_name] === visits::MAX_COUNT, 'A native visit counter overflowed'); }
		$anonymous = $values(ANONYMOUS);
		$expect(!$anonymous['plus_visit_seconds'] && !$anonymous['plus_visit_logins'] && !$anonymous['plus_visit_pages'], 'A shared guest identity acquired personal statistics');
		break;
	case 'restore':
		$state = json_decode($text->get($key), true);
		foreach (array(87=>'target', $junior=>'junior') as $id=>$name)
		{ $db->sql_query('UPDATE ' . USERS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $state[$name]) . " WHERE user_id=$id"); }
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_lang='" . $db->sql_escape($state['language']) . "' WHERE user_id=89");
		$db->sql_query('UPDATE ' . ACL_USERS_TABLE . ' SET auth_setting=' . ACL_YES . ' WHERE user_id=89 AND forum_id=0 AND auth_option_id=' . (int)$state['option']);
		$auth->acl_clear_prefetch(89);
		$text->delete($key);
		break;
	default: exit(2);
}
