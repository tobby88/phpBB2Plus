<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_forum_read_snapshot.php';

class PhpbbOnlineReader extends PhpbbForumReadSnapshot
{
	function __construct($database)
	{
		parent::__construct($database, array(CONFIG_TABLE, SESSIONS_TABLE, USERS_TABLE, BANLIST_TABLE,
			FORUMS_TABLE, TOPICS_TABLE, POSTS_TABLE, USER_GROUP_TABLE, AUTH_ACCESS_TABLE));
	}
}

// Internal SQL predicate comes only from validated topic/forum IDs and ACLs.
function phpbb_online_session_rows($reader, $now, $where = '')
{
	if (!is_int($now) || $now < 0 || $now > 2147483647 || !is_string($where)) { throw new PhpbbForumReadFailure('Invalid online projection'); }
	$cutoff = max(0, $now - 300);
	$rows = $reader->rows('SELECT u.user_id,u.username,u.user_allow_viewonline,u.user_level,s.session_id,s.session_logged_in,s.session_time,s.session_page,s.session_topic,s.session_ip'
		. ' FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE . ' s ON s.session_user_id=u.user_id'
		. ' WHERE s.session_time>=' . $cutoff . ' AND s.session_time<=' . $now
		. ' AND (s.session_logged_in=0 OR (s.session_logged_in=1 AND u.user_id>0 AND u.user_active=1 AND u.user_blocktime<' . $now
		. ' AND NOT EXISTS(SELECT 1 FROM ' . BANLIST_TABLE . ' b WHERE b.ban_userid=u.user_id)))'
		. ($where === '' ? '' : ' AND (' . $where . ')')
		. ' ORDER BY u.username ASC,u.user_id ASC,s.session_time DESC,s.session_id DESC');
	// One current location per account. Guest identities remain unique by
	// IP even when logged-out rows retain different historical user IDs.
	$registered = $guests = array();
	foreach ($rows as $row)
	{
		if ((string)$row['session_logged_in'] === '1')
		{
			$id = (int)$row['user_id'];
			if (!isset($registered[$id])) { $registered[$id] = $row; }
		}
		else
		{
			$ip = strtolower((string)$row['session_ip']);
			if (!isset($guests[$ip]) || (int)$row['session_time'] > (int)$guests[$ip]['session_time']
				|| (int)$row['session_time'] === (int)$guests[$ip]['session_time'] && strcmp($row['session_id'], $guests[$ip]['session_id']) > 0) { $guests[$ip] = $row; }
		}
	}
	ksort($guests, SORT_STRING);
	$rows = array_merge(array_values($registered), array_values($guests));
	foreach ($rows as $key => $row)
	{
		unset($rows[$key]['session_id'], $rows[$key]['session_ip']);
		if (!(int)$row['session_logged_in'])
		{
			$rows[$key]['user_id'] = ANONYMOUS; $rows[$key]['username'] = '';
			$rows[$key]['user_level'] = USER; $rows[$key]['user_allow_viewonline'] = 1;
		}
	}
	return $rows;
}

function phpbb_online_topic_from_sql()
{
	return ' FROM ' . TOPICS_TABLE . ' t'
		. ' JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id JOIN ' . USERS_TABLE . ' u ON u.user_id=t.topic_poster'
		. ' JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.topic_first_post_id AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id'
		. ' JOIN ' . POSTS_TABLE . ' p2 ON p2.post_id=t.topic_last_post_id AND p2.topic_id=t.topic_id AND p2.forum_id=t.forum_id'
		. ' JOIN ' . USERS_TABLE . ' u2 ON u2.user_id=p2.poster_id';
}
function phpbb_online_topic_live_sql()
{
	return 't.topic_id>0 AND t.topic_moved_id=0 AND t.topic_status IN (' . (int)TOPIC_UNLOCKED . ',' . (int)TOPIC_LOCKED . ')'
		. ' AND EXISTS(SELECT 1 FROM ' . POSTS_TABLE . ' live_post WHERE live_post.topic_id=t.topic_id AND live_post.forum_id=t.forum_id AND live_post.post_deleted=0)';
}

