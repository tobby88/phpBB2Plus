<?php
// Reuse only the isolated owning-connection adapter, never another test's run.
$shared = file_get_contents(__DIR__ . '/check-maintenance-sessions.php');
$end = strpos($shared, "\n\$controller=file_get_contents(");
if ($end === false) { throw new RuntimeException('Fixture boundary missing'); }
$shared = substr($shared, 5, $end - 5);
$shared = str_replace('function throw_error($message) { throw new ResetControllerFailure($message); }',
 'function throw_error($message) { reset_check($GLOBALS["resetServer"]->owner === null, "Release before terminal renderer"); throw new ResetControllerFailure($message); }', $shared, $replaced);
if ($replaced !== 1) { throw new RuntimeException('Error renderer assertion unavailable'); }
$shared = str_replace("'users'=>'user_id INTEGER PRIMARY KEY,user_level INTEGER,user_active INTEGER'", "'users'=>user_fixture_definition()", $shared, $replaced);
if ($replaced !== 1) { throw new RuntimeException('Full fixture user schema boundary missing'); }
$shared = str_replace('INSERT INTO fixture_users VALUES (1,1,1),(20,0,1)', 'INSERT INTO fixture_users (user_id,user_level,user_active) VALUES (1,1,1),(20,0,1)', $shared, $replaced);
if ($replaced !== 1) { throw new RuntimeException('Fixture user insertion boundary missing'); }
$shared = str_replace("'keys'=>'key_id VARCHAR(32),user_id INTEGER'", "'keys'=>'key_id VARCHAR(32),user_id INTEGER,last_login INTEGER DEFAULT 1'", $shared, $replaced);
if ($replaced !== 1) { throw new RuntimeException('Fixture key schema boundary missing'); }
$shared = str_replace("INSERT INTO fixture_keys VALUES ('remember',8)", "INSERT INTO fixture_keys (key_id,user_id) VALUES ('remember',8)", $shared, $replaced);
if ($replaced !== 1) { throw new RuntimeException('Fixture key insertion boundary missing'); }
$pattern = '/\$this->pdo->exec\(\x27DROP TABLE IF EXISTS fixture_\x27\.\$name\);\s*\$this->pdo->exec\(\x27CREATE TABLE fixture_\x27\.\$name\.\x27 \(\x27\.\$definition\.\x27\)\x27\.\(\$GLOBALS\[\x27resetNative\x27\]\?\x27 ENGINE=\x27\.\$engine:\x27\x27\)\);/';
$shared = preg_replace($pattern, 'user_fixture_table($this->pdo,$name,$definition,$engine);', $shared, -1, $replaced);
if ($replaced !== 1) { throw new RuntimeException('Fixture table setup boundary missing'); }
eval($shared);
foreach (array('ANONYMOUS'=>-1,'GROUPS_TABLE'=>'fixture_groups','USER_GROUP_TABLE'=>'fixture_user_group',
 'THEMES_TABLE'=>'fixture_themes','THEMES_NAME_TABLE'=>'fixture_theme_names','RANKS_TABLE'=>'fixture_ranks',
 'CONFIG_TABLE'=>'fixture_config','BANLIST_TABLE'=>'fixture_bans','SESSIONS_KEYS_TABLE'=>'fixture_keys') as $key=>$value) { define($key,$value); }
