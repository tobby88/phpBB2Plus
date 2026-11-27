<?php
use phpbb2plus\community\service\forum_display;

function plus_source_news_type($type) { return (int) ((int) $type === 4); }

function plus_forum_display_choice($value, $name)
{
	return forum_display::valid($name, $value) ? (int) $value : -1;
}

function plus_import_forum_display()
{
	global $convert, $src_db, $config;
	$allowed = array('last_topic_title_length' => 'last_topic_title_length');
	foreach (forum_display::DEFAULTS as $name => $unused)
	{
		$source = forum_display::ANNOUNCEMENTS[$name] ?? $name;
		$allowed[$source] = $name;
		$allowed[$source . '_over'] = $name . '_over';
	}
	$result = $src_db->sql_query('SELECT config_name,config_value FROM ' . $convert->src_table_prefix .
		'config WHERE ' . $src_db->sql_in_set('config_name', array_keys($allowed)));
	while ($row = $src_db->sql_fetchrow($result))
	{
		$name = $allowed[$row['config_name']];
		$value = $row['config_value'];
		if ($name === 'last_topic_title_length') { $value = ctype_digit((string) $value) ? min(255, (int) $value) : 24; }
		elseif (substr($name, -5) === '_over') { $value = (int) (bool) ((int) $value); }
		elseif (!forum_display::valid($name, $value)) { continue; }
		$config->set('plus_' . $name, (int) $value);
	}
	$src_db->sql_freeresult($result);
}
