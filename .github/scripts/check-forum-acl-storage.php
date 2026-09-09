<?php
require __DIR__ . '/check-acl-storage.php';
if (!defined('POST_FORUM_URL')) { define('POST_FORUM_URL','f'); }
require $forum_root . 'includes/functions_forum_acl_storage.php';
eval(group_test_function(file_get_contents($forum_root.'admin/pagestart.php'),'phpbb_admin_html'));
// Read real presets and Attachment MOD metadata, not a test-only copy.
$metadata=file_get_contents($forum_root.'includes/def_auth.php');
preg_match_all('/\$lang\[\x27([^\x27]+)\x27\]/',$metadata,$keys);
foreach ($keys[1] as $key) { if (!isset($lang[$key])) { $lang[$key]=$key; } }
$lang['Auth_attach']='Attach'; $lang['Auth_download']='Download';
if (!function_exists('attach_setup_forum_auth')) { eval(group_test_function(file_get_contents($forum_root.'attach_mod/includes/functions_includes.php'),'attach_setup_forum_auth')); }
$forum_auth_fields=array(); require $forum_root.'includes/def_auth.php';
function fa_post($extra=array()) { return array_merge(array('f'=>'f2','sid'=>'fixture-session'),$extra); }
function fa_run($extra=array()) { return phpbb_forum_acl_save($GLOBALS['db'],fa_post($extra)); }
class FaMixedRowConnection extends sql_db
{
	function sql_fetchrowset($result=0)
	{
		$rows=parent::sql_fetchrowset($result);
		foreach ($rows as $i=>$row)
		{
			$numeric=array(); foreach ($row as $key=>$value) { if (is_string($key)) { $numeric[]=$value; } }
			$rows[$i]=$row+$numeric;
		}
		return $rows;
	}
}
class FaMixedRowForum extends MutationForum
{
	function sql_dedicated_connection() { return new FaMixedRowConnection(preg_replace('/^p:/','',$this->server),$this->user,$this->password,$this->dbname,false); }
}
class FaTemplate
{
	var $vars=array();
	function assign_vars($values) { mutation_check($GLOBALS['mutation_server']->owner===null,'Rendering outside writer'); $this->vars=array_merge($this->vars,$values); }
}
set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	acl_fixture();
	phpbb_forum_acl_save(new FaMixedRowForum(),fa_post(array('simpleauth'=>'0')));
	mutation_check(group_value('SELECT auth_read FROM fixture_forums WHERE forum_id=2')===0,'Numeric/associative duplicate driver columns do not break result verification');
	foreach ($simple_auth_ary as $preset=>$levels)
	{
		acl_fixture(); mutation_check(fa_run(array('simpleauth'=>(string)$preset))===2,'Actual simple preset saved: '.$preset);
		foreach (phpbb_acl_fields() as $i=>$field) { mutation_check(group_value('SELECT '.$field.' FROM fixture_forums WHERE forum_id=2')===$levels[$i],'Preset preserves actual field '.$field); }
		mutation_check(group_value('SELECT auth_read FROM fixture_forums WHERE forum_id=3')===AUTH_ACL,'Other forum unaffected');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_auth')===2 && group_value('SELECT COUNT(*) FROM fixture_sessions')===6,'Policy changes do not replace group grants or force account logouts');
	}
	acl_fixture(); fa_run(array('auth_read'=>'5'));
	mutation_check(group_value('SELECT auth_read FROM fixture_forums WHERE forum_id=2')===5 && group_value('SELECT auth_view FROM fixture_forums WHERE forum_id=2')===2,'Partial advanced submission preserves omitted rights');
	foreach (phpbb_acl_fields() as $field)
	{
		foreach ($forum_auth_const as $value)
		{
			fa_run(array($field=>(string)$value));
			mutation_check(group_value('SELECT '.$field.' FROM fixture_forums WHERE forum_id=2')===($field==='auth_vote'&&$value===AUTH_ALL?AUTH_REG:$value),'Every advanced field supports actual allowed levels: '.$field);
		}
	}
	mutation_check(fa_run(array('auth_read'=>'5'))===2 && fa_run(array('auth_read'=>'5'))===2,'Unchanged valid save is successful independent of affected row convention');
	acl_fixture();
	foreach (array('auth_read','auth_download','auth_vote') as $field)
	{
		foreach (array(null,array(0),true,false,0.0,'','0oops','1.0',' 1','01','-1',4,6) as $bad)
		{
			acl_failure(function() use($field,$bad) { fa_run(array($field=>$bad)); },'Acl_selection_changed');
			mutation_check(group_value('SELECT auth_read FROM fixture_forums WHERE forum_id=2')===2,'Malformed controls cannot make forum public');
		}
	}
	foreach (array(null,array(0),true,false,'','public','0oops','01',7,-1) as $bad)
	{
		acl_failure(function() use($bad) { fa_run(array('simpleauth'=>$bad)); },'Acl_selection_changed');
	}
	foreach (array(null,array('f2'),true,2,'2','c2','f0','f-1','f2oops','f16777216') as $bad)
	{
		$_GET['f']='f2'; acl_failure(function() use($bad) { fa_run(array('f'=>$bad,'simpleauth'=>'0')); },'Acl_selection_changed');
	}
	acl_fixture(); acl_failure(function() { fa_run(); },'Acl_selection_changed');
	acl_failure(function() use($db) { phpbb_forum_acl_save($db,array('sid'=>'fixture-session','simpleauth'=>'0')); },'Acl_selection_changed');
	acl_fixture(); acl_failure(function() { fa_run(array('f'=>'f999','simpleauth'=>'0')); },'Acl_selection_changed');
	foreach (array('get','bad-sid','nested-sid','no-acp','inactive','deleted','demoted') as $case)
	{
		acl_fixture(); $post=fa_post(array('simpleauth'=>'0')); $expected='Not_Authorised';
		if ($case==='get') { $_SERVER['REQUEST_METHOD']='GET'; $expected='Session_invalid'; }
		if ($case==='bad-sid'||$case==='nested-sid') { $post['sid']=$case==='bad-sid'?'wrong':array('fixture-session'); $expected='Session_invalid'; }
		if ($case==='no-acp') { $userdata['session_admin']=false; }
		if ($case==='inactive') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1'); }
		if ($case==='deleted') { $mutation_server->pdo->exec('DELETE FROM fixture_users WHERE user_id=1'); }
		if ($case==='demoted') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1'); }
		acl_failure(function() use($db,$post) { phpbb_forum_acl_save($db,$post); },$expected);
		mutation_check(group_value('SELECT auth_read FROM fixture_forums WHERE forum_id=2')===2,'Invalid session/actor cannot change forum policy');
	}
	foreach (array('user','group','forum','manage') as $module)
	{
		acl_fixture(8); $route=$module==='forum'?'ForumsPermissionsadmin_forumauth.php':($module==='manage'?'GroupsManageadmin_groups.php':($module==='user'?'Users':'Groups').'Permissionsadmin_ug_auth.php?mode='.$module);
		$mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".md5($route)."')");
		if ($module==='forum')
		{
			fa_run(array('auth_read'=>'1'));
			foreach (array('user','group') as $other) { acl_failure(function() use($db,$other) { phpbb_acl_save($db,$other,$other==='user'?9:3,acl_post()); },'Not_Authorised'); }
		}
		else { acl_failure(function() { fa_run(array('auth_read'=>'1')); },'Not_Authorised'); }
	}
	foreach (array('actor','grant','deleted-forum','changed-policy') as $case)
	{
		acl_fixture($case==='grant'?8:1);
		if ($case==='grant') { $mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".md5('ForumsPermissionsadmin_forumauth.php')."')"); }
		$mutation_server->hook=function($sql) use($case) {
			if (strpos($sql,'UPDATE fixture_forums')===0) {
				$GLOBALS['mutation_server']->hook=null;
				$changes=array('actor'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=1','grant'=>'DELETE FROM fixture_junior','deleted-forum'=>'DELETE FROM fixture_forums WHERE forum_id=2','changed-policy'=>'UPDATE fixture_forums SET auth_view=5 WHERE forum_id=2');
				$GLOBALS['mutation_server']->pdo->exec($changes[$case]);
			}
		};
		acl_failure(function() { fa_run(array('auth_read'=>'0')); },$case==='actor'||$case==='grant'?'Not_Authorised':'Acl_selection_changed');
		if ($case!=='deleted-forum') { mutation_check(group_value('SELECT auth_read FROM fixture_forums WHERE forum_id=2')===2,'Late policy or actor change defeats stale SQL update'); }
	}
	foreach (array('SELECT user_id, user_level','SELECT auth_view','UPDATE fixture_forums') as $failure)
	{
		acl_fixture(); $mutation_server->failure=$failure; acl_failure(function() { fa_run(array('simpleauth'=>'0')); },'Acl_storage_failed');
		mutation_check(group_value('SELECT auth_read FROM fixture_forums WHERE forum_id=2')===2,'Storage failure before/at single update preserves policy');
	}
	acl_fixture(); $owner=new attach_mutation_lock($db); $caught=false;
	try { fa_run(array('auth_read'=>0)); } catch (PhpbbAclException $e) { $caught=$e->getMessage()==='busy'; }
	mutation_check($caught && $mutation_server->owner===$owner->connection,'Forum-policy and content/ACL writers share actual lock'); $owner->release();
	$source=file_get_contents($forum_root.'admin/admin_forumauth.php');
	$begin=strpos($source,"if( isset(\$_POST['submit']) )"); $end=strpos($source,'} // End of submit',$begin);
	mutation_check($begin!==false && $end!==false,'Actual controller submit branch found'); $body=substr($source,$begin,$end-$begin+1);
	$lang['Forum_auth_updated']='Updated'; $lang['Click_return_forumauth']='%sForum%s';
	foreach (array('success','bad-id','read-after-write') as $case)
	{
		acl_fixture(); $_POST=fa_post(array('submit'=>1,'auth_read'=>0)); $_GET['f']='f2'; $acl_cache_refreshes=0; $template=new FaTemplate();
		if ($case==='bad-id') { $_POST['f']=array('f2'); }
		if ($case==='read-after-write') { $mutation_server->hook=function($sql) { if (strpos($sql,'UPDATE fixture_forums')===0) { $GLOBALS['mutation_server']->hook=null; $GLOBALS['mutation_server']->failure='SELECT auth_view'; } }; }
		$caught=false;
		try { eval($body); } catch (MutationFailure $e) { $caught=$case==='success'?strpos($e->getMessage(),'Updated')===0:$e->getMessage()===($case==='bad-id'?'Acl_selection_changed':'Acl_storage_failed'); }
		mutation_check($caught && $acl_cache_refreshes===($case==='bad-id'?0:1),'Controller refreshes outside lock after attempted write, including failed verification');
		mutation_check(group_value('SELECT auth_read FROM fixture_forums WHERE forum_id=2')===($case==='bad-id'?2:0),'Actual controller write result is honest after failure');
		if ($case==='success') { mutation_check(strpos($template->vars['META'],'?f=f2')!==false,'Success redirect retains hierarchy-prefixed forum ID'); }
	}
	$begin=strpos($source,'try { phpbb_acl_actor('); $end=strpos($source,"\n\n//",$begin);
	mutation_check($begin!==false && $end!==false,'Actual pre-render actor guard found'); $view=substr($source,$begin,$end-$begin);
	foreach (array('root','junior','denied','read-failure') as $case)
	{
		acl_fixture($case==='root'?1:8); $reader=new AclViewDatabase();
		if ($case==='junior') { $mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".md5('ForumsPermissionsadmin_forumauth.php')."')"); }
		if ($case==='read-failure') { $reader->failure=true; }
		$callback=function() use($view,$reader) { $db=$reader; eval($view); };
		if ($case==='denied'||$case==='read-failure') { mutation_expect_failure($callback,$case==='denied'?'Not_Authorised':'Acl_read_failed'); } else { $callback(); }
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions')===6,'Viewing forum policy never invalidates sessions');
	}
	echo "Coordinated forum permission presets, partial fields, current delegation and cache boundaries passed.\n";
}
finally { restore_error_handler(); }
