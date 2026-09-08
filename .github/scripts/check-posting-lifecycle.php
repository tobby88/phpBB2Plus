<?php
require __DIR__ . '/check-attachment-mutation.php';
require_once __DIR__ . '/fixture-current-moderator.php';
foreach (array('GENERAL_MESSAGE'=>200,'BEGIN_TRANSACTION'=>1,'END_TRANSACTION'=>2,'FORUMS_TABLE'=>'fixture_forums','USERS_TABLE'=>'fixture_users','POSTS_TEXT_TABLE'=>'fixture_post_text','VOTE_DESC_TABLE'=>'fixture_votes','VOTE_RESULTS_TABLE'=>'fixture_vote_results','VOTE_USERS_TABLE'=>'fixture_voters','BOOKMARK_TABLE'=>'fixture_bookmarks','TOPICS_WATCH_TABLE'=>'fixture_watches','TOPIC_VIEW_TABLE'=>'fixture_views','SEARCH_WORD_TABLE'=>'fixture_words','SEARCH_MATCH_TABLE'=>'fixture_matches','SQL_LAYER'=>'mysqli','FORUM_LOCKED'=>1,'TOPIC_LOCKED'=>1,'TOPIC_UNLOCKED'=>0,'POST_NEWS'=>4,'ANONYMOUS'=>-1,'POST_POST_URL'=>'p','POST_TOPIC_URL'=>'t','POST_FORUM_URL'=>'f') as $key=>$value) { define($key,$value); }
require $forum_root . 'includes/functions_post.php';
require $forum_root . 'includes/functions_moderation.php';
foreach (array('Topic_post_not_exist','Forum_locked','Topic_locked','No_valid_mode','Delete_own_posts','Edit_own_posts','Cannot_delete_replied','Cannot_delete_poll','Posting_storage_failed','Posting_target_changed','Flood_Error','Stored','Deleted','Poll_delete','None_selected','Moderation_delete_failed','Moderation_delete_changed','Click_view_message','Click_return_forum','Click_return_topic') as $key) { $lang[$key] = $key; }
function board_stats() { mutation_check($GLOBALS['mutation_server']->owner === null, 'Board metadata is refreshed after releasing storage lock'); }
function cache_tree($force = false) { board_stats(); }
function append_sid($url) { return $url; }
class PostingFixturePDO
{
	var $inner;
	function __construct($pdo) { $this->inner = $pdo; }
	function __call($method, $args) { return call_user_func_array(array($this->inner, $method), $args); }
	function query($sql) { return $this->inner->query(str_replace('INSERT IGNORE INTO ', 'INSERT OR IGNORE INTO ', $sql)); }
}
function posting_fixture()
{
	global $mutation_server, $db, $userdata, $is_auth, $board_config, $phpbb_root_path, $phpEx, $ctracker_config, $user_ip;
	$mutation_server = new MutationServer(); $db = new MutationForum();
	$mutation_server->pdo = new PostingFixturePDO($mutation_server->pdo); $p = $mutation_server->pdo;
	foreach (array('forum_id INTEGER DEFAULT 3','poster_id INTEGER DEFAULT 8','post_username TEXT','post_time INTEGER DEFAULT 1','poster_ip TEXT','enable_bbcode INTEGER DEFAULT 1','enable_html INTEGER DEFAULT 0','enable_smilies INTEGER DEFAULT 1','enable_sig INTEGER DEFAULT 0','post_icon INTEGER DEFAULT 0','post_edit_time INTEGER DEFAULT 0','post_edit_count INTEGER DEFAULT 0') as $column) { $p->exec('ALTER TABLE fixture_posts ADD ' . $column); }
	foreach (array('forum_id INTEGER DEFAULT 3','topic_status INTEGER DEFAULT 0','topic_moved_id INTEGER DEFAULT 0','topic_title TEXT','topic_desc TEXT','topic_poster INTEGER DEFAULT 8','topic_time INTEGER DEFAULT 1','news_id INTEGER DEFAULT 0','topic_type INTEGER DEFAULT 0','topic_calendar_time INTEGER DEFAULT 0','topic_calendar_duration INTEGER DEFAULT 0','topic_icon INTEGER DEFAULT 0','topic_announce_duration INTEGER DEFAULT 0','topic_vote INTEGER DEFAULT 0','topic_replies INTEGER DEFAULT 0','topic_first_post_id INTEGER DEFAULT 10','topic_last_post_id INTEGER DEFAULT 10') as $column) { $p->exec('ALTER TABLE fixture_topics ADD ' . $column); }
	$p->exec('CREATE TABLE fixture_forums (forum_id INTEGER PRIMARY KEY, forum_status INTEGER, count_posts INTEGER, forum_posts INTEGER, forum_topics INTEGER, forum_last_post_id INTEGER)');
	$p->exec('INSERT INTO fixture_forums VALUES (3,0,1,1,1,10),(4,0,1,0,0,0)');
	$p->exec('CREATE TABLE fixture_users (user_id INTEGER PRIMARY KEY, user_posts INTEGER)');
	$p->exec('INSERT INTO fixture_users VALUES (8,1),(9,0),(-1,77)');
	$p->exec('CREATE TABLE fixture_post_text (post_id INTEGER PRIMARY KEY, post_text TEXT, post_subject TEXT, bbcode_uid TEXT)');
	$p->exec("INSERT INTO fixture_post_text VALUES (10,'originalword','originaltitle','abc')");
	$p->exec('CREATE TABLE fixture_votes (vote_id INTEGER PRIMARY KEY AUTOINCREMENT, topic_id INTEGER, vote_text TEXT, vote_start INTEGER, vote_length INTEGER)');
	$p->exec('CREATE TABLE fixture_vote_results (vote_id INTEGER, vote_option_id INTEGER, vote_option_text TEXT, vote_result INTEGER)');
	$p->exec('CREATE TABLE fixture_voters (vote_id INTEGER, vote_user_id INTEGER)');
	foreach (array('fixture_bookmarks','fixture_watches','fixture_views') as $table) { $p->exec('CREATE TABLE ' . $table . ' (topic_id INTEGER, user_id INTEGER)'); $p->exec('INSERT INTO ' . $table . ' VALUES (100,8)'); }
	$p->exec('CREATE TABLE fixture_words (word_id INTEGER PRIMARY KEY AUTOINCREMENT, word_text VARCHAR(50) UNIQUE, word_common INTEGER DEFAULT 0)');
	$p->exec('CREATE TABLE fixture_matches (post_id INTEGER, word_id INTEGER, title_match INTEGER)');
	$p->exec("INSERT INTO fixture_words VALUES (1,'originalword',0),(2,'originaltitle',0)");
	$p->exec('INSERT INTO fixture_matches VALUES (10,1,0),(10,2,1)');
	$userdata = array('user_id'=>8,'user_level'=>0,'user_posts'=>1);
	$is_auth = array('auth_mod'=>false,'auth_pollcreate'=>true);
	$board_config = array('default_lang'=>'english','flood_interval'=>0);
	$phpbb_root_path = $GLOBALS['forum_root']; $phpEx = 'php'; $user_ip = '127.0.0.1';
	$ctracker_config = new stdClass(); $ctracker_config->settings = array('spammer_blockmode'=>0,'spam_attack_boost'=>0);
}
function posting_value($sql) { return $GLOBALS['mutation_server']->pdo->query($sql)->fetchColumn(); }
function posting_submit($mode, $post_id = 10, $topic_id = 100, $forum_id = 3, $poll = false)
{
	$post_data = array('first_post'=>true,'last_post'=>true,'poster_post'=>true,'poster_id'=>8,'has_poll'=>$poll === 'edit','edit_poll'=>true);
	$message = ''; $meta = ''; $poll_id = $poll === 'edit' ? 1 : 0; $topic_type=0; $bbcode_on=1; $html_on=0; $smilies_on=1; $attach_sig=0; $bbcode_uid='abc';
	$poll_title = $poll ? 'fixture poll' : ''; $poll_options = $poll ? array(1=>'firstoption',2=>'secondoption') : array(); $poll_length=1; $topic_desc='description'; $news_category='';
	submit_post($mode, $post_data, $message, $meta, $forum_id, $topic_id, $post_id, $poll_id, $topic_type, $bbcode_on, $html_on, $smilies_on, $attach_sig, $bbcode_uid, '', 'fixturetitle', addslashes("Grüße author's quasarwort"), $poll_title, $poll_options, $poll_length, $topic_desc, 0,0,0,0,$news_category);
	return array($post_id,$topic_id,$post_data,$message,$poll_id);
}
function posting_delete($post_id = 10, $topic_id = 100, $mode = 'delete', $poll = false)
{
	$forum_id=3; $poll_id=$poll ? 1 : 0; $message=''; $meta='';
	$post_data=array('first_post'=>true,'last_post'=>true,'last_topic'=>true,'poster_post'=>true,'poster_id'=>8,'has_poll'=>$poll,'edit_poll'=>true);
	delete_post($mode,$post_data,$message,$meta,$forum_id,$topic_id,$post_id,$poll_id);
	return $post_data;
}
set_error_handler(function ($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	posting_fixture();
	$created = posting_submit('newtopic',0,0);
	$reply = posting_submit('reply',0,$created[1]);
	$legacy_mode='reply'; $legacy_data=$reply[2]; $legacy_forum=3; $legacy_topic=$reply[1]; $legacy_post=$reply[0]; $legacy_user=8;
	update_post_stats($legacy_mode,$legacy_data,$legacy_forum,$legacy_topic,$legacy_post,$legacy_user);
	mutation_check((int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===3 && (int)posting_value('SELECT forum_posts FROM fixture_forums WHERE forum_id=3')===3,'New topic and reply update counters exactly once');
	mutation_check((int)posting_value('SELECT topic_replies FROM fixture_topics WHERE topic_id='.$created[1])===1,'Reply counters share publication');
	posting_submit('editpost',$reply[0],$reply[1]); posting_submit('editpost',$reply[0],$reply[1]);
	mutation_check(posting_value('SELECT post_text FROM fixture_post_text WHERE post_id='.$reply[0])==="Grüße author's quasarwort",'Edits preserve quoted UTF-8 on actual storage path');
	mutation_check((int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===3,'Identical edits do not double-count posts');
	posting_delete($reply[0],$reply[1]); posting_delete($created[0],$created[1]);
	mutation_check((int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===1 && (int)posting_value('SELECT COUNT(*) FROM fixture_topics')===1,'Delete reply then topic without nested locks');
	mutation_check($mutation_server->owner===null,'Release after successful complete core operation');
	posting_fixture();
	$mutation_server->pdo->exec('INSERT INTO fixture_topics (topic_id,forum_id,topic_moved_id) VALUES (200,3,100),(201,4,100)');
	$mutation_server->pdo->exec('INSERT INTO fixture_posts (post_id,topic_id,forum_id,poster_id) VALUES (14,201,4,9)');
	$mutation_server->pdo->exec('UPDATE fixture_forums SET forum_topics=2 WHERE forum_id=3');
	posting_delete();
	mutation_check((int)posting_value('SELECT forum_topics FROM fixture_forums WHERE forum_id=3')===0 && (int)posting_value('SELECT COUNT(*) FROM fixture_topics WHERE topic_id=201')===1,'Normal deletion synchronizes same-forum redirect totals without removing a nonempty stub');

	foreach (array('DELETE FROM fixture_topics','UPDATE fixture_topics SET forum_id=4','UPDATE fixture_topics SET topic_moved_id=999') as $change)
	{
		posting_fixture(); $mutation_server->pdo->exec($change);
		mutation_expect_failure(function () { posting_submit('reply'); }, 'Topic_post_not_exist');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_posts')===1 && !$mutation_server->owner,'Stale reply never creates an orphan');
	}
	foreach (array('forum','topic') as $type)
	{
		posting_fixture(); $mutation_server->pdo->exec('UPDATE fixture_'.$type.'s SET '.$type.'_status=1');
		mutation_expect_failure(function () { posting_submit('reply'); }, ucfirst($type).'_locked');
	}
	posting_fixture(); $mutation_server->pdo->exec('UPDATE fixture_posts SET poster_id=9');
	mutation_expect_failure(function () { posting_submit('editpost'); }, 'Edit_own_posts');
	mutation_expect_failure(function () { posting_delete(); }, 'Delete_own_posts');
	mutation_check(posting_value('SELECT post_text FROM fixture_post_text WHERE post_id=10')==='originalword','Changed owner retains original text');
	posting_fixture(); $newreply=posting_submit('reply');
	mutation_expect_failure(function () { posting_delete(); }, 'Cannot_delete_replied');
	$is_auth['auth_mod']=true; posting_delete();
	mutation_check((int)posting_value('SELECT topic_first_post_id FROM fixture_topics WHERE topic_id=100')===$newreply[0] && (int)posting_value('SELECT COUNT(*) FROM fixture_bookmarks')===1,'Moderator deletion of first post retains topic and preferences with fresh bounds');

	posting_fixture(); $interleaved=false;
	$mutation_server->hook=function($sql) use (&$interleaved)
	{
		if ($interleaved || strpos($sql,'INSERT INTO fixture_post_text')!==0) { return; }
		$interleaved=true;
		mutation_expect_failure(function () { posting_delete(); }, 'busy');
		mutation_expect_failure(function () { phpbb_delete_moderated_topics($GLOBALS['db'],3,array(100)); }, 'busy');
	};
	posting_submit('reply'); $mutation_server->hook=null;
	mutation_check($interleaved && !$mutation_server->owner,'Parent deletion cannot interleave with text/index/counter publication');
	fixture_current_moderator($mutation_server->pdo);
	phpbb_delete_moderated_topics($db,3,array(100));
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_matches')===0 && (int)posting_value('SELECT forum_posts FROM fixture_forums WHERE forum_id=3')===0,'Moderator cleanup includes search and forum totals on owning connection');
	mutation_expect_failure(function () { posting_submit('reply'); }, 'Topic_post_not_exist');

	foreach (array('SELECT forum_status','SELECT MAX(post_time)','INSERT INTO fixture_posts','INSERT INTO fixture_post_text','UPDATE fixture_forums','UPDATE fixture_users') as $failure)
	{
		posting_fixture(); $mutation_server->failure=$failure;
		mutation_expect_failure(function () { posting_submit('reply'); });
		mutation_check(!$mutation_server->owner,'Release lock after read or partial write failure');
	}
	posting_fixture(); $mutation_server->failure='UPDATE fixture_post_text';
	mutation_expect_failure(function () { posting_submit('editpost'); });
	mutation_check(posting_value('SELECT post_text FROM fixture_post_text WHERE post_id=10')==='originalword' && (int)posting_value('SELECT COUNT(*) FROM fixture_matches')===2,'Failed text edit preserves previous search references');
	posting_fixture();
	$mutation_server->hook=function($sql)
	{
		if (strpos($sql,'UPDATE fixture_post_text')===0) { $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_posts SET poster_id=9 WHERE post_id=10'); }
	};
	mutation_expect_failure(function () { posting_submit('editpost'); }, 'Posting_target_changed');
	mutation_check(posting_value('SELECT post_text FROM fixture_post_text WHERE post_id=10')==='originalword','Write predicate rejects a newly changed owner without destroying text');
	posting_fixture();
	$mutation_server->hook=function($sql)
	{
		if (strpos($sql,'UPDATE fixture_post_text')===0) { $GLOBALS['mutation_server']->pdo->exec('DELETE FROM fixture_post_text WHERE post_id=10'); }
	};
	mutation_expect_failure(function () { posting_submit('editpost'); }, 'Posting_target_changed');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_matches')===2,'Lost text row is not treated as a successful unchanged edit');
	posting_fixture();
	$mutation_server->hook=function($sql)
	{
		if (strpos($sql,'INSERT INTO fixture_posts')===0) { $GLOBALS['mutation_server']->pdo->exec('DELETE FROM fixture_topics WHERE topic_id=100'); }
	};
	mutation_expect_failure(function () { posting_submit('reply'); }, 'Posting_target_changed');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_posts')===1,'INSERT SELECT cannot publish into a target lost after validation');
	posting_fixture(); $mutation_server->failure='DELETE FROM fixture_posts';
	mutation_expect_failure(function () { posting_delete(); });
	mutation_check((int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===1 && (int)posting_value('SELECT COUNT(*) FROM fixture_matches')===2,'Failed parent deletion leaves counts and index intact');

	posting_fixture(); $pollpost=posting_submit('newtopic',0,0,3,true); $is_auth['auth_mod']=true;
	$mutation_server->hook=function($sql,$connection)
	{
		if (strpos($sql,'UPDATE fixture_vote_results SET vote_option_text')===0) { $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_vote_results SET vote_result=vote_result+1 WHERE vote_option_id=1'); }
	};
	posting_submit('editpost',$pollpost[0],$pollpost[1],3,'edit'); $mutation_server->hook=null;
	mutation_check((int)posting_value('SELECT vote_result FROM fixture_vote_results WHERE vote_option_id=1')===2,'Poll option text edits do not restore stale vote counts');
	$is_auth['auth_mod']=false;
	mutation_expect_failure(function () use ($pollpost) { posting_delete($pollpost[0],$pollpost[1],'poll_delete',true); }, 'Posting_target_changed');
	$is_auth['auth_mod']=true; posting_delete($pollpost[0],$pollpost[1],'poll_delete',true);
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_votes')===0 && (int)posting_value('SELECT COUNT(*) FROM fixture_posts')===2,'Explicit poll deletion keeps posts');
	posting_fixture(); $mutation_server->pdo->exec('UPDATE fixture_forums SET count_posts=0 WHERE forum_id=3');
	$uncounted=posting_submit('reply'); posting_delete($uncounted[0],$uncounted[1]);
	mutation_check((int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===1,'Excluded forum leaves personal counts unchanged through publication and deletion');
	posting_fixture(); $userdata['user_id']=-1;
	posting_submit('reply');
	mutation_check((int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=-1')===77,'Guest posts never change shared guest identity counters');
	$controller=file_get_contents($forum_root.'posting.php');
	mutation_check(strpos($controller,'update_post_stats(')===false,'Controller does not repeat already-coordinated counters');
	echo "Posting lifecycle, stale targets, counters, polls and writer coordination checks passed.\n";
}
finally
{
	if (isset($mutation_server) && $mutation_server->owner!==null) { $mutation_server->owner->sql_close(); }
	restore_error_handler();
}
