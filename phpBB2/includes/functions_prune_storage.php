<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_moderation.php';

class PhpbbPruneException extends RuntimeException {}
function phpbb_prune_error($key)
{
	global $lang;
	throw new PhpbbPruneException(isset($lang[$key]) ? $lang[$key] : $key);
}
class PhpbbPruneDatabase
{
	var $connection;
	function __construct($connection) { $this->connection=$connection; }
	function __call($method,$args) { return call_user_func_array(array($this->connection,$method),$args); }
	function sql_query($sql,$transaction=false)
	{
		$result=$this->connection->sql_query($sql,$transaction);
		if(!$result) { phpbb_prune_error('Prune_storage_failed'); }
		return $result;
	}
}
function phpbb_prune_rows($db,$sql)
{
	$result=$db->sql_query($sql); $rows=$db->sql_fetchrowset($result); $db->sql_freeresult($result); return $rows;
}
function phpbb_prune_id($value)
{
	if(!(is_int($value) || is_string($value)) || !preg_match('/^[0-9]+$/D',(string)$value)) { phpbb_prune_error('Prune_selection_changed'); }
	$value=ltrim((string)$value,'0');
	if($value==='' || strlen($value)>8 || (int)$value>16777215) { phpbb_prune_error('Prune_selection_changed'); }
	return (int)$value;
}
function phpbb_prune_actor($db)
{
	global $userdata;
	if(empty($userdata['session_logged_in']) || !isset($userdata['user_id'])) { phpbb_prune_error('Not_Authorised'); }
	$id=phpbb_prune_id($userdata['user_id']);
	$rows=phpbb_prune_rows($db,'SELECT user_id, user_level, user_active FROM '.USERS_TABLE.' WHERE user_id = '.$id);
	if(!$rows || empty($rows[0]['user_active'])) { phpbb_prune_error('Not_Authorised'); }
	$rows[0]['session_logged_in']=true; return $rows[0];
}
function phpbb_prune_admin_allowed($db,$user,$module='admin_forum_prune')
{
	global $userdata, $phpEx;
	if(!in_array($module,array('admin_forum_prune','admin_forums'),true)) { return false; }
	if(empty($userdata['session_admin'])) { return false; }
	if((int)$user['user_level']===ADMIN) { return true; }
	require_once dirname(__FILE__) . '/functions_jr_admin.php';
	$rows=phpbb_prune_rows($db,'SELECT user_jr_admin FROM '.JR_ADMIN_TABLE.' WHERE user_id = '.(int)$user['user_id']);
	if(!$rows) { return false; }
	$routes=jr_admin_authorization_routes();
	if($routes===false) { return false; }
	foreach(explode(EXPLODE_SEPERATOR_CHAR,$rows[0]['user_jr_admin']) as $hash)
	{
		if(isset($routes[$hash]) && jr_admin_route_matches_file($routes[$hash],$module.'.'.$phpEx)) { return true; }
	}
	return false;
}
function phpbb_prune_topic_guard($forum_id,$cutoff)
{
	$types=array(POST_ANNOUNCE);
	if(defined('POST_GLOBAL_ANNOUNCE')) { $types[]=POST_GLOBAL_ANNOUNCE; }
	// Cached last-post/vote flags cannot authorize removal of recent posts or
	// an actual poll. A malformed cross-forum topic is preserved for repair.
	return ' AND topic_moved_id = 0 AND topic_status IN ('.TOPIC_UNLOCKED.','.TOPIC_LOCKED.') AND topic_vote = 0 AND topic_type NOT IN ('.implode(',',$types).')'
		.' AND EXISTS (SELECT 1 FROM '.POSTS_TABLE.' p WHERE p.topic_id = '.TOPICS_TABLE.'.topic_id AND p.forum_id = '.$forum_id.')'
		.' AND NOT EXISTS (SELECT 1 FROM '.POSTS_TABLE.' p WHERE p.topic_id = '.TOPICS_TABLE.'.topic_id AND (p.forum_id <> '.$forum_id.' OR p.post_time >= '.$cutoff.'))'
		.' AND NOT EXISTS (SELECT 1 FROM '.VOTE_DESC_TABLE.' v WHERE v.topic_id = '.TOPICS_TABLE.'.topic_id)';
}

