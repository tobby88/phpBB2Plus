<?php
require __DIR__ . '/check-user-removal-storage.php';
require_once $forum_root.'includes/functions_user_prune.php';
require $forum_root.'language/lang_english/lang_prune_users.php';
$lang['Confirm']='Confirm'; $lang['Removal_jobs_unavailable']='Unavailable'; $lang['Session_invalid']='Session_invalid';
function prune_fixture($actor=1)
{
	removal_fixture($actor); $p=$GLOBALS['mutation_server']->pdo;
	$GLOBALS['userdata']['session_admin']=false;
	$GLOBALS['userdata']['username']='Root';
	$GLOBALS['board_config']=array('smtp_delivery'=>0,'board_email'=>'board@example.invalid','sitename'=>'Fixture');
	foreach(array('user_posts INTEGER DEFAULT 0','user_lastvisit INTEGER DEFAULT 0',"user_actkey VARCHAR(32) DEFAULT 'pending'") as $field) { $p->exec('ALTER TABLE fixture_users ADD '.$field); }
	$p->query('SELECT 1')->closeCursor(); // Flush batched native fixture DDL.
	$GLOBALS['prune_mail_count']=0; $GLOBALS['prune_mail_fail']=false;
}
function prune_policy_fixture($mode='prune_0') { return array('mode'=>$mode,'days'=>240,'at'=>time()); }
function prune_run($post=array(),$mode='prune_0')
{
	if(!$post) { return phpbb_prune_user_remove($GLOBALS['db'],array('sid'=>'fixture-session','confirm'=>'1'),9,prune_policy_fixture($mode)); }
	return phpbb_prune_user_remove($GLOBALS['db'],array_merge(array('sid'=>'fixture-session'),$post));
}
class emailer
{
	function __construct($smtp,$optional=false) { mutation_check($optional,'Pruning mail uses optional delivery failure boundary'); }
	function from($value) {} function replyto($value) {} function email_address($value) { mutation_check($value==='member@example.invalid','Only captured removed account is notified'); }
	function use_template($name,$language) { mutation_check($name==='delete_users' && ($language==='' || $language==='english'),'Mail template path is constrained'); }
	function assign_vars($values) { mutation_check($values['USERNAME']==="O'Brien Grüße",'Notification preserves captured Unicode identity'); }
	function send() { mutation_check($GLOBALS['mutation_server']->owner===null && group_value('SELECT COUNT(*) FROM fixture_user_removals')===0,'Mail handoff occurs after durable completion and lock release'); $GLOBALS['prune_mail_count']++; if($GLOBALS['prune_mail_fail']) { throw new RuntimeException('transport failure'); } return true; }
	function reset() {}
}
set_error_handler(function($severity,$message) { if(error_reporting()&$severity) { throw new RuntimeException($message); } });
try
{
	foreach(array('user_id','prune_0','prune_1','prune_2','prune_3','prune_4') as $mode)
	{
		prune_fixture(); if($mode==='prune_4') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_lastvisit=864101,user_posts=1 WHERE user_id=9'); }
		$result=prune_run(array(),$mode); mutation_check($result['status']==='Removal_completed' && $result['email']==='member@example.invalid','Prune mode completes with captured notification: '.$mode); removal_assert_completed();
		mutation_check(phpbb_prune_notify($result) && $prune_mail_count===1,'One optional notification after completion');
	}
	foreach(array('zero_poster'=>'prune_0','not_login'=>'prune_1') as $alias=>$mode) { mutation_check(phpbb_prune_policy(array('mode'=>$alias,'days'=>'0240'))['mode']===$mode,'Legacy selection alias preserved'); }
	foreach(array(null,array(),array('mode'=>array('prune_0'),'days'=>240),array('mode'=>'unknown','days'=>240),array('mode'=>'prune_0','days'=>'1garbage'),array('mode'=>'prune_0','days'=>0),array('mode'=>'prune_0','days'=>36501),array('mode'=>'prune_0','days'=>array(240))) as $bad)
	{
		prune_fixture(); removal_failure(function() use($bad) { phpbb_prune_policy($bad); },'Removal_invalid'); mutation_check(group_value('SELECT COUNT(*) FROM fixture_user_removals')===0,'Invalid criteria have no writes');
	}
	foreach(array('prune_0'=>'user_posts=1','prune_1'=>'user_lastvisit=1','prune_2'=>"user_actkey=''",'prune_3'=>'user_lastvisit='.time(),'prune_4'=>'user_lastvisit=user_regdate') as $mode=>$change)
	{
		prune_fixture(); $mutation_server->pdo->exec('UPDATE fixture_users SET '.$change.' WHERE user_id=9');
		mutation_check(prune_run(array(),$mode)['status']==='Prune_skipped' && group_value('SELECT COUNT(*) FROM fixture_users WHERE user_id=9')===1 && group_value('SELECT COUNT(*) FROM fixture_user_removals')===0,'Ineligible candidate skipped without journal/content writes: '.$mode);
	}
	prune_fixture(); $policy=prune_policy_fixture(); $mutation_server->pdo->exec('UPDATE fixture_users SET user_regdate='.($policy['at']-86400*240).' WHERE user_id=9');
	mutation_check(phpbb_prune_user_remove($db,array('sid'=>'fixture-session','confirm'=>1),9,$policy)['status']==='Prune_skipped','Registration cutoff is strict');
	foreach(array('demoted','inactive','delegated','session','get','bad-sid') as $case)
	{
		prune_fixture($case==='delegated'?8:1);
		if($case==='demoted') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1'); }
		if($case==='inactive') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1'); }
		if($case==='delegated') { $mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".md5('UsersPrune_usersadmin_prune_users.php')."')"); }
		if($case==='session') { $userdata['session_logged_in']=false; }
		if($case==='get') { $_SERVER['REQUEST_METHOD']='GET'; }
		if($case==='bad-sid') { $userdata['session_id']='changed'; }
		removal_failure(function() { prune_run(); },in_array($case,array('get','bad-sid'),true)?'Session_invalid':'Not_Authorised'); mutation_check(group_value('SELECT COUNT(*) FROM fixture_user_removals')===0,'Invalid actor/session cannot prepare a job');
	}
	foreach(array('DELETE FROM fixture_keys','DELETE FROM fixture_watches','UPDATE fixture_posts','UPDATE fixture_shouts','UPDATE fixture_groups','DELETE FROM fixture_messages','DELETE FROM fixture_pm_text','DELETE FROM fixture_links','DELETE FROM fixture_descriptions','DELETE FROM fixture_auth','DELETE FROM fixture_groups',"UPDATE fixture_user_removals SET removal_state = 'complete'",'DELETE FROM fixture_user_removals') as $failure)
	{
		prune_fixture(); $mutation_server->failure=$failure; removal_failure(function() { prune_run(); },'Removal_storage_failed'); $job=removal_job();
		mutation_check(is_string($job) && $prune_mail_count===0,'Partial failure retains intent and sends no mail');
		$mutation_server->failure=''; $result=prune_run(array('removal_resume'=>$job)); mutation_check($result['status']==='Removal_completed','Missing account cleanup resumes: '.$failure); removal_assert_completed();
		phpbb_prune_notify($result); mutation_check($prune_mail_count<=1,'Recovery makes at most one notification handoff');
		removal_failure(function() use($job) { prune_run(array('removal_resume'=>$job)); },'Removal_invalid'); mutation_check($prune_mail_count<=1,'Replayed token cannot trigger notification again');
	}
	prune_fixture(); $mutation_server->failure="UPDATE fixture_user_removals SET removal_state = 'removing'"; removal_failure(function() { prune_run(); },'Removal_storage_failed'); $job=removal_job();
	$policy_before=$mutation_server->pdo->query("SELECT item_name FROM fixture_user_removal_items WHERE item_type='prune_policy'")->fetchColumn();
	$mutation_server->failure=''; $mutation_server->pdo->exec('UPDATE fixture_users SET user_posts=1 WHERE user_id=9');
	removal_failure(function() use($job) { prune_run(array('removal_resume'=>$job)); },'Removal_account_changed');
	mutation_check($mutation_server->pdo->query("SELECT item_name FROM fixture_user_removal_items WHERE item_type='prune_policy'")->fetchColumn()===$policy_before,'Resume retains original criteria');
	removal_failure(function() use($job) { prune_run(array('removal_resume'=>$job,'mode'=>'user_id')); },'Removal_invalid');
	mutation_check(prune_run(array('removal_cancel'=>$job))==='Removal_cancelled','Prepared outdated selection can be discarded without content writes');
	prune_fixture(); $mutation_server->failure='DELETE FROM fixture_users'; removal_failure(function() { prune_run(); },'Removal_storage_failed'); $job=removal_job(); $mutation_server->failure='';
	removal_failure(function() use($job) { prune_run(array('removal_resume'=>$job)); },'Removal_account_changed'); mutation_check(group_value('SELECT COUNT(*) FROM fixture_messages')===7,'Ambiguous claim never deletes account again or changes mail');
	foreach(array('eligibility','actor-before','actor-after','restored') as $case)
	{
		prune_fixture(); $mutation_server->hook=function($sql) use($case) {
			if(strpos($sql,in_array($case,array('eligibility','actor-before'),true)?'DELETE FROM fixture_users':'DELETE FROM fixture_keys')===0) {
				$GLOBALS['mutation_server']->hook=null; $p=$GLOBALS['mutation_server']->pdo;
				if($case==='eligibility') { $p->exec('UPDATE fixture_users SET user_posts=1 WHERE user_id=9'); }
				elseif($case==='restored') { $p->exec("INSERT INTO fixture_users (user_id,user_level,user_active,username,user_email,user_lang) VALUES (9,0,1,'Restored','','english')"); }
				else { $p->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1'); }
			}
		};
		removal_failure(function() { prune_run(); },'Removal_account_changed'); mutation_check(group_value('SELECT COUNT(*) FROM fixture_posts WHERE poster_id=9')===1 && group_value('SELECT COUNT(*) FROM fixture_messages')===7 && $prune_mail_count===0,'Fresh guards stop every destructive phase: '.$case);
	}
	prune_fixture(); $mutation_server->failure='DELETE FROM fixture_keys'; removal_failure(function() { prune_run(); },'Removal_storage_failed'); $job=removal_job(); $mutation_server->failure='';
	$phpbb_root_path='../'; $html=phpbb_prune_pending_html(new RemovalReader());
	mutation_check(strpos($html,'../delete_users.php')!==false && strpos($html,'name="removal_resume"')!==false && strpos($html,'name="removal_cancel"')===false && strpos($html,"O&#039;Brien Grüße")!==false,'ACP recovery UI is escaped and never offers discard of missing-account cleanup');
	removal_failure(function() use($job) { phpbb_user_remove($GLOBALS['db'],array('sid'=>'fixture-session','removal_resume'=>$job),'user'); },'Not_Authorised');
	$userdata['session_admin']=true; removal_failure(function() use($job) { phpbb_user_remove($GLOBALS['db'],array('sid'=>'fixture-session','removal_resume'=>$job),'user'); },'Removal_invalid');
	$result=prune_run(array('removal_resume'=>$job)); $prune_mail_fail=true; mutation_check(!phpbb_prune_notify($result) && $prune_mail_count===1,'Mail failure is reported without undoing cleanup or retrying deletion');
	$controller=file_get_contents($forum_root.'delete_users.php');
	$begin=strpos($controller,"\$messages = ''; \$deleted_users = 0;"); $end=strpos($controller,'message_die(GENERAL_MESSAGE, $messages',$begin);
	mutation_check($begin!==false && $end>$begin,'Actual controller execution body located'); $body=substr($controller,$begin,$end-$begin);
	foreach(array('confirm','bad-sid','preview','resume') as $case)
	{
		prune_fixture(); $reader=new RemovalReader(); $post=array('mode'=>'user_id','del_user'=>'9','sid'=>'fixture-session','confirm'=>1); $get=array();
		if($case==='bad-sid') { $post['sid']='bad'; }
		if($case==='preview') { $_SERVER['REQUEST_METHOD']='GET'; $get=$post; $post=array(); }
		if($case==='resume') { $mutation_server->failure='DELETE FROM fixture_keys'; removal_failure(function() { prune_run(); },'Removal_storage_failed'); $post=array('sid'=>'fixture-session','removal_resume'=>removal_job()); $mutation_server->failure=''; }
		$_POST=$post; $_GET=$get;
		try { $messages=call_user_func(function() use($body,$reader) { global $lang,$userdata,$phpEx; $phpbb_root_path='./'; $db=$reader; eval($body); return $messages; }); }
		finally { set_time_limit(0); } // Controller deadline must not cover the next isolated fixture's DDL.
		if($case==='confirm'||$case==='resume') { mutation_check(group_value('SELECT COUNT(*) FROM fixture_users WHERE user_id=9')===0 && $prune_mail_count===1,'Actual confirmed/recovery controller delegates lifecycle and sends once'); }
		else { mutation_check(group_value('SELECT COUNT(*) FROM fixture_users WHERE user_id=9')===1 && group_value('SELECT COUNT(*) FROM fixture_user_removals')===0 && $prune_mail_count===0,'Actual preview/invalid-token controller cannot delete or send'); }
	}
	echo "Durable standalone pruning, recovery, selection and notification checks passed.\n";
}
finally { removal_clear_files(); restore_error_handler(); }
