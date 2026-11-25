<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
use phpbb2plus\gallery\service\preview_cache;
function preview_rows($sql)
{
	global $db;
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
}
$prefix = $table_prefix . 'plus_album_';
$dir = realpath($phpbb_root_path . $config['upload_path']);
$pic = preview_rows("SELECT storage_name,preview_hash FROM {$prefix}pics WHERE pic_id=100")[0];
$hash = substr($pic['storage_name'], 8);
$protected = array($pic['storage_name'], 'gallery_small_' . $hash, 'gallery_mark_' . hash_hmac('sha256', 'watermark', $config['plus_gallery_media_key']));
$sentinels = array('gallery_cache_' . $hash . '_fixture', 'album-fixture-' . substr($dbname, -12), 'other-upload-' . substr($dbname, -12));
$configs = array(preview_cache::GENERATION, preview_cache::DUE);
$settings = $prefix . 'settings';
$scope = 'cacheqa';
if ($argv[2] === 'seed')
{
	if (!$dir || !preg_match('/^[a-f0-9]{64}$/D', $hash) || !$pic['preview_hash'] ||
		preview_rows("SELECT setting_name FROM $settings WHERE scope='$scope'")) { throw new RuntimeException('Unsafe gallery cache fixture'); }
	$old = array();
	foreach ($configs as $key) { $old[$key] = $config[$key]; }
	$options = array('album' => array('thumbnail_size' => 8, 'thumbnail_cache' => 1), 'sp' => array('midthumb_use' => 1, 'midthumb_cache' => 1));
	foreach ($options as $section => $values)
	{
		foreach ($values as $key => $value)
		{
			$row = preview_rows("SELECT setting_value FROM $settings WHERE scope='$section' AND setting_name='$key'");
			$old[$section . '/' . $key] = $row ? $row[0]['setting_value'] : null;
			if ($row) { $db->sql_query("UPDATE $settings SET setting_value='$value' WHERE scope='$section' AND setting_name='$key'"); }
			else { $db->sql_query("INSERT INTO $settings (scope,setting_name,setting_value) VALUES ('$section','$key','$value')"); }
		}
	}
	foreach ($old as $key => $value)
	{
		$db->sql_query("INSERT INTO $settings " . $db->sql_build_array('INSERT', array('scope' => $scope, 'setting_name' => $key, 'setting_value' => json_encode($value))));
	}
	foreach ($sentinels as $name)
	{
		$file = fopen($dir . '/' . $name, 'xb');
		if (!$file) { throw new RuntimeException('Cache fixture file already exists'); }
		fwrite($file, 'Preserve foreign and temporary files');
		fclose($file);
	}
}
elseif ($argv[2] === 'state')
{
	$files = array();
	foreach (array_merge($protected, $sentinels) as $name)
	{
		if (!is_file($dir . '/' . $name)) { throw new RuntimeException('Missing a protected gallery source or fixture'); }
		$files[] = hash_file('sha256', $dir . '/' . $name);
	}
	$generations = array();
	foreach (glob($dir . '/gallery_cache_' . $hash . '_*') as $file)
	{
		$version = preview_cache::generation(basename($file), $hash);
		if ($version !== null) { $generations[] = $version; }
	}
	sort($generations, SORT_NUMERIC);
	echo json_encode(array('generation' => (int) $config[preview_cache::GENERATION], 'due' => (int) $config[preview_cache::DUE],
		'files' => $files, 'variants' => $generations, 'actions' => (int) preview_rows('SELECT COUNT(*) AS n FROM ' . LOG_TABLE .
		" WHERE log_operation='LOG_PLUS_ALBUM_CACHE' AND user_id=87")[0]['n']));
}
elseif ($argv[2] === 'cron' || $argv[2] === 'expire')
{
	if ($argv[2] === 'expire') { $config->set(preview_cache::DUE, time() - 1, false); }
	$phpbb_container->get('phpbb2plus.gallery.cleanup')->run();
}
elseif ($argv[2] === 'cleanup')
{
	$saved = preview_rows("SELECT setting_name,setting_value FROM $settings WHERE scope='$scope'");
	if (!$saved) { throw new RuntimeException('Missing cache fixture settings'); }
	$original_generation = (int) json_decode(array_column($saved, 'setting_value', 'setting_name')[preview_cache::GENERATION], true);
	foreach (glob($dir . '/gallery_cache_' . $hash . '_*') as $path)
	{
		$generation = preview_cache::generation(basename($path), $hash);
		if ($generation !== null && $generation > $original_generation) { preview_cache::remove_regular($path); }
	}
	foreach ($saved as $row)
	{
		$key = $row['setting_name'];
		$value = json_decode($row['setting_value'], true);
		if (in_array($key, $configs, true)) { $config->set($key, $value, false); }
		else
		{
			list($section, $name) = explode('/', $key);
			$where = " WHERE scope='" . $db->sql_escape($section) . "' AND setting_name='" . $db->sql_escape($name) . "'";
			$db->sql_query($value === null ? "DELETE FROM $settings" . $where :
				"UPDATE $settings SET setting_value='" . $db->sql_escape($value) . "'" . $where);
		}
	}
	foreach ($sentinels as $name) { preview_cache::remove_regular($dir . '/' . $name); }
	$db->sql_query("DELETE FROM $settings WHERE scope='$scope'");
}
else { exit(2); }
