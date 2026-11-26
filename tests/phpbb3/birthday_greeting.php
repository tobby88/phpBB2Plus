<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
$phase = $argv[2];
if ($phase === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$db = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1],
		(int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$db->query("INSERT INTO phpbb_config (config_name, config_value) VALUES ('birthday_greeting','1')
		ON DUPLICATE KEY UPDATE config_value='1'");
	$db->query('UPDATE phpbb_users SET user_next_birthday_greeting=2500+user_id WHERE user_id IN (1,2)');
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
function birthday_expect($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function birthday_value($sql)
{
	global $db;
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row ? reset($row) : null;
}
$type = \phpbb2plus\community\notification\birthday::TYPE;
$historic = (int) birthday_value('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='historicmember'");
$today = new DateTimeImmutable('today', new DateTimeZone($config['board_timezone']));
$year = (int) $today->format('Y');
$where = ' WHERE user_id=' . $historic;
$marker = 'SELECT plus_next_birthday FROM ' . USERS_TABLE . $where;
$notices = 'SELECT COUNT(*) FROM ' . NOTIFICATIONS_TABLE . ' n INNER JOIN ' . NOTIFICATION_TYPES_TABLE .
	" t ON t.notification_type_id=n.notification_type_id WHERE t.notification_type_name='$type'";
$manager = $phpbb_container->get('notification_manager');
if ($phase === 'converted')
{
	birthday_expect($historic > 2 && $config['plus_birthday_greeting'] && (int) birthday_value($marker) === 2501 &&
		(int) birthday_value('SELECT plus_next_birthday FROM ' . USERS_TABLE . ' WHERE user_id=2') === 2502, 'Converted birthday schedules were lost.');
	$check_date = new DateTimeImmutable('2025-02-28', new DateTimeZone('Pacific/Kiritimati'));
	$calendar = '\phpbb2plus\community\event\birthday';
	$before = $calendar::anniversary('29- 2-2000', $check_date);
	$after = $calendar::anniversary('29- 2-2000', $check_date->modify('+1 day'));
	birthday_expect($before['date']->format('Ymd') === '20240229' && $after['date']->format('Ymd') === '20250301' &&
		$before['age'] === 24 && $after['age'] === 25 && $calendar::anniversary('29- 2-   0', $check_date)['age'] === null &&
		!$calendar::anniversary('31- 2-1970', $check_date) && !$calendar::anniversary(' 1- 1-2030', $check_date), 'Birthday calendar regression.');
}
elseif ($phase === 'due' || $phase === 'opt-out')
{
	$birthday = sprintf('%2d-%2d-2000', $today->format('j'), $today->format('n'));
	// Year 2000 is valid for every calendar day including leap day.
	$db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_next_birthday=$year,user_birthday='$birthday'" . $where);
	if ($phase === 'opt-out')
	{
		birthday_expect((string) birthday_value('SELECT notify FROM ' . USER_NOTIFICATIONS_TABLE .
			" WHERE user_id=$historic AND item_type='$type' AND method='notification.method.board'") === '0',
			'The native notification preferences did not record the member opt-out.');
		$manager->delete_notifications($type, $historic);
	}
}
elseif ($phase === 'delivered' || $phase === 'silent')
{
	birthday_expect((int) birthday_value($marker) === $year + 1 &&
		(int) birthday_value($notices . ' AND n.user_id=' . $historic) === ($phase === 'delivered' ? 1 : 0) &&
		(int) birthday_value($notices . ' AND n.user_id<>' . $historic) === 0, 'Birthday notification recipient, opt-out, duplicate or schedule mismatch.');
}
elseif ($phase === 'off' || $phase === 'clean')
{
	$config->set('plus_birthday_greeting', $phase === 'clean' ? 1 : 0);
	if ($phase === 'clean')
	{
		$db->sql_query('DELETE FROM ' . USER_NOTIFICATIONS_TABLE . " WHERE user_id=$historic AND item_type='$type'");
		$manager->delete_notifications($type, $historic);
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_next_birthday=2501,user_birthday='29- 2-1980'" . $where);
	}
}
else { exit(2); }
