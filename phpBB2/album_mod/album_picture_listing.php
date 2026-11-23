<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__DIR__) . '/includes/functions_forum_read_snapshot.php';

// Hierarchical galleries use real positive category IDs. Do not truncate a
// legitimate hierarchy to an arbitrary number of categories.
function album_picture_category_ids($values)
{
	if (is_string($values) || is_int($values)) { $values = explode(',', (string)$values); }
	if (!is_array($values)) { throw new PhpbbForumReadFailure('Invalid Album categories'); }
	$ids = array();
	foreach ($values as $value)
	{
		if (!(is_string($value) || is_int($value)) || preg_match('/^[1-9][0-9]{0,7}$/D', (string)$value) !== 1 || (int)$value > 16777215) { throw new PhpbbForumReadFailure('Invalid Album category'); }
		$ids[(int)$value] = (int)$value;
	}
	return array_values($ids);
}

// Album ACL helpers read these request globals. Each caller restores the
// original values and releases its independent reader in a finally block.
function album_picture_read_context($reader)
{
	global $db, $userdata, $album_config, $album_data;
	$actor = $userdata;
	if (!empty($actor['session_logged_in'])) {
		$fresh = $reader->authenticated_user($actor);
		if (!$fresh) { throw new PhpbbForumReadFailure('Album session unavailable'); }
		$actor = array_merge($actor, $fresh);
	} else {
		$actor = array('user_id'=>ANONYMOUS, 'user_level'=>USER, 'session_logged_in'=>false, 'session_id'=>'');
	}
	$settings = array_column($reader->rows('SELECT config_name,config_value FROM ' . ALBUM_CONFIG_TABLE), 'config_value', 'config_name');
	foreach (array('rate','comment','personal_gallery','personal_gallery_view','personal_gallery_private','personal_allow_gallery_mod','personal_allow_sub_categories','personal_sub_category_limit','album_debug_mode') as $key) {
		if (!isset($settings[$key])) { throw new PhpbbForumReadFailure('Album settings unavailable'); }
	}
	$db = $reader;
	$userdata = $actor;
	$album_config = $settings;
	$album_data = array();
}

function album_picture_visibility($categories)
{
	$visible = $rights = $pending = array();
	foreach ($categories as $category) {
		$id = (int)$category['cat_id'];
		$access = album_permissions((int)$category['cat_user_id'], $id, ALBUM_AUTH_ALL, $category);
		if (!album_check_permission($access, ALBUM_AUTH_VIEW)) { continue; }
		$visible[$id] = $category;
		$rights[$id] = $access;
		if (album_picture_pending_visible($category, $access)) { $pending[] = $id; }
	}
	$where = $visible ? 'p.pic_cat_id IN (' . implode(',', array_keys($visible)) . ') AND (p.pic_approval=1'
		. ($pending ? ' OR p.pic_cat_id IN (' . implode(',', $pending) . ')' : '') . ')' : '1=0';
	return array('categories'=>$visible, 'rights'=>$rights, 'where'=>$where);
}

