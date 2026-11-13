<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_forum_read_snapshot.php';

class PhpbbBookmarkNavigationFailure extends PhpbbForumReadFailure {}
class PhpbbBookmarkNavigationReader extends PhpbbForumReadSnapshot
{
	protected function fail() { throw new PhpbbBookmarkNavigationFailure('Bookmark navigation unavailable'); }
	function __construct($database)
	{
		parent::__construct($database, array(CONFIG_TABLE, SESSIONS_TABLE, USERS_TABLE, BANLIST_TABLE, BOOKMARK_TABLE,
			FORUMS_TABLE, TOPICS_TABLE, POSTS_TABLE, USER_GROUP_TABLE, AUTH_ACCESS_TABLE));
	}
}

function phpbb_bookmark_navigation_limit($value)
{
	return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]{0,3}$/D', (string)$value)
		&& (int)$value <= 1000 ? (int)$value : 0;
}
function phpbb_bookmark_navigation_rows($database, $actor, $limit)
{
	$limit = phpbb_bookmark_navigation_limit($limit);
	if (!$limit || !PhpbbForumReadSnapshot::valid_actor($actor)) { return array(); }
	$reader = null;
	try
	{
		$reader = new PhpbbBookmarkNavigationReader($database);
		$user = $reader->authenticated_user($actor);
		if (!$user) { return array(); }
		$id = (int)$user['user_id'];
		$permissions = auth(AUTH_ALL, AUTH_LIST_ALL, $user, '', $reader); $forums = array();
		foreach ($permissions as $forum => $access) { if ((int)$forum > 0 && !empty($access['auth_view']) && !empty($access['auth_read'])) { $forums[] = (int)$forum; } }
		if (!$forums) { return array(); }
		return $reader->rows('SELECT t.topic_id,t.topic_title,p2.post_time FROM ' . TOPICS_TABLE . ' t'
			. ' JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id JOIN ' . USERS_TABLE . ' u ON u.user_id=t.topic_poster'
			. ' JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.topic_first_post_id AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id'
			. ' JOIN ' . POSTS_TABLE . ' p2 ON p2.post_id=t.topic_last_post_id AND p2.topic_id=t.topic_id AND p2.forum_id=t.forum_id'
			. ' JOIN ' . USERS_TABLE . ' u2 ON u2.user_id=p2.poster_id WHERE t.topic_id>0 AND t.forum_id IN (' . implode(',', $forums) . ')'
			. ' AND t.topic_moved_id=0 AND t.topic_status IN (' . (int)TOPIC_UNLOCKED . ',' . (int)TOPIC_LOCKED . ')'
			. ' AND EXISTS(SELECT 1 FROM ' . BOOKMARK_TABLE . ' b WHERE b.topic_id=t.topic_id AND b.user_id=' . $id . ')'
			. ' AND EXISTS(SELECT 1 FROM ' . POSTS_TABLE . ' live_post WHERE live_post.topic_id=t.topic_id AND live_post.forum_id=t.forum_id AND live_post.post_deleted=0)'
			. ' ORDER BY p2.post_time DESC,t.topic_id DESC LIMIT ' . ($limit + 1));
	}
	// Bookmark navigation is optional. A failed read must expose no titles and
	// must not prevent the actual forum page from rendering.
	catch (Exception $error) { return array(); }
	catch (Error $error) { return array(); }
	finally { if ($reader) { $reader->release(); } }
}
