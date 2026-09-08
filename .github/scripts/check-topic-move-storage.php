<?php
require __DIR__ . '/check-topic-state-storage.php';
foreach(array('POST_GLOBAL_ANNOUNCE'=>3,'TOPIC_MOVED'=>2,'POST_CAT_URL'=>'c') as $key=>$value) { if(!defined($key)) { define($key,$value); } }
require $forum_root . 'includes/functions_topic_move.php';
foreach(array('Moderation_move_failed','Moderation_move_changed','Moderation_move_denied','Forum_not_exist','Topics_Moved','No_Topics_Moved') as $key) { $lang[$key]=$key; }
// SQLite models only the joined UPDATE. All predicates still execute as a
// SELECT; native mysqli/MyISAM/InnoDB fixtures execute the original SQL verbatim.
class TopicMoveFixtureStatement
{
	var $affected;
	function __construct($affected) { $this->affected=$affected; }
	function rowCount() { return $this->affected; }
}
class TopicMoveFixturePDO
{
	var $inner;
	function __construct($inner) { $this->inner=$inner; }
	function __call($method,$args) { return call_user_func_array(array($this->inner,$method),$args); }
	function query($sql)
	{
		if(strpos($sql,'UPDATE fixture_topics t JOIN fixture_posts p ')!==0 || $this->inner->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite') { return $this->inner->query($sql); }
		mutation_check(preg_match('/^UPDATE (.+) SET t.forum_id = ([0-9]+), p.forum_id = \2 WHERE (.+)$/D',$sql,$parts)===1,'Recognize exact joined move SQL');
		$rows=$this->inner->query('SELECT t.topic_id, p.post_id FROM '.$parts[1].' WHERE '.$parts[3])->fetchAll(PDO::FETCH_ASSOC);
		$topics=array(); $posts=array(); foreach($rows as $row) { $topics[(int)$row['topic_id']]=(int)$row['topic_id']; $posts[(int)$row['post_id']]=(int)$row['post_id']; }
		if($topics)
		{
			$this->inner->exec('UPDATE fixture_topics SET forum_id='.$parts[2].' WHERE topic_id IN ('.implode(',',$topics).')');
			$this->inner->exec('UPDATE fixture_posts SET forum_id='.$parts[2].' WHERE post_id IN ('.implode(',',$posts).')');
		}
		return new TopicMoveFixtureStatement(count($topics)+count($posts));
	}
}
function topic_move_fixture()
{
	global $mutation_server;
	topic_state_fixture(); $mutation_server->pdo=new TopicMoveFixturePDO($mutation_server->pdo); $p=$mutation_server->pdo;
	$p->exec("ALTER TABLE fixture_forums ADD forum_link VARCHAR(255) DEFAULT ''");
	$p->exec('ALTER TABLE fixture_topics ADD topic_views INTEGER DEFAULT 4');
	$p->exec("UPDATE fixture_topics SET topic_title=".$p->quote("Grüße O'Reilly \\ 😀").",topic_desc=".$p->quote("Pfad \\ und 'Zitat'").',topic_replies=1,topic_last_post_id=11');
	$p->exec('INSERT INTO fixture_posts (post_id,topic_id,forum_id,poster_id) VALUES (11,100,3,-1)');
	$p->exec('INSERT INTO fixture_links VALUES (1,10,0,8,0)');
	$p->exec('UPDATE fixture_forums SET forum_posts=2,forum_last_post_id=11 WHERE forum_id=3');
}
function topic_move_failure($callback,$expected)
{
	$caught=false; try { call_user_func($callback); } catch(PhpbbTopicMoveException $error) { $caught=$error->getMessage()===$expected; }
	mutation_check($caught,'Expected move failure: '.$expected);
	mutation_check($GLOBALS['mutation_server']->owner===null,'Move failure releases lock');
}
function topic_move_preserved()
{
	$tables=array('fixture_post_text','fixture_matches','fixture_links','fixture_votes','fixture_vote_results','fixture_voters');
	$rows=array(); foreach($tables as $table) { $rows[$table]=$GLOBALS['mutation_server']->pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC); }
	foreach(array('fixture_bookmarks','fixture_watches','fixture_views') as $table) { $rows[$table]=$GLOBALS['mutation_server']->pdo->query('SELECT * FROM '.$table.' WHERE topic_id=100')->fetchAll(PDO::FETCH_ASSOC); }
	$rows['topic']=$GLOBALS['mutation_server']->pdo->query('SELECT topic_title,topic_desc,topic_poster,topic_type,topic_status,topic_replies,topic_first_post_id,topic_last_post_id,topic_attachment,topic_vote,topic_views FROM fixture_topics WHERE topic_id=100')->fetchAll(PDO::FETCH_ASSOC);
	return $rows;
}
set_error_handler(function($severity,$message) { if(error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	topic_move_fixture(); $p=$mutation_server->pdo; $preserved=topic_move_preserved();
	mutation_check(phpbb_move_topics($db,3,4,array(100,100),true)===array(100),'Complete move returns deduplicated IDs');
	mutation_check((int)posting_value('SELECT forum_id FROM fixture_topics WHERE topic_id=100')===4 && (int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE forum_id=4')===2,'Topic and all posts enter destination');
	mutation_check(topic_move_preserved()===$preserved,'Moving retains real topic metadata, text, poll, search, attachments and preferences');
	mutation_check((int)posting_value('SELECT forum_posts FROM fixture_forums WHERE forum_id=3')===0 && (int)posting_value('SELECT forum_topics FROM fixture_forums WHERE forum_id=3')===1 && (int)posting_value('SELECT forum_last_post_id FROM fixture_forums WHERE forum_id=4')===11,'Both forums have current counts including source shadow');
	mutation_check(posting_value('SELECT topic_title FROM fixture_topics WHERE topic_moved_id=100')===posting_value('SELECT topic_title FROM fixture_topics WHERE topic_id=100'),'Shadow title copies quoted UTF-8 without escape accumulation');
	mutation_check(posting_value('SELECT topic_desc FROM fixture_topics WHERE topic_moved_id=100')===posting_value('SELECT topic_desc FROM fixture_topics WHERE topic_id=100'),'Shadow description is retained');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===1,'Actual moved topic is logged once');
	$p->exec('INSERT INTO fixture_auth (forum_id,group_id,auth_mod) VALUES (4,7,1)');
	$stub=(int)posting_value('SELECT topic_id FROM fixture_topics WHERE topic_moved_id=100');
	foreach(array('fixture_bookmarks','fixture_watches','fixture_views') as $table) { $p->exec('INSERT INTO '.$table.' VALUES ('.$stub.',9)'); }
	phpbb_move_topics($db,4,3,array(100),true);
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_topics WHERE topic_id='.$stub.' AND forum_id=3')===0,'Returning removes redundant destination redirect (SQLite may reuse its ID for the new source redirect)');
	foreach(array('fixture_bookmarks','fixture_watches','fixture_views') as $table) { mutation_check((int)posting_value('SELECT COUNT(*) FROM '.$table.' WHERE topic_id='.$stub)===0,'Removed redirect preferences cleaned: '.$table); }
	mutation_check(topic_move_preserved()===$preserved && (int)posting_value('SELECT COUNT(*) FROM fixture_topics')===2,'Round trip keeps original content plus exactly one redirect');
	mutation_check(phpbb_move_topics($db,3,3,array(100),true)===array() && (int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===2,'Same-forum request is a validated no-op');
	phpbb_move_topics($db,3,4,array(100),true);
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_topics')===2,'Repeated round trip does not accumulate redirects');

	topic_move_fixture(); $p=$mutation_server->pdo; $p->exec('UPDATE fixture_forums SET count_posts=0 WHERE forum_id=4');
	phpbb_move_topics($db,3,4,array(100));
	mutation_check((int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===0 && (int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=-1')===77,'Excluded destination recounts real posters, never anonymous sentinel');
	$p->exec('INSERT INTO fixture_auth (forum_id,group_id,auth_mod) VALUES (4,7,1)'); phpbb_move_topics($db,4,3,array(100));
	mutation_check((int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===1,'Returning to counted forum restores actual total');
	topic_move_fixture(); $p=$mutation_server->pdo;
	$p->exec('INSERT INTO fixture_topics (topic_id,forum_id,topic_status,topic_moved_id) VALUES (200,3,2,100),(201,4,2,100)');
	$p->exec('INSERT INTO fixture_posts (post_id,topic_id,forum_id,poster_id) VALUES (20,201,4,9)');
	phpbb_move_topics($db,3,4,array(100),true);
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_topics WHERE forum_id=3 AND topic_moved_id=100')===1,'Existing source redirect is not duplicated');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_topics WHERE topic_id=201')===1 && (int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE post_id=20')===1,'A malformed nonempty destination redirect and its user content are not deleted');
	topic_move_fixture(); $p=$mutation_server->pdo;
	$p->exec('INSERT INTO fixture_auth (forum_id,group_id,auth_mod) VALUES (4,7,1)');
	$p->exec('UPDATE fixture_forums SET auth_post=5,forum_status=1 WHERE forum_id=4');
	mutation_check(phpbb_move_topics($db,3,4,array(100))===array(100),'Current destination moderator may move a normal topic into a locked destination without general posting permission');
	foreach(array(
		array('DELETE FROM fixture_groups','Not_Moderator'),
		array('UPDATE fixture_auth SET auth_mod=0','Not_Moderator'),
		array('UPDATE fixture_forums SET auth_read=5 WHERE forum_id=3','Not_Moderator'),
		array('UPDATE fixture_forums SET auth_post=5 WHERE forum_id=4','Moderation_move_denied'),
		array('UPDATE fixture_forums SET auth_view=5 WHERE forum_id=4','Moderation_move_denied'),
		array('UPDATE fixture_forums SET auth_read=5 WHERE forum_id=4','Moderation_move_denied'),
		array('UPDATE fixture_forums SET forum_status=1 WHERE forum_id=4','Forum_locked'),
		array("UPDATE fixture_forums SET forum_link='https://example.invalid/' WHERE forum_id=4",'Forum_not_exist'),
		array('DELETE FROM fixture_forums WHERE forum_id=4','Forum_not_exist'),
		array('UPDATE fixture_topics SET forum_id=4','Moderation_move_changed'),
		array('UPDATE fixture_topics SET topic_moved_id=99','Moderation_move_changed'),
		array('UPDATE fixture_topics SET topic_status=2','Moderation_move_changed'),
		array('DELETE FROM fixture_topics','Moderation_move_changed'),
		array('DELETE FROM fixture_posts','Moderation_move_changed'),
		array('UPDATE fixture_posts SET forum_id=4 WHERE post_id=11','Moderation_move_changed')
	) as $case)
	{
		topic_move_fixture(); $mutation_server->pdo->exec($case[0]);
		topic_move_failure(function() use($db) { phpbb_move_topics($db,3,4,array(100),true); },$case[1]);
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===0 && (int)posting_value('SELECT COUNT(*) FROM fixture_topics WHERE topic_moved_id=100')===0,'Denied move creates neither audit nor shadow');
	}
	foreach(array(POST_STICKY=>'auth_sticky',POST_ANNOUNCE=>'auth_announce',POST_GLOBAL_ANNOUNCE=>'auth_global_announce',POST_NEWS=>'auth_news') as $type=>$permission)
	{
		topic_move_fixture(); $mutation_server->pdo->exec('UPDATE fixture_topics SET topic_type='.$type); $mutation_server->pdo->exec('UPDATE fixture_forums SET '.$permission.'=5 WHERE forum_id=4');
		topic_move_failure(function() use($db) { phpbb_move_topics($db,3,4,array(100)); },'Moderation_move_denied');
		$mutation_server->pdo->exec('UPDATE fixture_forums SET '.$permission.'=1 WHERE forum_id=4');
		phpbb_move_topics($db,3,4,array(100)); mutation_check((int)posting_value('SELECT topic_type FROM fixture_topics WHERE topic_id=100')===$type,'Allowed special topic type remains intact');
	}
	topic_move_fixture(); $userdata['session_logged_in']=false;
	topic_move_failure(function() use($db) { phpbb_move_topics($db,3,4,array(100)); },'Not_Moderator');
	foreach(array(array(),array(-1),array(0),array('100x'),array(array(100)),array('9223372036854775808')) as $ids)
	{
		topic_move_fixture(); topic_move_failure(function() use($db,$ids) { phpbb_move_topics($db,3,4,$ids); },'None_selected');
	}
	foreach(array(0,-1,true,'4x',array(4),'9223372036854775808') as $invalid)
	{
		topic_move_fixture(); topic_move_failure(function() use($db,$invalid) { phpbb_move_topics($db,3,$invalid,array(100)); },'None_selected');
	}
	topic_move_fixture(); $mutation_server->pdo->exec('INSERT INTO fixture_topics (topic_id,forum_id) VALUES (200,4)');
	topic_move_failure(function() use($db) { phpbb_move_topics($db,3,4,array(100,200)); },'Moderation_move_changed');
	mutation_check((int)posting_value('SELECT forum_id FROM fixture_topics WHERE topic_id=100')===3,'Mixed-forum selection does not partially move');
	topic_move_failure(function() use($db) { phpbb_move_topics($db,3,4,array(100,999)); },'Moderation_move_changed');
	topic_move_fixture(); $p=$mutation_server->pdo;
	$p->exec('INSERT INTO fixture_topics (topic_id,forum_id) VALUES (200,3)'); $p->exec('INSERT INTO fixture_posts (post_id,topic_id,forum_id) VALUES (12,200,3)');
	mutation_check(phpbb_move_topics($db,3,4,array(200,100))===array(100,200),'Valid batch moves every selected topic in stable order');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===2 && (int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE forum_id=4')===3,'Batch counts all topics and all posts');
	foreach(array('SELECT forum_id','SELECT a.forum_id','UPDATE fixture_topics t JOIN','INSERT INTO fixture_topics','UPDATE fixture_forums','INSERT INTO fixture_action_log') as $failure)
	{
		topic_move_fixture(); $mutation_server->failure=$failure;
		topic_move_failure(function() use($db) { phpbb_move_topics($db,3,4,array(100),true); },'Moderation_move_failed');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_action_log')===0,'SQL failure is not falsely audited as success');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_posts p JOIN fixture_topics t ON t.topic_id=p.topic_id WHERE t.forum_id<>p.forum_id')===0,'Even later failures do not separate topic/post forum IDs');
	}
	topic_move_fixture(); $interleaved=false;
	$mutation_server->hook=function($sql) use(&$interleaved)
	{
		if($interleaved || strpos($sql,'UPDATE fixture_topics t JOIN')!==0) { return; } $interleaved=true;
		$caught=false; try { phpbb_move_topics($GLOBALS['db'],3,4,array(100)); } catch(PhpbbTopicMoveException $e) { $caught=$e->getMessage()==='busy'; } mutation_check($caught,'Another move cannot overlap');
		$caught=false; try { phpbb_cast_poll_vote($GLOBALS['db'],100,1); } catch(PhpbbPollStorageException $e) { $caught=$e->getMessage()==='busy'; } mutation_check($caught,'Poll vote cannot overlap move');
		mutation_expect_failure(function() { posting_submit('reply'); },'busy');
		mutation_expect_failure(function() { posting_delete(); },'busy');
	};
	phpbb_move_topics($db,3,4,array(100)); $mutation_server->hook=null; mutation_check($interleaved,'Real move storage interleaving exercised');
	foreach(array('UPDATE fixture_topics SET topic_moved_id=99','DELETE FROM fixture_topics','UPDATE fixture_forums SET forum_status=1 WHERE forum_id=4') as $change)
	{
		topic_move_fixture(); $mutation_server->hook=function($sql) use($change) { if(strpos($sql,'UPDATE fixture_topics t JOIN')===0) { $GLOBALS['mutation_server']->pdo->exec($change); } };
		topic_move_failure(function() use($db) { phpbb_move_topics($db,3,4,array(100)); },'Moderation_move_changed');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE forum_id=4')===0,'Joined predicate rejects a changed target'); $mutation_server->hook=null;
	}
	// Execute the actual confirmed modcp storage/response block, without page rendering.
	$modcp=file_get_contents($forum_root.'modcp.php'); $start=strpos($modcp,'$old_forum_id = $forum_id;'); $end=strpos($modcp,'message_die(GENERAL_MESSAGE, $message);',$start);
	mutation_check($start!==false && $end>$start,'Locate actual move controller'); $branch=substr($modcp,$start,$end-$start).'message_die(GENERAL_MESSAGE, $message);';
	topic_move_fixture(); $template=new PollTemplateFixture(); $forum_id=3; $new_forum_id=4; $topic_id=0; $topic_id_list=array(100); $_POST=array();
	$caught=false; try { eval($branch); } catch(MutationFailure $e) { $caught=strpos($e->getMessage(),'Topics_Moved')===0; }
	mutation_check($caught && $topic_id===0 && strpos($redirect_page,'modcp.php?')===0,'Bulk move keeps moderator return link instead of overwriting selection with last topic');
	topic_move_fixture(); $mutation_server->pdo->exec('UPDATE fixture_forums SET auth_post=5 WHERE forum_id=4');
	$caught=false; try { eval($branch); } catch(MutationFailure $e) { $caught=$e->getMessage()==='Moderation_move_denied'; } mutation_check($caught,'Controller presents controlled move errors');
	echo "Topic move ACL, joined storage, redirect lifecycle, counters and controller checks passed.\n";
}
finally
{
	if(isset($mutation_server) && $mutation_server->owner!==null) { $mutation_server->owner->sql_close(); }
	restore_error_handler();
}
