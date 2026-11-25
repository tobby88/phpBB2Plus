<?php
if (!defined('IN_PHPBB')) { exit; }

/** The old loader required a readable module, template and information file. */
function plus_statistics_renderer($root, $directory, $name, &$fingerprint)
{
	$known = array(
		'forum_index' => 'cc2141bce4945332fc8aa2200ff0a5b0e215c0b62c2e944045413ca678d1ba5d',
		'most_active_topics' => '1aa9c688b5cb047801d7683118ad8fe69877e24096bcff9339b477351a2a92c7',
		'most_viewed_topics' => '3623a14cb864d44dd5895452643b7e0f5d2597d44fbd4ab5d8b746d58edb00fb',
		'top_posters' => 'c9bd1b3b9907e4b8951492dd5617345d87b89c2e24690e3987f6e0c8e07c6daa',
		'site_hist_week_top_posters' => '358c548f1b29440ac4ed3ffd3334dbfa625bcc63f46bc81761c8bdf830f4bc5f',
		'site_hist_month_top_posters' => '6b13a1a71e9494f6c9ba9ac284b962f6815e86a09b8646da515da10f6d634a4f',
	);
	$fingerprint = '';
	if (!isset($known[$name]) && !in_array($name, array('admin_statistics','most_used_languages','top_attachments','top_smilies'), true)) { return 'unported'; }
	$dir = trim(str_replace('\\', '/', $directory), '/');
	if ($dir === '' || strpos($dir, '..') !== false || !preg_match('~^[a-zA-Z0-9_./-]+$~D', $dir)) { return 'unavailable'; }
	try
	{
		$base = plus_media_path($root, $dir . '/' . $name, true);
		if (!is_readable(plus_media_path($base, 'info.txt'))) { return 'unavailable'; }
		$parts = array();
		foreach (array('module.php', 'module.tpl') as $file)
		{
			$contents = @file_get_contents(plus_media_path($base, $file));
			if ($contents === false) { return 'unavailable'; }
			$parts[] = str_replace(array("\r\n", "\r"), "\n", $contents);
		}
		$fingerprint = hash('sha256', implode("\0", $parts));
		// A subsequent port can recognise the remaining bundled modules without reopening the source board.
		if (!isset($known[$name])) { return 'unported'; }
		return $fingerprint === $known[$name] ? 'native' : 'customised';
	}
	catch (\RuntimeException $e) { return 'unavailable'; }
}

function plus_statistics_settings()
{
	global $config, $src_db, $convert, $phpbb_container;
	$prefix = $convert->src_table_prefix;
	$tables = array($prefix . 'stats_config', $prefix . 'stats_modules');
	$result = $src_db->sql_query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ' .
		$src_db->sql_in_set('TABLE_NAME', $tables));
	$found = array_column($src_db->sql_fetchrowset($result), 'TABLE_NAME');
	$src_db->sql_freeresult($result);
	$settings = $modules = array();
	if (in_array($tables[0], $found, true))
	{
		$result = $src_db->sql_query("SELECT config_name,config_value FROM {$tables[0]}");
		while ($row = $src_db->sql_fetchrow($result)) { $settings[$row['config_name']] = trim($row['config_value']); }
		$src_db->sql_freeresult($result);
	}
	if (in_array($tables[1], $found, true))
	{
		$result = $src_db->sql_query("SELECT module_id,name,active,installed,display_order,update_time,auth_value FROM {$tables[1]} ORDER BY display_order,module_id");
		while ($row = $src_db->sql_fetchrow($result))
		{
			// Preserve custom, disabled and unported registrations; never import executable code or old audience caches.
			foreach (array('module_id','active','installed','display_order','update_time','auth_value') as $key) { $row[$key] = (int) $row[$key]; }
			$row['renderer'] = plus_statistics_renderer($convert->options['forum_path'], $settings['modules_dir'] ?? '', trim($row['name']), $fingerprint);
			$row['source_sha256'] = $fingerprint;
			$modules[] = $row;
		}
		$src_db->sql_freeresult($result);
	}
	$phpbb_container->get('config_text')->set('plus_statistics_modules', json_encode($modules, JSON_INVALID_UTF8_SUBSTITUTE));
	$config->set('plus_statistics_limit', max(1, min(100, (int) ($settings['return_limit'] ?? 10))));
	$config->set('plus_statistics_installed', max(0, (int) ($settings['install_date'] ?? 0)));
	$config->set('plus_statistics_views', max(0, (int) ($settings['page_views'] ?? 0)), false);
	$config->set('plus_statistics_version', $settings['version'] ?? '');
}
