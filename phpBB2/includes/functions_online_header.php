<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_online.php';
require_once dirname(__FILE__) . '/functions_online_record.php';

class PhpbbOnlineHeaderReader extends PhpbbForumReadSnapshot
{
	function __construct($database, $scoped)
	{
		$tables = array(CONFIG_TABLE, SESSIONS_TABLE, USERS_TABLE, BANLIST_TABLE);
		if ($scoped) { $tables = array_merge($tables, array(FORUMS_TABLE, TOPICS_TABLE, POSTS_TABLE, USER_GROUP_TABLE, AUTH_ACCESS_TABLE)); }
		parent::__construct($database, $tables);
	}
}

function phpbb_online_scope_id($value)
{
	if (!(is_int($value) || is_string($value)) || !preg_match('/^[0-9]+$/D', (string)$value)) { return false; }
	$value = ltrim((string)$value, '0');
	return $value === '' ? 0 : (strlen($value) <= 8 && (int)$value <= 16777215 ? (int)$value : false);
}
function phpbb_online_session_counts($rows)
{
	$visible = $hidden = $guests = 0;
	foreach ($rows as $row)
	{
		if (!(int)$row['session_logged_in']) { $guests++; }
		elseif (!empty($row['user_allow_viewonline'])) { $visible++; }
		else { $hidden++; }
	}
	return array('visible'=>$visible, 'hidden'=>$hidden, 'guests'=>$guests, 'total'=>$visible + $hidden + $guests);
}

function phpbb_online_header_context($database, $actor, $topic_id = 0, $forum_id = 0)
{
	$topic_id = phpbb_online_scope_id($topic_id); $forum_id = phpbb_online_scope_id($forum_id);
	if ($topic_id === false || $forum_id === false) { return false; }
	$scope = $topic_id ? 'topic' : ($forum_id ? 'forum' : 'global'); $reader = null;
	try
	{
		$reader = new PhpbbOnlineHeaderReader($database, $scope !== 'global');
		$viewer = $reader->authenticated_user($actor);
		if (!$viewer) { $viewer = array('user_id'=>ANONYMOUS, 'user_level'=>USER, 'session_logged_in'=>false); }
		$now = time(); $global = phpbb_online_session_rows($reader, $now); $rows = $global;
		if ($scope !== 'global')
		{
			$permissions = auth(AUTH_ALL, AUTH_LIST_ALL, $viewer, '', $reader); $read = array();
			foreach ($permissions as $id => $access) { if ((int)$id > 0 && !empty($access['auth_view']) && !empty($access['auth_read'])) { $read[] = (int)$id; } }
			$rows = array(); $where = false;
			if ($scope === 'topic' && $read)
			{
				$target = $reader->rows('SELECT t.topic_id' . phpbb_online_topic_from_sql() . ' WHERE t.topic_id=' . $topic_id
					. ' AND t.forum_id IN (' . implode(',', $read) . ') AND ' . phpbb_online_topic_live_sql());
				if (count($target) === 1) { $where = 's.session_topic=' . $topic_id . ' AND (s.session_page>0 OR s.session_page=' . (int)PAGE_TOPIC_VIEW . ')'; }
			}
			elseif ($scope === 'forum' && in_array($forum_id, $read, true))
			{
				$where = '((s.session_topic=0 AND s.session_page=' . $forum_id . ') OR (s.session_topic>0 AND (s.session_page>0 OR s.session_page=' . (int)PAGE_TOPIC_VIEW . ')'
					. ' AND EXISTS(SELECT 1' . phpbb_online_topic_from_sql() . ' WHERE t.topic_id=s.session_topic AND t.forum_id=' . $forum_id . ' AND ' . phpbb_online_topic_live_sql() . ')))';
			}
			if ($where !== false) { $rows = phpbb_online_session_rows($reader, $now, $where); }
		}
		return array('rows'=>$rows, 'counts'=>phpbb_online_session_counts($rows), 'global_counts'=>phpbb_online_session_counts($global),
			'admin'=>(string)$viewer['user_level'] === (string)ADMIN, 'scope'=>$scope, 'record'=>phpbb_online_record_values($reader), 'observed_at'=>$now);
	}
	catch (Exception $error) { return false; }
	catch (Error $error) { return false; }
	finally { if ($reader) { $reader->release(); } }
}
