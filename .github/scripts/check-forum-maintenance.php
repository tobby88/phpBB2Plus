<?php
require __DIR__ . '/check-prune-storage.php';
foreach(array('CATEGORIES_TABLE'=>'fixture_categories','USER'=>0,'MOD'=>2) as $key=>$value) { define($key,$value); }
require $forum_root.'includes/functions_forum_maintenance.php';
$lang['Prune_selection_changed']='Prune_selection_changed';
class ForumMaintenanceStatement
{
	var $count;
	function __construct($count) { $this->count=$count; }
	function rowCount() { return $this->count; }
}
class ForumMaintenanceFixturePDO extends PostingFixturePDO
{
	function query($sql)
	{
		if($this->inner->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')
		{
			if(strpos($sql,'UPDATE fixture_topics t LEFT JOIN')===0)
			{
				mutation_check(preg_match('/^UPDATE (.+) SET t.forum_id = ([0-9]+), p.forum_id = \2 WHERE (.+)$/D',$sql,$parts)===1,'Recognize actual joined forum move');
				$rows=$this->inner->query('SELECT t.topic_id, p.post_id FROM '.$parts[1].' WHERE '.$parts[3])->fetchAll(PDO::FETCH_ASSOC);
				$topics=array(); $posts=array(); foreach($rows as $row) { $topics[(int)$row['topic_id']]=(int)$row['topic_id']; if($row['post_id']!==null) { $posts[(int)$row['post_id']]=(int)$row['post_id']; } }
				foreach(array('fixture_topics'=>array('topic_id',$topics),'fixture_posts'=>array('post_id',$posts)) as $table=>$selection)
				{ if($selection[1]) { $this->inner->exec('UPDATE '.$table.' SET forum_id='.$parts[2].' WHERE '.$selection[0].' IN ('.implode(',',$selection[1]).')'); } }
				return new ForumMaintenanceStatement(count($topics)+count($posts));
			}
			if(strpos($sql,'DELETE f FROM fixture_forums f ')===0)
			{
				$ids=$this->inner->query(str_replace('DELETE f FROM','SELECT f.forum_id FROM',$sql))->fetchAll(PDO::FETCH_COLUMN);
				if($ids) { $this->inner->exec('DELETE FROM fixture_forums WHERE forum_id IN ('.implode(',',$ids).')'); }
				return new ForumMaintenanceStatement(count($ids));
			}
		}
		return parent::query($sql);
	}
}
function forum_maintenance_fixture()
{
	global $mutation_server,$upload_dir;
	prune_fixture(); $p=$mutation_server->pdo; $mutation_server->pdo=new ForumMaintenanceFixturePDO($p);
	$p->exec("ALTER TABLE fixture_forums ADD main_type CHAR(1) DEFAULT 'c'"); $p->exec('ALTER TABLE fixture_forums ADD cat_id INTEGER DEFAULT 1');
	$p->exec('CREATE TABLE fixture_categories (cat_id INTEGER PRIMARY KEY, cat_main_type CHAR(1), cat_main INTEGER)');
	$p->exec("INSERT INTO fixture_categories VALUES (1,'c',0)");
	$p->exec('UPDATE fixture_posts SET forum_id=3 WHERE topic_id=105');
	$p->exec('INSERT INTO fixture_topics (topic_id,forum_id,topic_first_post_id,topic_last_post_id) VALUES (300,4,30,30)');
	$p->exec('INSERT INTO fixture_posts (post_id,topic_id,forum_id,poster_id,post_time) VALUES (30,300,4,9,1)');
	$p->exec("INSERT INTO fixture_post_text VALUES (30,'foreign shared attachment','foreign','abc')");
	$p->exec('INSERT INTO fixture_users (user_id,user_posts,user_level,user_active) VALUES (10,0,2,1),(11,0,2,1),(12,0,1,1),(13,0,2,1)');
	$p->exec('INSERT INTO fixture_groups VALUES (10,7,0),(11,7,0),(12,7,0),(13,7,0),(11,8,0),(13,8,1)');
	$p->exec('INSERT INTO fixture_auth (forum_id,group_id,auth_mod) VALUES (4,8,1)');
	file_put_contents($upload_dir.'/shared.txt','owned forum maintenance fixture');
	$p->exec("INSERT INTO fixture_descriptions (attach_id,physical_filename,thumbnail) VALUES (1,'shared.txt',0)");
	$p->exec('INSERT INTO fixture_links VALUES (1,10,0,8,0)');
	$p->exec('INSERT INTO fixture_links VALUES (1,30,0,9,0)');
}
function forum_content_snapshot()
{
	$state=array(); foreach(array('fixture_post_text','fixture_matches','fixture_links','fixture_descriptions','fixture_votes','fixture_vote_results','fixture_voters','fixture_watches','fixture_bookmarks','fixture_views') as $table)
	{ $state[$table]=$GLOBALS['mutation_server']->pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC); } return $state;
}
$upload_dir=sys_get_temp_dir().'/phpbb-forum-maintenance-'.uniqid('',true);
set_error_handler(function($severity,$message){ if(error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	mutation_check(mkdir($upload_dir,0700),'Create owned maintenance file fixture');
	forum_maintenance_fixture(); $before=forum_content_snapshot();
	$result=phpbb_remove_forum($db,3,4);
	mutation_check($result===array('topics'=>10,'posts'=>9,'target'=>4),'Move reports complete original source set');
	mutation_check(forum_content_snapshot()===$before && is_file($upload_dir.'/shared.txt'),'Move preserves all text/poll/attachment/preference content and bytes');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_topics WHERE forum_id=4')===11 && (int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE forum_id=4')===10,'Move includes empty topics and existing redirect content');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_forums WHERE forum_id=3')===0 && (int)posting_value('SELECT forum_posts FROM fixture_forums WHERE forum_id=4')===10,'Only empty source forum removed; target counters synchronized');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_auth WHERE forum_id=3')===0 && (int)posting_value('SELECT COUNT(*) FROM fixture_prune WHERE forum_id=3')===0,'Removed forum grants/schedule cleaned');
	mutation_check((int)posting_value('SELECT user_level FROM fixture_users WHERE user_id=10')===USER && (int)posting_value('SELECT user_level FROM fixture_users WHERE user_id=11')===MOD && (int)posting_value('SELECT user_level FROM fixture_users WHERE user_id=12')===ADMIN && (int)posting_value('SELECT user_level FROM fixture_users WHERE user_id=13')===USER,'Moderator cleanup respects other current grants, admins and pending membership');
	forum_maintenance_fixture(); $mutation_server->pdo->exec('UPDATE fixture_forums SET count_posts=0 WHERE forum_id=4');
	phpbb_remove_forum($db,3,4);
	mutation_check((int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===0 && (int)posting_value('SELECT user_posts FROM fixture_users WHERE user_id=-1')===77,'Cross-count boundary recounts moved real users only');
	forum_maintenance_fixture(); phpbb_remove_forum($db,3);
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_topics')===1 && (int)posting_value('SELECT COUNT(*) FROM fixture_posts')===1 && (int)posting_value('SELECT COUNT(*) FROM fixture_post_text')===1,'Explicit full removal includes polls/announcements/empty topics but preserves target posts');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_votes')===0 && (int)posting_value('SELECT COUNT(*) FROM fixture_vote_results')===0 && (int)posting_value('SELECT COUNT(*) FROM fixture_voters')===0,'Full removal cleans actual poll records');
	mutation_check(is_file($upload_dir.'/shared.txt') && (int)posting_value('SELECT COUNT(*) FROM fixture_links')===1,'Shared foreign attachment survives full source removal');
	phpbb_remove_forum($db,4); mutation_check(!is_file($upload_dir.'/shared.txt'),'Final sharing forum removal cleans the owned physical file');
	foreach(array("INSERT INTO fixture_forums (forum_id,main_type,cat_id) VALUES (5,'f',3)","INSERT INTO fixture_categories VALUES (2,'f',3)",'UPDATE fixture_posts SET forum_id=4 WHERE post_id=10','INSERT INTO fixture_posts (post_id,topic_id,forum_id) VALUES (99,999,3)',"UPDATE fixture_forums SET forum_link='https://fixture.invalid/' WHERE forum_id=4") as $change)
	{
		forum_maintenance_fixture(); $mutation_server->pdo->exec($change); $before=forum_content_snapshot();
		prune_expect_failure(function() use($db){ phpbb_remove_forum($db,3,4); },'Prune_selection_changed');
		mutation_check(forum_content_snapshot()===$before,'Invalid hierarchy/content/destination changes nothing');
	}
	foreach(array('UPDATE fixture_posts SET post_id=99 WHERE post_id=10','UPDATE fixture_posts SET forum_id=4 WHERE post_id=10','UPDATE fixture_topics SET topic_id=999 WHERE topic_id=107','INSERT INTO fixture_posts (post_id,topic_id,forum_id) VALUES (99,999,3)','UPDATE fixture_forums SET count_posts=0 WHERE forum_id=4') as $change)
	{
		forum_maintenance_fixture(); $changed=false;
		$mutation_server->hook=function($sql) use($change,&$changed){ if(!$changed && strpos($sql,'UPDATE fixture_topics t LEFT JOIN')===0) { $changed=true; $GLOBALS['mutation_server']->pdo->exec($change); } };
		prune_expect_failure(function() use($db){ phpbb_remove_forum($db,3,4); },'Prune_selection_changed');
		mutation_check($changed && (int)posting_value('SELECT forum_id FROM fixture_topics WHERE topic_id=100')===3,'Changed complete set rejects the joined publication before moving any topic');
	}
	forum_maintenance_fixture(); $added=false;
	$mutation_server->hook=function($sql) use(&$added){ if(!$added && strpos($sql,'DELETE f FROM')===0) { $added=true; $GLOBALS['mutation_server']->pdo->exec("INSERT INTO fixture_categories VALUES (2,'f',3)"); } };
	prune_expect_failure(function() use($db){ phpbb_remove_forum($db,3,4); },'Prune_selection_changed');
	mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_forums WHERE forum_id=3')===1 && (int)posting_value('SELECT COUNT(*) FROM fixture_auth WHERE forum_id=3')>0,'Late child prevents parent removal and grant cleanup');
	foreach(array('UPDATE fixture_users SET user_level=0 WHERE user_id=8','UPDATE fixture_users SET user_active=0 WHERE user_id=8') as $change)
	{
		forum_maintenance_fixture(); $mutation_server->pdo->exec($change);
		prune_expect_failure(function() use($db){ phpbb_remove_forum($db,3,4); },'Not_Authorised');
	}
	forum_maintenance_fixture(); $_POST['sid']='wrong';
	prune_expect_failure(function() use($db){ phpbb_remove_forum($db,3,4); },'Session_invalid');
	foreach(array('UPDATE fixture_topics t LEFT JOIN','DELETE f FROM','DELETE FROM fixture_auth','UPDATE fixture_users SET user_level') as $failure)
	{
		forum_maintenance_fixture(); $mutation_server->failure=$failure;
		prune_expect_failure(function() use($db){ phpbb_remove_forum($db,3,4); },'Prune_storage_failed');
		mutation_check(is_file($upload_dir.'/shared.txt'),'Late move failure does not remove attachment bytes');
	}
	foreach(array(null,0,-1,16777216,'3x',true,array(3)) as $bad)
	{
		forum_maintenance_fixture();
		prune_expect_failure(function() use($db,$bad){ phpbb_remove_forum($db,$bad,4); },'Prune_selection_changed');
		if($bad!==null) { prune_expect_failure(function() use($db,$bad){ phpbb_remove_forum($db,3,$bad); },'Prune_selection_changed'); }
	}
	forum_maintenance_fixture();
	prune_expect_failure(function() use($db){ phpbb_remove_forum($db,3,3); },'Prune_selection_changed');
	$_SERVER['REQUEST_METHOD']='GET';
	prune_expect_failure(function() use($db){ phpbb_remove_forum($db,3,4); },'Session_invalid');
	forum_maintenance_fixture(); $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=8');
	$mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".md5('ForumsPruneadmin_forum_prune.php')."')");
	prune_expect_failure(function() use($db){ phpbb_remove_forum($db,3,4); },'Not_Authorised');
	$mutation_server->pdo->exec("UPDATE fixture_junior SET user_jr_admin='".md5('ForumsManageadmin_forums.php')."'");
	mutation_check(phpbb_remove_forum($db,3,4)['posts']===9,'Actual delegated forum-management grant works, prune-only grant does not');
	forum_maintenance_fixture(); $mutation_server->pdo->exec("INSERT INTO fixture_forums (forum_id,main_type,cat_id) VALUES (5,'c',1)");
	mutation_check(phpbb_remove_forum($db,5,4)===array('topics'=>0,'posts'=>0,'target'=>4),'Empty forum can be removed without changing destination contents');
	forum_maintenance_fixture(); $added=false;
	$mutation_server->hook=function($sql) use(&$added){ if(!$added && strpos($sql,'DELETE FROM fixture_topics')===0) { $added=true; $GLOBALS['mutation_server']->pdo->exec('INSERT INTO fixture_posts (post_id,topic_id,forum_id) VALUES (99,100,3)'); } };
	prune_expect_failure(function() use($db){ phpbb_remove_forum($db,3); },'Prune_selection_changed');
	mutation_check($added && (int)posting_value('SELECT COUNT(*) FROM fixture_topics WHERE forum_id=3')===10 && (int)posting_value('SELECT COUNT(*) FROM fixture_posts WHERE post_id=99')===1,'Late reply prevents destructive consumption of previously selected parents');
	forum_maintenance_fixture(); $interleaved=false;
	$mutation_server->hook=function($sql) use(&$interleaved){ if(!$interleaved && strpos($sql,'UPDATE fixture_topics t LEFT JOIN')===0) { $interleaved=true; $owner=$GLOBALS['mutation_server']->owner; $busy=false; try { phpbb_remove_forum($GLOBALS['db'],3,4); } catch(PhpbbPruneException $error) { $busy=$error->getMessage()==='busy'; } mutation_check($busy && $GLOBALS['mutation_server']->owner===$owner,'Busy contender preserves the original owner'); } };
	phpbb_remove_forum($db,3,4); mutation_check($interleaved,'Second forum mutation cannot enter the active writer boundary');
	// Exercise the real ACP storage branch, including original request parsing
	// and typed-error rendering. Cache routines themselves are checked separately.
	$controller=file_get_contents($forum_root.'admin/admin_forums.php');
	$start=strpos($controller,"case 'movedelforum':")+strlen("case 'movedelforum':");
	$end=strpos($controller,'cache_tree(true);',$start);
	mutation_check($end>$start,'Locate real ACP forum removal branch'); $branch=substr($controller,$start,$end-$start);
	foreach(array(array('from_id'=>'3','to_id'=>'f4'),array('from_id'=>'3','to_id'=>'-1')) as $request)
	{
		forum_maintenance_fixture(); $_POST=array_merge($_POST,$request); eval($branch);
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_forums WHERE forum_id=3')===0,'Actual ACP controller reaches coordinated worker');
	}
	foreach(array(array('from_id'=>'3junk','to_id'=>'f4'),array('from_id'=>'3','to_id'=>'-1junk'),array('from_id'=>'3','to_id'=>'f4junk'),array('from_id'=>array(3),'to_id'=>'f4')) as $request)
	{
		forum_maintenance_fixture(); $_POST=array_merge($_POST,$request);
		mutation_expect_failure(function() use($branch){ global $db,$phpbb_root_path,$phpEx; eval($branch); },'Prune_selection_changed');
		mutation_check((int)posting_value('SELECT COUNT(*) FROM fixture_topics WHERE forum_id=3')===10,'Controller rejects malformed original values without changing contents');
	}
	require $forum_root.'includes/prune.php';
	forum_maintenance_fixture(); mutation_check(prune(3,100)['topics']===3,'Actual manual-prune wrapper delegates age policy');
	prune_expect_failure(function(){ prune(3,0,true); },'Prune_selection_changed');
	$prune_controller=file_get_contents($forum_root.'admin/admin_forum_prune.php');
	mutation_check(strpos($prune_controller,"sync('forum'")===false && strpos($prune_controller,'catch (PhpbbPruneException')!==false,'Manual ACP pruning has no unlocked legacy resync and handles failures');
	$start=strpos($prune_controller,'$prunedays = isset('); $end=strpos($prune_controller,'// Convert days',$start);
	mutation_check($end>$start,'Locate actual prune age validation'); $age_branch=substr($prune_controller,$start,$end-$start);
	foreach(array('',array(7),'7junk','0','36501','-7') as $bad)
	{
		$_POST['prunedays']=$bad;
		mutation_expect_failure(function() use($age_branch){ global $lang; eval($age_branch); },'Prune_selection_changed');
	}
	$_POST['prunedays']='7'; eval($age_branch); mutation_check($prunedays===7,'Valid manual age is not silently replaced');
	$view=str_replace("\r\n","\n",file_get_contents($forum_root.'viewforum.php'));
	$start=strpos($view,"if ( \$is_auth['auth_mod'] && \$board_config['prune_enable'] )"); $end=strpos($view,"//\n// End of forum prune",$start);
	mutation_check($end>$start,'Locate actual automatic-prune page branch'); $view_branch=substr($view,$start,$end-$start);
	forum_maintenance_fixture(); $is_auth['auth_mod']=true; $board_config['prune_enable']=1; $forum_row=array('prune_next'=>0,'prune_enable'=>1); $forum_id=3;
	eval($view_branch); mutation_check((int)posting_value('SELECT prune_next FROM fixture_forums WHERE forum_id=3')>time(),'Actual viewforum branch runs configured maintenance and refreshes caches outside lock');
	forum_maintenance_fixture(); $is_auth['auth_mod']=true; $board_config['prune_enable']=1; $mutation_server->failure='SELECT user_id, user_level';
	$old_log=ini_set('error_log',$upload_dir.'/maintenance-test.log');
	try { eval($view_branch); } finally { ini_set('error_log',$old_log); }
	mutation_check(strpos(file_get_contents($upload_dir.'/maintenance-test.log'),'phpBB automatic forum pruning did not complete.')!==false && (int)posting_value('SELECT COUNT(*) FROM fixture_topics WHERE forum_id=3')===10,'Optional automatic failure logs no user content and leaves page readable');
	unlink($upload_dir.'/maintenance-test.log');
	echo "Whole-forum maintenance scope, complete-set movement, dependent cleanup, controller and failure checks passed.\n";
}
finally
{
	if(isset($mutation_server) && $mutation_server->owner!==null) { $mutation_server->owner->sql_close(); }
	if(is_file($upload_dir.'/shared.txt')) { unlink($upload_dir.'/shared.txt'); }
	if(is_file($upload_dir.'/maintenance-test.log')) { unlink($upload_dir.'/maintenance-test.log'); }
	if(is_dir($upload_dir)) { rmdir($upload_dir); }
	restore_error_handler();
}
