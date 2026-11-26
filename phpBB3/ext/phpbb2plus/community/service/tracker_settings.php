<?php
namespace phpbb2plus\community\service;

class tracker_settings
{
	public const RANGES = array(
		'plus_login_history' => array(0, 1), 'plus_login_limit' => array(1, 60),
		'plus_login_network_check' => array(0, 1), 'plus_pw_reminder' => array(0, 1), 'plus_pw_reminder_days' => array(6, 365),
		'plus_friend_protection' => array(0, 2), 'plus_friend_minutes' => array(1, 180),
	);

	public static function accepts($name, $value)
	{
		if (!isset(self::RANGES[$name]) || !is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $value)) { return false; }
		list($min, $max) = self::RANGES[$name];
		return (int) $value >= $min && (int) $value <= $max;
	}

	public static function read($db, $lock = 'read')
	{
		// Logins already in flight must honour a change to the board's collection policy.
		$suffix = array('read' => '', 'shared' => ' LOCK IN SHARE MODE', 'write' => ' FOR UPDATE');
		$result = $db->sql_query('SELECT config_name,config_value FROM ' . CONFIG_TABLE . ' WHERE ' .
			$db->sql_in_set('config_name', array_keys(self::RANGES)) . ' ORDER BY config_name' . $suffix[$lock]);
		$values = array();
		while ($row = $db->sql_fetchrow($result)) { $values[$row['config_name']] = (int) $row['config_value']; }
		$db->sql_freeresult($result);
		return $values + array('plus_login_history' => 0, 'plus_login_limit' => 10, 'plus_login_network_check' => 0,
			'plus_pw_reminder' => 0, 'plus_pw_reminder_days' => 30, 'plus_friend_protection' => 1, 'plus_friend_minutes' => 20);
	}
}
