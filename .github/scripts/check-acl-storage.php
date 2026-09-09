<?php
require __DIR__ . '/check-group-storage.php';
foreach (array('IN_ADMIN'=>true,'AUTH_ALL'=>0,'AUTH_REG'=>1,'AUTH_ACL'=>2,'AUTH_MOD'=>3,'AUTH_ADMIN'=>5,'JR_ADMIN_TABLE'=>'fixture_junior','POST_USERS_URL'=>'u') as $key=>$value) { if (!defined($key)) { define($key,$value); } }
require $forum_root . 'includes/functions_acl_storage.php';
function acl_fixture($actor=1)
{
	global $mutation_server,$userdata;
	group_fixture($actor); $p=$mutation_server->pdo; $userdata['session_admin']=true;
	foreach (phpbb_acl_fields() as $field) { $p->exec('ALTER TABLE fixture_auth ADD '.$field.' INTEGER DEFAULT 0'); }
	foreach (phpbb_acl_fields() as $field) { $p->exec('ALTER TABLE fixture_forums ADD '.$field.' INTEGER DEFAULT 2'); }
	$p->exec('UPDATE fixture_forums SET auth_post=1 WHERE forum_id=2');
	$p->exec('INSERT INTO fixture_forums (forum_id) VALUES (3)');
	$p->exec('CREATE TABLE fixture_junior (user_id INTEGER,user_jr_admin VARCHAR(255))');
	$p->exec("INSERT INTO fixture_groups VALUES (20,0,1,1,'Root personal'),(21,0,1,8,'Leader personal'),(22,0,1,10,'Pending personal')");
	$p->exec('INSERT INTO fixture_memberships VALUES (1,20,0),(8,21,0),(10,22,0)');
	$p->exec('UPDATE fixture_users SET user_level=2 WHERE user_id=12');
}
function acl_post($extra=array()) { return array_merge(array('sid'=>'fixture-session','adv'=>'0'),$extra); }
function acl_failure($callback,$expected)
{
	$caught=false; try { call_user_func($callback); } catch (PhpbbAclException $e) { $caught=$e->getMessage()===$expected; }
	mutation_check($caught,'Expected ACL failure: '.$expected);
	mutation_check($GLOBALS['mutation_server']->owner===null,'ACL failure releases owner');
}
function cache_tree($refresh) { mutation_check($refresh && $GLOBALS['mutation_server']->owner===null,'Hierarchy refresh follows writer release'); $GLOBALS['acl_cache_refreshes']++; }
function phpbb_admin_require_post_session() { mutation_check($_SERVER['REQUEST_METHOD']==='POST' && $_POST['sid']===$GLOBALS['userdata']['session_id'],'Controller requires actual ACP POST session'); }
class AclViewDatabase extends GroupControllerReadDatabase
{
	var $failure=false;
	function sql_query($sql) { return $this->failure ? false : parent::sql_query($sql); }
	function sql_fetchrowset($r) { return $r->fetchAll(PDO::FETCH_ASSOC); }
	function sql_escape($value) { return substr($GLOBALS['mutation_server']->pdo->quote($value),1,-1); }
}
set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	// Keep the storage whitelist aligned with actual core + Attachment MOD metadata.
	$metadata=file_get_contents($forum_root.'includes/def_auth.php');
	preg_match_all('/\$lang\[\x27([^\x27]+)\x27\]/',$metadata,$keys);
	foreach ($keys[1] as $key) { if (!isset($lang[$key])) { $lang[$key]=$key; } }
	$lang['Auth_attach']='Attach'; $lang['Auth_download']='Download';
	if (!function_exists('attach_setup_forum_auth')) { eval(group_test_function(file_get_contents($forum_root.'attach_mod/includes/functions_includes.php'),'attach_setup_forum_auth')); }
	$forum_auth_fields=array(); require $forum_root.'includes/def_auth.php';
	mutation_check(array_keys($field_names)===phpbb_acl_fields(),'Actual def_auth and attachment field list matches storage whitelist');
	acl_fixture(); phpbb_acl_save($db,'group',3,acl_post(array('moderator'=>array(2=>'0'),'private'=>array(2=>'1'))));
	foreach (phpbb_acl_fields() as $field) { mutation_check(group_value('SELECT '.$field.' FROM fixture_auth WHERE group_id=3 AND forum_id=2')===($field==='auth_post'?0:1),'Simple mode changes only current private fields: '.$field); }
	mutation_check(group_value('SELECT user_level FROM fixture_users WHERE user_id=12')===2,'Unrelated account not globally repaired/demoted');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions WHERE session_user_id IN (1,11,12)')===3,'Unrelated/current actor sessions preserved');
	acl_fixture(); $mutation_server->pdo->exec('UPDATE fixture_auth SET auth_mod=0,auth_view=1 WHERE group_id=3');
	phpbb_acl_save($db,'group',3,acl_post(array('adv'=>'1','private_auth_read'=>array(2=>'1'))));
	mutation_check(group_value('SELECT auth_view+auth_read FROM fixture_auth WHERE group_id=3 AND forum_id=2')===2,'Advanced field save preserves unsubmitted permission');
	mutation_check(group_value('SELECT auth_mod FROM fixture_auth WHERE group_id=7')===1,'Another group ACL remains untouched');
	acl_fixture(); $mutation_server->pdo->exec('INSERT INTO fixture_auth (group_id,forum_id,auth_mod,auth_view) VALUES (3,2,0,1)');
	phpbb_acl_save($db,'group',3,acl_post(array('adv'=>'1','private_auth_read'=>array(2=>'0'))));
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_auth WHERE group_id=3 AND forum_id=2 AND auth_view=1 AND auth_mod=1')===2,'Existing duplicate ACL rows preserve OR grants consistently');
	acl_fixture(); phpbb_acl_save($db,'group',3,acl_post(array('moderator'=>array(2=>'1'))));
	mutation_check(group_value('SELECT user_level FROM fixture_users WHERE user_id=9')===2 && group_value('SELECT user_level FROM fixture_users WHERE user_id=10')===0,'Only actual approved memberships derive moderator role');
	$mutation_server->pdo->exec('INSERT INTO fixture_memberships VALUES (9,7,0)');
	phpbb_acl_save($db,'group',3,acl_post(array('moderator'=>array(2=>'0'))));
	mutation_check(group_value('SELECT user_level FROM fixture_users WHERE user_id=9')===2 && group_value('SELECT user_level FROM fixture_users WHERE user_id=8')===0,'Other approved moderator group preserves role');
	acl_fixture(); phpbb_acl_save($db,'group',3,acl_post(array('private'=>array(3=>'1'))));
	mutation_check(group_value('SELECT auth_read FROM fixture_auth WHERE group_id=3 AND forum_id=3')===1,'New per-forum permission row inserted');
	phpbb_acl_save($db,'group',3,acl_post(array('private'=>array(3=>'0'))));
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_auth WHERE group_id=3 AND forum_id=3')===0,'Explicit all-zero permission row removed');
	acl_fixture(); mutation_check(!phpbb_acl_save($db,'group',3,acl_post()),'Empty permission selection is a no-op');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions')===6,'Empty selection does not expire sessions');
	acl_fixture(); phpbb_acl_save($db,'user',9,acl_post(array('private'=>array(2=>'1'))));
	mutation_check(group_value('SELECT auth_read FROM fixture_auth WHERE group_id=4 AND forum_id=2')===1,'User mode resolves actual personal group');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_auth WHERE group_id=3')===1,'Personal permissions do not replace shared group ACL');
	acl_fixture(); $mutation_server->pdo->exec('INSERT INTO fixture_memberships VALUES (10,4,0)');
	acl_failure(function() use($db) { phpbb_acl_save($db,'user',9,acl_post(array('private'=>array(2=>1)))); },'Acl_selection_changed');
	acl_fixture(); $mutation_server->pdo->exec('INSERT INTO fixture_auth (group_id,forum_id,auth_mod,auth_view,auth_download) VALUES (4,2,0,1,1)');
	phpbb_acl_save($db,'user',9,acl_post(array('userlevel'=>'admin')));
	mutation_check(group_value('SELECT user_level FROM fixture_users WHERE user_id=9')===1 && group_value('SELECT COUNT(*) FROM fixture_auth WHERE group_id=4')===0,'Root promotion clears redundant personal permissions');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions WHERE session_user_id=9')===0,'Promotion expires target sessions');
	$mutation_server->pdo->exec('INSERT INTO fixture_auth (group_id,forum_id,auth_mod,auth_view,auth_download) VALUES (4,2,1,1,1)');
	phpbb_acl_save($db,'user',9,acl_post(array('userlevel'=>'user')));
	mutation_check(group_value('SELECT user_level FROM fixture_users WHERE user_id=9')===2 && group_value('SELECT auth_view+auth_download FROM fixture_auth WHERE group_id=4')===0,'Demotion derives actual moderator role and clears all redundant fields');
	acl_fixture(8); $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=8');
	acl_failure(function() use($db) { phpbb_acl_save($db,'user',1,acl_post(array('userlevel'=>'user'))); },'ctracker_gmb_1stadmin');
	acl_fixture(); acl_failure(function() use($db) { phpbb_acl_save($db,'user',1,acl_post(array('userlevel'=>'user'))); },'Acl_self_role_change');
	foreach (array('user','group') as $mode)
	{
		acl_fixture(8); $hash=md5(($mode==='user'?'Users':'Groups').'Permissionsadmin_ug_auth.php?mode='.$mode);
		$mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".$hash."')");
		phpbb_acl_save($db,$mode,$mode==='user'?9:3,acl_post(array('private'=>array(3=>'1'))));
		$wrong=$mode==='user'?'group':'user';
		acl_failure(function() use($db,$wrong) { phpbb_acl_save($db,$wrong,$wrong==='user'?9:3,acl_post(array('private'=>array(3=>1)))); },'Not_Authorised');
		if ($mode==='user') { acl_failure(function() use($db) { phpbb_acl_save($db,'user',9,acl_post(array('userlevel'=>'admin'))); },'Acl_root_required'); }
		$mutation_server->pdo->exec('DELETE FROM fixture_junior');
		acl_failure(function() use($db,$mode) { phpbb_acl_save($db,$mode,$mode==='user'?9:3,acl_post()); },'Not_Authorised');
	}
	foreach (array(array(9),'9oops',true,0,-1,16777216) as $bad)
	{
		acl_fixture(); acl_failure(function() use($db,$bad) { phpbb_acl_save($db,'user',$bad,acl_post()); },'Acl_selection_changed');
	}
	foreach (array(array('moderator'=>'2'),array('private'=>array(2=>array(1))),array('moderator'=>array('2oops'=>'1')),array('private'=>array(2=>'yes')),array('adv'=>array(1)),array('userlevel'=>array('admin'))) as $bad)
	{
		acl_fixture(); acl_failure(function() use($db,$bad) { phpbb_acl_save($db,'group',3,acl_post($bad)); },'Acl_selection_changed');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions')===6,'Malformed original map has no writes');
	}
	foreach (array('no-acp','inactive','deleted','demoted','get','nested-sid','wrong-sid') as $case)
	{
		acl_fixture(); $expected='Not_Authorised';
		if ($case==='no-acp') { $userdata['session_admin']=false; }
		if ($case==='inactive') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1'); }
		if ($case==='deleted') { $mutation_server->pdo->exec('DELETE FROM fixture_users WHERE user_id=1'); }
		if ($case==='demoted') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1'); }
		if ($case==='get') { $_SERVER['REQUEST_METHOD']='GET'; $expected='Session_invalid'; }
		$post=acl_post(array('private'=>array(3=>'1')));
		if ($case==='nested-sid') { $post['sid']=array('fixture-session'); $expected='Session_invalid'; }
		if ($case==='wrong-sid') { $post['sid']='wrong'; $expected='Session_invalid'; }
		acl_failure(function() use($db,$post) { phpbb_acl_save($db,'group',3,$post); },$expected);
	}
	acl_fixture(); $mutation_server->hook=function($sql) {
		if (strpos($sql,'UPDATE fixture_users SET user_level = CASE')===0) { $GLOBALS['mutation_server']->hook=null; $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=9'); }
	};
	phpbb_acl_save($db,'group',3,acl_post(array('moderator'=>array(2=>0))));
	mutation_check(group_value('SELECT user_level FROM fixture_users WHERE user_id=9')===1,'Late administrator promotion never overwritten by role derivation');
	foreach (array('actor','forum','personal') as $case)
	{
		acl_fixture(); $mutation_server->hook=function($sql) use($case) {
			if (strpos($sql,'INSERT INTO fixture_auth')===0)
			{
				$GLOBALS['mutation_server']->hook=null;
				$GLOBALS['mutation_server']->pdo->exec($case==='actor'?'UPDATE fixture_users SET user_level=0 WHERE user_id=1':($case==='forum'?'UPDATE fixture_forums SET auth_read=1 WHERE forum_id=3':'UPDATE fixture_groups SET group_single_user=1 WHERE group_id=3'));
			}
		};
		acl_failure(function() use($db) { phpbb_acl_save($db,'group',3,acl_post(array('private'=>array(3=>1)))); },'Acl_selection_changed');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_auth WHERE group_id=3 AND forum_id=3')===0,'Actual ACL write repeats current actor/forum/target predicates');
	}
	foreach (array('DELETE FROM fixture_sessions','INSERT INTO fixture_auth','UPDATE fixture_users') as $failure)
	{
		acl_fixture(); $mutation_server->failure=$failure;
		acl_failure(function() use($db) { phpbb_acl_save($db,'group',3,acl_post(array('private'=>array(3=>1)))); },'Acl_storage_failed');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_auth WHERE group_id=3 AND forum_id=3')===($failure==='UPDATE fixture_users'?1:0),'Partial save is explicit, not imaginary rollback');
	}
	acl_fixture(); $interleaved=false; $mutation_server->hook=function($sql) use($db,&$interleaved) {
		if ($interleaved || strpos($sql,'INSERT INTO fixture_auth')!==0) { return; } $interleaved=true; $caught=false;
		try { phpbb_acl_save($db,'group',3,acl_post()); } catch (PhpbbAclException $e) { $caught=$e->getMessage()==='busy'; }
		mutation_check($caught,'Concurrent ACL request cannot enter owning writer');
	};
	phpbb_acl_save($db,'group',3,acl_post(array('private'=>array(3=>1)))); mutation_check($interleaved,'Lock contender exercised');
	$controller=file_get_contents($forum_root.'admin/admin_ug_auth.php');
	$view_start=strpos($controller,'// Viewing a different mode');
	$view_end=strpos($controller,"//\n// Start program",$view_start);
	mutation_check($view_start!==false && $view_end!==false,'Current mode view guard precedes metadata/rendering');
	$view=substr($controller,$view_start,$view_end-$view_start);
	foreach (array('user','group') as $granted_mode)
	{
		acl_fixture(8); $hash=md5(($granted_mode==='user'?'Users':'Groups').'Permissionsadmin_ug_auth.php?mode='.$granted_mode);
		$mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".$hash."')");
		$reader=new AclViewDatabase();
		$view_actor=call_user_func(function() use($view,$reader,$granted_mode,$phpbb_root_path,$phpEx) { $db=$reader; $mode=$granted_mode; eval($view); return $acl_view_actor; });
		mutation_check(!$view_actor['root'],'Junior view uses current account role, not cached role');
		mutation_expect_failure(function() use($view,$reader,$granted_mode,$phpbb_root_path,$phpEx) { $db=$reader; $mode=$granted_mode==='user'?'group':'user'; eval($view); },'Not_Authorised');
		$reader->failure=true;
		mutation_expect_failure(function() use($view,$reader,$granted_mode,$phpbb_root_path,$phpEx) { $db=$reader; $mode=$granted_mode; eval($view); },'Acl_read_failed');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions')===6,'Permission viewing does not mutate sessions');
	}
	$start=strpos($controller,"if ( isset(\$_POST['submit']) )"); $end=strpos($controller,"else if ( ( \$mode == 'user'",$start);
	mutation_check($start!==false && $end!==false,'Actual ACL controller submit branch found'); $branch=substr($controller,$start,$end-$start);
	$lang['Auth_updated']='Updated'; $lang['Click_return_userauth']='%sUser%s'; $lang['Click_return_groupauth']='%sGroup%s'; $lang['Click_return_admin_index']='%sAdmin%s';
	acl_fixture(); $_POST=acl_post(array('submit'=>1,'mode'=>'group','g'=>3,'private'=>array(3=>1))); $acl_cache_refreshes=0;
	mutation_expect_failure(function() use($branch,$db,$lang,$phpbb_root_path,$phpEx) { $mode='group'; eval($branch); });
	mutation_check($acl_cache_refreshes===1 && group_value('SELECT auth_read FROM fixture_auth WHERE group_id=3 AND forum_id=3')===1,'Actual controller writes then refreshes hierarchy once');
	acl_fixture(); $_POST=acl_post(array('submit'=>1,'mode'=>array('group'),'g'=>3)); $acl_cache_refreshes=0;
	mutation_expect_failure(function() use($branch,$db,$lang,$phpbb_root_path,$phpEx) { $mode='user'; eval($branch); },'Acl_selection_changed');
	mutation_check($acl_cache_refreshes===0 && group_value('SELECT COUNT(*) FROM fixture_sessions')===6,'Controller preserves nested original mode for rejection');
	echo "Coordinated user/group ACL, scoped roles, founder protection and delegated modes passed.\n";
}
finally { if (isset($mutation_server) && $mutation_server->owner!==null) { $mutation_server->owner->sql_close(); } restore_error_handler(); }
