<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions_author_visibility.php';

// Limit both the result list and its counts before sorting or pagination.
// A visible child does not grant access through an unreadable/missing parent.
function pafiledb_toplist_categories($module)
{
	$allowed = array();
	foreach ($module->cat_rowset as $id => $category)
	{
		if (!preg_match('/^[1-9][0-9]{0,9}$/D', (string)$id) || (float)$id > 2147483647) { continue; }
		$cursor = (int)$id; $leaf = true; $seen = array(); $visible = true;
		while ($cursor !== 0)
		{
			if (isset($seen[$cursor]) || !isset($module->cat_rowset[$cursor], $module->auth[$cursor])) { $visible = false; break; }
			$seen[$cursor] = true;
			$row = $module->cat_rowset[$cursor]; $auth = $module->auth[$cursor];
			foreach ($leaf ? array('auth_view', 'auth_read', 'auth_view_file') : array('auth_view', 'auth_read') as $permission)
			{
				if (empty($auth[$permission])) { $visible = false; break 2; }
			}
			if (!array_key_exists('cat_parent', $row)) { $visible = false; break; }
			$parent = $row['cat_parent'] === null ? '0' : (string)$row['cat_parent'];
			if (!preg_match('/^[0-9]+$/D', $parent) || (float)$parent > 2147483647) { $visible = false; break; }
			$cursor = (int)$parent; $leaf = false;
		}
		if ($visible) { $allowed[] = (int)$id; }
	}
	return $allowed;
}

function pafiledb_toplist_scope_sql($categories)
{
	if (!is_array($categories)) { throw new InvalidArgumentException('Invalid download category scope'); }
	$ids = array();
	foreach ($categories as $id)
	{
		if (!is_int($id) || $id < 1 || $id > 2147483647) { throw new InvalidArgumentException('Invalid download category identity'); }
		$ids[$id] = $id;
	}
	return $ids ? 'f.file_catid IN (' . implode(',', $ids) . ')' : '1=0';
}

function pafiledb_toplist_submitter($row)
{
	global $lang;
	$registered = !empty($row['current_submitter_id']) && (int)$row['current_submitter_id'] > 0 && isset($row['username']);
	$name = phpbb_author_name((int)$row['user_id'], $registered ? $row['username'] : $lang['Guest']);
	return $registered ? '<a href="' . append_sid('profile.php?mode=viewprofile&amp;' . POST_USERS_URL . '=' . (int)$row['current_submitter_id']) . '">' . $name . '</a>' : $name;
}

function pafiledb_toplist_files_sql($categories, $sort, $file_ids = array(), $limit = 0, $rated_only = false)
{
	if (!in_array($sort, array('file_time', 'file_dls', 'rating'), true) || !is_int($limit) || $limit < 0 || !is_array($file_ids))
	{ throw new InvalidArgumentException('Invalid download toplist options'); }
	$filter = pafiledb_toplist_scope_sql($categories);
	if ($file_ids)
	{
		foreach ($file_ids as $id)
		{
			if (!is_int($id) || $id < 1 || $id > 2147483647) { throw new InvalidArgumentException('Invalid download identity'); }
		}
		$filter .= ' AND f.file_id IN (' . implode(',', array_unique($file_ids)) . ')';
	}
	// Independent correlated aggregates retain exact counts and work with
	// ONLY_FULL_GROUP_BY; joining votes to comments multiplies both counts.
	return 'SELECT f.*, u.username, u.user_id AS current_submitter_id, c.cat_id, c.cat_name,'
		. ' (SELECT AVG(v.rate_point) FROM ' . PA_VOTES_TABLE . ' v WHERE v.votes_file=f.file_id) AS rating,'
		. ' (SELECT COUNT(*) FROM ' . PA_VOTES_TABLE . ' v WHERE v.votes_file=f.file_id) AS total_votes,'
		. ' (SELECT COUNT(*) FROM ' . PA_COMMENTS_TABLE . ' cm WHERE cm.file_id=f.file_id) AS total_comments'
		. ' FROM ' . PA_FILES_TABLE . ' f INNER JOIN ' . PA_CATEGORY_TABLE . ' c ON c.cat_id=f.file_catid'
		. ' LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=f.user_id'
		. ' WHERE f.file_approved=1 AND ' . $filter
		. ($rated_only ? ' AND EXISTS (SELECT 1 FROM ' . PA_VOTES_TABLE . ' v WHERE v.votes_file=f.file_id AND v.rate_point>0)' : '')
		. ' ORDER BY ' . $sort . ' DESC, f.file_id DESC'
		. ($limit ? ' LIMIT ' . $limit : '');
}
