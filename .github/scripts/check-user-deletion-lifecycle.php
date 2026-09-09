<?php
// Execute the actual controller mutation blocks against isolated SQLite data.
// Inactive deletion's owning connection and durable jobs are exercised in
// check-user-removal-storage.php, including its actual controller rendering.
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR,"pdo_sqlite required\n"); exit(1); }
foreach (array('USERS_TABLE'=>'fixture_users','GROUPS_TABLE'=>'fixture_groups','USER_GROUP_TABLE'=>'fixture_members','AUTH_ACCESS_TABLE'=>'fixture_permissions','POSTS_TABLE'=>'fixture_posts','TOPICS_TABLE'=>'fixture_topics','VOTE_USERS_TABLE'=>'fixture_votes','ADMIN'=>1,'ANONYMOUS'=>-1,'DELETED'=>-1,'GENERAL_ERROR'=>202) as $key=>$value) { define($key,$value); }
define('IN_PHPBB',true);
foreach(array('SESSIONS_KEYS_TABLE'=>'fixture_keys','SESSIONS_TABLE'=>'fixture_sessions','JR_ADMIN_TABLE'=>'fixture_grants','TOPICS_WATCH_TABLE'=>'fixture_watches','BOOKMARK_TABLE'=>'fixture_bookmarks','BANLIST_TABLE'=>'fixture_bans') as $key=>$value) { define($key,$value); }
$lang=array('User_reference_cleanup_failed'=>'reference cleanup');
function deletion_reference_tables()
{
	return array('fixture_keys'=>'user_id','fixture_sessions'=>'session_user_id','fixture_grants'=>'user_id','fixture_watches'=>'user_id','fixture_bookmarks'=>'user_id','fixture_bans'=>'ban_userid');
}
class DeletionFailure extends RuntimeException {}
function message_die($type,$message) { throw new DeletionFailure($message); }
function phpbb_admin_require_post_session() { if (empty($GLOBALS['fixture_session'])) { throw new DeletionFailure('session'); } }
function deletion_check($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
class DeletionTemplate { var $vars=array(); function assign_vars($vars) { $this->vars=$vars; } }
class DeletionDatabase
{
	var $pdo; var $affected=0; var $hook=null; var $failure=''; var $queries=array();
	function __construct()
	{
		$this->pdo=new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec('CREATE TABLE fixture_users(user_id INTEGER PRIMARY KEY,user_active INTEGER,user_level INTEGER,user_posts INTEGER,username TEXT,user_email TEXT,user_lang TEXT)');
		$this->pdo->exec("INSERT INTO fixture_users VALUES(7,0,0,0,'Old name','fixture@example.invalid','english'),(8,1,0,1,'Other','','english'),(9,1,1,1,'Admin','','english')");
		$this->pdo->exec('CREATE TABLE fixture_groups(group_id INTEGER PRIMARY KEY,group_single_user INTEGER,group_moderator INTEGER)');
		$this->pdo->exec('INSERT INTO fixture_groups VALUES(70,1,7),(80,1,8),(90,1,9),(100,1,0),(200,0,7),(201,0,200)');
		$this->pdo->exec('CREATE TABLE fixture_members(user_id INTEGER,group_id INTEGER)');
		$this->pdo->exec('INSERT INTO fixture_members VALUES(7,70),(7,200),(8,80),(9,90)');
		$this->pdo->exec('CREATE TABLE fixture_permissions(group_id INTEGER,auth_read INTEGER)');
		$this->pdo->exec('INSERT INTO fixture_permissions VALUES(70,1),(80,1),(90,1),(100,1),(200,1)');
		$this->pdo->exec('CREATE TABLE fixture_posts(poster_id INTEGER,post_username TEXT)');
		$this->pdo->exec("INSERT INTO fixture_posts VALUES(7,''),(8,'')");
		$this->pdo->exec('CREATE TABLE fixture_topics(topic_poster INTEGER)'); $this->pdo->exec('INSERT INTO fixture_topics VALUES(7),(8)');
		$this->pdo->exec('CREATE TABLE fixture_votes(vote_user_id INTEGER)'); $this->pdo->exec('INSERT INTO fixture_votes VALUES(7),(8)');
		$this->pdo->exec('CREATE TABLE fixture_shouts(shout_user_id INTEGER,shout_username TEXT)');
		$this->pdo->exec("INSERT INTO fixture_shouts VALUES(7,''),(8,'')");
		foreach(deletion_reference_tables() as $table=>$column)
		{
			$this->pdo->exec('CREATE TABLE '.$table.'('.$column.' INTEGER)');
			$this->pdo->exec('INSERT INTO '.$table.' VALUES(0),(7),(8),(9)');
		}
	}
	function sql_query($sql) { $this->queries[]=$sql; if (is_callable($this->hook)) { call_user_func($this->hook,$sql,$this); } if ($this->failure!=='' && strpos($sql,$this->failure)===0) { return false; } $r=$this->pdo->query($sql); $this->affected=$r->rowCount(); return $r; }
	function sql_fetchrow($r) { return $r->fetch(PDO::FETCH_ASSOC); }
	function sql_fetchrowset($r) { return $r->fetchAll(PDO::FETCH_ASSOC); }
	function sql_freeresult($r) { $r->closeCursor(); }
	function sql_affectedrows() { return $this->affected; }
	function sql_escape($s) { return str_replace("'","''",$s); }
	function scalar($sql) { return $this->pdo->query($sql)->fetchColumn(); }
}
$root=dirname(dirname(__DIR__));
require $root.'/phpBB2/includes/functions_user_cleanup.php';
$prune=file_get_contents($root.'/phpBB2/delete_users.php');
$start=strpos($prune,'@set_time_limit(5);'); $end=strpos($prune,'phpbb_pm_prune_user_messages($user_id);',$start);
deletion_check($start!==false && $end!==false,'Prune controller extraction markers');
$prune_block=substr($prune,$start,$end-$start);
$user_admin=file_get_contents($root.'/phpBB2/admin/admin_users.php');
$delete_branch=strpos($user_admin,"if( !empty(\$_POST['deleteuser'])");
deletion_check($delete_branch!==false,'User deletion branch exists');
$start=strpos($user_admin,'$sql = "SELECT g.group_id',$delete_branch);
$end=strpos($user_admin,"\$message = \$lang['User_deleted']",$start);
deletion_check($start!==false && $end!==false,'User manager deletion extraction markers');
$user_admin_block=substr($user_admin,$start,$end-$start);
define('SHOUTBOX_TABLE','fixture_shouts');
function admin_user_sql_value($value) { return str_replace("'","''",$value); }
function phpbb_pm_delete_user_messages($id)
{
	deletion_check($id===7,'PN cleanup scoped to selected user');
	if (!empty($GLOBALS['fixture_pm_denied'])) { throw new DeletionFailure('pm permission'); }
}
function run_user_admin($db)
{
	global $user_admin_block, $lang;
	$user_id=7; $userdata=array('user_id'=>9); $this_userdata=array('username'=>"O'Brien");
	eval($user_admin_block);
}
function run_prune($db)
{
	global $prune_block;
	$user_list=array(array('user_id'=>7)); $i=0; $userdata=array('user_id'=>9);
	$prune_selection_sql='FROM fixture_users WHERE user_id <> -1 AND user_level <> 1 AND user_posts = 0';
	try { eval('foreach (array(0) as $iteration) {' . $prune_block . '}'); }
	finally
	{
		// The controller's per-user timer must not cover subsequent fixtures.
		set_time_limit(0);
	}
}
function expect_deletion_failure($callback) { $caught=false; try { $callback(); } catch(DeletionFailure $e) { $caught=true; } deletion_check($caught,'Expected controlled rejection'); }
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try
{
	foreach(array('user_level=1','user_posts=1') as $change)
	{
		$db=new DeletionDatabase(); $db->hook=function($sql,$db)use($change){if(strpos($sql,'DELETE FROM fixture_users')===0){$db->pdo->exec('UPDATE fixture_users SET '.$change.' WHERE user_id=7');}};
		run_prune($db);
		deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_users WHERE user_id=7')===1 && (int)$db->scalar('SELECT COUNT(*) FROM fixture_posts WHERE poster_id=7')===1 && (int)$db->scalar('SELECT group_moderator FROM fixture_groups WHERE group_id=200')===7,'Prune rechecks eligibility in DELETE before any dependent mutation');
	}
	$db=new DeletionDatabase(); $db->pdo->exec("UPDATE fixture_users SET username='O''Brien' WHERE user_id=7"); run_prune($db);
	deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_users WHERE user_id=7')===0 && $db->scalar('SELECT post_username FROM fixture_posts WHERE poster_id=-1')==="O'Brien",'Prune refreshes and safely escapes actual current name');
	deletion_check((int)$db->scalar('SELECT group_moderator FROM fixture_groups WHERE group_id=200')===9 && (int)$db->scalar('SELECT group_moderator FROM fixture_groups WHERE group_id=201')===200,'Prune replaces target moderator without confusing group IDs with user IDs');
	deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_groups WHERE group_id=70')===0 && (int)$db->scalar('SELECT COUNT(*) FROM fixture_permissions WHERE group_id=70')===0,'Prune removes the empty personal group with its permissions');
	$db=new DeletionDatabase(); $db->hook=function($sql,$db){if(strpos($sql,'DELETE FROM fixture_groups')===0){$db->pdo->exec('INSERT INTO fixture_members VALUES(8,70)');}};
	run_prune($db); deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_groups WHERE group_id=70')===1 && (int)$db->scalar('SELECT COUNT(*) FROM fixture_permissions WHERE group_id=70')===1,'Prune preserves a personal group that gained a member');
	deletion_check(strpos($prune,'$deleted_users++;')!==false && strpos($prune,"sprintf(\$lang['Prune_users_number'], \$deleted_users)")!==false,'Prune counts completed deletions separately from skipped candidates');
	$db=new DeletionDatabase(); $GLOBALS['fixture_pm_denied']=true;
	expect_deletion_failure(function()use($db){run_user_admin($db);});
	deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_users WHERE user_id=7')===1 && (int)$db->scalar('SELECT COUNT(*) FROM fixture_posts WHERE poster_id=7')===1,'PN capability failure stops subsequent account/post mutations');
	$GLOBALS['fixture_pm_denied']=false;
	foreach(array('missing','multiple','shared','reclassified','ordinary') as $case)
	{
		$db=new DeletionDatabase();
		if($case==='missing') { $db->pdo->exec('DELETE FROM fixture_members WHERE group_id=70'); $db->pdo->exec('DELETE FROM fixture_groups WHERE group_id=70'); }
		if($case==='multiple') { $db->pdo->exec('INSERT INTO fixture_groups VALUES(71,1,7)'); $db->pdo->exec('INSERT INTO fixture_members VALUES(7,71)'); $db->pdo->exec('INSERT INTO fixture_permissions VALUES(71,1)'); }
		if($case==='shared' || $case==='reclassified')
		{
			$db->hook=function($sql,$db)use($case){if(strpos($sql,'DELETE FROM fixture_groups')===0){$db->pdo->exec($case==='shared'?'INSERT INTO fixture_members VALUES(8,70)':'UPDATE fixture_groups SET group_single_user=0 WHERE group_id=70');}};
		}
		run_user_admin($db);
		deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_users WHERE user_id=7')===0,'User manager supports '.$case.' personal groups');
		deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_groups WHERE group_id IN (80,90,100,200,201)')===5,'User manager retains unrelated and nonpersonal groups');
		deletion_check($db->scalar('SELECT shout_username FROM fixture_shouts WHERE shout_user_id=-1')==="O'Brien",'Actual controller safely anonymizes shout author');
		if($case==='shared' || $case==='reclassified') { deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_groups WHERE group_id=70')===1 && (int)$db->scalar('SELECT COUNT(*) FROM fixture_permissions WHERE group_id=70')===1,'Changed group retains its permissions'); }
		if($case==='multiple' || $case==='ordinary') { deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_groups WHERE group_id IN (70,71)')===0 && (int)$db->scalar('SELECT COUNT(*) FROM fixture_permissions WHERE group_id IN (70,71)')===0,'All captured empty personal groups and only their ACLs removed'); }
	}
	$db=new DeletionDatabase(); $db->failure='DELETE FROM fixture_groups';
	expect_deletion_failure(function()use($db){run_user_admin($db);});
	deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_permissions WHERE group_id=70')===1,'Group deletion failure preserves permission recovery records');
	foreach(array('run_prune','run_user_admin') as $controller)
	{
		$db=new DeletionDatabase(); call_user_func($controller,$db);
		foreach(deletion_reference_tables() as $table=>$column)
		{
			deletion_check((int)$db->scalar('SELECT COUNT(*) FROM '.$table.' WHERE '.$column.'=7')===0,'Controller clears removed account reference: '.$controller.'/'.$table);
			deletion_check((int)$db->scalar('SELECT COUNT(*) FROM '.$table)===3,'Other accounts and zero/global rows retained');
		}
		$db=new DeletionDatabase(); $db->failure='DELETE FROM fixture_keys';
		expect_deletion_failure(function()use($controller,$db){call_user_func($controller,$db);});
		deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_members WHERE user_id=7')===2,'Credential cleanup failure stops later group changes');
	}
	foreach(array('missing','multiple') as $case)
	{
		$db=new DeletionDatabase();
		if($case==='missing') { $db->pdo->exec('DELETE FROM fixture_members WHERE group_id=70'); $db->pdo->exec('DELETE FROM fixture_groups WHERE group_id=70'); }
		else { $db->pdo->exec('INSERT INTO fixture_groups VALUES(71,1,7)'); $db->pdo->exec('INSERT INTO fixture_members VALUES(7,71)'); $db->pdo->exec('INSERT INTO fixture_permissions VALUES(71,1)'); }
		run_prune($db);
		deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_users WHERE user_id=7')===0 && (int)$db->scalar('SELECT COUNT(*) FROM fixture_groups WHERE group_id IN (70,71)')===0,'Pruning handles '.$case.' personal groups');
		if($case==='multiple') { deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_permissions WHERE group_id IN (70,71)')===0,'Pruning clears all captured empty personal-group permissions'); }
	}
	$db=new DeletionDatabase(); $db->hook=function($sql,$db){if(strpos($sql,'DELETE FROM fixture_users')===0){$db->pdo->exec('DELETE FROM fixture_users WHERE user_id=7');}};
	expect_deletion_failure(function()use($db){run_user_admin($db);});
	deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_keys WHERE user_id=7')===1 && (int)$db->scalar('SELECT COUNT(*) FROM fixture_members WHERE user_id=7')===2,'Zero-row user manager deletion stops subsequent cleanup');
	foreach(array(0,-1,null,'7','7 OR 1=1',array(7)) as $bad)
	{
		$db=new DeletionDatabase(); expect_deletion_failure(function()use($db,$bad){phpbb_cleanup_removed_user_references($db,$bad);});
		deletion_check(count($db->queries)===0,'Invalid internal target fails before SQL');
	}
	$db=new DeletionDatabase(); expect_deletion_failure(function()use($db){phpbb_cleanup_removed_user_references($db,7);});
	deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_keys')===4,'Existing account references cannot be cleaned');
	$db=new DeletionDatabase(); $db->pdo->exec('DELETE FROM fixture_users WHERE user_id=7'); $restored=false;
	$db->hook=function($sql,$db)use(&$restored){if(!$restored && strpos($sql,'DELETE FROM fixture_keys')===0){$restored=true;$db->pdo->exec("INSERT INTO fixture_users VALUES(7,1,0,0,'Restored','','english')");}};
	expect_deletion_failure(function()use($db){phpbb_cleanup_removed_user_references($db,7);});
	foreach(deletion_reference_tables() as $table=>$column){deletion_check((int)$db->scalar('SELECT COUNT(*) FROM '.$table)===4,'Fresh write predicates protect restored account: '.$table);}
	$db=new DeletionDatabase(); $db->pdo->exec('DELETE FROM fixture_users WHERE user_id=7'); $db->failure='DELETE FROM fixture_bookmarks';
	expect_deletion_failure(function()use($db){phpbb_cleanup_removed_user_references($db,7);});
	deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_keys WHERE user_id=7')===0 && (int)$db->scalar('SELECT COUNT(*) FROM fixture_sessions WHERE session_user_id=7')===0 && (int)$db->scalar('SELECT COUNT(*) FROM fixture_grants WHERE user_id=7')===0,'Credentials and delegated grants revoked before ancillary-table failure');
	$db->failure=''; phpbb_cleanup_removed_user_references($db,7); phpbb_cleanup_removed_user_references($db,7);
	foreach(deletion_reference_tables() as $table=>$column){deletion_check((int)$db->scalar('SELECT COUNT(*) FROM '.$table)===3,'Reference helper is safely repeatable after partial failure');}
	foreach(array('run_prune') as $controller)
	{
		$db=new DeletionDatabase(); $db->pdo->exec("UPDATE fixture_users SET username='O''Brien Grüße' WHERE user_id=7");
		call_user_func($controller,$db);
		deletion_check($db->scalar('SELECT post_username FROM fixture_posts WHERE poster_id=-1')==="O'Brien Grüße" && $db->scalar('SELECT shout_username FROM fixture_shouts WHERE shout_user_id=-1')==="O'Brien Grüße",'Removed authors retain escaped UTF-8 display names: '.$controller);
		deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_posts WHERE poster_id=8')===1 && (int)$db->scalar('SELECT COUNT(*) FROM fixture_shouts WHERE shout_user_id=8')===1,'Other authors remain untouched');
		deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_topics WHERE topic_poster=7')===0 && (int)$db->scalar('SELECT COUNT(*) FROM fixture_votes WHERE vote_user_id=7')===0 && (int)$db->scalar('SELECT group_moderator FROM fixture_groups WHERE group_id=200')===9,'Topics/votes/moderation reassigned only for removed target');
	}
	$db=new DeletionDatabase(); expect_deletion_failure(function()use($db){phpbb_anonymize_removed_user_content($db,7,'Name',9);});
	deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_posts WHERE poster_id=7')===1,'Existing account content cannot be anonymized');
	$db=new DeletionDatabase(); $db->pdo->exec('DELETE FROM fixture_users WHERE user_id=7'); $restored=false;
	$db->hook=function($sql,$db)use(&$restored){if(!$restored && strpos($sql,'UPDATE fixture_posts')===0){$restored=true;$db->pdo->exec("INSERT INTO fixture_users VALUES(7,1,0,0,'Restored','','english')");}};
	expect_deletion_failure(function()use($db){phpbb_anonymize_removed_user_content($db,7,'Name',9);});
	deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_posts WHERE poster_id=7')===1 && (int)$db->scalar('SELECT COUNT(*) FROM fixture_shouts WHERE shout_user_id=7')===1 && (int)$db->scalar('SELECT group_moderator FROM fixture_groups WHERE group_id=200')===7,'Restored account protected in every content write');
	$db=new DeletionDatabase(); $db->pdo->exec('DELETE FROM fixture_users WHERE user_id=7'); $db->failure='UPDATE fixture_shouts';
	expect_deletion_failure(function()use($db){phpbb_anonymize_removed_user_content($db,7,'Name',9);});
	$db->failure=''; phpbb_anonymize_removed_user_content($db,7,'Name',9); phpbb_anonymize_removed_user_content($db,7,'Name',9);
	deletion_check((int)$db->scalar('SELECT COUNT(*) FROM fixture_posts')===2 && (int)$db->scalar('SELECT COUNT(*) FROM fixture_shouts')===2 && (int)$db->scalar('SELECT COUNT(*) FROM fixture_shouts WHERE shout_user_id=7')===0,'Content helper can finish after partial failure without deleting content');
	echo "User management and pruning eligibility/group/reference/content-scope checks passed.\n";
}
finally { restore_error_handler(); }
