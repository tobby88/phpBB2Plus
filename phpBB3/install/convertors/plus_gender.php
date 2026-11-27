<?php
if (!defined('IN_PHPBB')) { exit; }
require_once dirname(__DIR__, 2) . '/ext/phpbb2plus/community/profile/gender.php';

function plus_gender_catalogue()
{
	global $config, $db;
	static $loaded = false, $field;
	if (!$loaded) { $field = \phpbb2plus\community\profile\gender::catalogue($db, $config); $loaded = true; }
	return $field;
}

function plus_gender_schema($source, $prefix)
{
	$tools = (new \phpbb\db\tools\factory())->get($source);
	if (!$tools->sql_column_exists($prefix . 'users', 'user_gender')) { return array(); }
	$field = plus_gender_catalogue();
	return $field ? array(array('pf_' . $field['ident'], 'users.user_gender', 'plus_gender_value')) : array();
}

function plus_gender_value($value)
{
	return \phpbb2plus\community\profile\gender::imported_value($value, plus_gender_catalogue());
}
