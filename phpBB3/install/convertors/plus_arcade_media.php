<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_arcade_asset_path($value)
{
	$value = str_replace('\\', '/', trim((string) $value));
	while (substr($value, 0, 2) === './') { $value = substr($value, 2); }
	$value = rtrim($value, '/');
	return $value !== '' && $value[0] !== '/' && !preg_match('~[:?#%\x00-\x1f\x7f]|(?:^|/)\.{1,2}(?:/|$)|//~', $value) ? $value : '';
}

function plus_arcade_asset_type($path, $extension, $auxiliary)
{
	$types = array('swf'=>'application/x-shockwave-flash','gif'=>'image/gif','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp',
		'mp3'=>'audio/mpeg','ogg'=>'audio/ogg','wav'=>'audio/wav','m4a'=>'audio/mp4','mp4'=>'video/mp4','webm'=>'video/webm','ogv'=>'video/ogg',
		'mov'=>'video/quicktime','flv'=>'video/x-flv','mpeg'=>'video/mpeg','mpg'=>'video/mpeg');
	if ($auxiliary) { $types += array('xml'=>'application/octet-stream','txt'=>'application/octet-stream','json'=>'application/octet-stream','dat'=>'application/octet-stream'); }
	if (!isset($types[$extension])) { return ''; }
	$type = $types[$extension];
	if (strpos($type, 'image/') === 0)
	{
		$info = @getimagesize($path);
		return $info && $info['mime'] === $type ? $type : '';
	}
	if ($extension === 'swf')
	{
		$header = file_get_contents($path, false, null, 0, 8);
		if ($header === false || strlen($header) !== 8 || !in_array(substr($header, 0, 3), array('FWS','CWS','ZWS'), true)) { return ''; }
		$size = unpack('Vsize', substr($header, 4))['size'];
		if ($size < 8 || (substr($header, 0, 3) === 'FWS' && $size !== filesize($path))) { return ''; }
	}
	return $type;
}

function plus_arcade_import_asset($id, $name, $path, $type, $target)
{
	global $db, $table_prefix, $config;
	$storage = 'arcade_' . hash_hmac('sha256', $id . '/' . $name, $config['plus_arc_media_key']);
	plus_copy_media($path, $target . '/' . $storage);
	$row = array('game_id' => $id, 'path_key' => hash('sha256', $name), 'relative_name' => $name, 'storage_name' => $storage,
		'mime_type' => $type, 'bytes' => filesize($path), 'legacy_checksum' => $type === 'application/x-shockwave-flash' ? md5_file($path) : '');
	$db->sql_query('INSERT INTO ' . $table_prefix . 'plus_arc_assets ' . $db->sql_build_array('INSERT', $row));
}

function plus_arcade_directories($board, $root)
{
	global $src_db, $convert;
	static $owners = null;
	if ($owners !== null) { return $owners; }
	$owners = array();
	$result = $src_db->sql_query('SELECT game_path FROM ' . $convert->src_table_prefix . 'ina_games WHERE game_id>0');
	while ($row = $src_db->sql_fetchrow($result))
	{
		$path = plus_arcade_asset_path($row['game_path']);
		if (!$path || ($path !== $root && strpos($path, $root . '/') !== 0)) { continue; }
		$absolute = realpath($board . '/' . $path);
		if ($absolute !== false) { $owners[$absolute] = ($owners[$absolute] ?? 0) + 1; }
	}
	$src_db->sql_freeresult($result);
	return $owners;
}

function plus_arcade_artwork_source()
{
	global $convert, $db, $table_prefix, $config, $phpbb_root_path, $phpbb_container;
	static $artwork = null, $source = null;
	if ($artwork === null)
	{
		$artwork = new \phpbb2plus\arcade\service\artwork($db, $config, $table_prefix . 'plus_arc_', $phpbb_root_path, $phpbb_container->get('filesystem'));
		$source = $artwork->source_root($convert->options['forum_path']);
	}
	return array($artwork, $source);
}

