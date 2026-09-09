<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_moderator_identity.php';
require_once dirname(__DIR__) . '/attach_mod/includes/functions_mutation.php';

class PhpbbAclException extends RuntimeException {}
function phpbb_acl_error($key)
{
	global $lang;
	throw new PhpbbAclException(isset($lang[$key]) ? $lang[$key] : $key);
}
class PhpbbAclDatabase
{
	var $connection;
	var $failure_key;
	function __construct($connection,$failure_key='Acl_storage_failed') { $this->connection = $connection; $this->failure_key=$failure_key; }
	function __call($method, $args) { return call_user_func_array(array($this->connection,$method),$args); }
	function sql_query($sql,$transaction=false)
	{
		$result = $this->connection->sql_query($sql,$transaction);
		if (!$result) { phpbb_acl_error($this->failure_key); }
		return $result;
	}
}
function phpbb_acl_rows($db,$sql)
{
	$result=$db->sql_query($sql); $rows=$db->sql_fetchrowset($result); $db->sql_freeresult($result); return $rows;
}
function phpbb_acl_id($value)
{
	if (!(is_int($value)||is_string($value)) || !preg_match('/^[0-9]+$/D',(string)$value)) { phpbb_acl_error('Acl_selection_changed'); }
	$value=ltrim((string)$value,'0');
	if ($value==='' || strlen($value)>8 || (int)$value>16777215) { phpbb_acl_error('Acl_selection_changed'); }
	return (int)$value;
}
function phpbb_acl_boolean_map($value)
{
	if (!is_array($value) || count($value)>10000) { phpbb_acl_error('Acl_selection_changed'); }
	$result=array();
	foreach ($value as $id=>$enabled)
	{
		$id=phpbb_acl_id($id);
		if (!(is_string($enabled)||is_int($enabled)) || !in_array((string)$enabled,array('0','1'),true)) { phpbb_acl_error('Acl_selection_changed'); }
		$result[$id]=(int)$enabled;
	}
	return $result;
}
function phpbb_acl_fields()
{
	// Regression-checked against def_auth.php, including Attachment MOD fields.
	return array('auth_view','auth_read','auth_post','auth_news','auth_reply','auth_edit','auth_delete','auth_cal','auth_sticky','auth_announce','auth_global_announce','auth_vote','auth_pollcreate','auth_ban','auth_greencard','auth_bluecard','auth_attachments','auth_download');
}
function phpbb_acl_actor($db,$mode)
{
	global $userdata,$phpEx;
	if (!is_string($mode) || !in_array($mode,array('user','group','forum','maintenance'),true)) { phpbb_acl_error('Acl_selection_changed'); }
	$route=$mode==='maintenance'?'admin_db_maintenance.'.$phpEx:($mode==='forum'?'admin_forumauth.'.$phpEx:'admin_ug_auth.'.$phpEx.'?mode='.$mode);
	$user=phpbb_current_moderator_user($db);
	if (!$user || empty($userdata['session_admin'])) { phpbb_acl_error('Not_Authorised'); }
	$user['root']=(int)$user['user_level']===ADMIN;
	$grant='acl_actor.user_level = '.ADMIN;
	if (!$user['root'])
	{
		require_once dirname(__FILE__).'/functions_jr_admin.php';
		$rows=phpbb_acl_rows($db,'SELECT user_jr_admin FROM '.JR_ADMIN_TABLE.' WHERE user_id = '.(int)$user['user_id']);
		$routes=jr_admin_authorization_routes(); $allowed=false;
		if ($rows && $routes!==false)
		{
			foreach (explode(EXPLODE_SEPERATOR_CHAR,$rows[0]['user_jr_admin']) as $hash)
			{
				// Both ACP modes share a file but are distinct delegated modules.
				if (isset($routes[$hash]) && $routes[$hash]===$route) { $allowed=true; break; }
			}
		}
		if (!$allowed) { phpbb_acl_error('Not_Authorised'); }
		$grant.=' OR EXISTS (SELECT 1 FROM '.JR_ADMIN_TABLE.' j WHERE j.user_id = '.(int)$user['user_id']." AND HEX(j.user_jr_admin) = HEX('".$db->sql_escape($rows[0]['user_jr_admin'])."'))";
	}
	$user['guard']='EXISTS (SELECT 1 FROM (SELECT DISTINCT user_id,user_active,user_level FROM '.USERS_TABLE.' WHERE user_id = '.(int)$user['user_id'].') acl_actor WHERE acl_actor.user_active <> 0 AND ('.$grant.'))';
	return $user;
}
function phpbb_acl_target($db,$mode,$id)
{
	if ($mode==='group')
	{
		$rows=phpbb_acl_rows($db,'SELECT group_id FROM '.GROUPS_TABLE.' WHERE group_id = '.$id.' AND group_single_user = 0');
		if (!$rows) { phpbb_acl_error('Group_not_exist'); }
		return array('group_id'=>$id,'guard'=>'EXISTS (SELECT 1 FROM '.GROUPS_TABLE.' acl_group WHERE acl_group.group_id = '.$id.' AND acl_group.group_single_user = 0)');
	}
	$rows=phpbb_acl_rows($db,'SELECT DISTINCT g.group_id,u.user_level FROM '.GROUPS_TABLE.' g,'.USER_GROUP_TABLE.' ug,'.USERS_TABLE.' u WHERE u.user_id = '.$id.' AND ug.user_id = u.user_id AND ug.group_id = g.group_id AND ug.user_pending = 0 AND g.group_single_user = 1');
	if (count($rows)!==1) { phpbb_acl_error('Acl_selection_changed'); }
	$group_id=(int)$rows[0]['group_id'];
	$others=phpbb_acl_rows($db,'SELECT user_id FROM '.USER_GROUP_TABLE.' WHERE group_id = '.$group_id.' AND user_id <> '.$id);
	if ($others) { phpbb_acl_error('Acl_selection_changed'); }
	return array('group_id'=>$group_id,'user_level'=>(int)$rows[0]['user_level'],
		'guard'=>'EXISTS (SELECT 1 FROM '.GROUPS_TABLE.' acl_group,'.USER_GROUP_TABLE.' acl_member WHERE acl_group.group_id = '.$group_id.' AND acl_group.group_single_user = 1 AND acl_member.group_id = acl_group.group_id AND acl_member.user_id = '.$id.' AND acl_member.user_pending = 0)'
		.' AND NOT EXISTS (SELECT 1 FROM '.USER_GROUP_TABLE.' acl_other WHERE acl_other.group_id = '.$group_id.' AND acl_other.user_id <> '.$id.')'
		.' AND EXISTS (SELECT 1 FROM (SELECT DISTINCT user_id FROM '.USERS_TABLE.' WHERE user_id = '.$id.') acl_target)');
}
function phpbb_acl_mod_guard($id)
{
	return 'EXISTS (SELECT 1 FROM '.USER_GROUP_TABLE.' ug,'.GROUPS_TABLE.' g,'.AUTH_ACCESS_TABLE.' a,'.FORUMS_TABLE.' f WHERE ug.user_id = '.$id.' AND ug.user_pending = 0 AND g.group_id = ug.group_id AND a.group_id = g.group_id AND a.auth_mod = 1 AND f.forum_id = a.forum_id)';
}
function phpbb_acl_expire_sessions($db,$actor,$target)
{
	// Current administrators' sessions are unaffected by group ACLs; a direct
	// administrator role transition invalidates its target explicitly below.
	$db->sql_query('DELETE FROM '.SESSIONS_TABLE.' WHERE session_user_id <> '.(int)$actor['user_id'].' AND session_user_id IN (SELECT ug.user_id FROM '.USER_GROUP_TABLE.' ug,'.USERS_TABLE.' u WHERE ug.group_id = '.$target['group_id'].' AND u.user_id = ug.user_id AND u.user_id > 0 AND u.user_level <> '.ADMIN.') AND '.$actor['guard'].' AND '.$target['guard']);
}

