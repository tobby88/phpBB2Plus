<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/album_root_storage.php';

// Existing-gallery creation only. Own the transaction; do not render while
// the global database points at this writer or touch a caller's pending work.
function album_personal_category_create($owner, $requested_parent, $title, $description, $levels)
{
	global $db, $userdata, $album_data, $album_config;
	if (!is_array($userdata) || !is_array($album_config) || !is_int($owner) || $owner <= 0 || $owner > 8388607 ||
		!is_int($requested_parent) || ($requested_parent !== ALBUM_ROOT_CATEGORY && ($requested_parent <= 0 || $requested_parent > 16777215)) ||
		!is_string($title) || $title === '' || !is_string($description) ||
		strlen($title) > 6120 || strlen($description) > 262140 ||
		preg_match('//u', $title) !== 1 || preg_match('//u', $description) !== 1 || !is_array($levels)) { return false; }
	$limit = isset($album_config['personal_sub_category_limit']) ? $album_config['personal_sub_category_limit'] : null;
	if ((!is_int($limit) && !is_string($limit)) || preg_match('/^-?[0-9]{1,10}$/D', (string) $limit) !== 1 ||
		(float) $limit < -2147483648 || (float) $limit > 2147483647) { return false; }
	$limit = (int) $limit;
	$plain_title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
	$title_length = preg_match_all('/./us', $plain_title);
	$description_length = preg_match_all('/./us', $description);
	if ($title_length === false || $title_length > 255 || trim($plain_title) === '' ||
		$description_length === false || $description_length > 65535 ||
		htmlspecialchars($plain_title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true) !== $title) { return false; }
	foreach (array('view', 'upload', 'rate', 'comment') as $field)
	{
		if (!array_key_exists($field, $levels) || (!is_scalar($levels[$field]) && $levels[$field] !== null)) { return false; }
	}
	$original_database = $db;
	$original_userdata = $userdata;
	$original_album_data = $album_data;
	$committed = false;
	$storage = null;
	try
	{
		$storage = new AlbumRootStorage($original_database);
		$db = $storage;
		if (!$storage->begin($owner) || !($actor = $storage->actor())) { return false; }
		$is_admin = (int) $actor['user_level'] === ADMIN;
		if (!$is_admin && album_user_id_value($actor['user_id']) !== $owner) { return false; }
		// The actor and exact current login row remain locked until COMMIT.
		$userdata['user_level'] = $actor['user_level'];

		$result = $storage->sql_query('SELECT cat_id FROM ' . ALBUM_CAT_TABLE . ' WHERE cat_user_id=' . $owner . ' AND cat_parent=0 ORDER BY cat_id FOR UPDATE');
		$roots = $storage->sql_numrows($result);
		$root = $storage->sql_fetchrow($result); $storage->sql_freeresult($result);
		if ($roots !== 1 || !$root) { return false; }
		$parent = $requested_parent === ALBUM_ROOT_CATEGORY ? (int) $root['cat_id'] : $requested_parent;
		$result = $storage->sql_query('SELECT * FROM ' . ALBUM_CAT_TABLE . ' WHERE cat_id=' . $parent . ' AND cat_user_id=' . $owner . ' FOR UPDATE');
		$parent_row = $storage->sql_fetchrow($result); $storage->sql_freeresult($result);
		if (!$parent_row) { return false; }
		// Bind management to the actual locked target, not a posted category's
		// old authorization cache. Group reads share-lock current membership.
		$access = album_permissions($owner, $parent, ALBUM_AUTH_MANAGE_PERSONAL_CATEGORIES, $parent_row);
		if (!album_check_permission($access, ALBUM_AUTH_MANAGE_PERSONAL_CATEGORIES)) { return false; }
		// The UI already limits all personal subcategories, excluding the root.
		// Enforce it under the same owner lock, not just by disabling a button.
		if ($limit >= 0)
		{
			$result = $storage->sql_query('SELECT COUNT(*) AS category_count FROM ' . ALBUM_CAT_TABLE . ' WHERE cat_user_id=' . $owner . ' AND cat_parent<>0');
			$count = $storage->sql_fetchrow($result); $storage->sql_freeresult($result);
			if (!$count || (int) $count['category_count'] >= $limit) { return false; }
		}
		$result = $storage->sql_query('SELECT cat_order FROM ' . ALBUM_CAT_TABLE . ' ORDER BY cat_order DESC LIMIT 1');
		$last = $storage->sql_fetchrow($result); $storage->sql_freeresult($result);
		$order = ($last ? (int) $last['cat_order'] : 0) + 10;
		$view = album_personal_permission_value($levels['view'], $is_admin, ALBUM_PRIVATE);
		$upload = album_personal_permission_value($levels['upload'], $is_admin, ALBUM_PRIVATE);
		$rate = album_personal_permission_value($levels['rate'], $is_admin, ALBUM_USER);
		$comment = album_personal_permission_value($levels['comment'], $is_admin, ALBUM_USER);
		$sql = 'INSERT INTO ' . ALBUM_CAT_TABLE . ' (cat_title,cat_desc,cat_order,cat_view_level,cat_upload_level,cat_rate_level,cat_comment_level,cat_edit_level,cat_delete_level,cat_approval,cat_parent,cat_user_id) VALUES (' .
			"'" . $storage->sql_escape($title) . "','" . $storage->sql_escape($description) . "'," . $order . ',' . $view . ',' . $upload . ',' . $rate . ',' . $comment . ',' . ALBUM_PRIVATE . ',' . ALBUM_PRIVATE . ',0,' . $parent . ',' . $owner . ')';
		if (!$storage->sql_query($sql) || (int) $storage->sql_affectedrows() !== 1) { return false; }
		$storage->commit();
		$committed = true;
		$album_data = array();
		return true;
	}
	catch (AlbumRootStorageFailure $error) { return false; }
	finally
	{
		$db = $original_database;
		$userdata = $original_userdata;
		if (!$committed) { $album_data = $original_album_data; }
		if ($storage) { $storage->release(); }
	}
}
