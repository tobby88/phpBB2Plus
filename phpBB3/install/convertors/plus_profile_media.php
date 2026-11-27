<?php
if (!defined('IN_PHPBB')) { exit; }

require_once __DIR__ . '/plus_media.php';

function plus_profile_media_issue($kind, $source_id, $reference, $reason)
{
	global $db, $table_prefix;
	$table = $table_prefix . 'plus_media_issues';
	$id = (int) $source_id;
	$db->sql_query("DELETE FROM $table WHERE asset_kind='" . $db->sql_escape($kind) . "' AND source_id=$id");
	$row = array('asset_kind' => $kind, 'source_id' => $id, 'reference_json' => json_encode($reference), 'reason' => $reason);
	$db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT', $row));
}

function plus_profile_image($directory, $relative, $allow_board_root = false)
{
	global $convert;
	$directory = rtrim((string) $directory, '/');
	if (strpos($directory, './') === 0) { $directory = substr($directory, 2); }
	$ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
	$types = array('jpeg' => IMAGETYPE_JPEG, 'jpg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'gif' => IMAGETYPE_GIF);
	if (!isset($types[$ext])) { return false; }
	try
	{
		$root = $directory === '' && $allow_board_root ? $convert->options['forum_path'] : plus_media_path($convert->options['forum_path'], $directory, true);
		$file = plus_media_path($root, $relative);
	}
	catch (\RuntimeException $e) { return false; }
	$dims = @getimagesize($file);
	if (!$dims || $dims[2] !== $types[$ext] || min($dims[0], $dims[1]) < 1 || max($dims[0], $dims[1]) > 65535) { return false; }
	return array('source' => $file, 'width' => $dims[0], 'height' => $dims[1], 'ext' => $ext);
}

function plus_gallery_component($part)
{
	// Keep ordinary source labels. Map other names to ASCII that phpBB's native
	// gallery form can submit, avoiding aliases on case-insensitive filesystems.
	$label = preg_replace('/[^A-Za-z0-9 _-]+/', '_', $part);
	$label = trim(substr($label, 0, 42), ' .-_');
	if ($label === '' || $label !== $part || preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/iD', $part))
	{
		return ($label !== '' ? $label : 'Image') . '-' . substr(hash('sha256', $part), 0, 12);
	}
	return $label;
}

function plus_gallery_avatar($relative)
{
	global $config, $phpbb_root_path;
	$image = plus_profile_image(get_config_value('avatar_gallery_path'), $relative);
	if (!$image) { return false; }
	$directory = dirname($relative);
	$category = $directory === '.' ? 'Plus' : 'Plus-' . plus_gallery_component($directory);
	$file = plus_gallery_component(pathinfo($relative, PATHINFO_FILENAME)) . '.' . $image['ext'];
	$root = plus_media_path($phpbb_root_path, rtrim($config['avatar_gallery_path'], '/'), true);
	$dest = $root . DIRECTORY_SEPARATOR . $category;
	if (!is_dir($dest) && !@mkdir($dest) && !is_dir($dest)) { throw new \RuntimeException('Cannot create the converted avatar gallery.'); }
	if (is_link($dest)) { throw new \RuntimeException('Expected a local avatar gallery directory.'); }
	$dest = plus_media_path($root, $category, true);
	plus_copy_media($image['source'], $dest . DIRECTORY_SEPARATOR . $file);
	$image['name'] = rawurlencode($category) . '/' . rawurlencode($file);
	return $image;
}

function plus_import_avatar_gallery()
{
	global $convert;
	$dir = rtrim((string) get_config_value('avatar_gallery_path'), '/');
	if (strpos($dir, './') === 0) { $dir = substr($dir, 2); }
	try { $root = plus_media_path($convert->options['forum_path'], $dir, true); }
	catch (\RuntimeException $e) { return; }
	$entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
	foreach ($entries as $file)
	{
		if (!$file->isFile() || $file->isLink()) { continue; }
		$relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
		if (!preg_match('~(?:^|/)\.~', $relative)) { plus_gallery_avatar($relative); }
	}
}

function plus_import_topic_avatar()
{
	global $src_db, $convert, $config, $db, $table_prefix;
	$result = $src_db->sql_query('SELECT config_value FROM ' . $convert->src_table_prefix . "plus WHERE config_name='default_avatar'");
	$enabled = $src_db->sql_fetchfield('config_value');
	$src_db->sql_freeresult($result);
	$image = plus_profile_image('images','default_avatar.gif');
	$name = $image ? plus_public_bitmap($image,'plusavatars') : '';
	if ($image) { $db->sql_query('DELETE FROM ' . $table_prefix . "plus_media_issues WHERE asset_kind='topic-avatar' AND source_id=0"); }
	else { plus_profile_media_issue('topic-avatar',0,'images/default_avatar.gif','unavailable'); }
	$config->set('plus_topic_avatar_image',$name);
	$config->set('plus_topic_default_avatar',(int) ((string) $enabled === '1'));
}

function plus_profile_avatar_field($id, $allowed, $type, $reference, $field)
{
	global $config, $phpbb_root_path;
	static $last = null, $avatar;
	$id = (int) $id;
	if ($last !== $id)
	{
		$last = $id;
		$avatar = array('user_avatar' => '', 'user_avatar_type' => '', 'user_avatar_width' => 0, 'user_avatar_height' => 0);
		$type = (int) $type;
		$reference = (string) $reference;
		if ($id > 0 && $reference !== '' && $type && !$allowed)
		{
			plus_profile_media_issue('avatar', $id, array('type'=>$type,'name'=>$reference), 'disabled_in_source');
		}
		elseif ($id > 0 && $reference !== '' && $type)
		{
			$driver = ''; $name = ''; $width = $height = 0;
			if ($type === 2)
			{
				// A phpBB2 database has no measurements for externally hosted avatars.
				// Retain the configured maximum dimensions without requesting the URL.
				$url = html_entity_decode($reference, ENT_QUOTES, 'UTF-8');
				$parts = parse_url($url);
				if (filter_var($url, FILTER_VALIDATE_URL) && isset($parts['host'], $parts['scheme']) &&
					!isset($parts['user']) && !isset($parts['pass']) && in_array(strtolower($parts['scheme']), array('http','https'), true))
				{
					$name = utf8_htmlspecialchars($url);
					$driver = 'remote';
					$width = min(65535, max(1, (int) get_config_value('avatar_max_width')));
					$height = min(65535, max(1, (int) get_config_value('avatar_max_height')));
				}
			}
			else
			{
				$image = $type === 3 ? plus_gallery_avatar($reference) :
					($type === 1 ? plus_profile_image(get_config_value('avatar_path'), $reference) : false);
				if ($image)
				{
					$width = $image['width']; $height = $image['height'];
					if ($type === 3) { $driver = 'local'; $name = $image['name']; }
					else
					{
						$driver = 'upload';
						$owner = phpbb_user_id($id);
						$dir = plus_media_path($phpbb_root_path, rtrim($config['avatar_path'], '/'), true);
						plus_copy_media($image['source'], $dir . '/' . $config['avatar_salt'] . '_' . $owner . '.' . $image['ext']);
						$name = $owner . '_' . max(1, (int) filemtime($image['source'])) . '.' . $image['ext'];
					}
				}
			}
			if ($name === '') { plus_profile_media_issue('avatar', $id, array('type'=>$type,'name'=>$reference), 'unavailable'); }
			else
			{
				$avatar = array('user_avatar' => $name, 'user_avatar_type' => 'avatar.driver.' . $driver,
					'user_avatar_width' => $width, 'user_avatar_height' => $height);
			}
		}
		$avatar['plus_no_default_avatar'] = (int) ($avatar['user_avatar'] === '' && ($reference !== '' || ($type && $id > 0 && $allowed)));
	}
	return $avatar[$field];
}

function plus_profile_rank($id, $reference)
{
	global $config, $phpbb_root_path;
	if ($reference === null || $reference === '') { return ''; }
	$style = 'templates/fisubsilversh/images/';
	$dir = plus_media_path($phpbb_root_path, rtrim($config['ranks_path'], '/'), true);
	$variants = array();
	foreach (array('de'=>'german', 'en'=>'english') as $lang => $legacy)
	{
		$image = plus_profile_image($style . 'lang_' . $legacy . '/ranks', $reference);
		if ($image) { $variants[$lang] = $image; }
	}
	$generic = $variants ? false : plus_profile_image($style . 'ranks', $reference);
	if (!$generic && !$variants)
	{
		$basename = preg_replace('~^images/ranks/~', '', $reference);
		$generic = plus_profile_image('images/ranks', $basename);
	}
	if ($generic) { $variants['base'] = $generic; }
	if (!$variants) { plus_profile_media_issue('rank', $id, $reference, 'unavailable'); return ''; }
	$first = reset($variants);
	$prefix = 'plus-rank-' . (int) $id . '-' . substr(hash('sha256', implode(':', array_map(static function ($image) { return hash_file('sha256', $image['source']); }, $variants))), 0, 16);
	$localized = count($variants) === 2;
	foreach ($variants as $language => $image)
	{
		$name = $prefix . ($localized ? '-' . $language : '') . '.' . $image['ext'];
		plus_copy_media($image['source'], $dir . '/' . $name);
	}
	$language = (get_config_value('default_lang') === 'german' || get_config_value('default_lang') === 'de') ? 'de' : 'en';
	return $prefix . ($localized ? '-' . $language : '') . '.' . $first['ext'];
}

function plus_profile_smiley($id, $reference, $code, $emotion)
{
	global $config, $db, $phpbb_root_path;
	$image = plus_profile_image(get_config_value('smilies_path'), $reference);
	if (!$image)
	{
		plus_profile_media_issue('smiley', $id, array('name'=>$reference,'code'=>$code,'emotion'=>$emotion), 'unavailable');
		return;
	}
	$dir = plus_media_path($phpbb_root_path, rtrim($config['smilies_path'], '/'), true);
	// Native smiley_url is limited to 50 characters. Multiple codes can share a file.
	$name = 'plus-' . substr(hash_file('sha256', $image['source']), 0, 36) . '.' . $image['ext'];
	plus_copy_media($image['source'], $dir . '/' . $name);
	$row = array('smiley_id'=>(int) $id, 'code'=>utf8_htmlspecialchars(phpbb_smilie_html_decode($code)),
		'emotion'=>plus_convert_byline($emotion), 'smiley_url'=>$name, 'smiley_width'=>$image['width'], 'smiley_height'=>$image['height'],
		'smiley_order'=>(int) $id, 'display_on_posting'=>get_smiley_display());
	$db->sql_query('INSERT INTO ' . SMILIES_TABLE . ' ' . $db->sql_build_array('INSERT', $row));
}
