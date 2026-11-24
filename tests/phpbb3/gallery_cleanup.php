<?php
// Verify the native cron task against the one picture just removed by the synthetic moderator.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 2 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$name = 'gallery_' . hash_hmac('sha256', 'picture:102', $config['plus_gallery_media_key']);
$remaining = 'gallery_' . hash_hmac('sha256', 'picture:100', $config['plus_gallery_media_key']);
$dir = realpath($config['upload_path']);
$table = $table_prefix . 'plus_album_trash';
$result = $db->sql_query("SELECT storage_name FROM $table WHERE storage_name='$name'");
$queued = $db->sql_fetchfield('storage_name');
$db->sql_freeresult($result);
$derivatives = $dir . '/gallery_cache_' . substr($name, 8) . '_*';
if ($queued !== $name || !is_file($dir . '/' . $name) || !glob($derivatives)) { throw new RuntimeException('Incomplete cleanup receipt for the removed picture'); }
foreach (array('pics','comments','ratings') as $part)
{
	$result = $db->sql_query('SELECT COUNT(*) AS total FROM ' . $table_prefix . 'plus_album_' . $part . ' WHERE pic_id=102');
	$count = (int) $db->sql_fetchfield('total');
	$db->sql_freeresult($result);
	if ($count) { throw new RuntimeException('Removed picture metadata still visible'); }
}
$task = $phpbb_container->get('phpbb2plus.gallery.cleanup');
$task->run();
if (!is_file($dir . '/' . $name)) { throw new RuntimeException('Cleanup removed an original during its in-flight grace period'); }
$db->sql_query("UPDATE $table SET removed_at=1 WHERE storage_name='$name'");
$task->run();
if (file_exists($dir . '/' . $name) || glob($derivatives) || !is_file($dir . '/' . $remaining)) { throw new RuntimeException('Gallery media cleanup touched the wrong files'); }
