<?php
require __DIR__ . '/check-poll-storage.php';
foreach(array('POST_STICKY'=>1,'POST_ANNOUNCE'=>2,'POST_NORMAL'=>0,'AJAX_LOCK_TOPIC'=>7,'LOGS_TABLE'=>'fixture_action_log') as $key=>$value) { if(!defined($key)) { define($key,$value); } }
require $forum_root . 'includes/functions_topic_state.php';
if(!function_exists('decode_ip'))
{
	$source=file_get_contents($forum_root.'includes/functions.php'); $start=strpos($source,'function decode_ip('); $end=strpos($source,'function phpbb_timezone_label(',$start);
	mutation_check($start!==false && $end>$start,'Locate actual legacy IP decoder'); eval(substr($source,$start,$end-$start));
}
foreach(array('Moderation_state_failed','Moderation_state_changed','Moderation_state_denied','Not_Moderator','Lock_topic','Unlock_topic','Reply_to_topic','Topics_Locked','Topics_Unlocked','Topics_Stickyd','Topics_Announced','Topics_Normalised','Click_return_modcp') as $key) { $lang[$key]=$key; }
function topic_state_fixture()
{
	global $mutation_server,$userdata,$client_ip;
	poll_fixture(); $p=$mutation_server->pdo;
	$p->exec('ALTER TABLE fixture_users ADD user_level INTEGER DEFAULT 0');
	$p->exec('ALTER TABLE fixture_users ADD user_active INTEGER DEFAULT 1');
	$userdata['username']="O'Reilly \\' Grüße 😀"; $userdata['session_id']='fixture'; $client_ip='2001:db8::42';
	$p->exec('CREATE TABLE fixture_action_log (mode VARCHAR(20),topic_id INTEGER,user_id INTEGER,username VARCHAR(255),user_ip VARCHAR(45),log_time INTEGER)');
	$p->exec('INSERT INTO fixture_auth (forum_id,group_id,auth_mod) VALUES (3,7,1)');
	$p->exec('INSERT INTO fixture_groups VALUES (8,7,0)');
}
function topic_state_failure($callback,$expected)
{
	$caught=false;
	try { call_user_func($callback); } catch(PhpbbTopicStateException $error) { $caught=$error->getMessage()===$expected; }
	mutation_check($caught,'Expected topic-state failure: '.$expected);
	mutation_check($GLOBALS['mutation_server']->owner===null,'Topic-state failure releases lock');
}
set_error_handler(function($severity,$message) { if(error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	foreach(array('lock'=>array('topic_status',1),'unlock'=>array('topic_status',0),'sticky'=>array('topic_type',1),'announce'=>array('topic_type',2),'normalise'=>array('topic_type',0)) as $action=>$expected)
	{
		topic_state_fixture(); $p=$mutation_server->pdo;
		if($expected[1]===0) { $p->exec('UPDATE fixture_topics SET '.$expected[0].'=1'); }
		$changed=phpbb_moderate_topic_state($db,3,array(100,100),$action);
		mutation_check($changed===array(100) && (int)posting_value('SELECT '.$expected[0].' FROM fixture_topics WHERE topic_id=100')===$expected[1],'Desired state is stored for '.$action);
		mutation_check(posting_value('SELECT username FROM fixture_action_log')===$userdata['username'],'Audit names survive database escaping');
		mutation_check(posting_value('SELECT user_ip FROM fixture_action_log')==='2001:db8::42','Audit log keeps actual IPv6 rather than decoding its 32-bit session hash');
		mutation_check(phpbb_moderate_topic_state($db,3,array(100),$action)===array(),'Repeated state action is idempotent');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===1,'No audit record for a no-op');
	}
	foreach(array('lock','unlock','sticky','announce','normalise') as $action)
	{
		topic_state_fixture(); $mutation_server->pdo->exec('INSERT INTO fixture_topics (topic_id,forum_id,topic_status,topic_type) VALUES (200,4,0,0)');
		topic_state_failure(function() use($db,$action) { phpbb_moderate_topic_state($db,3,array(100,200),$action); },'Moderation_state_changed');
		mutation_check((int)posting_value('SELECT SUM(topic_type)+SUM(topic_status) FROM fixture_topics')===0 && (int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===0,'Cross-forum batch cannot partially mutate or log topics: '.$action);
	}
	foreach(array(
		array('DELETE FROM fixture_groups','Not_Moderator'),
		array('UPDATE fixture_groups SET user_pending=1','Not_Moderator'),
		array('UPDATE fixture_auth SET auth_mod=0','Not_Moderator'),
		array('UPDATE fixture_forums SET auth_read=5','Not_Moderator'),
		array('UPDATE fixture_forums SET auth_view=5','Not_Moderator'),
		array('DELETE FROM fixture_forums','Not_Moderator'),
		array('DELETE FROM fixture_topics','Moderation_state_changed'),
		array('UPDATE fixture_topics SET topic_moved_id=99','Moderation_state_changed'),
		array('UPDATE fixture_topics SET topic_status=2','Moderation_state_changed')
	) as $case)
	{
		topic_state_fixture(); $mutation_server->pdo->exec($case[0]);
		topic_state_failure(function() use($db) { phpbb_moderate_topic_state($db,3,array(100),'lock'); },$case[1]);
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===0,'Denied state has no audit record');
	}
	foreach(array('sticky','announce') as $action)
	{
		topic_state_fixture(); $mutation_server->pdo->exec('UPDATE fixture_forums SET auth_'.$action.'=5');
		topic_state_failure(function() use($db,$action) { phpbb_moderate_topic_state($db,3,array(100),$action); },'Moderation_state_denied');
	}
	topic_state_fixture(); $userdata['session_logged_in']=false;
	topic_state_failure(function() use($db) { phpbb_moderate_topic_state($db,3,array(100),'lock'); },'Not_Moderator');
	foreach(array(array(),array(-1),array(0),array('100x'),array(array(100)),array('9223372036854775808')) as $ids)
	{
		topic_state_fixture(); topic_state_failure(function() use($db,$ids) { phpbb_moderate_topic_state($db,3,$ids,'lock'); },'None_selected');
	}
	topic_state_fixture();
	topic_state_failure(function() use($db) { phpbb_moderate_topic_state($db,null,array(100,200),'lock'); },'None_selected');
	topic_state_failure(function() use($db) { phpbb_moderate_topic_state($db,3,array(100),'delete'); },'None_selected');
	foreach(array('SELECT topic_id','SELECT a.forum_id','UPDATE fixture_topics','INSERT INTO fixture_action_log') as $failure)
	{
		topic_state_fixture(); $mutation_server->failure=$failure;
		topic_state_failure(function() use($db) { phpbb_moderate_topic_state($db,3,array(100),'lock'); },'Moderation_state_failed');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===0,'Failed log is not reported as success');
		mutation_check((int)posting_value('SELECT topic_status FROM fixture_topics WHERE topic_id=100')===($failure==='INSERT INTO fixture_action_log'?1:0),'Partial audit failure is explicit; failed UPDATE does not change state');
	}
	foreach(array('DELETE FROM fixture_topics','UPDATE fixture_topics SET forum_id=4','UPDATE fixture_topics SET topic_moved_id=99','UPDATE fixture_topics SET topic_status=1') as $change)
	{
		topic_state_fixture(); $mutation_server->hook=function($sql) use($change) { if(strpos($sql,'UPDATE fixture_topics')===0) { $GLOBALS['mutation_server']->pdo->exec($change); } };
		topic_state_failure(function() use($db) { phpbb_moderate_topic_state($db,3,array(100),'lock'); },'Moderation_state_changed');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===0,'Changed target is not falsely audited'); $mutation_server->hook=null;
	}
	topic_state_fixture(); $interleaved=false;
	$mutation_server->hook=function($sql) use(&$interleaved)
	{
		if($interleaved || strpos($sql,'UPDATE fixture_topics')!==0) { return; } $interleaved=true;
		$caught=false; try { phpbb_cast_poll_vote($GLOBALS['db'],100,1); } catch(PhpbbPollStorageException $error) { $caught=$error->getMessage()==='busy'; }
		mutation_check($caught,'Vote cannot pass a concurrent lock mutation');
		$caught=false; try { phpbb_moderate_topic_state($GLOBALS['db'],3,array(100),'unlock'); } catch(PhpbbTopicStateException $error) { $caught=$error->getMessage()==='busy'; }
		mutation_check($caught,'Second topic-state writer cannot overlap');
		$caught=false; try { posting_delete(); } catch(MutationFailure $error) { $caught=$error->getMessage()==='busy'; }
		mutation_check($caught,'Topic deletion cannot overlap');
	};
	phpbb_moderate_topic_state($db,3,array(100),'lock'); mutation_check($interleaved,'Actual writer interleaving was exercised'); $mutation_server->hook=null;
	// Execute all actual modcp state branches including their controller catches.
	topic_state_fixture(); $mutation_server->pdo->exec('INSERT INTO fixture_topics (topic_id,forum_id,topic_status,topic_type) VALUES (200,3,0,0)');
	mutation_check(phpbb_moderate_topic_state($db,3,array(200,100),'lock')===array(100,200),'Valid multi-topic selection changes both topics in stable order');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===2,'Each actual change is audited once');
	topic_state_fixture(); $mutation_server->pdo->exec('INSERT INTO fixture_topics (topic_id,forum_id,topic_status,topic_type) VALUES (200,3,0,0)');
	$mutation_server->hook=function($sql)
	{
		if(strpos($sql,'UPDATE fixture_topics')===0 && strpos($sql,'topic_id = 200')!==false) { $GLOBALS['mutation_server']->failure='UPDATE fixture_topics'; }
	};
	topic_state_failure(function() use($db) { phpbb_moderate_topic_state($db,3,array(100,200),'lock'); },'Moderation_state_failed');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===1 && (int)posting_value('SELECT topic_id FROM fixture_action_log')===100,'A later failure retains an accurate audit of the completed topic only');
	topic_state_fixture();
	$mutation_server->hook=function($sql)
	{
		if(strpos($sql,'SELECT a.forum_id')===0) { $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_topics SET topic_status=1'); }
	};
	topic_state_failure(function() use($db) { phpbb_moderate_topic_state($db,3,array(100),'unlock'); },'Moderation_state_changed');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===0,'A stale no-op does not claim or audit an unlock');
	topic_state_fixture(); unset($client_ip);
	phpbb_moderate_topic_state($db,3,array(100),'lock');
	mutation_check(posting_value('SELECT user_ip FROM fixture_action_log')==='127.0.0.1','Legacy callers without client_ip retain the IPv4 fallback');
	$modcp=file_get_contents($forum_root.'modcp.php'); $major=strpos($modcp,'// Do major work');
	$start=strpos($modcp,"\tcase 'lock':",$major); $end=strpos($modcp,"\tcase 'split':",$start);
	mutation_check($start!==false && $end>$start,'Locate actual modcp state branches');
	$branches="switch(\$mode) {".substr($modcp,$start,$end-$start)."}";
	foreach(array('lock','unlock','sticky','announce','normalise') as $mode)
	{
		topic_state_fixture(); $template=new PollTemplateFixture(); $topic_id=100; $forum_id=3; $topic_id_list=array(100,200); $is_auth=array('auth_sticky'=>true,'auth_announce'=>true);
		$mutation_server->pdo->exec('INSERT INTO fixture_topics (topic_id,forum_id,topic_status,topic_type) VALUES (200,4,0,0)');
		$caught=false; try { eval($branches); } catch(MutationFailure $error) { $caught=$error->getMessage()==='Moderation_state_changed'; }
		mutation_check($caught && (int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===0,'Actual controller rejects foreign batch: '.$mode);
		$topic_id_list=array(100); $completed=false; try { eval($branches); } catch(MutationFailure $error) { $completed=strpos($error->getMessage(),'Topics_')===0; }
		mutation_check($completed,'Actual modcp controller keeps its success response: '.$mode);
	}
	$ajax=file_get_contents($forum_root.'ajax.php'); $start=strpos($ajax,"else if (\$mode == 'lock_topic')"); $end=strpos($ajax,"else if (\$mode == 'mark_topic')",$start);
	mutation_check($start!==false && $end>$start,'Locate actual AJAX state controller'); $branch=substr($ajax,$start+5,$end-$start-5);
	topic_state_fixture(); $mode='lock_topic'; $HTTP_GET_VARS=array(); $HTTP_POST_VARS=array('t'=>100,'lock_status'=>1);
	$images=array('topic_mod_lock'=>'lock.gif','topic_mod_unlock'=>'unlock.gif','reply_new'=>'reply.gif','reply_locked'=>'locked.gif');
	foreach(array(1,1,0) as $desired)
	{
		$HTTP_POST_VARS['lock_status']=$desired; $response=null; try { eval($branch); } catch(AjaxFixtureResponse $reply) { $response=$reply->value; }
		mutation_check($response['result']===AJAX_LOCK_TOPIC && $response['locked']===$desired && strpos($response['linkurl'],'mod_token=')!==false,'Actual AJAX response matches desired state and keeps signed fallback');
	}
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===2,'AJAX logs actual lock and unlock only');
	foreach(array('',2,'1x',array(1)) as $invalid)
	{
		$HTTP_POST_VARS['lock_status']=$invalid; $response=null;
		try { eval($branch); } catch(AjaxFixtureResponse $reply) { $response=$reply->value; }
		mutation_check($response['result']===AJAX_ERROR && (int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===2,'Malformed desired state cannot implicitly unlock');
	}
	$HTTP_POST_VARS['lock_status']=1;
	$mutation_server->pdo->exec('DELETE FROM fixture_groups'); $response=null;
	try { eval($branch); } catch(AjaxFixtureResponse $reply) { $response=$reply->value; }
	mutation_check($response['result']===AJAX_ERROR && $response['error_msg']==='Not_Moderator','Revoked AJAX moderator receives a controlled error');
	echo "Moderator state scope, current ACLs, shared locks and action audit checks passed.\n";
}
finally
{
	if(isset($mutation_server) && $mutation_server->owner!==null) { $mutation_server->owner->sql_close(); }
	restore_error_handler();
}
