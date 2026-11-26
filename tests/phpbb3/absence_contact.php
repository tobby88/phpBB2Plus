<?php
if (PHP_SAPI!=='cli' || getenv('PLUS_TEST_DATABASE')!=='1' || $argc!==3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path='./'; $phpEx='php';
require './common.php';
if ($dbhost!=='127.0.0.1' || $dbname!==$argv[1]) { exit(2); }
$rows=static function($sql) use ($db) { $r=$db->sql_query($sql); $all=$db->sql_fetchrowset($r); $db->sql_freeresult($r); return $all; };
$check=static function($ok,$why) { if (!$ok) { throw new RuntimeException($why); } };
$text=$phpbb_container->get('config_text'); $key='plus_away_pm_fixture';
$saved=json_decode((string)$text->get($key),true);
$notes=$table_prefix . 'plus_away_notes'; $gid=947210;
$id=(int)$rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='HistoricMember'")[0]['user_id'];
$who=$db->sql_in_set('user_id',array($id,2,87,89));
$args=explode(':',$argv[2]);
$right=static function($name,$value) use ($db,$rows,$id,$auth)
{
	$option=(int)$rows('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='" . $db->sql_escape($name) . "'")[0]['auth_option_id'];
	$db->sql_query('DELETE FROM ' . ACL_USERS_TABLE . " WHERE user_id=$id AND forum_id=0 AND auth_option_id=$option");
	if ($value!==ACL_NO)
	{
		$db->sql_query('INSERT INTO ' . ACL_USERS_TABLE . ' ' . $db->sql_build_array('INSERT',array('user_id'=>$id,'forum_id'=>0,
			'auth_option_id'=>$option,'auth_role_id'=>0,'auth_setting'=>$value)));
	}
	$auth->acl_clear_prefetch($id);
};
switch ($args[0])
{
	case 'seed':
		$check(!$saved && count($args)===2 && ctype_digit($args[1]) && (int)$args[1]>=1024 && (int)$args[1]<=65535,'Invalid isolated mail fixture');
		$check(!$rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_id=$gid"), 'Disposable group already in use');
		$current=array('smtp_delivery'=>1,'smtp_host'=>'127.0.0.1','smtp_port'=>(int)$args[1],'smtp_username'=>'','smtp_password'=>'',
			'email_package_size'=>0,'board_email'=>'absences@example.invalid','board_contact'=>'board@example.invalid','email_enable'=>1,
			'board_email_form'=>1,'allow_privmsg'=>1,'allow_mass_pm'=>1,'flood_interval'=>0,'plus_away_mod_contact'=>0);
		$rights=array_fill_keys(array('u_sendpm','u_readpm','u_sendemail','u_viewprofile','u_masspm','u_masspm_group','u_plus_away_mod'),ACL_NO);
		foreach ($rows('SELECT o.auth_option,a.auth_setting FROM ' . ACL_USERS_TABLE . ' a JOIN ' . ACL_OPTIONS_TABLE .
			" o ON o.auth_option_id=a.auth_option_id WHERE a.forum_id=0 AND a.user_id=$id AND " . $db->sql_in_set('o.auth_option',array_keys($rights))) as $r)
		{ $rights[$r['auth_option']]=(int)$r['auth_setting']; }
		$original=array(); foreach ($current as $k=>$v) { $original[$k]=$config[$k]; }
		$members=$rows('SELECT user_id,user_allow_viewemail,user_allow_pm,user_emailtime,user_lastpost_time,user_unread_privmsg,
			user_new_privmsg,user_last_privmsg,plus_away,plus_away_mode FROM ' . USERS_TABLE . " WHERE $who");
		$check(count($members)===4,'Missing disposable recipients');
		$text->set($key,json_encode(array('config'=>$original,'rights'=>$rights,'users'=>$members,'notes'=>$rows("SELECT user_id,note_text FROM $notes WHERE $who"))));
		foreach ($current as $k=>$v) { $config->set($k,$v); }
		foreach ($rights as $k=>$v) { $right($k,$k==='u_plus_away_mod' ? ACL_NO : ACL_YES); }
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_allow_pm=1,user_allow_viewemail=1,plus_away=0,plus_away_mode=2,user_emailtime=0,user_lastpost_time=0 WHERE $who");
		$db->sql_query("DELETE FROM $notes WHERE $who");
		foreach (array(2=>'Direkter Hinweis & <privat>',87=>'Privates Gruppenmitglied',89=>'Nur beim Moderatorkonto') as $u=>$note)
		{ $db->sql_query("INSERT INTO $notes " . $db->sql_build_array('INSERT',array('user_id'=>$u,'note_text'=>json_encode($note)))); }
		$db->sql_query('INSERT INTO ' . GROUPS_TABLE . ' ' . $db->sql_build_array('INSERT',array('group_id'=>$gid,'group_type'=>GROUP_OPEN,
			'group_name'=>'Absence check circle','group_desc'=>'','group_receive_pm'=>1)));
		foreach (array(2=>1,87=>0,89=>0) as $u=>$pending)
		{ $db->sql_query('INSERT INTO ' . USER_GROUP_TABLE . ' ' . $db->sql_build_array('INSERT',array('group_id'=>$gid,'user_id'=>$u,'user_pending'=>$pending,'group_leader'=>0))); }
		break;
	case 'away':
		$db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_away=1 WHERE user_id IN (2,87)'); break;
	case 'group_available':
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_away=0 WHERE user_id=87"); break;
	case 'mods':
		$config->set('plus_away_mod_contact',1); break;
	case 'promote':
		$right('u_plus_away_mod',ACL_YES); break;
	case 'revoke':
		$right('u_plus_away_mod',ACL_NEVER); break;
	case 'private_email':
		$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_allow_viewemail=0 WHERE user_id=2'); break;
	case 'founder_target':
		$config->set('plus_away_mod_contact',0);
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_away=1 WHERE user_id=$id"); break;
	case 'outcome':
		$subjects=$rows('SELECT p.message_subject,pt.user_id FROM ' . PRIVMSGS_TABLE . ' p JOIN ' . PRIVMSGS_TO_TABLE .
			" pt ON pt.msg_id=p.msg_id WHERE p.message_subject LIKE 'Away check / %' AND pt.folder_id NOT IN (" .
			PRIVMSGS_OUTBOX . ',' . PRIVMSGS_SENTBOX . ') ORDER BY p.message_subject,pt.user_id');
		$delivered=array(); foreach ($subjects as $row) { $delivered[$row['message_subject']][]=(int)$row['user_id']; }
		$check($delivered===array('Away check / founder'=>array($id),'Away check / group'=>array(87,89),'Away check / moderator'=>array(2)),
			'Private message was lost, sent twice or delivered to an unavailable group or blind recipient: ' . json_encode($delivered));
		break;
	case 'restore':
		$check($saved,'Missing disposable communication state');
		$ids=array_column($rows('SELECT msg_id FROM ' . PRIVMSGS_TABLE . " WHERE message_subject LIKE 'Away check / %'"),'msg_id');
		foreach ($ids as $msg) { $phpbb_container->get('notification_manager')->delete_notifications('notification.type.pm',(int)$msg); }
		if ($ids)
		{
			foreach (array(PRIVMSGS_TO_TABLE,PRIVMSGS_TABLE) as $t) { $db->sql_query('DELETE FROM ' . $t . ' WHERE ' . $db->sql_in_set('msg_id',$ids)); }
		}
		$db->sql_query('DELETE FROM ' . USER_GROUP_TABLE . " WHERE group_id=$gid");
		$db->sql_query('DELETE FROM ' . GROUPS_TABLE . " WHERE group_id=$gid");
		foreach ($saved['config'] as $k=>$v) { $config->set($k,$v); }
		foreach ($saved['rights'] as $k=>$v) { $right($k,$v); }
		foreach ($saved['users'] as $row)
		{
			$u=(int)$row['user_id']; unset($row['user_id']);
			$db->sql_query('UPDATE ' . USERS_TABLE . ' SET ' . $db->sql_build_array('UPDATE',$row) . " WHERE user_id=$u");
		}
		$db->sql_query("DELETE FROM $notes WHERE $who");
		foreach ($saved['notes'] as $row) { $db->sql_query("INSERT INTO $notes " . $db->sql_build_array('INSERT',$row)); }
		$text->delete($key); break;
	default: exit(2);
}
