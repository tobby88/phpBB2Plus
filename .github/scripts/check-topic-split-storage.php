<?php
require __DIR__ . '/check-topic-move-storage.php';
require $forum_root . 'includes/functions_topic_split.php';
foreach(array('Moderation_split_failed','Moderation_split_changed','Moderation_split_denied','Moderation_split_keep_first','Moderation_split_subject','Click_view_split_topic','Topic_split') as $key) { $lang[$key]=$key; }
class TopicSplitFixturePDO extends TopicMoveFixturePDO
{
	function query($sql)
	{
		if(strpos($sql,'UPDATE fixture_posts p JOIN ')!==0 || $this->inner->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite') { return parent::query($sql); }
		// Only SQLite models the joined update; native mysqli executes it verbatim.
		mutation_check(preg_match('/^UPDATE (.+) SET p.topic_id = ([0-9]+), p.forum_id = ([0-9]+) WHERE (.+)$/D',$sql,$parts)===1,'Recognize exact joined split SQL');
		$rows=$this->inner->query('SELECT p.post_id FROM '.$parts[1].' WHERE '.$parts[4])->fetchAll(PDO::FETCH_COLUMN);
		if($rows) { $this->inner->exec('UPDATE fixture_posts SET topic_id='.$parts[2].',forum_id='.$parts[3].' WHERE post_id IN ('.implode(',',$rows).')'); }
		return new TopicMoveFixtureStatement(count($rows));
	}
}
function topic_split_fixture()
{
	global $mutation_server;
	topic_move_fixture(); $mutation_server->pdo=new TopicSplitFixturePDO($mutation_server->pdo); $p=$mutation_server->pdo;
	$p->exec('ALTER TABLE fixture_watches ADD notify_status INTEGER DEFAULT 0');
	$p->exec('UPDATE fixture_watches SET notify_status=1');
	$p->exec('INSERT INTO fixture_watches (topic_id,user_id,notify_status) VALUES (100,9,1),(100,9,1),(100,10,1),(100,-1,1)');
	$p->exec('INSERT INTO fixture_users (user_id,user_posts) VALUES (10,0)');
	$p->exec('INSERT INTO fixture_posts (post_id,topic_id,forum_id,poster_id,post_time) VALUES (12,100,3,9,2),(13,100,3,8,2),(14,100,3,9,3),(15,100,3,-1,4)');
	foreach(array(11,12,13,14,15) as $id)
	{
		$p->exec('INSERT INTO fixture_post_text VALUES ('.$id.','.$p->quote("Grüße 😀 text '".$id).','.$p->quote('subject '.$id).",'abc')");
		$p->exec('INSERT INTO fixture_matches VALUES ('.$id.',1,0)');
	}
	$p->exec('INSERT INTO fixture_links VALUES (2,12,0,9,0)');
	$p->exec('UPDATE fixture_users SET user_posts=2 WHERE user_id IN (8,9)');
	$p->exec('UPDATE fixture_topics SET topic_replies=5,topic_last_post_id=15,topic_vote=1');
	$p->exec('UPDATE fixture_forums SET forum_posts=6,forum_last_post_id=15 WHERE forum_id=3');
}
function topic_split_failure($callback,$expected)
{
	$caught=false; try { call_user_func($callback); } catch(PhpbbTopicSplitException $e) { $caught=$e->getMessage()===$expected; }
	mutation_check($caught,'Expected split failure: '.$expected);
	mutation_check($GLOBALS['mutation_server']->owner===null,'Split error releases owning connection');
}
function topic_split_snapshot()
{
	$rows=array();
	foreach(array('fixture_post_text','fixture_matches','fixture_links','fixture_votes','fixture_vote_results','fixture_voters','fixture_bookmarks','fixture_views') as $table)
	{
		$rows[$table]=$GLOBALS['mutation_server']->pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
	}
	$rows['watches']=$GLOBALS['mutation_server']->pdo->query('SELECT * FROM fixture_watches WHERE topic_id=100 ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
	return $rows;
}
function topic_split_run($ids=array(12,14),$mode='selected',$forum=4,$subject="Grüße O'Reilly \\ 😀")
{
	return phpbb_split_topic($GLOBALS['db'],3,100,$forum,$ids,$mode,$subject);
}
set_error_handler(function($severity,$message) { if(error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	topic_split_fixture(); $p=$mutation_server->pdo; $snapshot=topic_split_snapshot();
	$split=topic_split_run(); $new=$split['topic_id'];
	mutation_check($split['post_ids']===array(12,14) && $new!==100,'Selected split creates a new topic with exact post set');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE topic_id='.$new.' AND forum_id=4')===2 && (int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE topic_id=100 AND forum_id=3')===4,'Only selected posts change topic and forum');
	mutation_check(topic_split_snapshot()===$snapshot,'Original text/index/polls/attachment links/bookmarks/views and watches are unchanged');
	mutation_check(posting_value('SELECT topic_title FROM fixture_topics WHERE topic_id='.$new)==="Grüße O'Reilly \\ 😀",'New subject preserves UTF8, quotes and backslashes exactly');
	mutation_check((int)posting_value('SELECT topic_poster FROM fixture_topics WHERE topic_id='.$new)===9 && (int)posting_value('SELECT topic_time FROM fixture_topics WHERE topic_id='.$new)===2,'New topic metadata belongs to its actual first post');
	mutation_check((int)posting_value('SELECT topic_replies FROM fixture_topics WHERE topic_id=100')===3 && (int)posting_value('SELECT topic_replies FROM fixture_topics WHERE topic_id='.$new)===1,'Both reply totals match remaining real posts');
	mutation_check((int)posting_value('SELECT topic_first_post_id FROM fixture_topics WHERE topic_id='.$new)===12 && (int)posting_value('SELECT topic_last_post_id FROM fixture_topics WHERE topic_id='.$new)===14,'New first/last pointers match canonical post IDs');
	mutation_check((int)posting_value('SELECT topic_attachment FROM fixture_topics WHERE topic_id=100')===1 && (int)posting_value('SELECT topic_attachment FROM fixture_topics WHERE topic_id='.$new)===1 && (int)posting_value('SELECT post_attachment FROM fixture_posts WHERE post_id=12')===1,'Attachment flags follow posts on both topics');
	mutation_check((int)posting_value('SELECT topic_vote FROM fixture_topics WHERE topic_id=100')===1 && (int)posting_value('SELECT topic_vote FROM fixture_topics WHERE topic_id='.$new)===0,'Poll remains only with original first post');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_watches WHERE topic_id='.$new)===1 && (int)posting_value('SELECT user_id FROM fixture_watches WHERE topic_id='.$new)===9 && (int)posting_value('SELECT notify_status FROM fixture_watches WHERE topic_id='.$new)===0,'Only moved subscribed authors are added once with fresh notification state');
	mutation_check((int)posting_value('SELECT forum_posts FROM fixture_forums WHERE forum_id=3')===4 && (int)posting_value('SELECT forum_posts FROM fixture_forums WHERE forum_id=4')===2 && (int)posting_value('SELECT forum_topics FROM fixture_forums WHERE forum_id=4')===1,'Forum counters synchronize after split');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===2,'Completed split audits source and destination');
	topic_split_failure(function() { topic_split_run(); },'Moderation_split_changed');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_topics')===2,'Replayed selection creates no extra empty topic');

	topic_split_fixture(); $split=topic_split_run(array(13),'after'); $new=$split['topic_id'];
	mutation_check($split['post_ids']===array(13,14,15) && (int)posting_value('SELECT topic_id FROM fixture_posts WHERE post_id=12')===100,'Beyond mode uses stable timestamp/post-ID boundary, excluding earlier timestamp ties');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_watches WHERE topic_id='.$new)===2,'Beyond mode expands authors together with all selected posts');
	topic_split_fixture(); $split=topic_split_run(array(14,13),'after',3); $new=$split['topic_id'];
	mutation_check($split['post_ids']===array(13,14,15) && (int)posting_value('SELECT forum_posts FROM fixture_forums WHERE forum_id=3')===6 && (int)posting_value('SELECT forum_topics FROM fixture_forums WHERE forum_id=3')===2,'Earliest selected boundary also supports same-forum splits');
	foreach(array(array(10),array(10,12,14),array(11,12,13,14,15,10)) as $ids)
	{
		topic_split_fixture(); topic_split_failure(function() use($ids) { topic_split_run($ids); },'Moderation_split_keep_first');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_topics')===1 && (int)posting_value('SELECT COUNT(*) FROM fixture_posts')===6,'First-post selection cannot empty source or orphan poll');
	}
	topic_split_fixture(); $mutation_server->pdo->exec('UPDATE fixture_posts SET post_time=20 WHERE post_id=10');
	topic_split_failure(function() { topic_split_run(array(13),'after'); },'Moderation_split_keep_first');
	foreach(array(array(999),array(12,999),array('12x',14),array(0,14),array(array(12),14),array('9223372036854775808',14),array()) as $ids)
	{
		topic_split_fixture(); $expected=in_array(999,$ids,true)?'Moderation_split_changed':'None_selected';
		topic_split_failure(function() use($ids) { topic_split_run($ids); },$expected);
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_topics')===1,'Malformed or incomplete selection never creates a new topic');
	}
	topic_split_fixture(); $mutation_server->pdo->exec('INSERT INTO fixture_topics (topic_id,forum_id) VALUES (200,3)'); $mutation_server->pdo->exec('UPDATE fixture_posts SET topic_id=200 WHERE post_id=14');
	topic_split_failure(function() { topic_split_run(); },'Moderation_split_changed');
	mutation_check((int)posting_value('SELECT topic_id FROM fixture_posts WHERE post_id=12')===100,'Same-forum mixed-topic batch does not partially split');
	foreach(array(
		array('DELETE FROM fixture_groups','Not_Moderator'),array('UPDATE fixture_auth SET auth_mod=0','Not_Moderator'),
		array('UPDATE fixture_forums SET auth_read=5 WHERE forum_id=3','Not_Moderator'),
		array('UPDATE fixture_forums SET auth_view=5 WHERE forum_id=4','Moderation_split_denied'),
		array('UPDATE fixture_forums SET auth_read=5 WHERE forum_id=4','Moderation_split_denied'),
		array('UPDATE fixture_forums SET auth_post=5 WHERE forum_id=4','Moderation_split_denied'),
		array('UPDATE fixture_forums SET forum_status=1 WHERE forum_id=4','Forum_locked'),
		array("UPDATE fixture_forums SET forum_link='https://example.invalid/' WHERE forum_id=4",'Forum_not_exist'),
		array('DELETE FROM fixture_forums WHERE forum_id=4','Forum_not_exist'),
		array('UPDATE fixture_topics SET topic_moved_id=99','Moderation_split_changed'),
		array('UPDATE fixture_topics SET forum_id=4','Moderation_split_changed'),
		array('UPDATE fixture_topics SET topic_status=2','Moderation_split_changed'),
		array('DELETE FROM fixture_topics','Moderation_split_changed'),
		array('UPDATE fixture_posts SET forum_id=4 WHERE post_id=14','Moderation_split_changed')
	) as $case)
	{
		topic_split_fixture(); $mutation_server->pdo->exec($case[0]);
		topic_split_failure(function() { topic_split_run(); },$case[1]);
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===0,'Denied split creates no audit record');
	}
	topic_split_fixture(); $userdata['session_logged_in']=false;
	topic_split_failure(function() { topic_split_run(); },'Not_Moderator');
	topic_split_fixture(); $mutation_server->pdo->exec('INSERT INTO fixture_auth (forum_id,group_id,auth_mod) VALUES (4,7,1)'); $mutation_server->pdo->exec('UPDATE fixture_forums SET forum_status=1,auth_post=5 WHERE forum_id=4');
	mutation_check(topic_split_run()['post_ids']===array(12,14),'Destination moderator can split into locked forum');
	topic_split_fixture(); $mutation_server->pdo->exec('UPDATE fixture_forums SET count_posts=0 WHERE forum_id=4');
	$split=topic_split_run();
	mutation_check((int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=9')===0 && (int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===2 && (int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=-1')===77,'Counted-to-excluded split updates affected real posters only');
	$mutation_server->pdo->exec('INSERT INTO fixture_auth (forum_id,group_id,auth_mod) VALUES (4,7,1)'); phpbb_move_topics($db,4,3,array($split['topic_id']));
	mutation_check((int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=9')===2,'Moving split topic back restores actual user totals');
	foreach(array('0',"O'Reilly \\ 😀",str_repeat('ä',59).'😀extra',str_repeat('&',20)) as $subject)
	{
		topic_split_fixture(); $split=topic_split_run(array(12),'selected',4,$subject);
		mutation_check(posting_value('SELECT topic_title FROM fixture_topics WHERE topic_id='.$split['topic_id'])===phpbb_storage_subject($subject),'Title uses shared whole-character/entity storage boundary');
	}
	foreach(array(''=>'Empty_subject',"bad\xC3"=>'Moderation_split_subject',str_repeat('a',4097)=>'Moderation_split_subject') as $subject=>$error)
	{
		topic_split_fixture(); topic_split_failure(function() use($subject) { topic_split_run(array(12),'selected',4,$subject); },$error);
	}
	foreach(array('SELECT forum_id','SELECT a.forum_id','INSERT INTO fixture_topics','UPDATE fixture_posts p JOIN','UPDATE fixture_topics SET topic_replies','SELECT attach_id','INSERT INTO fixture_watches','UPDATE fixture_forums','INSERT INTO fixture_action_log') as $failure)
	{
		topic_split_fixture(); $snapshot=topic_split_snapshot(); $mutation_server->failure=$failure;
		topic_split_failure(function() { topic_split_run(); },'Moderation_split_failed');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_posts')===6 && topic_split_snapshot()===$snapshot,'Storage failure never deletes text, poll, attachment references or source preferences');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===0,'Failed split is not reported as complete');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_topics t WHERE NOT EXISTS (SELECT 1 FROM fixture_posts p WHERE p.topic_id=t.topic_id)')===0,'Failed publication removes only its empty new topic');
	}
	foreach(array('UPDATE fixture_topics SET topic_moved_id=99 WHERE topic_id=100','DELETE FROM fixture_posts WHERE post_id=10','UPDATE fixture_forums SET forum_status=1 WHERE forum_id=4','DELETE FROM fixture_topics WHERE topic_id<>100') as $change)
	{
		topic_split_fixture(); $mutation_server->hook=function($sql) use($change) { if(strpos($sql,'UPDATE fixture_posts p JOIN')===0) { $GLOBALS['mutation_server']->pdo->exec($change); } };
		topic_split_failure(function() { topic_split_run(); },'Moderation_split_changed');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE topic_id<>100')===0 && (int)posting_value('SELECT COUNT(*) FROM fixture_topics')===1,'Changed parent prevents post movement and empty topic is cleaned'); $mutation_server->hook=null;
	}
	topic_split_fixture(); $interleaved=false;
	$mutation_server->hook=function($sql) use(&$interleaved)
	{
		if($interleaved || strpos($sql,'UPDATE fixture_posts p JOIN')!==0) { return; } $interleaved=true;
		$caught=false; try { topic_split_run(); } catch(PhpbbTopicSplitException $e) { $caught=$e->getMessage()==='busy'; } mutation_check($caught,'Second split cannot overlap');
		$caught=false; try { phpbb_move_topics($GLOBALS['db'],3,4,array(100)); } catch(PhpbbTopicMoveException $e) { $caught=$e->getMessage()==='busy'; } mutation_check($caught,'Move cannot overlap split');
		$caught=false; try { phpbb_cast_poll_vote($GLOBALS['db'],100,1); } catch(PhpbbPollStorageException $e) { $caught=$e->getMessage()==='busy'; } mutation_check($caught,'Vote cannot overlap split');
		mutation_expect_failure(function() { posting_submit('reply'); },'busy'); mutation_expect_failure(function() { posting_delete(); },'busy');
	};
	topic_split_run(); $mutation_server->hook=null; mutation_check($interleaved,'Actual split interleaving exercised');
	$modcp=file_get_contents($forum_root.'modcp.php'); $start=strpos($modcp,'if (isset($_POST[\'split_type_all\']) || isset($_POST[\'split_type_beyond\']))'); $end=strpos($modcp,'message_die(GENERAL_MESSAGE, $message);',$start);
	mutation_check($start!==false && $end>$start,'Locate actual split controller'); $branch=substr($modcp,$start,$end-$start).'message_die(GENERAL_MESSAGE, $message); }';
	topic_split_fixture(); $template=new PollTemplateFixture(); $forum_id=3; $topic_id=100;
	$_POST=array('split_type_all'=>1,'new_forum_id'=>'f4','post_id_list'=>array(12,14),'subject'=>addslashes("O'Reilly \\ 😀"));
	$caught=false; try { eval($branch); } catch(MutationFailure $e) { $caught=strpos($e->getMessage(),'Topic_split')===0; }
	mutation_check($caught && posting_value('SELECT topic_title FROM fixture_topics WHERE topic_id<>100')==="O'Reilly \\ 😀" && $topic_id===100,'Controller decodes legacy slashes once and keeps source return link');
	topic_split_fixture(); $_POST['post_id_list']=array(12,'invalid',14);
	$caught=false; try { eval($branch); } catch(MutationFailure $e) { $caught=$e->getMessage()==='None_selected'; }
	mutation_check($caught && (int)posting_value('SELECT COUNT(*) FROM fixture_topics')===1,'Actual controller validates raw complete selection, not silently filtered request IDs');
	echo "Topic split scope, first-post retention, storage, subscriptions, counters and controller checks passed.\n";
}
finally
{
	if(isset($mutation_server) && $mutation_server->owner!==null) { $mutation_server->owner->sql_close(); }
	restore_error_handler();
}
