<?php
if (PHP_SAPI!=='cli' || getenv('PLUS_TEST_DATABASE')!=='1' || $argc!==3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
$note="Nachricht & Grüße 🎨 <Wort>\nNur für Mitglieder &amp;.";
if ($argv[2]==='source')
{
	mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
	$old=new mysqli('127.0.0.1',getenv('PLUS_TEST_DB_USER') ?: 'root',getenv('PLUS_TEST_DB_PASSWORD'),$argv[1],(int)(getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$old->set_charset('utf8mb4');
	$stmt=$old->prepare('UPDATE phpbb_users SET user_absence=?,user_absence_mode=?,user_absence_text=? WHERE user_id=?');
	foreach (array(array(1,2,$note,2),array(0,13,'0',1)) as $item)
	{
		list($active,$mode,$body,$id)=$item; $body=htmlspecialchars($body,ENT_QUOTES,'UTF-8');
		$stmt->bind_param('iisi',$active,$mode,$body,$id); $stmt->execute();
		if ($stmt->affected_rows!==1) { throw new RuntimeException('Missing original absence fixture'); }
	}
	$old->query("UPDATE phpbb_users SET user_level=2 WHERE user_id=89 AND username='ModerationFixture'");
	return;
}
define('IN_PHPBB',true);
$phpbb_root_path='./'; $phpEx='php';
require './common.php';
if ($dbhost!=='127.0.0.1' || $dbname!==$argv[1]) { exit(2); }
$rows=static function($sql) use ($db) { $r=$db->sql_query($sql); $all=$db->sql_fetchrowset($r); $db->sql_freeresult($r); return $all; };
$check=static function($ok,$why) { if (!$ok) { throw new RuntimeException($why); } };
$text=$phpbb_container->get('config_text'); $key='plus_away_fixture';
$saved=json_decode((string)$text->get($key),true);
$notes=$table_prefix . 'plus_away_notes';
$people=$rows('SELECT user_id,username,plus_away,plus_away_mode,plus_away_revision FROM ' . USERS_TABLE . " WHERE username IN ('ModernMember','HistoricMember')");
$by=array_column($people,null,'username'); $member=(int)$by['HistoricMember']['user_id'];
$messages=$rows("SELECT user_id,note_text FROM $notes WHERE user_id IN (2,$member) ORDER BY user_id");
$body=array_map(static function($stored) { return json_decode($stored,true); },array_column($messages,'note_text','user_id'));
switch ($argv[2])
{
	case 'converted':
		$moderators=$rows('SELECT a.user_id FROM ' . ACL_USERS_TABLE . ' a JOIN ' . ACL_OPTIONS_TABLE .
			" o ON o.auth_option_id=a.auth_option_id WHERE o.auth_option='u_plus_away_mod' AND a.auth_setting=" . ACL_YES);
		$check(in_array(89,array_column($moderators,'user_id')) && !in_array($member,array_column($moderators,'user_id')),'A partial forum grant became the special absence role, or a real source moderator lost it');
		$check(count($people)===2 && $member!==1 && (int)$by['HistoricMember']['plus_away_mode']===13 &&
			(int)$by['ModernMember']['plus_away']===1 && (int)$by['ModernMember']['plus_away_mode']===2 &&
			($body[2] ?? null)===$note && ($body[$member] ?? null)==='0','Converted absence lost the remapped owner, mode or exact private note');
		$check((int)$config['plus_away_members']===0 && (int)$config['plus_away_mod_contact']===0 && (int)$config['plus_away_by_name']===1,'Absent User Mod board defaults changed');
		// Other integration blocks independently exercise communication with the synthetic administrator.
		$db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_away=0 WHERE user_id=2');
		break;
	case 'seed':
		$check(!$saved,'An absence fixture is already running');
		require_once './includes/acp/auth.php';
		$group=(int)$rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_name='GUESTS' AND group_type=" . GROUP_SPECIAL)[0]['group_id'];
		$original=$rows('SELECT * FROM ' . ACL_GROUPS_TABLE . " WHERE group_id=$group AND forum_id=0");
		$saved=array('users'=>$people,'notes'=>$messages,'members'=>$config['plus_away_members'],'mods'=>$config['plus_away_mod_contact'],'position'=>$config['plus_away_by_name'],
			'guest'=>$group,'guest_acl'=>$original);
		$text->set($key,json_encode($saved)); $config->set('plus_away_members',1); $config->set('plus_away_mod_contact',0); $config->set('plus_away_by_name',1);
		(new auth_admin())->acl_set('group',0,$group,array('u_'=>ACL_YES,'u_viewprofile'=>ACL_YES));
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_away=1,plus_away_mode=2 WHERE user_id=2");
		break;
	case 'locked':
		$config->set('plus_away_members',0);
		$actor=$rows('SELECT * FROM ' . USERS_TABLE . " WHERE user_id=$member")[0]; $actor['is_registered']=true; $actor['is_bot']=false;
		$auth->acl($actor);
		$groups=$rows('SELECT g.group_name FROM ' . GROUPS_TABLE . ' g JOIN ' . USER_GROUP_TABLE . " ug ON ug.group_id=g.group_id WHERE ug.user_id=$member AND ug.user_pending=0 AND g.group_name='ADMINISTRATORS'");
		$check(!$groups && !$auth->acl_get('u_plus_away_mod'),'The fixture expected an ordinary user but found an absence moderator');
		break;
	case 'upgrade':
		$name='\phpbb2plus\community\migrations\v155_absence';
		$installed="SELECT migration_name FROM {$table_prefix}migrations WHERE migration_name='" . $db->sql_escape($name) . "'";
		$native='SELECT user_id,user_type,user_allow_pm,user_allow_viewemail FROM ' . USERS_TABLE . " WHERE user_id IN ($member,2) ORDER BY user_id";
		$prior=$rows($native);
		$settings=array();
		foreach (array('plus_away_members','plus_away_mod_contact','plus_away_by_name') as $setting) { $settings[$setting]=$config[$setting]; }
		$before_users=$rows('SELECT user_id,plus_away,plus_away_mode,plus_away_revision FROM ' . USERS_TABLE . " WHERE plus_away<>0 OR plus_away_mode<>0 OR plus_away_revision<>''");
		$before_notes=$rows("SELECT user_id,note_text FROM $notes");
		$grants=$rows('SELECT a.user_id,a.auth_setting FROM ' . ACL_USERS_TABLE . ' a JOIN ' . ACL_OPTIONS_TABLE .
			" o ON o.auth_option_id=a.auth_option_id WHERE a.forum_id=0 AND o.auth_option='u_plus_away_mod'");
		$m=$phpbb_container->get('migrator'); $tools=$phpbb_container->get('dbal.tools');
		$m->load_migration_state();
		for ($i=0;$i<40 && $rows($installed);$i++) { $m->revert($name); }
		$check(!$rows($installed) && !$tools->sql_table_exists($notes) && !$tools->sql_column_exists(USERS_TABLE,'plus_away'),'Previous phpBB3 release still had the new absence schema');
		$m->load_migration_state(); $m->set_migrations(array($name));
		for ($i=0;$i<40 && !$m->finished();$i++) { $m->update(); }
		$modes=$rows('SELECT module_mode FROM ' . MODULES_TABLE . " WHERE module_basename LIKE '%community%absence_module'");
		$check($m->finished() && $tools->sql_table_exists($notes) && $prior===$rows($native) && array_column($modes,'module_mode')===array('edit') &&
			!$rows("SELECT user_id FROM $notes") && !$rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE plus_away<>0 OR plus_away_mode<>0 OR plus_away_revision<>''") &&
			!(int)$config['plus_away_members'] && !(int)$config['plus_away_mod_contact'] && (int)$config['plus_away_by_name']===1,'Previous phpBB3 board lost native privacy settings or the upgrade enabled unsolicited notices');
		foreach ($before_users as $row)
		{
			$id=(int)$row['user_id']; unset($row['user_id'],$row['username']);
			$db->sql_query('UPDATE ' . USERS_TABLE . ' SET ' . $db->sql_build_array('UPDATE',$row) . " WHERE user_id=$id");
		}
		foreach ($before_notes as $row) { $db->sql_query("INSERT INTO $notes " . $db->sql_build_array('INSERT',$row)); }
		foreach ($settings as $setting=>$value) { $config->set($setting,$value); }
		$option=(int)$rows('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='u_plus_away_mod'")[0]['auth_option_id'];
		foreach ($grants as $grant)
		{
			$db->sql_query('INSERT INTO ' . ACL_USERS_TABLE . ' ' . $db->sql_build_array('INSERT',$grant+array('forum_id'=>0,'auth_role_id'=>0,'auth_option_id'=>$option)));
		}
		$auth->acl_clear_prefetch();
		break;
	case 'available': $config->set('plus_away_members',1); break;
	case 'contact_label': $config->set('plus_away_by_name',0); break;
	case 'verify_return':
		$check(!(int)$by['HistoricMember']['plus_away'] && (int)$by['HistoricMember']['plus_away_mode']===1 && $body[$member]==='Eigene Auszeit & 0','Returning changed the stored category or personal note');
		break;
	case 'restore':
		$check($saved,'Missing saved absence fixture');
		$db->sql_query('DELETE FROM ' . ACL_GROUPS_TABLE . ' WHERE forum_id=0 AND group_id=' . (int)$saved['guest']);
		if ($saved['guest_acl']) { $db->sql_multi_insert(ACL_GROUPS_TABLE,$saved['guest_acl']); }
		$auth->acl_clear_prefetch();
		foreach ($saved['users'] as $row)
		{
			$id=(int)$row['user_id']; unset($row['user_id'],$row['username']);
			$db->sql_query('UPDATE ' . USERS_TABLE . ' SET ' . $db->sql_build_array('UPDATE',$row) . " WHERE user_id=$id");
		}
		$db->sql_query("DELETE FROM $notes WHERE user_id IN (2,$member)");
		foreach ($saved['notes'] as $row) { $db->sql_query("INSERT INTO $notes " . $db->sql_build_array('INSERT',$row)); }
		$config->set('plus_away_members',$saved['members']); $config->set('plus_away_mod_contact',$saved['mods']); $config->set('plus_away_by_name',$saved['position']);
		$text->delete($key);
		break;
	default: exit(2);
}
