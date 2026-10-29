<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/album_lifecycle_authority.php';
require_once __DIR__ . '/album_file_storage.php';

// GD generation precedes this short writer lease. A late renderer must not
// recreate a final cache after another request has deleted its parent picture.
class AlbumCachePublication
{
	private $database;
	private $prefix;
	private $root;
	private $storage = null;
	private $authority = null;
	private $source_path = null;
	private $source_stat = null;

	function __construct($database, $prefix, $root = null)
	{
		$this->database = $database;
		$this->prefix = $prefix;
		$this->root = $root === null ? dirname(__DIR__) : $root;
	}

	private function rows($sql)
	{
		$result = $this->storage->sql_query($sql);
		try { $rows = $this->storage->sql_fetchrowset($result); }
		finally { $this->storage->sql_freeresult($result); }
		if (!is_array($rows)) { throw new AlbumFileCoordinatorFailure('Album cache metadata unavailable'); }
		return $rows;
	}

	function current($file_context = null)
	{
		if (!$this->storage || !$this->authority || !$this->authority->current() || !$this->storage->can_publish()) { return false; }
		if ($this->source_stat !== null)
		{
			clearstatcache(true, $this->source_path);
			$stat = @lstat($this->source_path);
			if (!is_array($stat)) { return false; }
			foreach (array('dev', 'ino', 'mode', 'size', 'mtime', 'ctime') as $field)
			{
				if (!array_key_exists($field, $stat) || $stat[$field] !== $this->source_stat[$field]) { return false; }
			}
		}
		return true;
	}

	function publish($snapshot, $owner, $category, $picture, $expected_names, $image, $type, $medium = false)
	{
		if ($this->storage !== null || !is_int($picture) || $picture < 1 || $picture > 4294967295 ||
			!is_bool($medium) || !in_array($type, array('.jpg', '.png'), true) || !is_array($expected_names) || count($expected_names) !== 2 ||
			!isset($expected_names['pic_filename']) || !array_key_exists('pic_thumbnail', $expected_names) ||
			!album_cache_filename($expected_names['pic_filename'], $type) ||
			($expected_names['pic_thumbnail'] !== null && !is_string($expected_names['pic_thumbnail'])) ||
			!function_exists('imagesx') || !function_exists('imagesy') ||
			!((is_resource($image) && get_resource_type($image) === 'gd') || (is_object($image) && is_a($image, 'GdImage')))) { return false; }
		try
		{
			$this->storage = new AlbumFileCoordinator($this->database, $this->prefix);
			$this->storage->begin();
			$this->authority = new AlbumLifecycleAuthority($this->storage, $this->prefix);
			$context = $this->authority->lock($snapshot, $owner, $category, ALBUM_AUTH_VIEW);
			if ($context === false) { return false; }
			$rows = $this->rows('SELECT pic_filename,pic_thumbnail,pic_approval FROM ' . $this->prefix . 'album WHERE pic_id=' . $picture . ' AND pic_cat_id=' . $category . ' FOR UPDATE');
			if (count($rows) !== 1 || $rows[0]['pic_filename'] !== $expected_names['pic_filename'] || $rows[0]['pic_thumbnail'] !== $expected_names['pic_thumbnail']) { return false; }
			if (!in_array($rows[0]['pic_approval'], array(0, 1, '0', '1'), true)) { return false; }
			$policy = $context['category']['cat_approval'];
			if (!in_array($policy, array(0, 1, 2, '0', '1', '2'), true)) { return false; }
			if ($context['userdata']['user_level'] !== ADMIN && (int) $rows[0]['pic_approval'] !== 1 &&
				((int) $policy === ALBUM_ADMIN || ((int) $policy === ALBUM_MOD && empty($context['access']['moderator'])))) { return false; }

			$settings = $context['settings'];
			if ($medium)
			{
				if (!defined('ALBUM_SP_CONFIG_TABLE') || ALBUM_SP_CONFIG_TABLE !== $this->prefix . 'album_sp_config') { return false; }
				$this->storage->pin_authority_table('album_sp_config');
				$extra = array();
				foreach ($this->rows('SELECT config_name,config_value FROM ' . ALBUM_SP_CONFIG_TABLE . " WHERE config_name IN('midthumb_cache','midthumb_width','midthumb_height') ORDER BY config_name LOCK IN SHARE MODE") as $row)
				{
					if (!isset($row['config_name'], $row['config_value']) || !is_string($row['config_value']) || isset($extra[$row['config_name']])) { return false; }
					$extra[$row['config_name']] = $row['config_value'];
				}
				if (count($extra) !== 3 || $extra['midthumb_cache'] !== '1') { return false; }
				$width = $extra['midthumb_width']; $height = $extra['midthumb_height'];
			}
			else
			{
				if (!isset($settings['thumbnail_cache'], $settings['thumbnail_size']) || $settings['thumbnail_cache'] !== '1') { return false; }
				$width = $height = $settings['thumbnail_size'];
			}
			if (!isset($settings['thumbnail_quality']) || !preg_match('/^[0-9]{1,3}$/D', $settings['thumbnail_quality']) || (int) $settings['thumbnail_quality'] > 100) { return false; }
			$quality = (int) $settings['thumbnail_quality'];
			$files = new AlbumFileStorage(array($this, 'current'), $this->root);
			$original = $files->snapshot('original', $rows[0]['pic_filename']);
			if (!$original['present'] || $original['bytes'] < 1) { return false; }
			$base = realpath($this->root);
			$this->source_path = $base . DIRECTORY_SEPARATOR . 'album_mod' . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . $rows[0]['pic_filename'];
			clearstatcache(true, $this->source_path); $stat = @lstat($this->source_path);
			if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== $original['bytes']) { return false; }
			$this->source_stat = $stat;
			$info = @getimagesize($this->source_path);
			if (!is_array($info) || $info[2] !== ($type === '.jpg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG)) { return false; }
			$dimensions = album_thumbnail_dimensions($info[0], $info[1], $width, $height);
			if ($dimensions === false || imagesx($image) !== $dimensions[0] || imagesy($image) !== $dimensions[1]) { return false; }
			$directory = $medium ? ALBUM_MED_CACHE_PATH : ALBUM_CACHE_PATH;
			$expected_directory = $base . DIRECTORY_SEPARATOR . 'album_mod' . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . ($medium ? 'med_cache' : 'cache');
			if (album_cache_directory($directory) !== $expected_directory || !$this->current()) { return false; }
			if (!album_store_cached_image($image, $directory, $rows[0]['pic_filename'], $type, $quality, array($this, 'current'))) { return false; }
			$this->storage->commit();
			return true;
		}
		finally { if ($this->storage) { $this->storage->release(); } }
	}
}

function album_publish_picture_cache($snapshot, $owner, $category, $picture, $expected_names, $image, $type, $medium = false)
{
	global $db, $table_prefix, $phpbb_root_path;
	try
	{
		$writer = new AlbumCachePublication($db, $table_prefix, $phpbb_root_path);
		return $writer->publish($snapshot, $owner, $category, $picture, $expected_names, $image, $type, $medium);
	}
	catch (Exception $error) { return false; }
	catch (Error $error) { return false; }
}