function plus_arcade_category_artwork($id, $image)
{
	list($artwork, $source) = plus_arcade_artwork_source();
	$artwork->import_category((int) $id, utf8_encode_ucr($image), $source);
}

function plus_arcade_media($id, $name, $directory, $flash, $image)
{
	global $convert, $src_db, $db, $table_prefix, $config, $phpbb_root_path, $phpbb_container;
	$id = (int) $id;
	if ($id <= 0) { return; }
	list($artwork, $source) = plus_arcade_artwork_source();
	static $games_root = null;
	if ($games_root === null)
	{
		$result = $src_db->sql_query("SELECT config_value FROM " . $convert->src_table_prefix . "ina_data WHERE config_name='games_path'");
		$games_root = plus_arcade_asset_path($src_db->sql_fetchfield('config_value') ?: 'games');
		$src_db->sql_freeresult($result);
	}
	$directory = plus_arcade_asset_path($directory);
	$file = (string) $name;
	if ($flash && !pathinfo($file, PATHINFO_EXTENSION)) { $file .= '.swf'; }
	$file = plus_arcade_asset_path($file);
	$status = 'missing';
	$main = '';
	$db->sql_transaction('begin');
	try
	{
		$db->sql_query('DELETE FROM ' . $table_prefix . 'plus_arc_assets WHERE game_id=' . $id);
		if (!$file || strpos($file, '/') !== false || !$games_root || !$directory ||
			($directory !== $games_root && strpos($directory, $games_root . '/') !== 0)) { $status = 'outside'; }
		else
		{
			$board = $convert->options['forum_path'];
			$relative = $directory . '/' . $file;
			if (is_file($board . '/' . $relative))
			{
				$games_dir = plus_media_path($board, $games_root, true);
				$base = $directory === $games_root ? $games_dir : plus_media_path($games_dir, substr($directory, strlen($games_root) + 1), true);
				$path = plus_media_path($base, $file);
				$type = plus_arcade_asset_type($path, strtolower(pathinfo($file, PATHINFO_EXTENSION)), false);
				$status = $type ? 'ready' : 'unsupported';
				if ($type)
				{
					$main = $file;
					$target = plus_media_path($phpbb_root_path, trim($config['upload_path'], '/'), true);
					if (empty($config['plus_arc_media_key']) || $target === $games_dir) { throw new \RuntimeException('Use a separate initialized Arcade destination.'); }
					plus_arcade_import_asset($id, $file, $path, $type, $target);
					// A directory used by one game can contain its relative sound, level and picture assets.
					// Never assign an entire shared games directory to every player.
					$directories = plus_arcade_directories($board, $games_root);
					if (($directories[$base] ?? 0) === 1 && $base !== $games_dir)
					{
						$files = new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS);
						$filter = new \RecursiveCallbackFilterIterator($files, static function ($entry) use ($directories) {
							return !$entry->isLink() && substr($entry->getFilename(), 0, 1) !== '.' &&
								(!$entry->isDir() || !isset($directories[$entry->getRealPath()]));
						});
						$iterator = new \RecursiveIteratorIterator($filter);
						foreach ($iterator as $entry)
						{
							if ($entry->isLink() || !$entry->isFile()) { continue; }
							$rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($base) + 1));
							if ($rel === $file || plus_arcade_asset_path($rel) !== $rel || preg_match('~(?:^|/)\.~', $rel)) { continue; }
							$aux = plus_arcade_asset_type($entry->getPathname(), strtolower($entry->getExtension()), true);
							if ($aux) { plus_arcade_import_asset($id, $rel, plus_media_path($base, $rel), $aux, $target); }
						}
					}
				}
			}
		}
		$db->sql_query('UPDATE ' . $table_prefix . 'plus_arc_games SET ' . $db->sql_build_array('UPDATE', array('asset_status'=>$status,'main_asset'=>$main)) . ' WHERE game_id=' . $id);
		$artwork->import_game(array('game_id' => $id, 'game_name' => $name, 'game_path' => $directory, 'image_path' => $image), $source);
		$db->sql_transaction('commit');
	}
	catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }
}
