<?php
// Give storage-only legacy fixtures a real current administrator and the real
// authorization schema/functions. Do not stub permission decisions as true.
function fixture_current_moderator($pdo)
{
	global $forum_root,$userdata,$lang;
	foreach(array('AUTH_ALL'=>0,'AUTH_LIST_ALL'=>0,'AUTH_REG'=>1,'AUTH_ACL'=>2,'AUTH_MOD'=>3,'AUTH_ADMIN'=>5,'ADMIN'=>1,'AUTH_ACCESS_TABLE'=>'fixture_auth','USER_GROUP_TABLE'=>'fixture_groups','POST_FORUM_URL'=>'f') as $key=>$value) { if(!defined($key)) { define($key,$value); } }
	require_once $forum_root.'includes/auth.php';
	require_once $forum_root.'attach_mod/includes/functions_includes.php';
	foreach(array('Auth_Anonymous_Users','Auth_Registered_Users','Auth_Users_granted_access','Auth_Moderators','Auth_Administrators','Not_Moderator','Session_invalid') as $key) { $lang[$key]=$key; }
	$pdo->exec('ALTER TABLE fixture_users ADD user_level INTEGER DEFAULT 0');
	$pdo->exec('ALTER TABLE fixture_users ADD user_active INTEGER DEFAULT 1');
	$pdo->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=8');
	$columns=array('forum_id INTEGER','group_id INTEGER','auth_mod INTEGER DEFAULT 0');
	foreach(array('auth_view','auth_read','auth_news','auth_post','auth_reply','auth_edit','auth_delete','auth_cal','auth_sticky','auth_announce','auth_global_announce','auth_vote','auth_pollcreate','auth_ban','auth_greencard','auth_bluecard','auth_attachments','auth_download') as $field)
	{ $pdo->exec('ALTER TABLE fixture_forums ADD '.$field.' INTEGER DEFAULT 1'); $columns[]=$field.' INTEGER DEFAULT 0'; }
	$pdo->exec('CREATE TABLE fixture_auth ('.implode(',',$columns).')');
	$pdo->exec('CREATE TABLE fixture_groups (user_id INTEGER,group_id INTEGER,user_pending INTEGER)');
	$userdata=array('user_id'=>8,'user_level'=>1,'session_logged_in'=>true,'session_id'=>'fixture');
	$_SERVER['REQUEST_METHOD']='POST'; $_POST=array('sid'=>'fixture');
}