function phpbb_online_listing($database, $actor)
{
	$reader = null;
	try
	{
		$reader = new PhpbbOnlineReader($database);
		$viewer = $reader->authenticated_user($actor);
		if (!$viewer) { $viewer = array('user_id'=>ANONYMOUS, 'user_level'=>USER, 'session_logged_in'=>false); }
		$permissions = auth(AUTH_ALL, AUTH_LIST_ALL, $viewer, '', $reader); $view = $read = array();
		foreach ($permissions as $id => $access)
		{
			if ((int)$id > 0 && !empty($access['auth_view']))
			{
				$view[] = (int)$id;
				if (!empty($access['auth_read'])) { $read[] = (int)$id; }
			}
		}
		$now = time(); $cutoff = max(0, $now - 300); $forums = $topics = array();
		if ($view)
		{
			foreach ($reader->rows('SELECT forum_id,forum_name FROM ' . FORUMS_TABLE . ' WHERE forum_id IN (' . implode(',', $view) . ')') as $forum)
			{
				$forums[(int)$forum['forum_id']] = $forum['forum_name'];
			}
		}
		$rows = phpbb_online_session_rows($reader, $now);
		if ($read)
		{
			$sql = 'SELECT t.topic_id,t.topic_title,t.forum_id' . phpbb_online_topic_from_sql()
				. ' WHERE t.forum_id IN (' . implode(',', $read) . ') AND ' . phpbb_online_topic_live_sql()
				. ' AND EXISTS(SELECT 1 FROM ' . SESSIONS_TABLE . ' active_session WHERE active_session.session_time>=' . $cutoff . ' AND active_session.session_time<=' . $now
				. ' AND active_session.session_topic=t.topic_id AND (active_session.session_page>0 OR active_session.session_page=' . (int)PAGE_TOPIC_VIEW . '))';
			foreach ($reader->rows($sql) as $topic) { $topics[(int)$topic['topic_id']] = $topic; }
		}
		return array('rows'=>$rows, 'forums'=>$forums,
			'topics'=>$topics, 'admin'=>(string)$viewer['user_level'] === (string)ADMIN);
	}
	finally { if ($reader) { $reader->release(); } }
}

// The session's old forum is not authority for its topic. Use the current
// parent and permissions from the same snapshot as the online sessions.
function phpbb_online_topic_location($context, $page, $topic_id)
{
	global $lang, $phpEx;
	$fallback = array('label'=>$lang['Forum_index'], 'url'=>'index.' . $phpEx);
	$topic_id = (int)$topic_id; $page = (int)$page;
	if ($topic_id > 0)
	{
		if (!isset($context['topics'][$topic_id])) { return $fallback; }
		$topic = $context['topics'][$topic_id]; $forum = (int)$topic['forum_id'];
		if (!isset($context['forums'][$forum])) { return $fallback; }
		if ($page === (int)PAGE_TOPIC_VIEW)
		{
			return array('label'=>$lang['Topic_view_count'], 'url'=>'topic_view_users.' . $phpEx . '?' . POST_TOPIC_URL . '=' . $topic_id);
		}
		$label = $context['forums'][$forum]; if (isset($lang[$label])) { $label = $lang[$label]; }
		return array('label'=>phpbb_stored_text($label) . ' -> ' . phpbb_stored_text($topic['topic_title']),
			'url'=>'viewtopic.' . $phpEx . '?' . POST_TOPIC_URL . '=' . $topic_id);
	}
	if ($page > 0 && isset($context['forums'][$page]))
	{
		$label = $context['forums'][$page]; if (isset($lang[$label])) { $label = $lang[$label]; }
		return array('label'=>phpbb_stored_text($label), 'url'=>'viewforum.' . $phpEx . '?' . POST_FORUM_URL . '=' . $page);
	}
	return $fallback;
}