// Draw only from pictures the viewer can open. Category restrictions, approval,
// rating channel and repeat-vote settings belong to the same database snapshot.
function album_hotornot_picture($database)
{
	global $db, $userdata, $album_config, $album_data, $album_sp_config;
	$original = array($db, $userdata, $album_config, $album_data, $album_sp_config);
	$reader = null;
	try {
		$reader = new PhpbbForumReadSnapshot($database, array(ALBUM_TABLE, ALBUM_CAT_TABLE, ALBUM_RATE_TABLE, ALBUM_COMMENT_TABLE,
			ALBUM_CONFIG_TABLE, ALBUM_SP_CONFIG_TABLE, USERS_TABLE, USER_GROUP_TABLE, SESSIONS_TABLE, CONFIG_TABLE, BANLIST_TABLE));
		album_picture_read_context($reader);
		$settings = $reader->rows('SELECT config_name,config_value FROM ' . ALBUM_SP_CONFIG_TABLE
			. " WHERE config_name IN ('hon_rate_where','hon_rate_sep','hon_rate_times','hon_rate_users')");
		$settings = array_column($settings, 'config_value', 'config_name');
		$rating_enabled = !empty($album_config['rate']);
		$scale = isset($album_config['rate_scale']) ? (int)$album_config['rate_scale'] : 0;
		if (count($settings) !== 4 || ($rating_enabled && ($scale < 1 || $scale > 255))) {
			throw new PhpbbForumReadFailure('Album rating settings unavailable');
		}
		$album_sp_config = $settings;
		$allowed = array();
		foreach (preg_split('/[^0-9]+/', $settings['hon_rate_where'], -1, PREG_SPLIT_NO_EMPTY) as $id) {
			$id = (int)$id;
			if ($id > 0) { $allowed[$id] = $id; }
		}
		$categories = $reader->rows('SELECT ct.* FROM ' . ALBUM_CAT_TABLE . ' ct WHERE ct.cat_id>0'
			. ($allowed ? ' AND ct.cat_id IN (' . implode(',', $allowed) . ')' : '')
			. ' AND EXISTS (SELECT 1 FROM ' . ALBUM_TABLE . ' p WHERE p.pic_cat_id=ct.cat_id)');
		$scope = album_picture_visibility($categories);
		if (!$scope['categories']) { return null; }
		$draw = $reader->rows('SELECT p.pic_id FROM ' . ALBUM_TABLE . ' p WHERE ' . $scope['where'] . ' ORDER BY RAND() LIMIT 1');
		if (!$draw) { return null; }
		$id = album_read_id($draw[0]['pic_id']);
		$details = $reader->rows(album_picture_details_sql($id, $settings['hon_rate_sep'] === '1'));
		if (count($details) !== 1) { throw new PhpbbForumReadFailure('Album picture unavailable'); }
		$picture = $details[0];
		$rights = $scope['rights'][(int)$picture['pic_cat_id']];
		$can_rate = $rating_enabled && !empty($rights['rate']) && (empty($picture['pic_lock']) || !empty($rights['moderator']))
			&& (!empty($userdata['session_logged_in']) || $settings['hon_rate_users'] === '1');
		$already_rated = $can_rate && !CanRated($id, $userdata['user_id']);
		return array('picture'=>$picture, 'can_rate'=>$can_rate && !$already_rated, 'already_rated'=>$already_rated,
			'rating_enabled'=>$rating_enabled, 'comment_enabled'=>!empty($album_config['comment']), 'scale'=>$scale);
	} finally {
		list($db, $userdata, $album_config, $album_data, $album_sp_config) = $original;
		if ($reader !== null) { $reader->release(); }
	}
}

