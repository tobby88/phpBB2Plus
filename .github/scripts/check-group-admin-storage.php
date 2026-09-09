<?php
require __DIR__ . '/check-group-storage.php';
foreach (array('IN_ADMIN'=>true,'JR_ADMIN_TABLE'=>'fixture_junior','QUOTA_TABLE'=>'fixture_quotas','QUOTA_LIMITS_TABLE'=>'fixture_limits','QUOTA_UPLOAD_LIMIT'=>1,'QUOTA_PM_LIMIT'=>2,'PA_AUTH_ACCESS_TABLE'=>'fixture_pa_auth') as $key=>$value) { if (!defined($key)) { define($key,$value); } }
require $forum_root . 'includes/functions_group_admin_storage.php';
function ga_fixture($actor=1)
{
	global $mutation_server,$userdata;
	group_fixture($actor); $p=$mutation_server->pdo; $userdata['session_admin']=true;
	$p->exec('DROP TABLE fixture_groups');
	$p->exec('CREATE TABLE fixture_groups (group_id INTEGER PRIMARY KEY AUTOINCREMENT,group_type INTEGER,group_single_user INTEGER,group_moderator INTEGER,group_name VARCHAR(255),group_description VARCHAR(255),group_color_group INTEGER DEFAULT 0)');
	$p->exec("INSERT INTO fixture_groups VALUES (3,0,0,8,'Test','Description',7),(4,0,1,9,'Personal','Personal',0),(7,0,0,1,'Other','Other',0)");
	$p->exec('CREATE TABLE fixture_junior (user_id INTEGER,user_jr_admin VARCHAR(255))');
	$p->exec('CREATE TABLE fixture_quotas (user_id INTEGER,group_id INTEGER,quota_type INTEGER,quota_limit_id INTEGER)');
	$p->exec('INSERT INTO fixture_quotas VALUES (0,3,1,2),(0,7,1,2),(9,0,1,2)');
	$p->exec('CREATE TABLE fixture_limits (quota_limit_id INTEGER PRIMARY KEY)'); $p->exec('INSERT INTO fixture_limits VALUES (2),(5)');
	$p->exec('CREATE TABLE fixture_pa_auth (group_id INTEGER,cat_id INTEGER)'); $p->exec('INSERT INTO fixture_pa_auth VALUES (3,1),(7,1)');
	$p->exec('UPDATE fixture_users SET user_level=2 WHERE user_id IN (9,12)');
}
function ga_post($extra=array()) { return array_merge(array('mode'=>'editgroup','g'=>'3','sid'=>'fixture-session','group_name'=>addslashes("Grüße O'Brien"),'group_description'=>addslashes('Über uns & alle'),'username'=>'Pending','group_type'=>'1'),$extra); }
function ga_run($extra=array()) { return phpbb_group_admin_save($GLOBALS['db'],ga_post($extra)); }
function cache_tree($refresh) { mutation_check($refresh && $GLOBALS['mutation_server']->owner===null,'Group administration refreshes hierarchy only after release'); $GLOBALS['ga_cache_refreshes']++; }
function phpbb_admin_require_post_session() { mutation_check($_SERVER['REQUEST_METHOD']==='POST' && $_POST['sid']===$GLOBALS['userdata']['session_id'],'Controller checks ACP POST session'); }
class GaViewDatabase extends GroupControllerReadDatabase
{
	function sql_fetchrowset($r) { return $r->fetchAll(PDO::FETCH_ASSOC); }
	function sql_escape($value) { return substr($GLOBALS['mutation_server']->pdo->quote($value),1,-1); }
}
set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	ga_fixture(); mutation_check(ga_run()==='Updated_group','Save reports update');
	mutation_check(group_value('SELECT user_pending FROM fixture_memberships WHERE group_id=3 AND user_id=10')===0 && group_value('SELECT user_level FROM fixture_users WHERE user_id=10')===2,'Pending leader approved and actual moderator role derived');
	mutation_check(group_value('SELECT group_moderator FROM fixture_groups WHERE group_id=3')===10 && group_value('SELECT group_color_group FROM fixture_groups WHERE group_id=3')===7,'Leader updated without losing group color assignment');
	mutation_check($mutation_server->pdo->query('SELECT group_name FROM fixture_groups WHERE group_id=3')->fetchColumn()==="Grüße O'Brien",'Unicode and quotes preserved exactly');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_memberships WHERE user_id=8 AND group_id=3')===1,'Old leader retained unless removal requested');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions WHERE session_user_id IN (1,11,12)')===3 && group_value('SELECT COUNT(*) FROM fixture_sessions WHERE session_user_id IN (8,9,10)')===0,'Only affected other members sessions expired');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_quotas')===3,'Absent quota fields preserve existing assignments');
	foreach (array('none','pending','approved','orphan','admin') as $case)
	{
		ga_fixture();
		if ($case==='pending'||$case==='approved'||$case==='orphan') { $mutation_server->pdo->exec('INSERT INTO fixture_memberships VALUES (8,7,'.($case==='pending'?1:0).')'); }
		if ($case==='orphan') { $mutation_server->pdo->exec('DELETE FROM fixture_forums'); }
		if ($case==='admin') { $mutation_server->hook=function($sql) { if (strpos($sql,'UPDATE fixture_users')===0) { $GLOBALS['mutation_server']->hook=null; $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=8'); } }; }
		ga_run(array('delete_old_moderator'=>'on'));
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_memberships WHERE group_id=3 AND user_id=8')===0,'Explicit old leader removal: '.$case);
		mutation_check(group_value('SELECT user_level FROM fixture_users WHERE user_id=8')===($case==='admin'?1:($case==='approved'?2:0)),'Old leader role uses approved existing forum groups, preserves late administrator: '.$case);
	}
	ga_fixture(); $mutation_server->pdo->exec('DELETE FROM fixture_memberships WHERE user_id=8 AND group_id=3');
	ga_run(array('username'=>'Leader')); mutation_check(group_value('SELECT COUNT(*) FROM fixture_memberships WHERE user_id=8 AND group_id=3 AND user_pending=0')===1,'Unchanged leader missing membership repaired');
	ga_fixture(); ga_run(array('username'=>addslashes("O'Brien"))); mutation_check(group_value('SELECT group_moderator FROM fixture_groups WHERE group_id=3')===12,'Quoted leader lookup');
	ga_fixture(); $mutation_server->pdo->exec("UPDATE fixture_users SET username='O&#039;Brien' WHERE user_id=12"); ga_run(array('username'=>addslashes("O'Brien")));
	mutation_check(group_value('SELECT group_moderator FROM fixture_groups WHERE group_id=3')===12,'Legacy entity leader lookup');
	$mutation_server->pdo->exec("UPDATE fixture_users SET username='O''Brien' WHERE user_id=11");
	group_failure(function() { ga_run(array('username'=>addslashes("O'Brien"))); },'No_group_moderator');
	ga_fixture(); ga_run(array('group_upload_quota'=>'5','group_pm_quota'=>'2'));
	mutation_check(group_value('SELECT quota_limit_id FROM fixture_quotas WHERE group_id=3 AND quota_type=1')===5 && group_value('SELECT quota_limit_id FROM fixture_quotas WHERE group_id=3 AND quota_type=2')===2,'Both quota assignments inserted/updated');
	ga_run(array('group_upload_quota'=>'0')); mutation_check(group_value('SELECT COUNT(*) FROM fixture_quotas WHERE group_id=3 AND quota_type=1')===0 && group_value('SELECT COUNT(*) FROM fixture_limits')===2,'Zero clears assignment, never quota definition');
	ga_fixture(); mutation_check(ga_run(array('mode'=>'newgroup'))==='Added_new_group','Create group supported');
	$id=group_value('SELECT MAX(group_id) FROM fixture_groups');
	mutation_check($id>7 && group_value('SELECT COUNT(*) FROM fixture_memberships WHERE group_id='.$id.' AND user_id=10 AND user_pending=0')===1,'New group has approved leader');
	ga_fixture(); $mutation_server->failure='INSERT INTO fixture_memberships';
	group_failure(function() { ga_run(array('mode'=>'newgroup')); },'Group_storage_failed');
	$id=group_value('SELECT MAX(group_id) FROM fixture_groups'); $mutation_server->failure='';
	ga_run(array('g'=>$id)); mutation_check(group_value('SELECT COUNT(*) FROM fixture_memberships WHERE group_id='.$id.' AND user_id=10 AND user_pending=0')===1,'Partially created group can be repaired through ordinary edit');
	foreach (array('none','pending','approved','orphan','admin') as $case)
	{
		ga_fixture();
		if ($case==='pending'||$case==='approved'||$case==='orphan') { $mutation_server->pdo->exec('INSERT INTO fixture_memberships VALUES (9,7,'.($case==='pending'?1:0).')'); }
		if ($case==='orphan') { $mutation_server->pdo->exec('DELETE FROM fixture_forums'); }
		if ($case==='admin') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=9'); }
		mutation_check(ga_run(array('group_delete'=>'on'))==='Deleted_group','Delete group succeeds');
		foreach (array('fixture_groups','fixture_memberships','fixture_auth','fixture_pa_auth','fixture_quotas') as $table) { mutation_check(group_value('SELECT COUNT(*) FROM '.$table.' WHERE group_id=3')===0,'Remove only target dependents: '.$table); }
		mutation_check(group_value('SELECT user_level FROM fixture_users WHERE user_id=9')===($case==='admin'?1:($case==='approved'?2:0)),'Deletion preserves only real moderator memberships/admin status: '.$case);
		mutation_check(group_value('SELECT user_level FROM fixture_users WHERE user_id=12')===2 && group_value('SELECT COUNT(*) FROM fixture_memberships WHERE group_id=4')===1,'No unrelated global role sweep or personal membership removal');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_pa_auth WHERE group_id=7')===1 && group_value('SELECT COUNT(*) FROM fixture_quotas WHERE group_id IN (0,7)')===2,'Other groups and individual quotas preserved');
	}
	foreach (array(4,999,'3oops',array(3),true,0,16777216) as $bad)
	{
		ga_fixture(); group_failure(function() use($bad) { ga_run(array('g'=>$bad,'group_delete'=>'on')); },($bad===4||$bad===999)?'Group_not_exist':'Group_invalid_selection');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions')===6,'Invalid target has no writes');
	}
	foreach (array(array('mode'=>array('editgroup')),array('group_name'=>array('x')),array('group_name'=>str_repeat('ä',41)),array('group_description'=>str_repeat('ä',256)),array('group_name'=>"bad\0name"),array('group_name'=>"bad\xff"),array('username'=>array('Pending')),array('group_type'=>'1oops'),array('group_pm_quota'=>'2oops'),array('group_upload_quota'=>999)) as $bad)
	{
		ga_fixture(); $caught=false; try { ga_run($bad); } catch (PhpbbGroupException $e) { $caught=true; }
		mutation_check($caught && $mutation_server->owner===null && group_value('SELECT COUNT(*) FROM fixture_sessions')===6,'Malformed original form rejected before writes');
	}
	foreach (array('get','bad-sid','nested-sid','no-acp','inactive','deleted','demoted','inactive-leader') as $case)
	{
		ga_fixture(); $post=ga_post(); $expected='Not_Authorised';
		if ($case==='get') { $_SERVER['REQUEST_METHOD']='GET'; $expected='Session_invalid'; }
		if ($case==='bad-sid'||$case==='nested-sid') { $post['sid']=$case==='bad-sid'?'wrong':array('fixture-session'); $expected='Session_invalid'; }
		if ($case==='no-acp') { $userdata['session_admin']=false; }
		if ($case==='inactive') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1'); }
		if ($case==='deleted') { $mutation_server->pdo->exec('DELETE FROM fixture_users WHERE user_id=1'); }
		if ($case==='demoted') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1'); }
		if ($case==='inactive-leader') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=10'); $expected='No_group_moderator'; }
		group_failure(function() use($db,$post) { phpbb_group_admin_save($db,$post); },$expected);
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions')===6,'Stale actor or bad session has no writes: '.$case);
	}
	ga_fixture(8); $hash=md5('GroupsManageadmin_groups.php'); $mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".$hash."')");
	ga_run(); $mutation_server->pdo->exec("UPDATE fixture_junior SET user_jr_admin='".md5('GroupsPermissionsadmin_ug_auth.php?mode=group')."'");
	group_failure(function() { ga_run(); },'Not_Authorised');
	foreach (array('edit','delete','new') as $action)
	{
		ga_fixture(8); $mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".$hash."')");
		$mutation_server->hook=function($sql) {
			if (strpos($sql,'DELETE FROM fixture_sessions')===0) { $GLOBALS['mutation_server']->hook=null; $GLOBALS['mutation_server']->pdo->exec('DELETE FROM fixture_junior'); }
		};
		$extra=$action==='delete'?array('group_delete'=>'on'):($action==='new'?array('mode'=>'newgroup'):array());
		group_failure(function() use($extra) { ga_run($extra); },'Group_storage_failed');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions')===6 && group_value('SELECT COUNT(*) FROM fixture_groups')===3 && group_value('SELECT COUNT(*) FROM fixture_auth WHERE group_id=3')===1,'Late delegation revocation guards each actual write: '.$action);
	}
	ga_fixture(); ga_run();
	$mutation_server->hook=function($sql) {
		if (strpos($sql,'INSERT INTO fixture_quotas')===0) { $GLOBALS['mutation_server']->hook=null; $GLOBALS['mutation_server']->pdo->exec('DELETE FROM fixture_limits WHERE quota_limit_id=5'); }
	};
	group_failure(function() { ga_run(array('group_upload_quota'=>5)); },'Group_storage_failed');
	foreach (array('actor','leader','personal','deleted','quota') as $case)
	{
		ga_fixture(); $mutation_server->hook=function($sql) use($case) {
			if (strpos($sql,'INSERT INTO fixture_memberships')===0) {
				$GLOBALS['mutation_server']->hook=null;
				$queries=array('actor'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=1','leader'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=10','personal'=>'UPDATE fixture_groups SET group_single_user=1 WHERE group_id=3','deleted'=>'DELETE FROM fixture_groups WHERE group_id=3','quota'=>'DELETE FROM fixture_limits WHERE quota_limit_id=5');
				$GLOBALS['mutation_server']->pdo->exec($queries[$case]);
			}
		};
		group_failure(function() { ga_run(array('group_upload_quota'=>5)); },'Group_storage_failed');
		mutation_check(group_value('SELECT user_pending FROM fixture_memberships WHERE group_id=3 AND user_id=10')===1,'Late policy change prevents stale approval: '.$case);
	}
	foreach (array('DELETE FROM fixture_sessions','INSERT INTO fixture_memberships','UPDATE fixture_memberships','UPDATE fixture_users','UPDATE fixture_groups') as $failure)
	{
		ga_fixture(); $mutation_server->failure=$failure; group_failure(function() { ga_run(); },'Group_storage_failed');
		mutation_check(group_value('SELECT group_moderator FROM fixture_groups WHERE group_id=3')===8,'Failed save does not publish leadership before approved membership and role: '.$failure);
	}
	foreach (array('DELETE FROM fixture_auth','DELETE FROM fixture_pa_auth','DELETE FROM fixture_quotas','UPDATE fixture_users','DELETE FROM fixture_memberships','DELETE FROM fixture_groups') as $failure)
	{
		ga_fixture(); $mutation_server->failure=$failure; group_failure(function() { ga_run(array('group_delete'=>'on')); },'Group_storage_failed');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_groups WHERE group_id=3')===1,'Failed MyISAM deletion leaves group visible for retry');
		$mutation_server->failure=''; ga_run(array('group_delete'=>'on'));
		mutation_check(group_value('SELECT user_level FROM fixture_users WHERE user_id=9')===0,'Retry finishes role cleanup even after dependent failure');
	}
	ga_fixture(); $owner=new attach_mutation_lock($db); $caught=false;
	try { ga_run(); } catch (PhpbbGroupException $e) { $caught=$e->getMessage()==='busy'; }
	mutation_check($caught && $mutation_server->owner===$owner->connection,'Contending writer cannot enter group administration'); $owner->release();
	// Execute actual controller submission and its read-only opening guard.
	$source=file_get_contents($forum_root.'admin/admin_groups.php');
	$begin=strpos($source,"else if ( isset(\$_POST['group_update']) )"); $end=strpos($source,"\nelse\n{\n\t\$sql = \"SELECT group_id, group_name",$begin);
	mutation_check($begin!==false && $end!==false,'Actual controller mutation branch found');
	$body=substr($source,$begin,$end-$begin); $body=substr($body,strpos($body,'{')+1); $body=substr($body,0,strrpos($body,'}'));
	foreach (array('Updated_group','Added_new_group','Deleted_group','Click_return_groupsadmin','Click_return_admin_index') as $key) { $lang[$key]=$key; }
	ga_fixture(); $_POST=ga_post(array('group_update'=>'1')); $ga_cache_refreshes=0; $caught=false;
	try { eval($body); } catch (MutationFailure $e) { $caught=strpos($e->getMessage(),'Updated_group')===0; }
	mutation_check($caught && $ga_cache_refreshes===1 && group_value('SELECT group_moderator FROM fixture_groups WHERE group_id=3')===10,'Actual controller delegates storage and refreshes after release');
	eval(group_test_function(file_get_contents($forum_root.'admin/pagestart.php'),'phpbb_admin_html'));
	ga_fixture(); $_POST=ga_post(array('mode'=>array('editgroup'))); $ga_cache_refreshes=0;
	mutation_expect_failure(function() use($body,$db,$lang,$phpEx) { eval($body); },'No_group_action');
	mutation_check($ga_cache_refreshes===0 && group_value('SELECT COUNT(*) FROM fixture_sessions')===6,'Actual controller rejects original nested mode before writes/cache refresh');
	ga_fixture(8); $read=new GaViewDatabase(); group_failure(function() use($read) { phpbb_group_admin_actor(new PhpbbGroupDatabase($read)); },'Not_Authorised');
	$mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".$hash."')"); mutation_check(phpbb_group_admin_actor(new PhpbbGroupDatabase($read))['user_id']==8,'Current delegated group manager can view');
	$begin=strpos($source,"if (\$group_info['group_moderator'] != '')"); $end=strpos($source,"\n\t\$group_open =",$begin);
	mutation_check($begin!==false && $end!==false,'Actual leader display branch found');
	$group_info=array('group_moderator'=>999); $saved_db=$db; $db=$read; eval(substr($source,$begin,$end-$begin)); $db=$saved_db;
	mutation_check($group_moderator==='','Missing former leader no longer blocks repair form');
	echo "Coordinated group administration checks passed.\n";
}
finally { restore_error_handler(); }
