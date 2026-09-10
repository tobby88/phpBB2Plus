<?php
require __DIR__ . '/check-group-storage.php';
foreach (array('IN_ADMIN'=>true,'DELETED'=>-1,'ANONYMOUS'=>-1,'USER_REMOVALS_TABLE'=>'fixture_user_removals','USER_REMOVAL_ITEMS_TABLE'=>'fixture_user_removal_items','PM_WRITE_RECEIPTS_TABLE'=>'fixture_receipts','SESSIONS_KEYS_TABLE'=>'fixture_keys','JR_ADMIN_TABLE'=>'fixture_junior','TOPICS_WATCH_TABLE'=>'fixture_watches','BOOKMARK_TABLE'=>'fixture_bookmarks','BANLIST_TABLE'=>'fixture_bans','SHOUTBOX_TABLE'=>'fixture_shouts','VOTE_USERS_TABLE'=>'fixture_votes','PRIVMSGS_TEXT_TABLE'=>'fixture_pm_text','QUOTA_TABLE'=>'fixture_quotas','PA_AUTH_ACCESS_TABLE'=>'fixture_pa_auth') as $key=>$value) { if (!defined($key)) { define($key,$value); } }
require_once $forum_root.'includes/php_compat.php';
require $forum_root.'includes/functions_user_removal.php';
foreach (array('phpbb_admin_html','phpbb_admin_session_field') as $function) { eval(group_test_function(file_get_contents($forum_root.'admin/pagestart.php'),$function)); }
function phpbb_admin_require_post_session() { mutation_check($_SERVER['REQUEST_METHOD']==='POST' && $_POST['sid']===$GLOBALS['userdata']['session_id'],'Actual controller checks POST session'); }
function cache_tree($refresh) { mutation_check($refresh && $GLOBALS['mutation_server']->owner===null,'Cache refresh after removal owner release'); }
$removal_files_root=sys_get_temp_dir().'/phpbb-removal-'.uniqid('',true);
function removal_clear_files()
{
	global $removal_files_root;
	foreach (array('only.txt','shared.txt','twin.txt','thumb.txt','thumbs/t_thumb.txt') as $file)
	{
		$path=$removal_files_root.'/'.$file;
		if (is_file($path)) { unlink($path); } elseif (is_dir($path)) { rmdir($path); }
	}
	if (is_dir($removal_files_root.'/thumbs')) { rmdir($removal_files_root.'/thumbs'); }
	if (is_dir($removal_files_root)) { rmdir($removal_files_root); }
}
function removal_fixture($actor=1)
{
	global $mutation_server,$userdata,$upload_dir,$removal_files_root,$forum_root;
	removal_clear_files(); mkdir($removal_files_root,0700); mkdir($removal_files_root.'/thumbs',0700);
	foreach (array('only.txt','shared.txt','twin.txt','thumb.txt','thumbs/t_thumb.txt') as $file) { file_put_contents($removal_files_root.'/'.$file,'owned fixture'); }
	$upload_dir=$removal_files_root;
	group_fixture($actor); $p=$mutation_server->pdo; $userdata['session_admin']=true;
	foreach (array('user_regdate INTEGER DEFAULT 100','user_password VARCHAR(255) DEFAULT \'fixture-hash\'','user_new_privmsg INTEGER DEFAULT 0','user_unread_privmsg INTEGER DEFAULT 0') as $field) { $p->exec('ALTER TABLE fixture_users ADD '.$field); }
	$p->exec("UPDATE fixture_users SET user_active=0,username='O''Brien Grüße' WHERE user_id=9");
	$p->exec("INSERT INTO fixture_groups VALUES (10,0,0,9,'Managed'),(11,0,1,9,'Second personal'),(12,0,1,999,'Unrelated orphan')");
	$p->exec('INSERT INTO fixture_memberships VALUES (9,10,0),(9,11,0),(1,10,1)');
	$p->exec('INSERT INTO fixture_auth VALUES (4,2,1),(10,2,1),(11,2,0),(12,2,1)');
	$p->exec('ALTER TABLE fixture_posts ADD poster_id INTEGER DEFAULT 9'); $p->exec("ALTER TABLE fixture_posts ADD post_username VARCHAR(255) DEFAULT ''");
	$p->exec('ALTER TABLE fixture_topics ADD topic_poster INTEGER DEFAULT 9');
	$p->exec('CREATE TABLE fixture_shouts (shout_user_id INTEGER,shout_username VARCHAR(255))'); $p->exec("INSERT INTO fixture_shouts VALUES (9,''),(8,'')");
	$p->exec('CREATE TABLE fixture_votes (vote_user_id INTEGER)'); $p->exec('INSERT INTO fixture_votes VALUES (9),(8)');
	foreach (array('fixture_receipts'=>'user_id','fixture_keys'=>'user_id','fixture_watches'=>'user_id','fixture_bookmarks'=>'user_id','fixture_bans'=>'ban_userid') as $table=>$column)
	{
		$p->exec('CREATE TABLE '.$table.' ('.$column.' INTEGER)'); $p->exec('INSERT INTO '.$table.' VALUES (0),(9),(8)');
	}
	$p->exec('CREATE TABLE fixture_junior (user_id INTEGER,user_jr_admin VARCHAR(255))'); $p->exec("INSERT INTO fixture_junior VALUES (9,'old-grant')");
	$p->exec('CREATE TABLE fixture_quotas (user_id INTEGER,group_id INTEGER)'); $p->exec('INSERT INTO fixture_quotas VALUES (9,0),(0,4),(0,11),(8,0),(0,12)');
	$p->exec('CREATE TABLE fixture_pa_auth (group_id INTEGER)'); $p->exec('INSERT INTO fixture_pa_auth VALUES (4),(11),(12)');
	$p->exec('DELETE FROM fixture_messages');
	$p->exec('ALTER TABLE fixture_messages ADD COLUMN privmsgs_write_payload TEXT DEFAULT NULL');
	$p->exec('INSERT INTO fixture_messages (privmsgs_id,privmsgs_type,privmsgs_from_userid,privmsgs_to_userid,privmsgs_attachment) VALUES (20,0,9,8,1),(21,0,8,9,1),(22,1,9,8,1),(23,2,9,8,1),(24,4,8,9,0),(25,3,9,8,1),(26,1,9,-1,0)');
	$p->exec('CREATE TABLE fixture_pm_text (privmsgs_text_id INTEGER,privmsgs_text TEXT)'); $p->exec("INSERT INTO fixture_pm_text VALUES (20,'kept'),(21,'removed'),(22,'removed'),(23,'removed'),(24,'kept'),(25,'kept'),(26,'removed')");
	$p->exec("INSERT INTO fixture_descriptions (attach_id,physical_filename,thumbnail) VALUES (1,'only.txt',0),(2,'shared.txt',0),(3,'twin.txt',0),(4,'twin.txt',0),(5,'thumb.txt',1)");
	$p->exec('INSERT INTO fixture_links (attach_id,post_id,privmsgs_id,user_id_1,user_id_2) VALUES (1,0,21,8,9),(2,0,22,9,8),(2,0,20,9,8),(3,0,23,9,8),(4,0,25,9,8),(5,0,21,8,9)');
	$schema=file_get_contents($forum_root.'install/schemas/mysql_schema.sql');
	foreach (array('user_removals','user_removal_items') as $table)
	{
		mutation_check(preg_match('/CREATE TABLE phpbb_'.$table.' \(.*?\) ENGINE=InnoDB ROW_FORMAT=DYNAMIC[^;]*;/s',$schema,$match)===1,'Canonical removal table found');
		$sql=preg_replace('/\) ENGINE=InnoDB ROW_FORMAT=DYNAMIC[^;]*;$/',')',str_replace('phpbb_'.$table,'fixture_'.$table,$match[0]));
		if ($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')
		{
			$sql=preg_replace('/\b(?:mediumint|int)\([0-9]+\)(?: unsigned)?/i','INTEGER',$sql);
			$sql=preg_replace('/UNIQUE KEY [a-z_]+ /','UNIQUE ',$sql);
		}
		$p->exec($sql);
	}
}
function removal_run($post=array()) { return phpbb_inactive_user_remove($GLOBALS['db'],array_merge(array('sid'=>'fixture-session'),$post?$post:array('delete'=>9))); }
function removal_job() { return $GLOBALS['mutation_server']->pdo->query('SELECT job_id FROM fixture_user_removals')->fetchColumn(); }
function removal_failure($callback,$expected)
{
	$caught=false; try { $callback(); } catch (PhpbbRemovalException $e) { $caught=$e->getMessage()===$expected; }
	mutation_check($caught,'Expected removal failure: '.$expected); mutation_check($GLOBALS['mutation_server']->owner===null,'Failed removal releases owner');
}
class RemovalReader extends MutationForum
{
	function sql_query($sql) { mutation_check(strpos($sql,'SELECT ')===0,'Controller must not retain unlocked mutations'); return $GLOBALS['mutation_server']->pdo->query($sql); }
	function sql_fetchrow($r) { return $r->fetch(PDO::FETCH_ASSOC); }
	function sql_fetchrowset($r) { return $r->fetchAll(PDO::FETCH_ASSOC); }
	function sql_freeresult($r) { $r->closeCursor(); }
	function sql_escape($value) { return substr($GLOBALS['mutation_server']->pdo->quote($value),1,-1); }
}
class RemovalTemplate { var $vars=array(); function assign_vars($values) { $this->vars=array_merge($this->vars,$values); } }
function removal_assert_completed()
{
	global $mutation_server,$removal_files_root;
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_users WHERE user_id=9')===0 && group_value('SELECT COUNT(*) FROM fixture_user_removals')===0 && group_value('SELECT COUNT(*) FROM fixture_user_removal_items')===0,'Account removed and completed journal metadata cleared');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_posts')===1 && $mutation_server->pdo->query('SELECT post_username FROM fixture_posts')->fetchColumn()==="O'Brien Grüße",'Published post retained with escaped Unicode guest author');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_shouts WHERE shout_user_id=8')===1 && $mutation_server->pdo->query('SELECT shout_username FROM fixture_shouts WHERE shout_user_id=-1')->fetchColumn()==="O'Brien Grüße",'Other shouts preserved and target shout anonymized');
	mutation_check(group_value('SELECT group_moderator FROM fixture_groups WHERE group_id=10')===1 && group_value('SELECT user_pending FROM fixture_memberships WHERE group_id=10 AND user_id=1')===0,'Successor leader has approved membership');
	mutation_check(group_value('SELECT user_level FROM fixture_users WHERE user_id=1')===1,'Administrator role preserved');
	foreach (array('fixture_receipts'=>'user_id','fixture_keys'=>'user_id','fixture_watches'=>'user_id','fixture_bookmarks'=>'user_id','fixture_bans'=>'ban_userid') as $table=>$column)
	{
		mutation_check(group_value('SELECT COUNT(*) FROM '.$table.' WHERE '.$column.'=9')===0 && group_value('SELECT COUNT(*) FROM '.$table)===2,'Only target references removed; global and unrelated rows retained: '.$table);
	}
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions')===5 && group_value('SELECT COUNT(*) FROM fixture_sessions WHERE session_user_id=9')===0 && group_value('SELECT COUNT(*) FROM fixture_junior WHERE user_id=9')===0,'Target sessions and delegated grants revoked');
	foreach (array('fixture_groups','fixture_auth','fixture_pa_auth','fixture_quotas') as $table) { mutation_check(group_value('SELECT COUNT(*) FROM '.$table.' WHERE group_id IN (4,11)')===0 && group_value('SELECT COUNT(*) FROM '.$table.' WHERE group_id=12')===1,'Captured personal groups only, preserving unrelated orphan: '.$table); }
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_messages')===3 && group_value('SELECT COUNT(*) FROM fixture_pm_text')===3,'Other participants delivered/saved PM copies retained');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id IN (20,24,25)')===3 && group_value('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_from_userid=9 OR privmsgs_to_userid=9')===0,'Retained PM participant IDs anonymized');
	mutation_check(!file_exists($removal_files_root.'/only.txt') && !file_exists($removal_files_root.'/thumb.txt') && !file_exists($removal_files_root.'/thumbs/t_thumb.txt'),'Only removed PM files and thumbnails deleted');
	mutation_check(is_file($removal_files_root.'/shared.txt') && is_file($removal_files_root.'/twin.txt') && group_value('SELECT COUNT(*) FROM fixture_descriptions')===2,'Shared attachment IDs and shared physical filenames preserved');
}
set_error_handler(function($severity,$message) { if(error_reporting()&$severity) { throw new RuntimeException($message); } });
try
{
	removal_fixture(); mutation_check(removal_run()==='Removal_completed','Ordinary inactive removal succeeds'); removal_assert_completed();
	foreach (array(array(9,8),array(8,9)) as $participants)
	{
		removal_fixture(); $p=$mutation_server->pdo;
		$p->exec('INSERT INTO fixture_messages (privmsgs_id,privmsgs_type,privmsgs_from_userid,privmsgs_to_userid,privmsgs_attachment) VALUES (30,6,'.$participants[0].','.$participants[1].',1)');
		$p->exec("INSERT INTO fixture_pm_text VALUES (30,'Unpublished copy')");
		$p->exec('INSERT INTO fixture_links (attach_id,post_id,privmsgs_id,user_id_1,user_id_2) VALUES (2,0,30,'.$participants[0].','.$participants[1].')');
		removal_run(); removal_assert_completed();
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_type=6')===0,'Removing either participant retires never-published copy and preserves shared uploads');
	}
	removal_fixture(); $mutation_server->pdo->exec('DELETE FROM fixture_memberships WHERE group_id IN (4,11)'); $mutation_server->pdo->exec('DELETE FROM fixture_groups WHERE group_id IN (4,11)');
	removal_run(); mutation_check(group_value('SELECT COUNT(*) FROM fixture_users WHERE user_id=9')===0 && group_value('SELECT COUNT(*) FROM fixture_auth WHERE group_id IN (4,11)')===2,'Missing personal groups do not trigger unrelated orphan cleanup');
	foreach (array(array('delete'=>9,'removal_resume'=>str_repeat('a',32)),array('removal_resume'=>array('bad')),array('removal_resume'=>'bad'),array('removal_resume'=>str_repeat('a',32))) as $post)
	{
		removal_fixture(); removal_failure(function() use($post) { removal_run($post); },'Removal_invalid');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_users WHERE user_id=9')===1 && group_value('SELECT COUNT(*) FROM fixture_user_removals')===0,'Mixed actions and invalid/unknown job tokens cannot mutate accounts');
	}
	foreach (array('INSERT INTO fixture_user_removal_items',"UPDATE fixture_user_removals SET removal_state = 'removing'", "UPDATE fixture_user_removals SET removal_state = 'removed'", 'DELETE FROM fixture_keys','DELETE FROM fixture_watches','DELETE FROM fixture_receipts','UPDATE fixture_posts','UPDATE fixture_shouts','UPDATE fixture_groups','DELETE FROM fixture_messages','DELETE FROM fixture_pm_text','DELETE FROM fixture_links','UPDATE fixture_descriptions','DELETE FROM fixture_descriptions','DELETE FROM fixture_memberships','DELETE FROM fixture_auth','DELETE FROM fixture_pa_auth','DELETE FROM fixture_quotas','DELETE FROM fixture_groups',"UPDATE fixture_user_removals SET removal_state = 'complete'",'DELETE FROM fixture_user_removals') as $failure)
	{
		removal_fixture(); $mutation_server->failure=$failure;
		removal_failure(function() { removal_run(); },'Removal_storage_failed');
		$job=removal_job(); mutation_check(is_string($job) && strlen($job)===32,'Failure retains durable intent: '.$failure);
		$mutation_server->failure=''; mutation_check(removal_run(array('removal_resume'=>$job))==='Removal_completed','Resume succeeds: '.$failure); removal_assert_completed();
	}
	removal_fixture(); $mutation_server->failure='DELETE FROM fixture_users';
	removal_failure(function() { removal_run(); },'Removal_storage_failed'); $job=removal_job(); $mutation_server->failure='';
	removal_failure(function() use($job) { removal_run(array('removal_resume'=>$job)); },'Removal_account_changed');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_users WHERE user_id=9')===1 && group_value('SELECT COUNT(*) FROM fixture_messages')===7,'Ambiguous delete failure never retries deleting an existing account');
	mutation_check(removal_run(array('removal_cancel'=>$job))==='Removal_cancelled' && group_value('SELECT COUNT(*) FROM fixture_user_removal_items')===0,'Discard only ambiguous intent metadata');
	removal_run(); removal_assert_completed();
	foreach (array('active','admin','different-password','different-name','password-case','name-space') as $case)
	{
		removal_fixture(); $mutation_server->hook=function($sql) use($case) {
			if (strpos($sql,'DELETE FROM fixture_users')===0) { $GLOBALS['mutation_server']->hook=null; $changes=array('active'=>'user_active=1','admin'=>'user_level=1','different-password'=>"user_password='changed'",'different-name'=>"username='Renamed'",'password-case'=>"user_password='FIXTURE-HASH'",'name-space'=>"username='O''Brien Grüße '"); $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_users SET '.$changes[$case].' WHERE user_id=9'); }
		};
		removal_failure(function() { removal_run(); },'Removal_account_changed');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_posts WHERE poster_id=9')===1 && group_value('SELECT COUNT(*) FROM fixture_messages')===7 && group_value('SELECT COUNT(*) FROM fixture_memberships WHERE user_id=9')===4,'Late eligibility/identity change prevents subsequent cleanup: '.$case);
	}
	foreach (array('zero','negative','nested','suffix','admin','active','missing','self') as $case)
	{
		removal_fixture(); $values=array('zero'=>0,'negative'=>-1,'nested'=>array(9),'suffix'=>'9bad','admin'=>1,'active'=>8,'missing'=>999,'self'=>1);
		removal_failure(function() use($values,$case) { removal_run(array('delete'=>$values[$case])); },in_array($case,array('zero','negative','nested','suffix'),true)?'Removal_invalid':'Not_Authorised');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_user_removals')===0 && group_value('SELECT COUNT(*) FROM fixture_posts WHERE poster_id=9')===1,'Invalid target has no removal intent or content changes');
	}
	foreach (array('get','bad-sid','nested-sid','no-acp','inactive-actor','demoted-actor','no-grant') as $case)
	{
		removal_fixture($case==='no-grant'?8:1); $post=array('delete'=>9); $expected='Not_Authorised';
		if ($case==='get') { $_SERVER['REQUEST_METHOD']='GET'; $expected='Session_invalid'; }
		if ($case==='bad-sid'||$case==='nested-sid') { $post['sid']=$case==='bad-sid'?'wrong':array('fixture-session'); $expected='Session_invalid'; }
		if ($case==='no-acp') { $userdata['session_admin']=false; }
		if ($case==='inactive-actor') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1'); }
		if ($case==='demoted-actor') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1'); }
		removal_failure(function() use($post) { removal_run($post); },$expected);
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_user_removals')===0 && group_value('SELECT COUNT(*) FROM fixture_sessions')===6,'Invalid actor/session has no writes');
	}
	foreach (array('before-claim','after-delete') as $case)
	{
		removal_fixture(8); $mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".md5('UsersActivate_titleadmin_account.php')."')");
		$mutation_server->hook=function($sql) use($case) {
			if (strpos($sql,$case==='before-claim'?'INSERT INTO fixture_user_removal_items':'DELETE FROM fixture_keys')===0) { $GLOBALS['mutation_server']->hook=null; $GLOBALS['mutation_server']->pdo->exec('DELETE FROM fixture_junior WHERE user_id=8'); }
		};
		removal_failure(function() { removal_run(); },'Removal_account_changed');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_posts WHERE poster_id=9')===1,'Late delegation revocation stops content writes');
		mutation_check(group_value('SELECT COUNT(*) FROM fixture_users WHERE user_id=9')===($case==='before-claim'?1:0),'Claim ordering reflects actual deletion, not imaginary rollback');
	}
	removal_fixture(); $mutation_server->failure='DELETE FROM fixture_keys'; removal_failure(function() { removal_run(); },'Removal_storage_failed');
	$job=removal_job(); $mutation_server->failure='';
	$mutation_server->pdo->exec("INSERT INTO fixture_users (user_id,user_level,user_active,username,user_email,user_lang) VALUES (9,0,1,'Restored','','english')");
	removal_failure(function() use($job) { removal_run(array('removal_resume'=>$job)); },'Removal_account_changed');
	mutation_check(group_value('SELECT COUNT(*) FROM fixture_messages')===7 && group_value('SELECT COUNT(*) FROM fixture_posts WHERE poster_id=9')===1,'Restored account and all pending content protected on resume');
	removal_run(array('removal_cancel'=>$job)); mutation_check(group_value('SELECT COUNT(*) FROM fixture_messages')===7,'Cancel after restore changes only metadata');
	foreach (array('membership','reclassified') as $case)
	{
		removal_fixture(); $mutation_server->hook=function($sql) use($case) {
			if (strpos($sql,'DELETE FROM fixture_auth')===0) { $GLOBALS['mutation_server']->hook=null; $GLOBALS['mutation_server']->pdo->exec($case==='membership'?'INSERT INTO fixture_memberships VALUES (8,4,0)':'UPDATE fixture_groups SET group_single_user=0 WHERE group_id=4'); }
		};
		removal_run(); mutation_check(group_value('SELECT COUNT(*) FROM fixture_groups WHERE group_id=4')===1 && group_value('SELECT COUNT(*) FROM fixture_auth WHERE group_id=4')===1 && group_value('SELECT COUNT(*) FROM fixture_pa_auth WHERE group_id=4')===1,'Changed personal group retains all grants: '.$case);
	}
	foreach (array('main','thumbnail') as $storage)
	{
		removal_fixture();
		// A directory at a filename is not a regular attachment. Instead make
		// the actual storage inventory unavailable, keeping all owned bytes.
		if ($storage==='main') { $upload_dir=$removal_files_root.'/unavailable'; }
		else { rename($removal_files_root.'/thumbs',$removal_files_root.'/held-thumbs'); }
		try
		{
			removal_failure(function() { removal_run(); },'Removal_storage_failed'); $job=removal_job();
			mutation_check(is_file($removal_files_root.'/thumb.txt'),'Unavailable storage preserves main image');
			mutation_check(group_value('SELECT COUNT(*) FROM fixture_descriptions WHERE attach_id='.($storage==='main'?1:5))===1,'Failed unlink retains description for retry');
		}
		finally
		{
			$upload_dir=$removal_files_root;
			if ($storage==='thumbnail') { rename($removal_files_root.'/held-thumbs',$removal_files_root.'/thumbs'); }
		}
		removal_run(array('removal_resume'=>$job)); removal_assert_completed();
	}
	removal_fixture(); $mutation_server->hook=function($sql) {
		if (strpos($sql,'DELETE FROM fixture_user_removal_items')===0 && group_value('SELECT COUNT(*) FROM fixture_users WHERE user_id=9')===0) { $GLOBALS['mutation_server']->hook=null; $GLOBALS['mutation_server']->failure='DELETE FROM fixture_user_removal_items'; }
	};
	removal_failure(function() { removal_run(); },'Removal_storage_failed'); $job=removal_job();
	mutation_check($mutation_server->pdo->query('SELECT removal_state FROM fixture_user_removals')->fetchColumn()==='complete','Final metadata failure retains completed marker');
	$mutation_server->failure=''; removal_run(array('removal_resume'=>$job)); removal_assert_completed();
	// Actual controller rendering of failed and resumed jobs, without unlocked SQL.
	$controller=file_get_contents($forum_root.'admin/admin_account.php');
	$begin=strpos($controller,"if (isset(\$_POST['delete'])"); $end=strpos($controller,'// sort part',$begin);
	mutation_check($begin!==false && $end!==false,'Actual inactive controller branch found'); $body=substr($controller,$begin,$end-$begin);
	foreach (array('Removal_storage_failed','Removal_completed','Removal_cancelled','Removal_pending_title','Removal_pending_explain','Removal_resume','Removal_cancel') as $key) { $lang[$key]=$key; }
	removal_fixture(); $reader=new RemovalReader(); $template=new RemovalTemplate(); $_POST=array('sid'=>'fixture-session','delete'=>9); $mutation_server->failure='DELETE FROM fixture_keys';
	call_user_func(function() use($body,$reader,$template,$lang) { $db=$reader; eval($body); });
	mutation_check(strpos($template->vars['INFO_MESSAGE'],'Removal_storage_failed')!==false && strpos($template->vars['REMOVAL_JOBS'],'name="removal_resume"')!==false && strpos($template->vars['REMOVAL_JOBS'],'name="removal_cancel"')===false,'Failed actual deletion presents resume but not unsafe discard for missing account');
	$job=removal_job(); $mutation_server->failure=''; $_POST=array('sid'=>'fixture-session','removal_resume'=>$job);
	call_user_func(function() use($body,$reader,$template,$lang) { $db=$reader; eval($body); });
	mutation_check($template->vars['INFO_MESSAGE']==='Removal_completed' && $template->vars['REMOVAL_JOBS']==='','Actual resume completes and removes pending UI'); removal_assert_completed();
	removal_fixture(); $owner=new attach_mutation_lock($db); $caught=false;
	try { removal_run(); } catch(PhpbbRemovalException $e) { $caught=$e->getMessage()==='busy'; }
	mutation_check($caught && $mutation_server->owner===$owner->connection,'Existing writer prevents removal from interleaving'); $owner->release();
	echo "Durable inactive account removal checks passed.\n";
}
finally { removal_clear_files(); restore_error_handler(); }
