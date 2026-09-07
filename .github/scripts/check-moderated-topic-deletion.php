<?php
require __DIR__ . '/check-attachment-mutation.php';
foreach (array('GENERAL_MESSAGE'=>200,'BEGIN_TRANSACTION'=>1,'END_TRANSACTION'=>2,'FORUMS_TABLE'=>'fixture_forums','USERS_TABLE'=>'fixture_users','POSTS_TEXT_TABLE'=>'fixture_post_text','VOTE_DESC_TABLE'=>'fixture_votes','VOTE_RESULTS_TABLE'=>'fixture_vote_results','VOTE_USERS_TABLE'=>'fixture_voters','BOOKMARK_TABLE'=>'fixture_bookmarks','TOPICS_WATCH_TABLE'=>'fixture_watches','TOPIC_VIEW_TABLE'=>'fixture_views') as $key=>$value) { define($key,$value); }
$lang['None_selected'] = 'none';
$lang['Moderation_delete_failed'] = 'Moderated deletion failed';
$lang['Moderation_delete_changed'] = 'Post changed during moderated topic deletion';
require $forum_root . 'includes/functions_moderation.php';
function moderation_fixture($count_posts = 1)
{
	global $mutation_server, $upload_dir, $db;
	$mutation_server = new MutationServer(); $db = new MutationForum();
	$pdo = $mutation_server->pdo;
	$pdo->exec('ALTER TABLE fixture_posts ADD forum_id INTEGER DEFAULT 3');
	$pdo->exec('ALTER TABLE fixture_posts ADD poster_id INTEGER DEFAULT 8');
	$pdo->exec('ALTER TABLE fixture_topics ADD forum_id INTEGER DEFAULT 3');
	$pdo->exec('ALTER TABLE fixture_topics ADD topic_moved_id INTEGER DEFAULT 0');
	$pdo->exec('CREATE TABLE fixture_forums (forum_id INTEGER PRIMARY KEY, count_posts INTEGER)');
	$pdo->exec('CREATE TABLE fixture_users (user_id INTEGER PRIMARY KEY, user_posts INTEGER)');
	$pdo->exec('CREATE TABLE fixture_post_text (post_id INTEGER PRIMARY KEY, post_text TEXT)');
	$pdo->exec('CREATE TABLE fixture_votes (vote_id INTEGER PRIMARY KEY, topic_id INTEGER)');
	$pdo->exec('CREATE TABLE fixture_vote_results (vote_id INTEGER, vote_result INTEGER)');
	$pdo->exec('CREATE TABLE fixture_voters (vote_id INTEGER, vote_user_id INTEGER)');
	foreach (array('fixture_bookmarks','fixture_watches','fixture_views') as $table)
	{
		$pdo->exec('CREATE TABLE '.$table.' (topic_id INTEGER, user_id INTEGER)');
		$pdo->exec('INSERT INTO '.$table.' VALUES (100,8),(101,8)');
	}
	$pdo->exec('INSERT INTO fixture_forums VALUES (3,'.(int)$count_posts.'),(4,0)');
	$pdo->exec('INSERT INTO fixture_users VALUES (8,5),(9,0),(-1,77)');
	$pdo->exec('INSERT INTO fixture_topics VALUES (101,1,4,0),(200,0,4,100),(201,0,4,100)');
	$pdo->exec('INSERT INTO fixture_posts VALUES (11,100,0,3,9),(12,100,0,3,-1),(13,101,1,4,8),(14,201,0,4,8)');
	$pdo->exec("INSERT INTO fixture_post_text VALUES (10,'Grüße'),(11,'second'),(12,'guest'),(13,'foreign'),(14,'nonempty shadow')");
	$pdo->exec('INSERT INTO fixture_votes VALUES (1000,100),(1001,101)');
	$pdo->exec('INSERT INTO fixture_vote_results VALUES (1000,2),(1001,7)');
	$pdo->exec('INSERT INTO fixture_voters VALUES (1000,8),(1001,8)');
	file_put_contents($upload_dir.'/fixture.txt','owned');
	mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment','post',10);
	$pdo->exec('INSERT INTO fixture_links VALUES (1,13,0,8,0)');
}
function moderation_scalar($sql) { return (int)$GLOBALS['mutation_server']->pdo->query($sql)->fetchColumn(); }
function moderation_untouched()
{
	global $mutation_server, $upload_dir;
	mutation_check(moderation_scalar('SELECT user_posts FROM fixture_users WHERE user_id=8')===5 && moderation_scalar('SELECT user_posts FROM fixture_users WHERE user_id=9')===0 && moderation_scalar('SELECT user_posts FROM fixture_users WHERE user_id=-1')===77,'Unremoved posts never change counters');
	mutation_check($mutation_server->count_rows(POSTS_TABLE)===5 && $mutation_server->count_rows(POSTS_TEXT_TABLE)===5,'Preserve all unremoved parent/text rows');
	mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===2 && is_file($upload_dir.'/fixture.txt'),'Preserve attachment references and bytes');
	mutation_check($mutation_server->owner===null,'Release owning connection');
}
class ModerationReproDatabase
{
	function sql_query($sql) { return $GLOBALS['mutation_server']->pdo->query($sql); }
	function sql_fetchrow($result) { return $result->fetch(PDO::FETCH_ASSOC); }
	function sql_freeresult($result) { $result->closeCursor(); }
}
$upload_dir = sys_get_temp_dir().'/phpbb-moderation-'.uniqid('',true);
set_error_handler(function ($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	mutation_check(mkdir($upload_dir,0700),'Owned moderator fixture');
	$source=file_get_contents($forum_root.'modcp.php');
	$early=strpos($source, "\t\t\t\$sql = \"SELECT poster_id, COUNT(post_id)");
	if ($early!==false)
	{
		moderation_fixture(); $db=new ModerationReproDatabase(); $topic_id_sql='100';
		$end=strpos($source,"\t\t\t\$sql = \"SELECT post_id ",$early);
		eval(substr($source,$early,$end-$early));
		moderation_untouched();
	}
	mutation_check(strpos($source,'phpbb_delete_moderated_topics($db, $forum_id, $topics)')!==false,'Actual controller must use coordinated storage');
	foreach (array(array(),array(0),array('100x'),array(array(100)),array('100,101')) as $ids)
	{
		moderation_fixture(); $connections=count($mutation_server->connections);
		mutation_expect_failure(function () use ($db,$ids) { phpbb_delete_moderated_topics($db,3,$ids); },'none');
		mutation_check(count($mutation_server->connections)===$connections,'Invalid topic set must not open a mutation connection'); moderation_untouched();
	}
	foreach (array(0,true,array(3),'3,3','999999999999999999999999') as $forum)
	{
		moderation_fixture(); mutation_expect_failure(function () use ($db,$forum) { phpbb_delete_moderated_topics($db,$forum,array(100)); },'none'); moderation_untouched();
	}
	foreach (array('connect','DELETE FROM fixture_topics WHERE topic_id = 100','DELETE FROM fixture_posts WHERE post_id = 10') as $failure)
	{
		moderation_fixture(); $mutation_server->failure=$failure;
		mutation_expect_failure(function () use ($db) { phpbb_delete_moderated_topics($db,3,array(100)); }); moderation_untouched();
	}
	moderation_fixture(); $mutation_server->hook=function ($sql) { if (strpos($sql,'DELETE FROM fixture_topics WHERE topic_id = 100')===0) { $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_topics SET forum_id=4 WHERE topic_id=100'); } };
	$removed=phpbb_delete_moderated_topics($db,3,array(100));
	mutation_check(!$removed['topic_ids'] && !$removed['post_ids'],'Moved topic must not authorize dependent deletion'); moderation_untouched();
	moderation_fixture(); $mutation_server->hook=function ($sql) { if (strpos($sql,'DELETE FROM fixture_posts WHERE post_id = 10')===0) { $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_posts SET poster_id=9 WHERE post_id=10'); } };
	mutation_expect_failure(function () use ($db) { phpbb_delete_moderated_topics($db,3,array(100)); },'Post changed during moderated topic deletion'); moderation_untouched();
	foreach (array(0,1) as $count_posts)
	{
		moderation_fixture($count_posts); $interleaved=false;
		$mutation_server->hook=function ($sql) use (&$interleaved)
		{
			if ($interleaved || strpos($sql,'DELETE FROM fixture_posts WHERE post_id = 10')!==0) { return; }
			$interleaved=true; mutation_expect_failure(function () { mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment','post',10); },'busy');
		};
		$removed=phpbb_delete_moderated_topics($db,'3',array('100',101,100));
		mutation_check($removed===array('topic_ids'=>array(100),'post_ids'=>array(10,11,12)),'Only authorized forum parents appear in removed result');
		mutation_check($interleaved && $mutation_server->owner===null,'Coordinate publication/deletion on one owner');
		mutation_check(moderation_scalar('SELECT user_posts FROM fixture_users WHERE user_id=8')===($count_posts?4:5) && moderation_scalar('SELECT user_posts FROM fixture_users WHERE user_id=9')===0 && moderation_scalar('SELECT user_posts FROM fixture_users WHERE user_id=-1')===77,'Count only actual removed counted posts; floor0/preserve guest');
		mutation_check($mutation_server->count_rows(POSTS_TABLE)===2 && $mutation_server->count_rows(POSTS_TEXT_TABLE)===2,'Preserve foreign and nonempty-shadow posts/text');
		mutation_check($mutation_server->count_rows(TOPICS_TABLE)===2 && moderation_scalar('SELECT COUNT(*) FROM fixture_topics WHERE topic_id=201')===1,'Remove empty redirect stub only');
		foreach (array(BOOKMARK_TABLE,TOPICS_WATCH_TABLE,TOPIC_VIEW_TABLE,VOTE_DESC_TABLE,VOTE_RESULTS_TABLE,VOTE_USERS_TABLE) as $table) { mutation_check($mutation_server->count_rows($table)===1,'Scope dependent cleanup: '.$table); }
		mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'Shared foreign attachment survives');
		$again=phpbb_delete_moderated_topics($db,3,array(100)); mutation_check(!$again['topic_ids'] && moderation_scalar('SELECT user_posts FROM fixture_users WHERE user_id=8')===($count_posts?4:5),'Duplicate delete cannot recount posts');
		mutation_expect_failure(function () { mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment','post',10); },'unavailable');
		phpbb_delete_moderated_topics($db,4,array(101)); mutation_check(!is_file($upload_dir.'/fixture.txt'),'Deleting last sharing post cleans final file');
	}
	moderation_fixture(); $mutation_server->failure='DELETE FROM fixture_posts WHERE post_id = 11';
	mutation_expect_failure(function () use ($db) { phpbb_delete_moderated_topics($db,3,array(100)); });
	mutation_check(moderation_scalar('SELECT user_posts FROM fixture_users WHERE user_id=8')===4 && moderation_scalar('SELECT user_posts FROM fixture_users WHERE user_id=9')===0 && moderation_scalar('SELECT COUNT(*) FROM fixture_posts WHERE post_id=11')===1 && $mutation_server->owner===null,'Partial failure accounts only for actually removed post');
	foreach (array('UPDATE fixture_users','DELETE FROM fixture_post_text','DELETE FROM fixture_links') as $failure)
	{
		moderation_fixture(); $mutation_server->failure=$failure;
		mutation_expect_failure(function () use ($db) { phpbb_delete_moderated_topics($db,3,array(100)); });
		mutation_check($mutation_server->owner===null && is_file($upload_dir.'/fixture.txt') && $mutation_server->count_rows(ATTACHMENTS_DESC_TABLE)===1 && moderation_scalar('SELECT COUNT(*) FROM fixture_posts WHERE post_id=11')===1,'Later storage failure retains recoverable data and stops remaining posts');
	}
	foreach (array('german','english') as $language)
	{
		$strings=file_get_contents($forum_root.'language/lang_'.$language.'/lang_main.php');
		mutation_check(strpos($strings,"['Moderation_delete_failed']")!==false && strpos($strings,"['Moderation_delete_changed']")!==false,'Localized partial-deletion diagnostics');
	}
	echo "Moderated topic deletion storage checks passed.\n";
}
finally
{
	if (isset($mutation_server) && $mutation_server->owner!==null) { $mutation_server->owner->sql_close(); }
	if (is_file($upload_dir.'/fixture.txt')) { unlink($upload_dir.'/fixture.txt'); }
	if (is_dir($upload_dir)) { rmdir($upload_dir); }
	restore_error_handler();
}
