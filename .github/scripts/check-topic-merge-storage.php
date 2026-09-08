<?php
date_default_timezone_set('UTC');
require __DIR__ . '/check-topic-merge-access.php';
require $forum_root . 'includes/functions_topic_merge_storage.php';
require $forum_root . 'includes/functions_topic_views.php';
class TopicMergeFixturePDO extends TopicMoveFixturePDO
{
	function query($sql)
	{
		if ($this->inner->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')
		{
			if (strpos($sql,'UPDATE fixture_posts p JOIN ')===0)
			{
				mutation_check(preg_match('/^UPDATE (.+) SET p.topic_id = ([0-9]+), p.forum_id = ([0-9]+) WHERE (.+)$/D',$sql,$parts)===1,'Recognize complete guarded merge UPDATE');
				$ids=$this->inner->query('SELECT p.post_id FROM '.$parts[1].' WHERE '.$parts[4])->fetchAll(PDO::FETCH_COLUMN);
				if ($ids) { $this->inner->exec('UPDATE fixture_posts SET topic_id='.$parts[2].',forum_id='.$parts[3].' WHERE post_id IN ('.implode(',',$ids).')'); }
				return new TopicMoveFixtureStatement(count($ids));
			}
			if (preg_match('/^UPDATE fixture_views SET (.+) WHERE (.+) LIMIT 1$/D',$sql,$parts))
			{
				return $this->inner->query('UPDATE fixture_views SET '.$parts[1].' WHERE rowid IN (SELECT rowid FROM fixture_views WHERE '.$parts[2].' LIMIT 1)');
			}
		}
		return parent::query($sql);
	}
}
function merge_storage_fixture()
{
	global $mutation_server,$db;
	merge_access_fixture(); $db=new MutationForum();
	$mutation_server->pdo=new TopicMergeFixturePDO($mutation_server->pdo); $p=$mutation_server->pdo;
	$p->exec('INSERT INTO fixture_auth (forum_id,group_id,auth_mod) VALUES (4,7,1)');
	$p->exec("INSERT INTO fixture_topics (topic_id,forum_id,topic_title,topic_first_post_id,topic_last_post_id,topic_poster,topic_time,topic_views) VALUES (200,4,'Target',20,20,9,3,7)");
	$p->exec('INSERT INTO fixture_posts (post_id,topic_id,forum_id,poster_id,post_time) VALUES (20,200,4,9,3)');
	$p->exec("INSERT INTO fixture_post_text VALUES (11,'guest text','guest','abc'),(20,'target text','target','abc')");
	$p->exec('ALTER TABLE fixture_watches ADD notify_status INTEGER DEFAULT 0');
	$p->exec("ALTER TABLE fixture_watches ADD notify_claim CHAR(32) DEFAULT ''");
	$p->exec('ALTER TABLE fixture_watches ADD notify_claimed_at INTEGER DEFAULT 0');
	$p->exec("UPDATE fixture_watches SET notify_status=1,notify_claim='sourceclaim',notify_claimed_at=12");
	$p->exec("INSERT INTO fixture_watches VALUES (100,8,1,'duplicate',12),(100,9,0,'',0),(100,-1,0,'',0),(200,9,1,'targetclaim',15)");
	$p->exec('INSERT INTO fixture_bookmarks VALUES (100,8),(100,9),(200,8)');
	$p->exec('ALTER TABLE fixture_views ADD view_time INTEGER DEFAULT 1');
	$p->exec('ALTER TABLE fixture_views ADD view_count INTEGER DEFAULT 2');
	$p->exec('INSERT INTO fixture_views VALUES (100,9,10,5),(200,8,3,3),(200,9,4,5),(200,9,5,6)');
	$p->exec("INSERT INTO fixture_voters VALUES (1,9,'7f000001')");
	$p->exec('UPDATE fixture_vote_results SET vote_result=1 WHERE vote_option_id=1');
	$p->exec('UPDATE fixture_users SET user_posts=1 WHERE user_id=9');
}
function merge_storage_token($subject='', $shadow=false)
{
	return phpbb_prepare_topic_merge($GLOBALS['db'],100,200,$subject,$shadow)['token'];
}
function merge_storage_run($subject='', $shadow=false, $token=null)
{
	if ($token===null) { $token=merge_storage_token($subject,$shadow); }
	return phpbb_merge_topics($GLOBALS['db'],100,200,$subject,$shadow,$token);
}
function merge_storage_failure($callback,$message)
{
	$caught=false; try { call_user_func($callback); } catch(PhpbbTopicMergeException $e) { $caught=$e->getMessage()===$message; }
	mutation_check($caught,'Expected merge storage failure: '.$message);
	mutation_check($GLOBALS['mutation_server']->owner===null,'Merge failure releases shared lock');
}
function merge_content_snapshot()
{
	$rows=array(); foreach(array('fixture_post_text','fixture_matches','fixture_links','fixture_vote_results','fixture_voters') as $table)
	{ $rows[$table]=$GLOBALS['mutation_server']->pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC); } return $rows;
}
set_error_handler(function($severity,$message) { if(error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	merge_storage_fixture(); $before=merge_content_snapshot(); $p=$mutation_server->pdo;
	mutation_check(merge_storage_run()===200,'Merge publishes target ID');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE topic_id=200 AND forum_id=4')===3 && (int)posting_value('SELECT COUNT(*) FROM fixture_topics WHERE topic_id=100')===0,'Complete source moves before source metadata removal');
	mutation_check(merge_content_snapshot()===$before,'Text, search, attachments and transferred poll votes remain byte-identical');
	mutation_check((int)posting_value('SELECT topic_id FROM fixture_votes WHERE vote_id=1')===200 && (int)posting_value('SELECT topic_vote FROM fixture_topics WHERE topic_id=200')===1,'Actual poll transfers despite stale source topic_vote=0');
	mutation_check(posting_value('SELECT topic_title FROM fixture_topics WHERE topic_id=200')==='Target','Blank new title preserves target title');
	mutation_check((int)posting_value('SELECT topic_first_post_id FROM fixture_topics WHERE topic_id=200')===10 && (int)posting_value('SELECT topic_last_post_id FROM fixture_topics WHERE topic_id=200')===20 && (int)posting_value('SELECT topic_replies FROM fixture_topics WHERE topic_id=200')===2,'Target bounds and replies reflect all posts');
	mutation_check((int)posting_value('SELECT topic_poster FROM fixture_topics WHERE topic_id=200')===8 && (int)posting_value('SELECT topic_time FROM fixture_topics WHERE topic_id=200')===1 && (int)posting_value('SELECT topic_attachment FROM fixture_topics WHERE topic_id=200')===1,'First-post metadata and attachment flag synchronized');
	mutation_check((int)posting_value('SELECT topic_views FROM fixture_topics WHERE topic_id=200')===11,'Topic view totals preserved');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_watches WHERE topic_id=200')===2 && posting_value('SELECT notify_claim FROM fixture_watches WHERE topic_id=200 AND user_id=9')==='targetclaim','Existing target claim preserved; source duplicates do not multiply subscriptions');
	mutation_check(posting_value('SELECT notify_claim FROM fixture_watches WHERE topic_id=200 AND user_id=8')==='' && (int)posting_value('SELECT notify_status FROM fixture_watches WHERE topic_id=200 AND user_id=8')===0,'Source-only watch starts with fresh unclaimed state');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_bookmarks WHERE topic_id=200')===2,'Unique source bookmarks retained without multiplying overlap');
	mutation_check((int)posting_value('SELECT SUM(view_count) FROM fixture_views WHERE topic_id=200 AND user_id=8')===5 && (int)posting_value('SELECT SUM(view_count) FROM fixture_views WHERE topic_id=200 AND user_id=9')===16,'Overlapping duplicate target view rows receive source counts exactly once');
	mutation_check((int)posting_value('SELECT MIN(view_time) FROM fixture_views WHERE topic_id=200 AND user_id=9')===10,'Source last-view timestamp retained');
	mutation_check((int)posting_value('SELECT forum_posts FROM fixture_forums WHERE forum_id=3')===0 && (int)posting_value('SELECT forum_posts FROM fixture_forums WHERE forum_id=4')===3,'Forum counts refreshed');
	mutation_check((int)posting_value("SELECT COUNT(*) FROM fixture_action_log WHERE mode='merge'")===2,'Completed merge records source and target');
	merge_storage_failure(function(){ merge_storage_run(); },'Merge_changed');

	merge_storage_fixture(); $p=$mutation_server->pdo;
	$p->exec('INSERT INTO fixture_topics (topic_id,forum_id,topic_status,topic_moved_id) VALUES (300,3,2,100),(301,4,2,100)');
	$p->exec('INSERT INTO fixture_posts (post_id,topic_id,forum_id) VALUES (30,301,4)');
	merge_storage_run("Grüße O'Reilly \\ 😀",true);
	mutation_check((int)posting_value('SELECT topic_moved_id FROM fixture_topics WHERE topic_id=100')===200 && (int)posting_value('SELECT topic_first_post_id FROM fixture_topics WHERE topic_id=100')===10,'Source shadow retains renderable post pointers');
	mutation_check((int)posting_value('SELECT topic_moved_id FROM fixture_topics WHERE topic_id=300')===200 && (int)posting_value('SELECT topic_moved_id FROM fixture_topics WHERE topic_id=301')===100,'Only empty existing redirects are retargeted');
	mutation_check(posting_value('SELECT topic_title FROM fixture_topics WHERE topic_id=200')==="Grüße O'Reilly \\ 😀",'Plain UTF8 title stored without slash accumulation');
	merge_storage_fixture(); $p=$mutation_server->pdo; $p->exec('UPDATE fixture_forums SET count_posts=0 WHERE forum_id=4'); merge_storage_run();
	mutation_check((int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===0 && (int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=-1')===77,'Counted boundary recounts moved real posters, not anonymous sentinel');
	merge_storage_fixture(); $p=$mutation_server->pdo; $p->exec('UPDATE fixture_topics SET forum_id=3 WHERE topic_id=200'); $p->exec('UPDATE fixture_posts SET forum_id=3 WHERE topic_id=200'); merge_storage_run();
	mutation_check((int)posting_value('SELECT forum_posts FROM fixture_forums WHERE forum_id=3')===3,'Same-forum merge keeps post total');
	merge_storage_fixture(); $p=$mutation_server->pdo;
	$p->exec("INSERT INTO fixture_votes VALUES (2,200,'Target poll',1,0)"); $p->exec("INSERT INTO fixture_vote_results VALUES (2,1,'Target option',3)"); $p->exec("INSERT INTO fixture_voters VALUES (2,8,'7f000001')");
	merge_storage_run();
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_votes WHERE vote_id=1')===0 && (int)posting_value('SELECT vote_result FROM fixture_vote_results WHERE vote_id=2')===3 && (int)posting_value('SELECT COUNT(*) FROM fixture_voters WHERE vote_id=2')===1,'Explicitly confirmed conflicting source poll removed, target ballot unchanged');

	foreach(array('UPDATE fixture_auth SET auth_mod=0 WHERE forum_id=3','UPDATE fixture_auth SET auth_mod=0 WHERE forum_id=4','UPDATE fixture_forums SET auth_read=5','UPDATE fixture_users SET user_active=0') as $change)
	{
		merge_storage_fixture(); $token=merge_storage_token(); $mutation_server->pdo->exec($change);
		merge_storage_failure(function() use($token){ merge_storage_run('',false,$token); },'Not_Authorised');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE topic_id=100')===2,'Revoked permissions move no posts');
	}
	foreach(array("UPDATE fixture_topics SET topic_title='changed' WHERE topic_id=200",'UPDATE fixture_topics SET topic_moved_id=99 WHERE topic_id=100','UPDATE fixture_topics SET forum_id=3 WHERE topic_id=200',"UPDATE fixture_votes SET vote_text='changed'", "UPDATE fixture_vote_results SET vote_option_text='changed'", "INSERT INTO fixture_votes VALUES (2,200,'New target poll',1,0)", 'DELETE FROM fixture_topics WHERE topic_id=200') as $change)
	{
		merge_storage_fixture(); $token=merge_storage_token(); $mutation_server->pdo->exec($change);
		merge_storage_failure(function() use($token){ merge_storage_run('',false,$token); },'Merge_changed');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE topic_id=100')===2,'Stale confirmation cannot publish posts or unexpectedly delete new polls');
	}
	merge_storage_fixture(); $token=merge_storage_token();
	merge_storage_failure(function() use($token){ merge_storage_run('Changed title',false,$token); },'Merge_changed');
	merge_storage_failure(function() use($token){ merge_storage_run('',true,$token); },'Merge_changed');
	merge_storage_failure(function(){ merge_storage_run('',false,''); },'Merge_changed');
	merge_storage_fixture(); $token=merge_storage_token(); $mutation_server->pdo->exec('INSERT INTO fixture_posts (post_id,topic_id,forum_id,poster_id) VALUES (12,100,3,9)'); merge_storage_run('',false,$token);
	mutation_check((int)posting_value('SELECT topic_replies FROM fixture_topics WHERE topic_id=200')===3,'New replies before execution join the fresh complete set');
	foreach(array('SELECT topic_id, forum_id','SELECT user_id, user_level','SELECT user_id, view_time','UPDATE fixture_posts p JOIN','INSERT INTO fixture_bookmarks','INSERT INTO fixture_watches','UPDATE fixture_views','UPDATE fixture_votes','UPDATE fixture_topics SET topic_title','SELECT attach_id','DELETE FROM fixture_bookmarks','UPDATE fixture_topics SET topic_moved_id','DELETE FROM fixture_topics','UPDATE fixture_forums','INSERT INTO fixture_action_log') as $failure)
	{
		merge_storage_fixture(); $token=merge_storage_token(); $before=merge_content_snapshot(); $mutation_server->failure=$failure;
		merge_storage_failure(function() use($token){ merge_storage_run('',false,$token); },'Merge_storage_failed');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_posts')===3 && merge_content_snapshot()===$before,'Storage failure never deletes text/index/attachments/transferred vote contents');
	}
	merge_storage_fixture(); $interleaved=false;
	$mutation_server->hook=function($sql) use(&$interleaved)
	{
		if($interleaved || strpos($sql,'UPDATE fixture_posts p JOIN')!==0) { return; } $interleaved=true;
		$caught=false; try { merge_storage_run(); } catch(PhpbbTopicMergeException $e) { $caught=$e->getMessage()==='busy'; } mutation_check($caught,'Second merge cannot overlap');
		$caught=false; try { phpbb_move_topics($GLOBALS['db'],3,4,array(100)); } catch(PhpbbTopicMoveException $e) { $caught=$e->getMessage()==='busy'; } mutation_check($caught,'Move cannot overlap merge');
		$caught=false; try { phpbb_cast_poll_vote($GLOBALS['db'],100,1); } catch(PhpbbPollStorageException $e) { $caught=$e->getMessage()==='busy'; } mutation_check($caught,'Vote cannot overlap merge');
		mutation_expect_failure(function(){ posting_submit('reply'); },'busy'); mutation_expect_failure(function(){ posting_delete(); },'busy');
		mutation_check(phpbb_record_topic_view($GLOBALS['db'],100)===false,'View counters cannot race a merge snapshot');
		mutation_check($GLOBALS['mutation_last_lock_timeout']===0,'Optional view statistics request a nonblocking lock');
	};
	merge_storage_run(); $mutation_server->hook=null; mutation_check($interleaved,'Actual interleaving boundary exercised');
	foreach(array('0',"\\' OR '1'='1",str_repeat('ä',59).'😀extra',str_repeat('&',20)) as $subject)
	{
		merge_storage_fixture(); merge_storage_run($subject);
		mutation_check(posting_value('SELECT topic_title FROM fixture_topics WHERE topic_id=200')===phpbb_storage_subject($subject),'Actual worker title query preserves whole characters/entities and SQL literal boundary');
	}
	foreach(array("bad\xC3",str_repeat('a',4097),array('title')) as $subject)
	{
		merge_storage_fixture(); merge_storage_failure(function() use($subject){ merge_storage_run($subject); },'Merge_invalid_subject');
	}
	foreach(array('UPDATE fixture_posts SET forum_id=4 WHERE post_id=11','UPDATE fixture_posts SET topic_id=200,forum_id=4 WHERE post_id=11','INSERT INTO fixture_posts (post_id,topic_id,forum_id) VALUES (12,100,3)','DELETE FROM fixture_posts WHERE post_id=20','UPDATE fixture_posts SET post_id=12 WHERE post_id=11','UPDATE fixture_posts SET post_id=21 WHERE post_id=20') as $change)
	{
		merge_storage_fixture(); $token=merge_storage_token();
		$mutation_server->hook=function($sql) use($change){ if(strpos($sql,'UPDATE fixture_posts p JOIN')===0) { $GLOBALS['mutation_server']->pdo->exec($change); } };
		merge_storage_failure(function() use($token){ merge_storage_run('',false,$token); },'Merge_changed');
		mutation_check((int)posting_value('SELECT topic_id FROM fixture_posts WHERE post_id=10')===100,'Changed source/target post set rejects complete joined publication');
		$mutation_server->hook=null;
	}
	// Actual endpoint preparation/confirmation branch, ending before rendering.
	$controller=file_get_contents($forum_root.'merge.php'); $start=strpos($controller,'// submission:'); $end=strpos($controller,'// The confirmation is tied',$start);
	mutation_check($start!==false && $end>$start,'Locate real merge controller'); $branch=substr($controller,$start,$end-$start).'}';
	foreach(array('Merge_topic_done','Click_return_index','Merge_topics','Merge_poll_from','Merge_poll_from_and_to','Merge_confirm_process') as $key) { $lang[$key]=$key; }
	merge_storage_fixture(); $submit=true; $confirm=false; $sid=$userdata['session_id']; $from_topic_id=100; $to_topic_id=200; $topic_title="O'Reilly \\ 😀"; $shadow=false; $template=new PollTemplateFixture(); $_SERVER['REQUEST_METHOD']='POST'; $_POST=array();
	eval($branch); mutation_check(isset($merge_context['token']) && (int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE topic_id=100')===2,'Unconfirmed controller only prepares capability');
	$_POST['merge_token']=$merge_context['token']; $confirm=true; $caught=false;
	try { eval($branch); } catch(MutationFailure $e) { $caught=strpos($e->getMessage(),'Merge_topic_done')===0; }
	mutation_check($caught && posting_value('SELECT topic_title FROM fixture_topics WHERE topic_id=200')===$topic_title,'Confirmed controller delegates exact plain title and reports success after worker releases lock');
	merge_storage_fixture(); $_POST=array(); $caught=false;
	try { eval($branch); } catch(MutationFailure $e) { $caught=$e->getMessage()==='Merge_changed'; }
	mutation_check($caught && (int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE topic_id=100')===2,'Direct confirmation without issued capability cannot merge');
	$_SERVER['REQUEST_METHOD']='GET'; $caught=false;
	try { eval($branch); } catch(MutationFailure $e) { $caught=$e->getMessage()==='Invalid_session'; }
	mutation_check($caught,'Actual confirmed mutation refuses GET');
	$_SERVER['REQUEST_METHOD']='POST'; $sid='wrong'; $caught=false;
	try { eval($branch); } catch(MutationFailure $e) { $caught=$e->getMessage()==='Invalid_session'; }
	mutation_check($caught,'Actual confirmed mutation refuses wrong SID');
	merge_storage_fixture(); $from_topic_id=100; $to_topic_id=200; $shadow=false;
	$roundtrip_title="O'Reilly \\ &amp; <b> \" Grüße 😀";
	$_POST['topic_title']=addslashes($roundtrip_title);
	$start=strpos($controller,'// topic title'); $end=strpos($controller,'// start',$start);
	mutation_check($start!==false && $end>$start,'Locate actual request title decoding'); eval(substr($controller,$start,$end-$start));
	mutation_check($topic_title===$roundtrip_title,'Legacy request slashes decoded once before confirmation');
	$merge_context=phpbb_prepare_topic_merge($db,100,200,$topic_title,$shadow);
	$start=strpos($controller,"\$s_hidden_fields = '<input",strpos($controller,'// The confirmation is tied')); $end=strpos($controller,'$template->assign_vars',$start);
	mutation_check($start!==false && $end>$start,'Locate actual confirmation hidden fields'); eval(substr($controller,$start,$end-$start));
	mutation_check(preg_match('/name="topic_title" value="([^"]*)"/',$s_hidden_fields,$field)===1 && html_entity_decode($field[1],ENT_QUOTES,'UTF-8')===$roundtrip_title,'Confirmation HTML roundtrip preserves quotes, literal entities and backslashes');
	mutation_check(strpos($s_hidden_fields,'name="merge_token" value="'.$merge_context['token'].'"')!==false,'Actual form carries issued snapshot-bound capability');
	merge_storage_fixture(); $mutation_server->pdo->exec("INSERT INTO fixture_votes VALUES (2,100,'Malformed second source poll',1,0)");
	merge_storage_failure(function(){ merge_storage_token(); },'Merge_changed');
	foreach(array(0,-1,'100x',array(100),'16777216') as $bad)
	{
		merge_storage_fixture(); merge_storage_failure(function() use($db,$bad){ phpbb_prepare_topic_merge($db,$bad,200,'',false); },'Merge_changed');
	}
	merge_storage_fixture();
	mutation_check(phpbb_record_topic_view($db,100)===true && (int)posting_value('SELECT topic_views FROM fixture_topics WHERE topic_id=100')===5,'Personal and total view increment together');
	mutation_check((int)posting_value('SELECT view_count FROM fixture_views WHERE topic_id=100 AND user_id=8')===3,'Personal view increment stored');
	$userdata['user_id']=9; $mutation_server->pdo->exec('INSERT INTO fixture_views VALUES (100,9,1,4)');
	mutation_check(phpbb_record_topic_view($db,100)===true && (int)posting_value('SELECT SUM(view_count) FROM fixture_views WHERE topic_id=100 AND user_id=9')===10,'Legacy duplicate view rows count this visit only once');
	$userdata['user_id']=8; merge_storage_run();
	mutation_check(phpbb_record_topic_view($db,100)===false && (int)posting_value('SELECT COUNT(*) FROM fixture_views WHERE topic_id=100')===0,'Stale reader cannot recreate deleted source views');
	mutation_check(phpbb_record_topic_view($db,200)===true,'Reader of merged destination remains supported');
	merge_storage_fixture(); $userdata['session_logged_in']=false;
	mutation_check(phpbb_record_topic_view($db,100)===false,'Guest cannot record a view of a registered-only topic');
	$mutation_server->pdo->exec('UPDATE fixture_forums SET auth_read=0,auth_view=0');
	mutation_check(phpbb_record_topic_view($db,100)===true && (int)posting_value('SELECT view_count FROM fixture_views WHERE user_id=-1')===1,'Authorized guest still has the legacy anonymous view record');
	merge_storage_fixture(); $mutation_server->pdo->exec('UPDATE fixture_topics SET topic_views=16777215'); $mutation_server->pdo->exec('UPDATE fixture_views SET view_count=2147483647');
	mutation_check(phpbb_record_topic_view($db,100)===true && (int)posting_value('SELECT topic_views FROM fixture_topics WHERE topic_id=100')===16777215 && (int)posting_value('SELECT view_count FROM fixture_views WHERE user_id=8')===2147483647,'View counters respect actual storage limits');
	merge_storage_fixture(); $mutation_server->pdo->exec('UPDATE fixture_users SET user_active=0');
	mutation_check(phpbb_record_topic_view($db,100)===false,'Inactive account does not record privileged views');
	merge_storage_fixture(); $mutation_server->failure='UPDATE fixture_views';
	$stats_log=tmpfile(); $stats_meta=stream_get_meta_data($stats_log); $old_log=ini_get('error_log'); ini_set('error_log',$stats_meta['uri']);
	try
	{
		mutation_check(phpbb_record_topic_view($db,100)===false && $mutation_server->owner===null,'Optional statistics SQL failure returns safely and releases lock');
		mutation_check(strpos(file_get_contents($stats_meta['uri']),'optional topic view statistics failed')!==false,'Optional statistics failure leaves a generic diagnostic');
	}
	finally { ini_set('error_log',$old_log); fclose($stats_log); }
	$viewtopic=file_get_contents($forum_root.'viewtopic.php');
	mutation_check(strpos($viewtopic,'phpbb_record_topic_view($db, $topic_id)')>strpos($viewtopic,'// End auth check') && strpos($viewtopic,'$topic_id = intval($forum_topic_data[\'topic_id\']);')!==false,'Counter call follows session/authorization and keeps canonical post-to-topic resolution');
	mutation_check(substr_count($viewtopic,'phpbb_record_topic_view($db, $topic_id)')===1 && strpos($viewtopic,'SET topic_views = topic_views + 1')===false && strpos($viewtopic,'INSERT IGNORE INTO \'.TOPIC_VIEW_TABLE')===false,'Actual topic page has one coordinated counter path, no legacy unlocked fallback');
	echo "Coordinated merge storage, confirmation, poll/preferences, counters and failures passed.\n";
}
finally { if(isset($mutation_server) && $mutation_server->owner!==null) { $mutation_server->owner->sql_close(); } restore_error_handler(); }