// The owner directory is a projection of visible pictures, including each
// category's approval policy. Aggregate only after authorizing the categories;
// count and page must use the same independent snapshot as those permissions.
function album_gallery_owner_page($database, $sort, $order, $start, $size)
{
	global $db, $userdata, $album_config, $album_data;
	$original = array($db, $userdata, $album_config, $album_data); $reader = null;
	try
	{
		if (!is_int($start) || $start < 0 || $start > 1000000 || !is_int($size) || $size < 1 || $size > 100) { throw new PhpbbForumReadFailure('Invalid Album gallery page'); }
		$sorts = array('joindate'=>'u.user_regdate', 'username'=>'u.username', 'pics'=>'g.pics', 'last_pic'=>'g.last_pic');
		$sort = is_string($sort) && isset($sorts[$sort]) ? $sort : 'joindate'; $order = $order === 'DESC' ? 'DESC' : 'ASC';
		$out = array('rows'=>array(), 'total'=>0, 'start'=>0, 'size'=>$size, 'sort'=>$sort, 'order'=>$order);
		$reader = new PhpbbForumReadSnapshot($database, array(ALBUM_TABLE, ALBUM_CAT_TABLE, ALBUM_CONFIG_TABLE, USERS_TABLE, USER_GROUP_TABLE, SESSIONS_TABLE, CONFIG_TABLE, BANLIST_TABLE));
		album_picture_read_context($reader);
		$categories = $reader->rows('SELECT ct.* FROM ' . ALBUM_CAT_TABLE . ' ct JOIN ' . USERS_TABLE . ' u ON u.user_id=ct.cat_user_id WHERE ct.cat_id>0 AND ct.cat_user_id>0 AND ct.cat_user_id<=8388607');
		$scope = album_picture_visibility($categories);
		if (!$scope['categories']) { return $out; }
		// Group the picture facts separately, so username and join date do not
		// depend on permissive GROUP BY behavior. Last picture means upload time,
		// rather than the numeric ID of a possibly imported older picture.
		$from = ' FROM (SELECT ct.cat_user_id user_id,COUNT(p.pic_id) pics,MAX(p.pic_time) last_pic FROM ' . ALBUM_TABLE . ' p JOIN ' . ALBUM_CAT_TABLE . ' ct ON ct.cat_id=p.pic_cat_id'
			. ' WHERE ' . $scope['where'] . ' GROUP BY ct.cat_user_id) g'
			. ' JOIN ' . USERS_TABLE . ' u ON u.user_id=g.user_id';
		$count = $reader->rows('SELECT COUNT(*) total' . $from);
		if (count($count) !== 1 || !isset($count[0]['total']) || preg_match('/^(?:0|[1-9][0-9]{0,6})$/D', (string)$count[0]['total']) !== 1 || (int)$count[0]['total'] > 8388607) { throw new PhpbbForumReadFailure('Invalid Album gallery count'); }
		$out['total'] = (int)$count[0]['total']; $last = $out['total'] ? (int)floor(($out['total'] - 1) / $size) * $size : 0;
		$out['start'] = (int)floor(min($start, $last) / $size) * $size;
		if ($out['total'])
		{
			$out['rows'] = $reader->rows('SELECT u.user_id,u.username,u.user_regdate,g.pics,g.last_pic' . $from . ' ORDER BY ' . $sorts[$sort] . ' ' . $order . ',u.user_id ' . $order . ' LIMIT ' . $out['start'] . ',' . $size);
			if (count($out['rows']) !== min($size, $out['total'] - $out['start'])) { throw new PhpbbForumReadFailure('Inconsistent Album gallery page'); }
		}
		return $out;
	}
	finally
	{
		$db = $original[0]; $userdata = $original[1]; $album_config = $original[2]; $album_data = $original[3];
		if ($reader !== null) { $reader->release(); }
	}
}

function album_picture_pending_visible($category, $rights, $actor = null)
{
	if ($actor === null) { $actor = isset($GLOBALS['userdata']) ? $GLOBALS['userdata'] : array(); }
	if (!is_array($category) || !is_array($rights) || !isset($category['cat_approval']) || !(is_int($category['cat_approval']) || is_string($category['cat_approval']))) { return false; }
	// Disabling category approval makes old picture flags irrelevant. Category
	// view permission is checked separately before applying this predicate.
	if ((string)$category['cat_approval'] === (string)ALBUM_USER) { return true; }
	if (!is_array($actor) || !isset($actor['session_logged_in'], $actor['user_id'])
		|| !in_array($actor['session_logged_in'], array(true, 1, '1'), true) || !(is_int($actor['user_id']) || is_string($actor['user_id']))
		|| preg_match('/^[1-9][0-9]{0,6}$/D', (string)$actor['user_id']) !== 1 || (int)$actor['user_id'] > 8388607 || !(is_int($category['cat_approval']) || is_string($category['cat_approval']))) { return false; }
	$policy = (string)$category['cat_approval'];
	return in_array($policy, array((string)ALBUM_ADMIN, (string)ALBUM_MOD), true)
		&& (isset($actor['user_level']) && (string)$actor['user_level'] === (string)ADMIN
			|| $policy === (string)ALBUM_MOD && isset($rights['moderator']) && in_array($rights['moderator'], array(1, '1', true), true));
}