require_once $root . 'includes/functions_maintenance_users.php';
class UserConnection extends ResetConnection {
 function sql_nextid() { return $this->pdo->lastInsertId(); }
 function sql_query($sql) {
  try { return parent::sql_query($sql); }
  catch(Exception $e) { $GLOBALS['userFixtureError']=$e->getMessage(); throw $e; }
 }
}
class UserForum extends ResetForum { function sql_dedicated_connection() { return new UserConnection($GLOBALS['resetServer']); } }
$controller = file_get_contents($root . 'admin/admin_db_maintenance.php');
$start = strpos($controller, "case 'check_user':"); $end = strpos($controller, "case 'check_post':", $start);
reset_check($start !== false && $end > $start, 'Actual full user controller found');
$userCode = 'switch("check_user"){' . substr($controller, $start, $end-$start) . '}';
function user_fixture_definition() {
 $definition='user_id INTEGER PRIMARY KEY,user_level INTEGER,user_active INTEGER';
 foreach(array('username','user_lang','user_style','user_rank','user_regdate','user_password','user_email','user_icq','user_website','user_occ','user_from','user_interests','user_sig','user_viewemail','user_aim','user_yim','user_msnm','user_posts','user_attachsig','user_allowsmile','user_allowhtml','user_allowbbcode','user_allow_pm','user_notify_pm','user_allow_viewonline','user_avatar','user_timezone','user_dateformat','user_actkey','user_newpasswd','user_notify') as $column) {
  $definition.=','.$column.' '.(in_array($column,array('user_style','user_rank'),true)?'INTEGER DEFAULT 0':'VARCHAR(255)');
 } return $definition;
}
function user_fixture_table($pdo,$name,$definition,$engine) {
 static $engines=array();
 // Native fixtures reuse only their owned schema's empty table definitions.
 // Every row is reseeded per case; a new engine still recreates every table.
 if($GLOBALS['resetNative'] && isset($engines[$name]) && $engines[$name]===$engine) {
  $pdo->exec('DELETE FROM fixture_'.$name);return;
 }
 $pdo->exec('DROP TABLE IF EXISTS fixture_'.$name);
 $pdo->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['resetNative']?' ENGINE='.$engine.' DEFAULT CHARSET=utf8mb4':''));
 $engines[$name]=$engine;
}
function user_fixture($engine, $actor=1, $guest=false) {
 global $resetServer,$userdata,$board_config,$phpbb_version;
 reset_fixture($engine,$actor); $p=$resetServer->pdo; $GLOBALS['userFixtureError']='';
 $userdata['user_style']=1; $userdata['user_lang']='english'; $board_config=array('board_disable'=>0,'default_style'=>1); $phpbb_version=array(0,23);
 $suffix=$GLOBALS['resetNative']?' ENGINE='.$engine.' DEFAULT CHARSET=utf8mb4':'';
 foreach(array('groups'=>'group_id INTEGER PRIMARY KEY '.($GLOBALS['resetNative']?'AUTO_INCREMENT':'AUTOINCREMENT').',group_type INTEGER,group_name VARCHAR(100),group_description VARCHAR(100),group_moderator INTEGER,group_single_user INTEGER',
  'user_group'=>'group_id INTEGER,user_id INTEGER,user_pending INTEGER',
  'themes'=>'themes_id INTEGER PRIMARY KEY','theme_names'=>'themes_id INTEGER PRIMARY KEY',
  'ranks'=>'rank_id INTEGER PRIMARY KEY','config'=>'config_name VARCHAR(255),config_value VARCHAR(255)',
  'bans'=>'ban_id INTEGER PRIMARY KEY,ban_userid INTEGER,ban_ip VARCHAR(45),ban_email VARCHAR(255)',
  'auth'=>'group_id INTEGER,forum_id INTEGER,auth_read INTEGER','album_policy'=>'group_id INTEGER,permission_value INTEGER') as $name=>$definition) {
  user_fixture_table($p,$name,$definition,$engine);
 }
 $p->exec("UPDATE fixture_users SET username='Grüße',user_lang='english',user_style=1,user_rank=0");
 if(!$guest) { $p->exec("INSERT INTO fixture_users (user_id,user_level,user_active,username,user_lang,user_style,user_rank) VALUES (-1,0,0,'Anonymous','',NULL,0)"); }
 $p->exec("INSERT INTO fixture_users (user_id,user_level,user_active,username,user_lang,user_style,user_rank) VALUES (3,0,1,'Missing','broken',99,99),(4,0,1,'Shared','english',1,0)");
 $p->exec("INSERT INTO fixture_groups VALUES (10,1,'','Personal User',0,1),(20,1,'','Personal User',0,1),(30,1,'Shared personal','Keep',0,1),(40,0,'Empty protected','Keep',0,0),(50,0,'Lost moderator','Keep',999,0),(60,0,'Pending moderator','Keep',20,0)");
 $p->exec("INSERT INTO fixture_users (user_id,user_level,user_active,username,user_lang,user_style,user_rank) VALUES (5,0,1,'Pending','english',NULL,0),(6,0,1,'Null pending','english',1,0)");
 $p->exec("INSERT INTO fixture_groups VALUES (70,1,'','Personal User',0,1),(80,1,'','Personal User',0,1)");
 $p->exec('INSERT INTO fixture_user_group VALUES (70,5,1),(80,6,NULL)');
 $p->exec('INSERT INTO fixture_user_group VALUES (10,1,1),(20,20,0),(30,1,1),(30,4,0),(60,20,1),(10,998,0),(997,4,0)');
 $p->exec('INSERT INTO fixture_auth VALUES (10,1,1),(30,2,1),(40,3,1)'); $p->exec('INSERT INTO fixture_album_policy VALUES (30,1),(40,1)');
 $p->exec('INSERT INTO fixture_themes VALUES (1)'); $p->exec('INSERT INTO fixture_theme_names VALUES (1),(99)'); $p->exec('INSERT INTO fixture_ranks VALUES (1)');
 $p->exec("INSERT INTO fixture_config VALUES ('default_lang','german'),('board_disable','0')");
 $p->exec("INSERT INTO fixture_bans VALUES (1,991,'',''),(2,992,'127.0.0.1',''),(3,993,'','*@invalid.test'),(4,20,'',''),(5,0,'::1','')");
 $p->exec("INSERT INTO fixture_keys VALUES ('valid',20,1),('future',20,".(time()+3600).")");
}
function user_snapshot() {
 $out=array(); foreach(array('users'=>'user_id','groups'=>'group_id','user_group'=>'group_id,user_id,user_pending','config'=>'config_name','keys'=>'key_id,user_id','sessions'=>'session_id','bans'=>'ban_id','theme_names'=>'themes_id','auth'=>'group_id','album_policy'=>'group_id') as $table=>$order) {
  $out[$table]=$GLOBALS['resetServer']->pdo->query('SELECT * FROM fixture_'.$table.' ORDER BY '.$order)->fetchAll(PDO::FETCH_ASSOC);
 } return $out;
}
function user_run($expected='') {
 global $db,$userdata,$board_config,$phpbb_version,$phpbb_root_path,$phpEx,$lang,$userCode;
 $db=new UserForum(); $original=$db; $list_open=false; $error=''; ob_start();
 try { eval($userCode); } catch(ResetControllerFailure $e) { $error=$e->getMessage(); }
 finally { $html=ob_get_clean(); }
 reset_check($db===$original && $GLOBALS['resetServer']->owner===null,'Original connection restored and writer released');
 reset_check($error===$expected,'Full controller outcome: '.$error.' / '.$expected.' '.$GLOBALS['userFixtureError']);
 return $html;
}
function user_value($sql) { return $GLOBALS['resetServer']->pdo->query($sql)->fetchColumn(); }
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try {
 foreach($resetNative?array('MyISAM','InnoDB'):array('SQLite') as $engine) { foreach(array('english','german') as $locale) {
  $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy'); $mtnc=array(); $phpEx='php'; include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
  foreach(array(false,true) as $missingGuest) { foreach(array(0,1) as $board) {
   user_fixture($engine,1,$missingGuest); $board_config['board_disable']=$board; $resetServer->pdo->exec("UPDATE fixture_config SET config_value='".$board."' WHERE config_name='board_disable'");
   $before=user_snapshot(); $html=user_run(); $after=user_snapshot();
   foreach(array('config','sessions','auth','album_policy') as $table) { reset_check($before[$table]===$after[$table],'Preserve '.$table); }
   reset_check((int)user_value('SELECT COUNT(*) FROM fixture_users WHERE user_id=-1')===1,'Guest exists/recreated');
   reset_check(user_value('SELECT user_lang FROM fixture_users WHERE user_id=3')==='german' && (int)user_value('SELECT user_style FROM fixture_users WHERE user_id=3')===1 && (int)user_value('SELECT user_rank FROM fixture_users WHERE user_id=3')===0,'Invalid profile repaired');
   reset_check((int)user_value('SELECT group_moderator FROM fixture_groups WHERE group_id=50')===1,'Missing moderator repaired');
   reset_check((int)user_value('SELECT COUNT(*) FROM fixture_user_group WHERE group_id=50 AND user_id=1 AND user_pending=0')===1,'Moderator membership repaired');
   reset_check((int)user_value('SELECT user_pending FROM fixture_user_group WHERE group_id=60')===0,'Pending moderator approved');
   reset_check((int)user_value('SELECT COUNT(*) FROM fixture_groups WHERE group_id=40')===1 && (int)user_value('SELECT user_pending FROM fixture_user_group WHERE group_id=30 AND user_id=1')===1,'Empty and shared personal groups preserved');
   reset_check((int)user_value('SELECT COUNT(*) FROM fixture_user_group WHERE user_id=998 OR group_id=997')===0,'Current orphans removed');
   reset_check((int)user_value('SELECT COUNT(*) FROM fixture_bans WHERE ban_id=1')===0,'User-only orphan ban removed');
   reset_check((int)user_value('SELECT ban_userid FROM fixture_bans WHERE ban_id=2')===0 && user_value('SELECT ban_ip FROM fixture_bans WHERE ban_id=2')==='127.0.0.1' && user_value('SELECT ban_email FROM fixture_bans WHERE ban_id=3')==='*@invalid.test','Combined IP/email bans retained');
   reset_check(count($after['keys'])===1 && $after['keys'][0]['key_id']==='valid','Only invalid login keys removed');
   reset_check(strpos($html,$lang['Review_personal_groups'])!==false,'Ambiguity reported');
   // A dangling membership removed in pass one may unblock a personal repair.
   user_run(); $stable=user_snapshot(); user_run(); reset_check(user_snapshot()===$stable,'Stable repeat is idempotent');
  }}
  user_fixture($engine,1,true); user_run(); $writes=array();
  foreach($resetServer->queries as $sql) { if(preg_match('/^(?:INSERT INTO|UPDATE|DELETE FROM) /',$sql)) { $writes[]=$sql; } }
  reset_check(count($writes)>=18,'Full-controller write sites exercised, including NULL style and pending repairs');
  foreach(array('get','bad-sid','nested-sid','inactive','no-session','demoted','no-admin-session') as $case) {
   user_fixture($engine); $expected=$lang['Not_Authorised'];
   if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';$expected=$lang['Session_invalid'];}
   elseif($case==='bad-sid'){$_POST['sid']='wrong';$expected=$lang['Session_invalid'];}
   elseif($case==='nested-sid'){$_POST['sid']=array();$expected=$lang['Session_invalid'];}
   elseif($case==='inactive'){$resetServer->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
   elseif($case==='demoted'){$resetServer->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1');}
   elseif($case==='no-session'){$resetServer->pdo->exec('DELETE FROM fixture_sessions');}
   else{$resetServer->pdo->exec('UPDATE fixture_sessions SET session_admin=0');}
   $before=user_snapshot();user_run($expected);reset_check(user_snapshot()===$before,'Denied request writes nothing');
  }
  $grant=md5('GeneralDB_Maintenanceadmin_db_maintenance.php');
  user_fixture($engine,20);$resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$grant."')");user_run();
  user_fixture($engine,20);$resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'wrong')");user_run($lang['Not_Authorised']);
  // Every reached write is interrupted at its actual dispatch boundary.
  foreach($writes as $index=>$unused) { foreach(array('session','account','grant','failure','lost-owner') as $race) {
   user_fixture($engine,$race==='grant'?20:1,true);if($race==='grant'){$resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$grant."')");}
   $seen=0;$snapshot=null;
   $resetServer->hook=function($sql,$connection)use($index,$race,&$seen,&$snapshot){
    if(!preg_match('/^(?:INSERT INTO|UPDATE|DELETE FROM) /',$sql)){return;}if($seen++!==$index){return;}
    $s=$GLOBALS['resetServer'];$s->hook=null;
    if($race==='session'){$s->pdo->exec('UPDATE fixture_sessions SET session_admin=0');}
    elseif($race==='account'){$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
    elseif($race==='grant'){$s->pdo->exec('DELETE FROM fixture_junior');}
    elseif($race==='failure'){$s->failure=$sql;}
    else{$connection->sql_close();}
    $snapshot=user_snapshot();
   };
   user_run($lang[in_array($race,array('failure','lost-owner'),true)?'Maintenance_user_failed':'Not_Authorised']);
   reset_check($snapshot!==null && user_snapshot()===$snapshot,'No writes after dispatch-boundary failure/revocation '.$index.' '.$race);
  }}
  user_fixture($engine);$resetServer->failure='lock';$before=user_snapshot();user_run($lang['Attachment_storage_busy']);reset_check(user_snapshot()===$before,'Busy writer untouched');
  foreach($writes as $index=>$unused) {
   user_fixture($engine,1,true);$before=user_snapshot();$seen=0;$reached=false;
   $resetServer->hook=function($sql)use($index,&$seen,&$reached){
    if(!preg_match('/^(?:INSERT INTO|UPDATE|DELETE FROM) /',$sql)||$seen++!==$index){return;}
    $s=$GLOBALS['resetServer'];$s->hook=null;$s->failure=$sql;$s->lostAck=true;$reached=true;
   };
   user_run($lang['Maintenance_user_failed']);reset_check($reached,'Lost acknowledgement injected');$after=user_snapshot();
   foreach(array('sessions','auth','album_policy','config') as $table){reset_check($before[$table]===$after[$table],'Partial repair preserves unrelated '.$table);}
   $resetServer->failure='';$resetServer->lostAck=false;user_run();user_run();$stable=user_snapshot();user_run();reset_check(user_snapshot()===$stable,'Retry settles without destructive rollback');
  }
  $changes=array(
   'pending'=>array('UPDATE fixture_user_group','pending_other',"UPDATE fixture_groups SET group_single_user=0 WHERE group_id=70",'SELECT user_pending FROM fixture_user_group WHERE group_id=70',1),
   'group-created'=>array('INSERT INTO fixture_groups','existing_personal',array("INSERT INTO fixture_groups VALUES (90,1,'','Personal User',0,1)",'INSERT INTO fixture_user_group VALUES (90,3,0)'),'SELECT COUNT(*) FROM fixture_user_group WHERE user_id=3',1),
   'moderator'=>array('UPDATE fixture_groups','current_moderator','UPDATE fixture_groups SET group_moderator=20 WHERE group_id=50','SELECT group_moderator FROM fixture_groups WHERE group_id=50',20),
   'pending-moderator'=>array('UPDATE fixture_user_group','current_group','UPDATE fixture_groups SET group_moderator=1 WHERE group_id=60','SELECT user_pending FROM fixture_user_group WHERE group_id=60 AND user_id=20',1),
   'restored-user'=>array('DELETE FROM fixture_user_group','repair_user',"INSERT INTO fixture_users (user_id,user_level,user_active,username,user_lang,user_style,user_rank) VALUES (998,0,1,'Restored','english',1,0)",'SELECT COUNT(*) FROM fixture_user_group WHERE user_id=998',1),
   'restored-group'=>array('DELETE FROM fixture_user_group','current_group',"INSERT INTO fixture_groups VALUES (997,0,'Restored','Keep',20,0)",'SELECT COUNT(*) FROM fixture_user_group WHERE group_id=997',1),
   'restored-rank'=>array('UPDATE fixture_users','current_rank','INSERT INTO fixture_ranks VALUES (99)','SELECT user_rank FROM fixture_users WHERE user_id=3',99),
   'restored-style'=>array('UPDATE fixture_users','replacement_style','INSERT INTO fixture_themes VALUES (99)','SELECT user_style FROM fixture_users WHERE user_id=3',99),
   'restored-theme-name'=>array('DELETE FROM fixture_theme_names','current_style','INSERT INTO fixture_themes VALUES (99)','SELECT COUNT(*) FROM fixture_theme_names WHERE themes_id=99',1),
   'restored-ban-user'=>array('DELETE FROM fixture_bans','repair_user',"INSERT INTO fixture_users (user_id,user_level,user_active,username,user_lang,user_style,user_rank) VALUES (991,0,1,'Restored','english',1,0)",'SELECT COUNT(*) FROM fixture_bans WHERE ban_id=1',1),
   'changed-combined-ban'=>array('UPDATE fixture_bans','repair_user','UPDATE fixture_bans SET ban_userid=20 WHERE ban_id=2','SELECT ban_userid FROM fixture_bans WHERE ban_id=2',20)
  );
  foreach($changes as $name=>$change){
   user_fixture($engine);$reached=false;
   $resetServer->hook=function($sql)use($change,&$reached){
    if(strpos($sql,$change[0])!==0||strpos($sql,$change[1])===false){return;}
    $s=$GLOBALS['resetServer'];$s->hook=null;foreach((array)$change[2] as $statement){$s->pdo->exec($statement);}$reached=true;
   };
   user_run();reset_check($reached && (int)user_value($change[3])===$change[4],'Current source requalified: '.$name);
  }
  user_fixture($engine);$resetServer->pdo->exec('DELETE FROM fixture_themes');user_run('Fatal error!');
  user_fixture($engine);$resetServer->pdo->exec("DELETE FROM fixture_config WHERE config_name='default_lang'");user_run("Couldn't get config data! Please check your configuration table.");
  echo $engine.' '.$locale." full user-maintenance controller and every-write authority guards passed.\n";
 }}
} finally { restore_error_handler(); }