// A null cutoff means a configured automatic run, not "delete everything".
// Manual age pruning requires an authenticated ACP POST and fresh module grant.
// Whole-forum deletion uses a separate policy and must never enter this API.
function phpbb_prune_forum($database,$forum_id,$cutoff=null,&$schedule_updated=null)
{
	global $userdata;
	$schedule_updated=false;
	$forum_id=phpbb_prune_id($forum_id); $automatic=$cutoff===null;
	if(!$automatic)
	{
		if(!(is_int($cutoff) || is_string($cutoff)) || !preg_match('/^-?[0-9]+$/D',(string)$cutoff)
			|| (float)$cutoff < -2147483648 || (float)$cutoff > time()) { phpbb_prune_error('Prune_selection_changed'); }
		$cutoff=(int)$cutoff;
		if(!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD']!=='POST' || empty($userdata['session_id'])
			|| !isset($_POST['sid']) || !is_string($_POST['sid']) || !hash_equals((string)$userdata['session_id'],$_POST['sid'])) { phpbb_prune_error('Session_invalid'); }
	}
	$empty=array('topics'=>0,'posts'=>0);
	$lock=new attach_mutation_lock($database,!$automatic);
	if(!$lock->acquired) { if($automatic) { return $empty; } phpbb_prune_error('Attachment_storage_busy'); }
	try
	{
		$db=new PhpbbPruneDatabase($lock->connection); $user=phpbb_prune_actor($db);
		$forums=phpbb_prune_rows($db,'SELECT forum_id, forum_link, prune_enable, prune_next FROM '.FORUMS_TABLE.' WHERE forum_id = '.$forum_id);
		if(!$forums || !empty($forums[0]['forum_link'])) { phpbb_prune_error('Prune_selection_changed'); }
		$schedule_guard=''; $now=time();
		if($automatic)
		{
			$rights=auth(AUTH_ALL,$forum_id,$user,'',$db);
			if(empty($rights['auth_view']) || empty($rights['auth_read']) || empty($rights['auth_mod'])) { phpbb_prune_error('Not_Authorised'); }
			$enabled=phpbb_prune_rows($db,"SELECT config_value FROM ".CONFIG_TABLE." WHERE config_name = 'prune_enable'");
			if(!$enabled || (string)$enabled[0]['config_value']!=='1' || empty($forums[0]['prune_enable']) || (int)$forums[0]['prune_next'] >= $now) { return $empty; }
			$settings=phpbb_prune_rows($db,'SELECT prune_id, prune_days, prune_freq FROM '.PRUNE_TABLE.' WHERE forum_id = '.$forum_id);
			if(count($settings)!==1) { phpbb_prune_error('Prune_selection_changed'); }
			$settings=$settings[0]; $days=(int)$settings['prune_days']; $frequency=(int)$settings['prune_freq'];
			if($days<0 || $days>65535 || $frequency<0 || $frequency>65535) { phpbb_prune_error('Prune_selection_changed'); }
			// Preserve legacy zero-as-disabled settings and the schema's unsigned
			// SMALLINT range. Saturate timestamps at the existing signed INT limits.
			if(!$days || !$frequency) { return $empty; }
			$cutoff=(int)max(-2147483648,$now-$days*86400); $next=(int)min(2147483647,$now+$frequency*86400);
			$settings_guard=' AND EXISTS (SELECT 1 FROM '.CONFIG_TABLE." c WHERE c.config_name = 'prune_enable' AND c.config_value = '1')"
				.' AND EXISTS (SELECT 1 FROM '.PRUNE_TABLE.' s WHERE s.forum_id = '.$forum_id.' AND s.prune_id = '.(int)$settings['prune_id'].' AND s.prune_days = '.$days.' AND s.prune_freq = '.$frequency.')'
				.' AND (SELECT COUNT(*) FROM '.PRUNE_TABLE.' s WHERE s.forum_id = '.$forum_id.') = 1';
			$schedule_guard=' AND EXISTS (SELECT 1 FROM '.FORUMS_TABLE.' f WHERE f.forum_id = '.$forum_id.' AND f.prune_enable = 1 AND f.prune_next = '.(int)$forums[0]['prune_next'].')'.$settings_guard;
		}
		elseif(!phpbb_prune_admin_allowed($db,$user)) { phpbb_prune_error('Not_Authorised'); }
		$guard=phpbb_prune_topic_guard($forum_id,$cutoff).$schedule_guard;
		$rows=phpbb_prune_rows($db,'SELECT topic_id FROM '.TOPICS_TABLE.' WHERE forum_id = '.$forum_id.$guard.' ORDER BY topic_id');
		$ids=array(); foreach($rows as $row) { $ids[]=phpbb_prune_id($row['topic_id']); }
		$removed=$ids ? phpbb_delete_moderated_topics_owned($db,$forum_id,$ids,$guard) : array('topic_ids'=>array(),'post_ids'=>array());
		if($automatic)
		{
			// Repeated due-page requests serialize; a successful first run makes
			// the next one a no-op. Never advance scheduling on storage failure.
			$db->sql_query('UPDATE '.FORUMS_TABLE.' SET prune_next = '.$next.' WHERE forum_id = '.$forum_id.' AND prune_enable = 1 AND prune_next = '.(int)$forums[0]['prune_next'].$settings_guard);
			if((int)$db->sql_affectedrows()!==1) { phpbb_prune_error('Prune_selection_changed'); }
			$schedule_updated=true;
		}
		return array('topics'=>count($removed['topic_ids']),'posts'=>count($removed['post_ids']));
	}
	finally { $lock->release(); }
}
