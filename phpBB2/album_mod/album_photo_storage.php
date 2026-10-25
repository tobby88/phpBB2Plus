<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/album_root_storage.php';
require_once dirname(__FILE__) . '/album_photo_text.php';

// Text changes only: never move, create or delete an image or its cached files.
class AlbumPhotoEditStorage extends AlbumRootStorage
{
	function prepare_picture()
	{
		if (!$this->owned || !$this->transactional || !$this->ready || $this->finished) { throw new AlbumRootStorageFailure(); }
		$this->pin(ALBUM_TABLE); $this->pin(ALBUM_CONFIG_TABLE);
	}

	function settings()
	{
		$names = array('rate', 'comment', 'personal_gallery', 'personal_gallery_view', 'personal_gallery_private', 'personal_allow_gallery_mod', 'personal_allow_sub_categories', 'personal_sub_category_limit', 'desc_length');
		$result = $this->sql_query('SELECT config_name,config_value FROM ' . ALBUM_CONFIG_TABLE . " WHERE config_name IN ('" . implode("','", $names) . "') ORDER BY config_name LOCK IN SHARE MODE");
		$count = $this->sql_numrows($result); $settings = array();
		while ($row = $this->sql_fetchrow($result))
		{
			if (!isset($row['config_name'], $row['config_value']) || !is_string($row['config_name']) || !is_string($row['config_value']) ||
				!in_array($row['config_name'], $names, true) || isset($settings[$row['config_name']])) { $this->sql_freeresult($result); return false; }
			$settings[$row['config_name']] = $row['config_value'];
		}
		$this->sql_freeresult($result);
		return $count === count($names) && count($settings) === count($names) ? $settings : false;
	}

	function save_text($picture, $category, $author, $title, $description)
	{
		if (!$this->owned || !$this->connection || !$this->transactional || !$this->ready || $this->finished) { throw new AlbumRootStorageFailure(); }
		$sql = 'UPDATE ' . ALBUM_TABLE . " SET pic_title='" . $this->connection->sql_escape($title) . "',pic_desc='" . $this->connection->sql_escape($description) .
			"' WHERE pic_id=" . $picture . ' AND pic_cat_id=' . $category . ' AND pic_user_id=' . $author;
		return $this->connection->sql_query($sql) && (int) $this->connection->sql_affectedrows() === 1;
	}
}

function album_picture_text_edit($picture, $category, $owner, $author, $title, $description)
{
	global $db, $userdata, $album_config;
	if (!is_array($userdata) || !is_array($album_config) || !is_int($picture) || $picture <= 0 || $picture > 4294967295 ||
		!is_int($category) || $category <= 0 || $category > 16777215 || !is_int($owner) || $owner < 0 || $owner > 8388607 ||
		!is_int($author) || $author < -8388608 || $author > 8388607 || !is_string($title) || !is_string($description) ||
		strlen($title) > 6120 || strlen($description) > 393210 || preg_match('//u', $title) !== 1 || preg_match('//u', $description) !== 1) { return false; }
	$actor_id = isset($userdata['user_id']) ? album_user_id_value($userdata['user_id']) : false;
	if ($actor_id === false || $actor_id <= 0) { return false; }
	$plain_title = html_entity_decode($title, ENT_QUOTES, 'UTF-8'); $plain_description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');
	$title_length = preg_match_all('/./us', $plain_title); $description_length = preg_match_all('/./us', $plain_description);
	if ($title_length === false || $title_length > 255 || trim($plain_title) === '' || $description_length === false || $description_length > 65535 ||
		htmlspecialchars($plain_title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true) !== $title ||
		htmlspecialchars($plain_description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true) !== $description) { return false; }
	$original_database = $db; $original_userdata = $userdata; $original_config = $album_config; $storage = null;
	try
	{
		$storage = new AlbumPhotoEditStorage($original_database); $db = $storage;
		// Public owner0 is not an account. Serialize on the real actor instead;
		// personal galleries retain the established sorted owner/actor locks.
		if (!$storage->begin($owner > 0 ? $owner : $actor_id) || !($actor = $storage->actor())) { return false; }
		$userdata['user_level'] = $actor['user_level']; $userdata['user_active'] = $actor['user_active'];
		$storage->prepare_picture(); $settings = $storage->settings();
		if ($settings === false) { return false; }
		$album_config = array_merge($album_config, $settings);
		if ($description_length > album_photo_description_limit($album_config['desc_length'])) { return false; }
		// Shared category/config locks allow different photos to be edited while
		// excluding a concurrent permission change or category removal.
		$result = $storage->sql_query('SELECT * FROM ' . ALBUM_CAT_TABLE . ' WHERE cat_id=' . $category . ' AND cat_user_id=' . $owner . ' LOCK IN SHARE MODE');
		$current_category = $storage->sql_fetchrow($result); $count = $storage->sql_numrows($result); $storage->sql_freeresult($result);
		if (!$current_category || $count !== 1) { return false; }
		$result = $storage->sql_query('SELECT * FROM ' . ALBUM_TABLE . ' WHERE pic_id=' . $picture . ' FOR UPDATE');
		$current = $storage->sql_fetchrow($result); $count = $storage->sql_numrows($result); $storage->sql_freeresult($result);
		if (!$current || $count !== 1 || (int) $current['pic_cat_id'] !== $category || (int) $current['pic_user_id'] !== $author) { return false; }
		$access = album_permissions($owner, $category, ALBUM_AUTH_EDIT, $current_category);
		if (empty($access['edit']) || (empty($access['moderator']) && (int) $actor['user_level'] !== ADMIN && $actor_id !== $author)) { return false; }
		if ($current['pic_title'] !== $title || (string) $current['pic_desc'] !== $description)
		{
			if (!$storage->save_text($picture, $category, $author, $title, $description)) { return false; }
			$result = $storage->sql_query('SELECT * FROM ' . ALBUM_TABLE . ' WHERE pic_id=' . $picture . ' FOR UPDATE');
			$saved = $storage->sql_fetchrow($result); $count = $storage->sql_numrows($result); $storage->sql_freeresult($result);
			if (!$saved || $count !== 1 || $saved['pic_title'] !== $title || $saved['pic_desc'] !== $description) { return false; }
			foreach ($current as $field => $value)
			{
				if (!is_int($field) && $field !== 'pic_title' && $field !== 'pic_desc' && (!array_key_exists($field, $saved) || $saved[$field] !== $value)) { return false; }
			}
		}
		$storage->commit(); return true;
	}
	catch (AlbumRootStorageFailure $error) { return false; }
	finally
	{
		$db = $original_database; $userdata = $original_userdata; $album_config = $original_config;
		if ($storage) { $storage->release(); }
	}
}
