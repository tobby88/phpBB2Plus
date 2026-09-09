<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_acl_storage.php';

function phpbb_forum_acl_values($post)
{
	global $lang;
	// Use the same preserved presets as the actual ACP, including Attachment
	// MOD's two added columns. Field names never come from request keys.
	$forum_auth_fields = array();
	include dirname(__FILE__) . '/def_auth.php';
	$fields = phpbb_acl_fields();
	if (array_keys($field_names) !== $fields) { phpbb_acl_error('Acl_selection_changed'); }
	$values = array();
	if (array_key_exists('simpleauth', $post))
	{
		$preset = $post['simpleauth'];
		if (!(is_int($preset) || is_string($preset)) || !preg_match('/^[0-6]$/D', (string) $preset) || !isset($simple_auth_ary[(int) $preset]) || count($simple_auth_ary[(int) $preset]) !== count($fields)) { phpbb_acl_error('Acl_selection_changed'); }
		$values = array_combine($fields, $simple_auth_ary[(int) $preset]);
	}
	else
	{
		foreach ($fields as $field)
		{
			// A missing advanced control is not an instruction to grant public
			// access. Partial forms preserve all unsubmitted permissions.
			if (array_key_exists($field, $post)) { $values[$field] = $post[$field]; }
		}
	}
	if (!$values) { phpbb_acl_error('Acl_selection_changed'); }
	foreach ($values as $field=>$value)
	{
		if (!(is_int($value) || is_string($value)) || !preg_match('/^[0-9]$/D', (string) $value) || !in_array((int) $value, $forum_auth_const, true)) { phpbb_acl_error('Acl_selection_changed'); }
		$values[$field] = $field === 'auth_vote' && (int) $value === AUTH_ALL ? AUTH_REG : (int) $value;
	}
	return $values;
}

function phpbb_forum_acl_save($database, $post, &$refresh_needed = false)
{
	global $userdata;
	$refresh_needed = false;
	if (!is_array($post) || !isset($post[POST_FORUM_URL]) || !is_string($post[POST_FORUM_URL]) || !preg_match('/^' . preg_quote(POST_FORUM_URL, '/') . '([0-9]+)$/D', $post[POST_FORUM_URL], $match)) { phpbb_acl_error('Acl_selection_changed'); }
	$id = phpbb_acl_id($match[1]);
	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || empty($userdata['session_id']) || !isset($post['sid']) || !is_string($post['sid']) || !hash_equals((string) $userdata['session_id'], $post['sid'])) { phpbb_acl_error('Session_invalid'); }
	$values = phpbb_forum_acl_values($post); $fields = phpbb_acl_fields();
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbAclDatabase($lock->connection); $actor = phpbb_acl_actor($db, 'forum');
		$select = 'SELECT ' . implode(',', $fields) . ' FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . $id;
		$rows = phpbb_acl_rows($db, $select);
		if (count($rows) !== 1) { phpbb_acl_error('Acl_selection_changed'); }
		$current = $rows[0]; $expected = array(); $guard = $actor['guard']; $assign = array();
		// The preserved mysqli driver also returns numeric aliases. Compare
		// only named policy fields, not stale duplicate values at numeric keys.
		foreach ($fields as $field) { $guard .= ' AND ' . $field . ' = ' . (int) $current[$field]; $expected[$field] = (int) $current[$field]; }
		foreach ($values as $field=>$value) { $assign[] = $field . ' = ' . $value; $expected[$field] = $value; }
		// One atomic statement on either MyISAM or InnoDB, serialized with
		// content and group-ACL writers. Stale policy snapshots cannot win.
		// Refresh even if the following read fails after a successful write.
		$refresh_needed = true;
		$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET ' . implode(',', $assign) . ' WHERE forum_id = ' . $id . ' AND ' . $guard);
		phpbb_acl_actor($db, 'forum');
		$rows = phpbb_acl_rows($db, $select);
		if (count($rows) !== 1) { phpbb_acl_error('Acl_selection_changed'); }
		foreach ($expected as $field=>$value) { if ((int) $rows[0][$field] !== (int) $value) { phpbb_acl_error('Acl_selection_changed'); } }
		// No account role or membership changes here. Permission policies are
		// read per request; the caller refreshes the hierarchy after release.
		return $id;
	}
	finally { $lock->release(); }
}