// Index summaries already receive the hierarchy's authorized category set.
// Pending visibility still belongs to each category, never the first parent.
function album_picture_approval_sql($values, $alias = 'p')
{
	global $album_data;
	if (!in_array($alias, array('p', 'a'), true)) { throw new PhpbbForumReadFailure('Invalid Album summary alias'); }
	$pending = array();
	foreach (album_picture_category_ids($values) as $id)
	{
		if (isset($album_data['keys'][$id], $album_data['data'][$album_data['keys'][$id]])
			&& album_picture_pending_visible($album_data['data'][$album_data['keys'][$id]], isset($album_data['auth'][$id]) ? $album_data['auth'][$id] : array())) { $pending[] = $id; }
	}
	return ' AND (' . $alias . '.pic_approval=1' . ($pending ? ' OR ' . $alias . '.pic_cat_id IN (' . implode(',', $pending) . ')' : '') . ')';
}

// Counts, category/group policy, the current actor and the selected page all
// belong to one independent read-only snapshot. A caller's uncommitted work is
// neither read nor committed here. The existing Album ACL implementation decides
// access; each category keeps its own pending-picture policy in "show all" mode.
function album_picture_page($database, $owner, $values, $sort, $order, $start, $member = 0, $kind = 'pic', $count_only = false, $size_override = null)
{
	global $db, $userdata, $album_config, $album_data;
	$original = array($db, $userdata, $album_config, $album_data); $reader = null;
	try
	{
		$ids = album_picture_category_ids($values);
		if ($owner !== null && (!is_int($owner) || $owner < 0 || $owner > 8388607) || !is_int($member) || $member < 0 || $member > 8388607
			|| !in_array($kind, array('pic', 'rating', 'comment'), true) || !$member && $kind !== 'pic' || !is_int($start) || $start < 0 || $start > 1000000 || !is_bool($count_only)
			|| $size_override !== null && (!is_int($size_override) || $size_override < 1 || $size_override > 100)) { throw new PhpbbForumReadFailure('Invalid Album page'); }
		$sorts = array('pic_time'=>'p.pic_time', 'pic_title'=>'p.pic_title', 'username'=>'u.username', 'pic_view_count'=>'p.pic_view_count', 'rating'=>'rating', 'comments'=>'comments', 'new_comment'=>'new_comment', 'random'=>'RAND()', 'highest'=>'rating');
		$sort = is_string($sort) && isset($sorts[$sort]) ? $sort : 'pic_time'; $order = $order === 'ASC' ? 'ASC' : 'DESC';
		$columns = max(1, min(20, isset($album_config['cols_per_page']) ? (int)$album_config['cols_per_page'] : 4));
		$size = min(100, $columns * max(1, min(50, isset($album_config['rows_per_page']) ? (int)$album_config['rows_per_page'] : 3)));
		$out = array('rows'=>array(), 'total'=>0, 'start'=>0, 'size'=>$size, 'columns'=>$columns, 'sort'=>$sort, 'order'=>$order, 'categories'=>array(), 'rights'=>array(), 'viewer'=>$userdata);
		if (!$ids) { return $out; }
		$reader = new PhpbbForumReadSnapshot($database, array(ALBUM_TABLE, ALBUM_CAT_TABLE, ALBUM_RATE_TABLE, ALBUM_COMMENT_TABLE, ALBUM_CONFIG_TABLE, USERS_TABLE, USER_GROUP_TABLE, SESSIONS_TABLE, CONFIG_TABLE, BANLIST_TABLE));
		album_picture_read_context($reader);
		$columns = max(1, min(20, isset($album_config['cols_per_page']) ? (int)$album_config['cols_per_page'] : 4));
		$out['columns'] = $columns; $out['size'] = min(100, $columns * max(1, min(50, isset($album_config['rows_per_page']) ? (int)$album_config['rows_per_page'] : 3))); $out['viewer'] = $userdata;
		if ($size_override !== null) { $out['size'] = $size_override; }
		$owner_sql = $owner === null ? '' : ' AND cat_user_id=' . $owner;
		$categories = $reader->rows('SELECT * FROM ' . ALBUM_CAT_TABLE . ' WHERE cat_id IN (' . implode(',', $ids) . ')' . $owner_sql);
		$scope = album_picture_visibility($categories);
		$out['categories'] = $scope['categories'];
		$out['rights'] = $scope['rights'];
		if (!$scope['categories']) { return $out; }
		$where = $scope['where'];
		if ($member)
		{
			if ($kind === 'pic') { $where .= ' AND p.pic_user_id=' . $member; }
			elseif ($kind === 'rating') { $where .= ' AND EXISTS (SELECT 1 FROM ' . ALBUM_RATE_TABLE . ' member_rate WHERE member_rate.rate_pic_id=p.pic_id AND member_rate.rate_user_id=' . $member . ' AND member_rate.rate_point>0)'; }
			else { $where .= ' AND EXISTS (SELECT 1 FROM ' . ALBUM_COMMENT_TABLE . ' member_comment WHERE member_comment.comment_pic_id=p.pic_id AND member_comment.comment_user_id=' . $member . ')'; }
		}
		$from = ' FROM ' . ALBUM_TABLE . ' p JOIN ' . ALBUM_CAT_TABLE . ' ct ON ct.cat_id=p.pic_cat_id LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.pic_user_id WHERE ' . $where;
		$count = $reader->rows('SELECT COUNT(p.pic_id) total' . $from);
		if (count($count) !== 1 || !isset($count[0]['total']) || preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', (string)$count[0]['total']) !== 1 || (float)$count[0]['total'] > 2147483647) { throw new PhpbbForumReadFailure('Invalid Album picture count'); }
		$out['total'] = (int)$count[0]['total']; $last = $out['total'] ? (int)floor(($out['total'] - 1) / $out['size']) * $out['size'] : 0;
		$out['start'] = (int)floor(min($start, $last) / $out['size']) * $out['size'];
		if ($out['total'] && !$count_only)
		{
			// Indexed scalar aggregates avoid multiplying ratings by comments and
			// remain valid with ONLY_FULL_GROUP_BY on both supported database engines.
			$rate_where = 'rr.rate_pic_id=p.pic_id' . ($kind === 'rating' ? ' AND rr.rate_user_id=' . $member . ' AND rr.rate_point>0' : '');
			$comment_where = 'cc.comment_pic_id=p.pic_id' . ($kind === 'comment' ? ' AND cc.comment_user_id=' . $member : '');
			$out['rows'] = $reader->rows('SELECT p.*,u.user_id,u.username,ct.cat_id,ct.cat_user_id,ct.cat_title,p.pic_id AS rate_pic_id,'
				. '(SELECT AVG(NULLIF(rr.rate_point,0)) FROM ' . ALBUM_RATE_TABLE . ' rr WHERE ' . $rate_where . ') AS rating,'
				. '(SELECT COUNT(cc.comment_id) FROM ' . ALBUM_COMMENT_TABLE . ' cc WHERE ' . $comment_where . ') AS comments,'
				. '(SELECT MAX(cc.comment_id) FROM ' . ALBUM_COMMENT_TABLE . ' cc WHERE ' . $comment_where . ') AS new_comment'
				. $from . ' ORDER BY ' . $sorts[$sort] . ' ' . $order . ($sort === 'highest' ? ',RAND()' : '') . ',p.pic_id ' . $order . ' LIMIT ' . $out['start'] . ',' . $out['size']);
			if (count($out['rows']) !== min($out['size'], $out['total'] - $out['start'])) { throw new PhpbbForumReadFailure('Inconsistent Album page'); }
			foreach ($out['rows'] as $row)
			{
				$id = (string)$row['pic_id'];
				if (preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $id) !== 1 || strlen($id) === 10 && strcmp($id, '4294967295') > 0) { throw new PhpbbForumReadFailure('Invalid Album picture identity'); }
			}
		}
		return $out;
	}
	finally
	{
		$db = $original[0]; $userdata = $original[1]; $album_config = $original[2]; $album_data = $original[3];
		if ($reader !== null) { $reader->release(); }
	}
}
