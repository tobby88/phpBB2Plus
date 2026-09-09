<?php
// Execute the actual controller mutation blocks against isolated SQLite data.
// Inactive and managed deletion now use durable owning-connection workers,
// covered by check-user-removal-storage.php and check-admin-user-removal.php.
// Keep the separate legacy pruning and reusable cleanup-helper regressions.
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
function deletion_check($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
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
define('SHOUTBOX_TABLE','fixture_shouts');
// Actual pruning is exercised by check-user-pruning.php; retain the shared
// cleanup-helper contract tests below without copying the former controller.
function expect_deletion_failure($callback) { $caught=false; try { $callback(); } catch(DeletionFailure $e) { $caught=true; } deletion_check($caught,'Expected controlled rejection'); }
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try
{
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
	echo "Removed-account reference/content helper checks passed.\n";
}
finally { restore_error_handler(); }
