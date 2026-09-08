<?php
date_default_timezone_set('UTC');
require __DIR__ . '/check-ajax-edit-storage.php';
foreach(array('POST_ANNOUNCE'=>2,'POST_GLOBAL_ANNOUNCE'=>3,'CONFIG_TABLE'=>'fixture_config','PRUNE_TABLE'=>'fixture_prune','JR_ADMIN_TABLE'=>'fixture_junior') as $key=>$value) { define($key,$value); }
require $forum_root.'includes/functions_prune_storage.php';
function prune_fixture()
{
	global $mutation_server,$userdata;
	ajax_storage_fixture(); $p=$mutation_server->pdo;
	$p->exec('ALTER TABLE fixture_users ADD user_level INTEGER DEFAULT 0');
	$p->exec('ALTER TABLE fixture_users ADD user_active INTEGER DEFAULT 1');
	$p->exec('UPDATE fixture_users SET user_level=1,user_posts=10 WHERE user_id=8');
	$p->exec("ALTER TABLE fixture_forums ADD forum_link VARCHAR(255) DEFAULT ''");
	$p->exec('ALTER TABLE fixture_forums ADD prune_enable INTEGER DEFAULT 1');
	$p->exec('ALTER TABLE fixture_forums ADD prune_next INTEGER DEFAULT 0');
	$p->exec('CREATE TABLE fixture_config (config_name VARCHAR(255), config_value VARCHAR(255))');
	$p->exec("INSERT INTO fixture_config VALUES ('prune_enable','1')");
	$p->exec('CREATE TABLE fixture_prune (prune_id INTEGER, forum_id INTEGER, prune_days INTEGER, prune_freq INTEGER)');
	$p->exec('INSERT INTO fixture_prune VALUES (1,3,7,1)');
	$p->exec('CREATE TABLE fixture_junior (user_id INTEGER,user_jr_admin TEXT)');
	$p->exec('INSERT INTO fixture_auth (forum_id,group_id,auth_mod) VALUES (3,7,1)');
	$p->exec('INSERT INTO fixture_groups VALUES (8,7,0)');
	$p->exec('INSERT INTO fixture_topics (topic_id,forum_id,topic_type,topic_vote,topic_moved_id,topic_last_post_id) VALUES (101,3,2,0,0,11),(102,3,0,0,0,12),(103,3,0,1,0,13),(104,3,0,0,0,10),(105,3,0,0,0,15),(106,3,0,0,100,16),(107,3,0,0,0,0),(108,3,0,0,0,0),(109,3,3,0,0,19)');
	foreach(array(101,102,103,104,105,106,108,109) as $id)
	{
		$post=$id-90; $forum=$id===105?4:3; $time=$id===104?time():1;
		$p->exec("INSERT INTO fixture_posts (post_id,topic_id,forum_id,poster_id,post_time) VALUES ($post,$id,$forum,8,$time)");
		$p->exec("INSERT INTO fixture_post_text VALUES ($post,'preserve or delete by scope','subject','abc')");
		foreach(array('fixture_watches','fixture_bookmarks','fixture_views') as $table) { $p->exec("INSERT INTO $table VALUES ($id,8)"); }
	}
	$p->exec("INSERT INTO fixture_votes VALUES (1,102,'Actual protected poll',1,0)");
	$p->exec("INSERT INTO fixture_vote_results VALUES (1,1,'keep',4)");
	$p->exec('INSERT INTO fixture_voters VALUES (1,8)');
	$userdata['session_id']='fixture-session'; $userdata['session_admin']=true;
	$_SERVER['REQUEST_METHOD']='POST'; $_POST=array('sid'=>'fixture-session');
}
function prune_expect_failure($callback,$expected)
{
	$caught=false; try { call_user_func($callback); } catch(PhpbbPruneException $e) { $caught=$e->getMessage()===$expected; }
	mutation_check($caught,'Expected prune failure: '.$expected);
	mutation_check($GLOBALS['mutation_server']->owner===null,'Prune failure releases shared owner');
}
set_error_handler(function($severity,$message){ if(error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	prune_fixture(); $result=phpbb_prune_forum($db,3,100);
	mutation_check($result===array('topics'=>2,'posts'=>2),'Prune uses actual old posts even with last_post_id=0');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_topics WHERE topic_id IN (101,102,103,104,105,106,107,109)')===8,'Polls, announcements, recent posts, foreign posts and redirects are preserved');
	mutation_check((int)posting_value('SELECT vote_result FROM fixture_vote_results')===4 && (int)posting_value('SELECT COUNT(*) FROM fixture_voters')===1,'Actual poll content remains untouched');
	foreach(array('fixture_watches','fixture_bookmarks','fixture_views') as $table)
	{ mutation_check((int)posting_value('SELECT COUNT(*) FROM '.$table.' WHERE topic_id IN (100,108)')===0,'Removed topics lose dependent rows: '.$table); }
	mutation_check((int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===8,'Only actually removed counted posts decrement author');
	mutation_check(phpbb_prune_forum($db,3,100)===array('topics'=>0,'posts'=>0),'Repeated manual pruning does not delete protected topics');
	prune_fixture(); $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=8');
	prune_expect_failure(function() use($db){ phpbb_prune_forum($db,3,100); },'Not_Authorised');
	$module=md5('ForumsPruneadmin_forum_prune.php');
	$mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".$module."')");
	mutation_check(phpbb_prune_forum($db,3,100)['topics']===2,'Current delegated ACP prune grant is supported');
	prune_fixture(); $userdata['user_level']=ADMIN; $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0,user_active=0 WHERE user_id=8');
	prune_expect_failure(function() use($db){ phpbb_prune_forum($db,3,100); },'Not_Authorised');
	foreach(array('GET','sid') as $failure)
	{
		prune_fixture(); if($failure==='GET') { $_SERVER['REQUEST_METHOD']='GET'; } else { $_POST['sid']='wrong'; }
		prune_expect_failure(function() use($db){ phpbb_prune_forum($db,3,100); },'Session_invalid');
	}
	foreach(array(0,'3x',array(3),true) as $forum)
	{ prune_fixture(); prune_expect_failure(function() use($db,$forum){ phpbb_prune_forum($db,$forum,100); },'Prune_selection_changed'); }
	foreach(array('100x',array(100),true,time()+86400,'-999999999999999') as $cutoff)
	{ prune_fixture(); prune_expect_failure(function() use($db,$cutoff){ phpbb_prune_forum($db,3,$cutoff); },'Prune_selection_changed'); }
	foreach(array('UPDATE fixture_posts SET post_time=200 WHERE post_id=10',"INSERT INTO fixture_votes VALUES (2,100,'New poll',1,0)",'UPDATE fixture_topics SET forum_id=4 WHERE topic_id=100','UPDATE fixture_topics SET topic_status=2 WHERE topic_id=100') as $change)
	{
		prune_fixture(); $changed=false;
		$mutation_server->hook=function($sql) use($change,&$changed){ if(!$changed && strpos($sql,'DELETE FROM fixture_topics WHERE topic_id = 100')===0) { $changed=true; $GLOBALS['mutation_server']->pdo->exec($change); } };
		phpbb_prune_forum($db,3,100);
		mutation_check($changed && (int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE topic_id=100')===1,'Changed parent/new reply or poll prevents that topic deletion');
	}
	prune_fixture(); $_SERVER['REQUEST_METHOD']='GET'; $_POST=array(); $userdata['session_admin']=false;
	$mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=8');
	mutation_check(phpbb_prune_forum($db,3)['topics']===2,'Authorized automatic prune uses configured age without an ACP POST');
	mutation_check((int)posting_value('SELECT prune_next FROM fixture_forums WHERE forum_id=3')>time(),'Schedule advances after successful run');
	mutation_check(phpbb_prune_forum($db,3)===array('topics'=>0,'posts'=>0),'Second due-page request sees future schedule');
	foreach(array("UPDATE fixture_config SET config_value='0'",'UPDATE fixture_forums SET prune_enable=0','UPDATE fixture_forums SET prune_next=2147483647','UPDATE fixture_prune SET prune_days=0','UPDATE fixture_prune SET prune_freq=0') as $change)
	{
		prune_fixture(); $mutation_server->pdo->exec($change);
		mutation_check(phpbb_prune_forum($db,3)===array('topics'=>0,'posts'=>0) && (int)posting_value('SELECT COUNT(*) FROM fixture_posts')===9,'Disabled/not-due automatic prune performs no deletion');
	}
	prune_fixture(); $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=8'); $mutation_server->pdo->exec('DELETE FROM fixture_groups');
	prune_expect_failure(function() use($db){ phpbb_prune_forum($db,3); },'Not_Authorised');
	prune_fixture(); $mutation_server->pdo->exec('INSERT INTO fixture_prune VALUES (2,3,7,1)');
	prune_expect_failure(function() use($db){ phpbb_prune_forum($db,3); },'Prune_selection_changed');
	prune_fixture(); $mutation_server->pdo->exec('UPDATE fixture_prune SET prune_days=65535,prune_freq=65535');
	$scheduled=false;
	mutation_check(phpbb_prune_forum($db,3,null,$scheduled)===array('topics'=>0,'posts'=>0) && $scheduled && (int)posting_value('SELECT prune_next FROM fixture_forums WHERE forum_id=3')===2147483647,'Legacy maximum SMALLINT settings remain valid without timestamp overflow and signal cache refresh even without deletion');
	foreach(array("UPDATE fixture_config SET config_value='0'",'UPDATE fixture_prune SET prune_days=30','INSERT INTO fixture_prune VALUES (2,3,7,1)') as $change)
	{
		prune_fixture(); $changed=false;
		$mutation_server->hook=function($sql) use($change,&$changed){ if(!$changed && strpos($sql,'DELETE FROM fixture_topics')===0) { $changed=true; $GLOBALS['mutation_server']->pdo->exec($change); } };
		prune_expect_failure(function() use($db){ phpbb_prune_forum($db,3); },'Prune_selection_changed');
		mutation_check($changed && (int)posting_value('SELECT COUNT(*) FROM fixture_posts')===9 && (int)posting_value('SELECT prune_next FROM fixture_forums WHERE forum_id=3')===0,'Changed automatic policy protects posts and does not advance old schedule');
	}
	foreach(array('DELETE FROM fixture_topics','DELETE FROM fixture_post_text','DELETE FROM fixture_views','UPDATE fixture_forums SET prune_next') as $failure)
	{
		prune_fixture(); $mutation_server->failure=$failure;
		prune_expect_failure(function() use($db){ phpbb_prune_forum($db,3); },'Prune_storage_failed');
		mutation_check((int)posting_value('SELECT prune_next FROM fixture_forums WHERE forum_id=3')===0,'Failed automatic run does not advance schedule');
	}
	prune_fixture(); $interleaved=false;
	$mutation_server->hook=function($sql) use(&$interleaved)
	{
		if(!$interleaved && strpos($sql,'DELETE FROM fixture_topics')===0)
		{
			$interleaved=true; mutation_expect_failure(function(){ posting_submit('reply'); },'busy');
			mutation_check(phpbb_prune_forum($GLOBALS['db'],3)===array('topics'=>0,'posts'=>0),'Parallel automatic prune skips busy writer');
		}
	};
	phpbb_prune_forum($db,3,100); mutation_check($interleaved,'Actual delete boundary is exercised');
	echo "Prune storage policy, current authorization, age/poll guards and scheduling checks passed.\n";
}
finally { if(isset($mutation_server) && $mutation_server->owner!==null) { $mutation_server->owner->sql_close(); } restore_error_handler(); }
