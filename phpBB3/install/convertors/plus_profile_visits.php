<?php
if (!defined('IN_PHPBB')) { exit; }
require_once dirname(__DIR__, 2) . '/ext/phpbb2plus/community/profile/visits.php';

function plus_profile_visit_fields(array $columns)
{
	$fields = array();
	foreach (array('user_totaltime'=>'plus_visit_seconds', 'user_totallogon'=>'plus_visit_logins', 'user_totalpages'=>'plus_visit_pages') as $old => $new)
	{
		if (isset($columns[$old])) { $fields[] = array($new, 'users.' . $old, 'plus_profile_visit_count'); }
	}
	foreach (array('user_lastlogon'=>'plus_last_login', 'user_session_time'=>'plus_visit_until') as $old => $new)
	{
		if (isset($columns[$old])) { $fields[] = array($new, 'users.' . $old, 'plus_profile_visit_stamp'); }
	}
	return $fields;
}

function plus_profile_visit_count($value) { return \phpbb2plus\community\profile\visits::count($value); }

function plus_profile_visit_stamp($value)
{
	$stamp = (int) plus_profile_visit_count($value);
	return $stamp > 0 && $stamp <= time() ? $stamp : 0;
}