function phpbb_acl_save($database,$mode,$id,$post)
{
	global $userdata;
	if (!is_string($mode) || !in_array($mode,array('user','group'),true) || !is_array($post)) { phpbb_acl_error('Acl_selection_changed'); }
	$id=phpbb_acl_id($id); $fields=phpbb_acl_fields();
	$advanced=isset($post['adv']) ? $post['adv'] : '0';
	if (!(is_int($advanced)||is_string($advanced)) || !in_array((string)$advanced,array('0','1'),true)) { phpbb_acl_error('Acl_selection_changed'); }
	$level=isset($post['userlevel']) ? $post['userlevel'] : null;
	if ($level!==null && (!is_string($level) || !in_array($level,array('user','admin'),true))) { phpbb_acl_error('Acl_selection_changed'); }
	$mods=phpbb_acl_boolean_map(isset($post['moderator'])?$post['moderator']:array());
	$maps=array(); $forums=$mods;
	foreach ((int)$advanced ? $fields : array('simple') as $field)
	{
		$key=$field==='simple'?'private':'private_'.$field;
		$maps[$field]=phpbb_acl_boolean_map(isset($post[$key])?$post[$key]:array());
		foreach ($maps[$field] as $forum=>$value) { $forums[$forum]=0; }
	}
	if (count($forums)>10000) { phpbb_acl_error('Acl_selection_changed'); }
	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD']!=='POST' || empty($userdata['session_id']) || !isset($post['sid']) || !is_string($post['sid']) || !hash_equals((string)$userdata['session_id'],$post['sid'])) { phpbb_acl_error('Session_invalid'); }
	$lock=new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	try
	{
		$db=new PhpbbAclDatabase($lock->connection); $actor=phpbb_acl_actor($db,$mode); $target=phpbb_acl_target($db,$mode,$id);
		$guard=$actor['guard'].' AND '.$target['guard']; $group_id=$target['group_id'];
		$transition=$mode==='user' && $level!==null && (($level==='admin')!==($target['user_level']===ADMIN));
		if ($transition)
		{
			if (!$actor['root']) { phpbb_acl_error('Acl_root_required'); }
			if ($id===(int)$actor['user_id']) { phpbb_acl_error('Acl_self_role_change'); }
			if ($level==='user')
			{
				$first=phpbb_acl_rows($db,'SELECT MIN(user_id) AS first_id FROM '.USERS_TABLE.' WHERE user_level = '.ADMIN);
				if ((int)$first[0]['first_id']===$id) { phpbb_acl_error('ctracker_gmb_1stadmin'); }
				$guard.=' AND '.$id.' <> (SELECT MIN(first_admin.user_id) FROM (SELECT DISTINCT user_id FROM '.USERS_TABLE.' WHERE user_level = '.ADMIN.') first_admin)';
			}
			$guard.=' AND EXISTS (SELECT 1 FROM (SELECT DISTINCT user_id,user_level FROM '.USERS_TABLE.' WHERE user_id = '.$id.') changing_user WHERE changing_user.user_level = '.$target['user_level'].')';
			$db->sql_query('DELETE FROM '.SESSIONS_TABLE.' WHERE session_user_id = '.$id.' AND '.$guard);
			// Reset redundant per-user grants, retaining explicitly assigned
			// moderation. Never clear another personal/shared group's records.
			$db->sql_query('DELETE FROM '.AUTH_ACCESS_TABLE.' WHERE group_id = '.$group_id.' AND auth_mod = 0 AND '.$guard);
			$zero=array(); foreach ($fields as $field) { $zero[]=$field.' = 0'; }
			$db->sql_query('UPDATE '.AUTH_ACCESS_TABLE.' SET '.implode(',',$zero).' WHERE group_id = '.$group_id.' AND '.$guard);
			$desired=$level==='admin' ? (string)ADMIN : 'CASE WHEN '.phpbb_acl_mod_guard($id).' THEN '.MOD.' ELSE '.USER.' END';
			$db->sql_query('UPDATE '.USERS_TABLE.' SET user_level = '.$desired.' WHERE user_id = '.$id.' AND '.$guard);
			if ((int)$db->sql_affectedrows()!==1) { phpbb_acl_error('Acl_selection_changed'); }
			return true;
		}
		if (!$forums) { return false; }
		phpbb_acl_expire_sessions($db,$actor,$target);
		foreach ($forums as $forum_id=>$unused)
		{
			$actor=phpbb_acl_actor($db,$mode); $current=phpbb_acl_target($db,$mode,$id);
			if ($current['group_id']!==$group_id) { phpbb_acl_error('Acl_selection_changed'); }
			$forum=phpbb_acl_rows($db,'SELECT '.implode(',',$fields).' FROM '.FORUMS_TABLE.' WHERE forum_id = '.$forum_id);
			if (!$forum) { phpbb_acl_error('Acl_selection_changed'); }
			$rows=phpbb_acl_rows($db,'SELECT '.implode(',',$fields).',auth_mod FROM '.AUTH_ACCESS_TABLE.' WHERE group_id = '.$group_id.' AND forum_id = '.$forum_id);
			$values=array_fill_keys(array_merge($fields,array('auth_mod')),0);
			foreach ($rows as $row) { foreach ($values as $key=>$value) { if (!empty($row[$key])) { $values[$key]=1; } } }
			if (isset($mods[$forum_id])) { $values['auth_mod']=$mods[$forum_id]; }
			foreach ($fields as $field)
			{
				$map=(int)$advanced?$maps[$field]:$maps['simple'];
				if ((int)$forum[0][$field]===AUTH_ACL && isset($map[$forum_id])) { $values[$field]=$values['auth_mod']?0:$map[$forum_id]; }
			}
			$settings=array(); foreach ($fields as $field) { $settings[]='acl_forum.'.$field.' = '.(int)$forum[0][$field]; }
			$guard=$actor['guard'].' AND '.$current['guard'].' AND EXISTS (SELECT 1 FROM '.FORUMS_TABLE.' acl_forum WHERE acl_forum.forum_id = '.$forum_id.' AND '.implode(' AND ',$settings).')';
			$where='group_id = '.$group_id.' AND forum_id = '.$forum_id;
			if (!array_sum($values)) { $db->sql_query('DELETE FROM '.AUTH_ACCESS_TABLE.' WHERE '.$where.' AND '.$guard); }
			elseif (!$rows)
			{
				$db->sql_query('INSERT INTO '.AUTH_ACCESS_TABLE.' (group_id,forum_id,'.implode(',',array_keys($values)).') SELECT '.$group_id.','.$forum_id.','.implode(',',$values).' WHERE '.$guard.' AND NOT EXISTS (SELECT 1 FROM '.AUTH_ACCESS_TABLE.' acl_previous WHERE '.$where.')');
			}
			else
			{
				$assign=array(); foreach ($values as $field=>$value) { $assign[]=$field.' = '.$value; }
				$db->sql_query('UPDATE '.AUTH_ACCESS_TABLE.' SET '.implode(',',$assign).' WHERE '.$where.' AND '.$guard);
			}
			// Check the resulting values, not affected-row conventions (a no-op
			// UPDATE can validly report zero). Guard failures cannot claim success.
			$actual=phpbb_acl_rows($db,'SELECT '.implode(',',array_keys($values)).' FROM '.AUTH_ACCESS_TABLE.' WHERE '.$where);
			if (array_sum($values) && !$actual) { phpbb_acl_error('Acl_selection_changed'); }
			foreach ($actual as $row) { foreach ($values as $field=>$value) { if ((int)$row[$field]!==$value) { phpbb_acl_error('Acl_selection_changed'); } } }
		}
		$actor=phpbb_acl_actor($db,$mode); $target=phpbb_acl_target($db,$mode,$id);
		if ($target['group_id']!==$group_id) { phpbb_acl_error('Acl_selection_changed'); }
		$members=phpbb_acl_rows($db,'SELECT DISTINCT user_id FROM '.USER_GROUP_TABLE.' WHERE group_id = '.$group_id.' AND user_id > 0');
		foreach ($members as $member)
		{
			$member_id=(int)$member['user_id'];
			$db->sql_query('UPDATE '.USERS_TABLE.' SET user_level = CASE WHEN '.phpbb_acl_mod_guard($member_id).' THEN '.MOD.' ELSE '.USER.' END WHERE user_id = '.$member_id.' AND user_level IN ('.USER.','.MOD.') AND '.$actor['guard'].' AND '.$target['guard'].' AND EXISTS (SELECT 1 FROM '.USER_GROUP_TABLE.' acl_member WHERE acl_member.group_id = '.$group_id.' AND acl_member.user_id = '.$member_id.')');
		}
		phpbb_acl_actor($db,$mode);
		return true;
	}
	finally { $lock->release(); }
}
