<?php
$root=dirname(dirname(__DIR__)).'/phpBB2/';
foreach(array('IN_PHPBB'=>true,'ADMIN'=>1,'MOD'=>2,'USER'=>0,'GENERAL_ERROR'=>202,'ATTACHMENTS_TABLE'=>'fixture_links','USERS_TABLE'=>'fixture_users','PRIVMSGS_TABLE'=>'fixture_pm','IN_ADMIN'=>true,'DELETED'=>-1,'PAGE_PRIVMSGS'=>-10,'PRIVMSGS_TEXT_TABLE'=>'fixture_text','ATTACHMENTS_DESC_TABLE'=>'fixture_descriptions','PRIVMSGS_READ_MAIL'=>0,'PRIVMSGS_SENT_MAIL'=>2,'PRIVMSGS_SAVED_IN_MAIL'=>3,'PRIVMSGS_SAVED_OUT_MAIL'=>4,'PRIVMSGS_NEW_MAIL'=>1,'PRIVMSGS_UNREAD_MAIL'=>5,'JR_ADMIN_TABLE'=>'fixture_junior') as $key=>$value){define($key,$value);}
require_once $root.'includes/functions_maintenance_pm.php';
require_once $root.'attach_mod/includes/functions_delete.php';
require_once $root.'includes/functions_privmsgs.php';
function pm_repair_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function message_die($code,$message){throw new RuntimeException($message);}
class PmRepairControllerFailure extends RuntimeException {}
function throw_error($message){throw new PmRepairControllerFailure($message);}
function lock_db($unlock=false,$delay=true,$ignore=false){$GLOBALS['board_locks'][]=array($unlock,$delay,$ignore);}
class PmRepairRows {public $rows;function __construct($rows){$this->rows=$rows;}}
$dsn=getenv('PHPBB_PM_REPAIR_TEST_DSN');$native=$dsn!==false&&$dsn!=='';
if($native){pm_repair_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_pm_repair_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned local schemas allowed');}
class PmRepairServer {
 public $pdo;public $owner=null;public $hook=null;public $failure='';public $queries=array();
 function __construct($engine){
  $this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root',''):new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $definitions=array('users'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(255),user_level INTEGER,user_active INTEGER,user_new_privmsg INTEGER,user_unread_privmsg INTEGER','pm'=>'privmsgs_id INTEGER PRIMARY KEY,privmsgs_to_userid INTEGER,privmsgs_from_userid INTEGER,privmsgs_type INTEGER,privmsgs_date INTEGER DEFAULT 0,privmsgs_attachment INTEGER DEFAULT 0','junior'=>'user_id INTEGER,user_jr_admin VARCHAR(255)','text'=>'privmsgs_text_id INTEGER PRIMARY KEY,privmsgs_text VARCHAR(255)','links'=>'attach_id INTEGER,privmsgs_id INTEGER,post_id INTEGER','descriptions'=>'attach_id INTEGER PRIMARY KEY,physical_filename VARCHAR(255),thumbnail INTEGER');
  foreach($definitions as $name=>$definition){$this->pdo->exec('DROP TABLE IF EXISTS fixture_'.$name);$this->pdo->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['native']?' ENGINE='.$engine:''));}
  $this->pdo->exec("INSERT INTO fixture_users VALUES (1,'Root',1,1,0,0),(20,'Junior',0,1,0,0),(-1,'Anonymous',0,0,17,18),(0,'Deleted',0,0,19,20),(8,'Recipient',0,1,99,99),(9,'Empty',0,0,NULL,NULL)");
  $this->pdo->exec("INSERT INTO fixture_pm (privmsgs_id,privmsgs_to_userid,privmsgs_from_userid,privmsgs_type) VALUES (10,8,1,1),(11,8,1,1),(12,8,1,1),(14,8,999,0),(15,999,8,2),(16,8,-1,1),(17,-1,8,0),(18,8,1,3)");
  $this->pdo->exec('UPDATE fixture_pm SET privmsgs_date='.time().' WHERE privmsgs_id=11');
  $this->pdo->exec("INSERT INTO fixture_text VALUES (12,'Grüße'),(13,'orphan'),(14,'Received'),(15,'Sent copy'),(16,'Invalid'),(17,'Invalid'),(18,'Saved')");


 }
}
class PmRepairForum {
 public $dbname='pm-counter-fixture';
 function sql_query($sql){throw new RuntimeException('Unlocked main connection used');}
 function sql_dedicated_connection(){return new PmRepairConnection($GLOBALS['pm_repair_server']);}
}
class PmRepairConnection {
 public $server;public $pdo;public $db_connect_id=true;public $closed=false;public $affected=0;
 function __construct($server){$this->server=$server;$this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION)):$server->pdo;}
 function sql_query($sql){
  if($this->closed){return false;}$s=$this->server;$s->queries[]=$sql;
  if(strpos($sql,'SELECT GET_LOCK(')===0){
   if($s->failure==='lock'){return new PmRepairRows(array(array('acquired'=>0)));}
   $rows=$GLOBALS['native']?$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC):array(array('acquired'=>$s->owner===null?1:0));
   if((int)$rows[0]['acquired']===1){$s->owner=$this;}return new PmRepairRows($rows);
  }
  pm_repair_check($s->owner===$this,'Every protected query uses the owning connection');
  if(is_callable($s->hook)){call_user_func($s->hook,$sql,$this);}
  if($this->closed||($s->failure!==''&&strpos($sql,$s->failure)===0)){return false;}
  try{$r=$this->pdo->query($sql);$this->affected=$r->rowCount();return preg_match('/^SELECT/',$sql)?new PmRepairRows($r->fetchAll(PDO::FETCH_ASSOC)):true;}catch(PDOException $e){return false;}
 }
 function sql_fetchrow($r){return array_shift($r->rows);}
 function sql_fetchrowset($r){return $r->rows;}
 function sql_freeresult($r){}
 function sql_numrows($r){return count($r->rows);}
 function sql_affectedrows(){return $this->affected;}
 function sql_escape($s){return substr($this->pdo->quote($s),1,-1);}
 function sql_close(){if($this->server->owner===$this){$this->server->owner=null;}$this->closed=true;$this->db_connect_id=false;$this->pdo=null;}
}




function pm_repair_fixture($engine,$actor=1){
 global $pm_repair_server,$userdata,$phpEx,$phpbb_root_path,$root,$db;
 $pm_repair_server=new PmRepairServer($engine);$userdata=array('user_id'=>$actor,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-sid');
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-sid');$phpEx='php';$phpbb_root_path=$root;$db=new PmRepairForum();
}
function pm_repair_value($sql){return (int)$GLOBALS['pm_repair_server']->pdo->query($sql)->fetchColumn();}
function pm_repair_snapshot($table){return $GLOBALS['pm_repair_server']->pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);}
function pm_repair_run($expected='',$request=null,$mode=null,$ids=array()){
 $caught='';$result=null;try{$result=$mode===null?dbmtnc_repair_pm(new PmRepairForum(),$request===null?$_POST:$request):phpbb_pm_repair_messages($ids,$mode);}catch(PhpbbAclException $e){$caught=$e->getMessage();}
 pm_repair_check($caught===$expected,'Expected repair outcome: '.$caught.' / '.$expected);pm_repair_check($GLOBALS['pm_repair_server']->owner===null,'Owner released');return $result;
}
function pm_repair_many($count){
 $pdo=$GLOBALS['pm_repair_server']->pdo;$pdo->exec('DELETE FROM fixture_pm');$pdo->exec('DELETE FROM fixture_text');
 for($id=100;$id<100+$count;$id++){$pdo->exec('INSERT INTO fixture_pm (privmsgs_id,privmsgs_to_userid,privmsgs_from_userid,privmsgs_type) VALUES ('.$id.',8,999,0)');$pdo->exec("INSERT INTO fixture_text VALUES (".$id.",'Keep body')");}
}
$controller=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($controller,"case 'check_pm':");$b=strpos($controller,"case 'check_config':",$a);
pm_repair_check($a!==false&&$b>$a,'Actual complete PM controller found');$branch='switch($function){'.substr($controller,$a,$b-$a).'}';
$selections=array('missing_text'=>array(10),'orphan_text'=>array(13),'invalid_sender'=>array(14),'invalid_recipient'=>array(15),'deleted_users'=>array(16,17));
$writes=array('missing_text'=>'DELETE FROM fixture_pm','orphan_text'=>'DELETE FROM fixture_text','invalid_sender'=>'UPDATE fixture_pm SET privmsgs_from_userid','invalid_recipient'=>'UPDATE fixture_pm SET privmsgs_to_userid','deleted_users'=>'DELETE FROM fixture_pm');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach($native?array('MyISAM','InnoDB'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
  $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy','PM_cleanup_failed'=>'pm-failed');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
  pm_repair_fixture($engine);$sentinels=$pm_repair_server->pdo->query('SELECT * FROM fixture_users WHERE user_id<=0 ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
  $counts=pm_repair_run();pm_repair_check($counts===array('missing_text'=>1,'orphan_text'=>1,'invalid_sender'=>1,'invalid_recipient'=>1,'deleted_users'=>2),'All five repair modes return actual counts');
  pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id IN (11,12,14,15,18)')===5,'Recent incomplete, valid, received and sent/saved copies retained');
  pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_text WHERE privmsgs_text_id IN (12,14,15,18)')===4,'Retained messages keep their texts');
  pm_repair_check($sentinels===$pm_repair_server->pdo->query('SELECT * FROM fixture_users WHERE user_id<=0 ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC),'Negative/zero recipient sentinels not recounted');
  pm_repair_check(array_sum(pm_repair_run())===0,'Repeat repair is a no-op');
  $pm_repair_server->pdo->exec('DELETE FROM fixture_users WHERE user_id=-1');pm_repair_check(array_sum(pm_repair_run())===0,'No repeat anonymization without anonymous user row');
  foreach($selections as $mode=>$ids){
   foreach(array('inactive','demoted','session','gone','revoked-write','revoked-read','lost-owner','query') as $case){
    pm_repair_fixture($engine);$before=pm_repair_snapshot('fixture_pm');$texts=pm_repair_snapshot('fixture_text');$prefix=$writes[$mode];$expected=$lang['Not_Authorised'];
    if($case==='inactive'){$pm_repair_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
    elseif($case==='demoted'){$pm_repair_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1');}
    elseif($case==='gone'){$pm_repair_server->pdo->exec('DELETE FROM fixture_users WHERE user_id=1');}
    elseif($case==='session'){$userdata['session_admin']=false;}
    else{
     if($case==='lost-owner'||$case==='query'){$expected=$lang['Maintenance_pm_repair_failed'];}
     $pm_repair_server->hook=function($sql,$connection) use($prefix,$case){
      $match=$case==='revoked-read'?strpos($sql,'SELECT user_id, user_level, user_active FROM fixture_users')===0:strpos($sql,$prefix)===0;
      if(!$match){return;}$s=$GLOBALS['pm_repair_server'];$s->hook=null;
      if($case==='lost-owner'){$connection->sql_close();}elseif($case==='query'){$s->failure=$prefix;}else{$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
     };
    }
    pm_repair_run($expected,null,$mode,$ids);
    pm_repair_check($before===pm_repair_snapshot('fixture_pm')&&$texts===pm_repair_snapshot('fixture_text'),'Current authority/failure protects '.$mode.' / '.$case);
   }
   foreach(array('allowed','wrong','revoked','case-change','trailing-space') as $case){
    pm_repair_fixture($engine,20);$hash=md5('GeneralDB_Maintenanceadmin_db_maintenance.php');$grant=$case==='wrong'?md5('UsersManageadmin_users.php'):$hash;
    $pm_repair_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$grant."')");$before=pm_repair_snapshot('fixture_pm');$texts=pm_repair_snapshot('fixture_text');$prefix=$writes[$mode];
    if(in_array($case,array('revoked','case-change','trailing-space'),true)){$pm_repair_server->hook=function($sql) use($prefix,$case,$hash){if(strpos($sql,$prefix)!==0){return;}$s=$GLOBALS['pm_repair_server'];$s->hook=null;if($case==='revoked'){$s->pdo->exec('DELETE FROM fixture_junior');}else{$replacement=$case==='case-change'?strtoupper($hash):$hash.' ';$s->pdo->exec("UPDATE fixture_junior SET user_jr_admin='".$replacement."'");}};}
    pm_repair_run($case==='allowed'?'':$lang['Not_Authorised'],null,$mode,$ids);
    if($case!=='allowed'){pm_repair_check($before===pm_repair_snapshot('fixture_pm')&&$texts===pm_repair_snapshot('fixture_text'),'Exact byte/current grant guards '.$mode.' / '.$case);}
   }
  }
  foreach(array('text','parent','sender','recipient','mailbox') as $restore){
   pm_repair_fixture($engine);$mode=$restore==='text'?'missing_text':($restore==='parent'?'orphan_text':($restore==='sender'?'invalid_sender':($restore==='recipient'?'invalid_recipient':'deleted_users')));$prefix=$writes[$mode];
   $pm_repair_server->hook=function($sql) use($prefix,$restore){if(strpos($sql,$prefix)!==0){return;}$s=$GLOBALS['pm_repair_server'];$s->hook=null;
    if($restore==='text'){$s->pdo->exec("INSERT INTO fixture_text VALUES (10,'Restored')");}
    elseif($restore==='parent'){$s->pdo->exec('INSERT INTO fixture_pm (privmsgs_id,privmsgs_to_userid,privmsgs_from_userid,privmsgs_type) VALUES (13,8,1,0)');}
    elseif($restore==='sender'||$restore==='recipient'){$s->pdo->exec("INSERT INTO fixture_users VALUES (999,'Restored',0,1,0,0)");}
    else{$s->pdo->exec('UPDATE fixture_pm SET privmsgs_from_userid=1 WHERE privmsgs_id=16');$s->pdo->exec('UPDATE fixture_pm SET privmsgs_to_userid=8 WHERE privmsgs_id=17');}
   };
   pm_repair_check(pm_repair_run('',null,$mode,$selections[$mode])===0,'Current repaired source preserved: '.$restore);
  }
  foreach(array(100,101,205) as $count){
   pm_repair_fixture($engine);pm_repair_many($count);$result=pm_repair_run();pm_repair_check($result['invalid_sender']===$count&&array_sum($result)===$count,'All bounded pages repaired');
   $batches=0;foreach($pm_repair_server->queries as $sql){if(strpos($sql,'UPDATE fixture_pm SET privmsgs_from_userid')===0){$batches++;preg_match('/privmsgs_id IN \(([^)]+)\)/',$sql,$m);pm_repair_check(count(explode(',',$m[1]))<=100,'Repair batches bounded');}}
   pm_repair_check($batches===(int)ceil($count/100)&&pm_repair_value('SELECT COUNT(*) FROM fixture_text')===$count,'Batch count and message texts preserved');
  }
  foreach(array('query','actor') as $failure){
   pm_repair_fixture($engine);pm_repair_many(205);$batches=0;$pm_repair_server->hook=function($sql) use(&$batches,$failure){if(strpos($sql,'UPDATE fixture_pm SET privmsgs_from_userid')===0&&++$batches===2){$s=$GLOBALS['pm_repair_server'];$s->hook=null;if($failure==='actor'){$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}else{$s->failure='UPDATE fixture_pm SET privmsgs_from_userid';}}};
   pm_repair_run($failure==='actor'?$lang['Not_Authorised']:$lang['Maintenance_pm_repair_failed']);
   pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_from_userid=-1')===100,'Earlier confirmed batch is not rolled back');
   $pm_repair_server->failure='';$pm_repair_server->pdo->exec('UPDATE fixture_users SET user_active=1 WHERE user_id=1');$result=pm_repair_run();pm_repair_check($result['invalid_sender']===105,'Retry completes remaining source repairs');
  }
  foreach(array('get','array','wrong','missing') as $case){
   pm_repair_fixture($engine);$request=$_POST;if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($case==='array'){$request['sid']=array();}elseif($case==='missing'){unset($request['sid']);}else{$request['sid']='bad';}
   pm_repair_run($lang['Session_invalid'],$request);pm_repair_check(!$pm_repair_server->queries,'Invalid form rejected before database access');
  }
  pm_repair_fixture($engine);$pm_repair_server->failure='lock';pm_repair_run($lang['Attachment_storage_busy']);
  pm_repair_fixture($engine);$contended=false;$pm_repair_server->hook=function($sql) use(&$contended){if(strpos($sql,'SELECT privmsgs_id AS id')===0){$GLOBALS['pm_repair_server']->hook=null;$other=new attach_mutation_lock(new PmRepairForum(),false);$contended=!$other->acquired;$other->release();}};pm_repair_run();pm_repair_check($contended,'Same shared writer lock covers diagnostics and repairs');
  foreach(array('success','empty','invalid','query','actor') as $case){
   pm_repair_fixture($engine);if($case==='empty'){pm_repair_run();dbmtnc_synchronize_pm_counters($db,$_POST);}if($case==='invalid'){$_POST['sid']='bad';}if($case==='query'){$pm_repair_server->failure='DELETE FROM fixture_pm';}if($case==='actor'){$pm_repair_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
   $function='check_pm';$board_locks=array();$caught='';ob_start();try{eval($branch);}catch(PmRepairControllerFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
   pm_repair_check(!$board_locks,'Actual complete PM controller never disables the board');
   pm_repair_check(($caught!=='')===in_array($case,array('invalid','query','actor'),true),'Actual controller reports outcome');
   if($caught!==''){pm_repair_check(strpos($caught,$lang['Maintenance_pm_repair_failed'])!==false,'Failure acknowledges possible partial changes');}
   else{foreach($selections as $mode=>$ids){pm_repair_check(strpos($html,sprintf($lang['Maintenance_pm_repair_'.$mode],$case==='empty'?0:count($ids)))!==false,'Actual localized repair counts');}}
  }
  echo $engine.' '.$locale." current-authority PM repair passed.\n";
 }}
}finally{restore_error_handler();}

