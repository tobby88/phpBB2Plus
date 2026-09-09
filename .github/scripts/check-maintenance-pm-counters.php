<?php
$root=dirname(dirname(__DIR__)).'/phpBB2/';
foreach(array('IN_PHPBB'=>true,'ADMIN'=>1,'MOD'=>2,'USER'=>0,'GENERAL_ERROR'=>202,'ATTACHMENTS_TABLE'=>'fixture_links','USERS_TABLE'=>'fixture_users','PRIVMSGS_TABLE'=>'fixture_pm','PRIVMSGS_NEW_MAIL'=>1,'PRIVMSGS_UNREAD_MAIL'=>5,'JR_ADMIN_TABLE'=>'fixture_junior') as $key=>$value){define($key,$value);}
require_once $root.'includes/functions_maintenance_pm.php';
function pm_counter_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function message_die($code,$message){throw new RuntimeException($message);}
class PmCounterControllerFailure extends RuntimeException {}
function throw_error($message){throw new PmCounterControllerFailure($message);}
function lock_db($unlock=false,$delay=true,$ignore=false){$GLOBALS['board_locks'][]=array($unlock,$delay,$ignore);}
class PmCounterRows {public $rows;function __construct($rows){$this->rows=$rows;}}
$dsn=getenv('PHPBB_PM_COUNTER_TEST_DSN');$native=$dsn!==false&&$dsn!=='';
if($native){pm_counter_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_pm_counter_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned local schemas allowed');}
class PmCounterServer {
 public $pdo;public $owner=null;public $hook=null;public $failure='';public $queries=array();
 function __construct($engine){
  $this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root',''):new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $definitions=array('users'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(255),user_level INTEGER,user_active INTEGER,user_new_privmsg INTEGER,user_unread_privmsg INTEGER','pm'=>'privmsgs_id INTEGER PRIMARY KEY,privmsgs_to_userid INTEGER,privmsgs_from_userid INTEGER,privmsgs_type INTEGER,body VARCHAR(255)','junior'=>'user_id INTEGER,user_jr_admin VARCHAR(255)');
  foreach($definitions as $name=>$definition){$this->pdo->exec('DROP TABLE IF EXISTS fixture_'.$name);$this->pdo->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['native']?' ENGINE='.$engine:''));}
  $this->pdo->exec("INSERT INTO fixture_users VALUES (1,'Root',1,1,0,0),(20,'Junior',0,1,0,0),(-1,'Anonymous',0,0,17,18),(0,'Deleted',0,0,19,20),(8,'Recipient',0,1,99,99),(9,'Empty',0,0,NULL,NULL)");
  $this->pdo->exec("INSERT INTO fixture_pm VALUES (1,8,1,1,'Grüße'),(2,8,1,5,'Unread'),(3,8,1,0,'Read'),(4,8,1,2,'Sent'),(5,8,1,3,'Saved'),(6,8,1,4,'Saved out'),(7,77,8,1,'Sender only')");

 }
}
class PmCounterForum {
 public $dbname='pm-counter-fixture';
 function sql_query($sql){throw new RuntimeException('Unlocked main connection used');}
 function sql_dedicated_connection(){return new PmCounterConnection($GLOBALS['pm_counter_server']);}
}
class PmCounterConnection {
 public $server;public $pdo;public $db_connect_id=true;public $closed=false;public $affected=0;
 function __construct($server){$this->server=$server;$this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION)):$server->pdo;}
 function sql_query($sql){
  if($this->closed){return false;}$s=$this->server;$s->queries[]=$sql;
  if(strpos($sql,'SELECT GET_LOCK(')===0){
   if($s->failure==='lock'){return new PmCounterRows(array(array('acquired'=>0)));}
   $rows=$GLOBALS['native']?$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC):array(array('acquired'=>$s->owner===null?1:0));
   if((int)$rows[0]['acquired']===1){$s->owner=$this;}return new PmCounterRows($rows);
  }
  pm_counter_check($s->owner===$this,'Every protected query uses the owning connection');
  if(is_callable($s->hook)){call_user_func($s->hook,$sql,$this);}
  if($this->closed||($s->failure!==''&&strpos($sql,$s->failure)===0)){return false;}
  try{$r=$this->pdo->query($sql);$this->affected=$r->rowCount();return preg_match('/^SELECT/',$sql)?new PmCounterRows($r->fetchAll(PDO::FETCH_ASSOC)):true;}catch(PDOException $e){return false;}
 }
 function sql_fetchrow($r){return array_shift($r->rows);}
 function sql_fetchrowset($r){return $r->rows;}
 function sql_freeresult($r){}
 function sql_affectedrows(){return $this->affected;}
 function sql_escape($s){return substr($this->pdo->quote($s),1,-1);}
 function sql_close(){if($this->server->owner===$this){$this->server->owner=null;}$this->closed=true;$this->db_connect_id=false;$this->pdo=null;}
}



function pm_counter_fixture($engine,$actor=1){
 global $pm_counter_server,$userdata,$phpEx,$phpbb_root_path,$root;
 $pm_counter_server=new PmCounterServer($engine);$userdata=array('user_id'=>$actor,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-sid');
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-sid');$phpEx='php';$phpbb_root_path=$root;
}
function pm_counter_value($sql){return (int)$GLOBALS['pm_counter_server']->pdo->query($sql)->fetchColumn();}
function pm_counter_snapshot($sql){return $GLOBALS['pm_counter_server']->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
function pm_counter_run($expected='',$request=null){
 $caught='';$result=null;try{$result=dbmtnc_synchronize_pm_counters(new PmCounterForum(),$request===null?$_POST:$request);}catch(PhpbbAclException $e){$caught=$e->getMessage();}
 pm_counter_check($caught===$expected,'Expected counter outcome: '.$caught.' / '.$expected);pm_counter_check($GLOBALS['pm_counter_server']->owner===null,'Owner released');return $result;
}
function pm_counter_many($count){
 $pdo=$GLOBALS['pm_counter_server']->pdo;$pdo->exec('DELETE FROM fixture_pm');$pdo->exec('UPDATE fixture_users SET user_new_privmsg=0,user_unread_privmsg=0 WHERE user_id>0');
 for($id=100;$id<100+$count;$id++){$pdo->exec("INSERT INTO fixture_users VALUES (".$id.",'Batch',0,1,1,1)");}
}
$controller=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($controller,'// Synchronize both PM counters from current mailbox state.');$b=strpos($controller,"case 'check_config':",$a);
pm_counter_check($a!==false&&$b>$a,'Actual counter controller stage found');$branch="switch(\$function){case 'check_pm':".substr($controller,$a,$b-$a).'}';
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach($native?array('MyISAM','InnoDB'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
  $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
  pm_counter_fixture($engine);
  $messages=pm_counter_snapshot('SELECT * FROM fixture_pm ORDER BY privmsgs_id');$accounts=pm_counter_snapshot('SELECT user_id,username,user_level,user_active FROM fixture_users ORDER BY user_id');
  $sentinels=pm_counter_snapshot('SELECT * FROM fixture_users WHERE user_id<=0 ORDER BY user_id');
  pm_counter_check(pm_counter_run()===2,'Only two incorrect recipient counters repaired, including NULL and inactive user');
  pm_counter_check(pm_counter_value('SELECT user_new_privmsg FROM fixture_users WHERE user_id=8')===1&&pm_counter_value('SELECT user_unread_privmsg FROM fixture_users WHERE user_id=8')===1,'Only received NEW and UNREAD states counted');
  pm_counter_check(pm_counter_value('SELECT user_new_privmsg+user_unread_privmsg FROM fixture_users WHERE user_id=9')===0,'Empty mailbox reset');
  pm_counter_check(pm_counter_run()===0,'Second pass is a no-op');
  pm_counter_check($messages===pm_counter_snapshot('SELECT * FROM fixture_pm ORDER BY privmsgs_id'),'Messages, content and states untouched');
  pm_counter_check($accounts===pm_counter_snapshot('SELECT user_id,username,user_level,user_active FROM fixture_users ORDER BY user_id'),'Other account fields unchanged');
  pm_counter_check($sentinels===pm_counter_snapshot('SELECT * FROM fixture_users WHERE user_id<=0 ORDER BY user_id'),'Nonpositive sentinel counters preserved');
  foreach(array('deliver-new','deliver-unread','open','read','save','delete','move','already-correct','new-recipient') as $race){
   pm_counter_fixture($engine);$pm_counter_server->hook=function($sql) use($race){
    if(strpos($sql,'UPDATE fixture_users SET user_new_privmsg')!==0){return;}$s=$GLOBALS['pm_counter_server'];$s->hook=null;
    if($race==='deliver-new'||$race==='deliver-unread'||$race==='new-recipient'){
     $type=$race==='deliver-unread'?5:1;$recipient=$race==='new-recipient'?9:8;
     $s->pdo->exec("INSERT INTO fixture_pm VALUES (99,".$recipient.",1,".$type.",'Concurrent')");
    }elseif($race==='delete'){$s->pdo->exec('DELETE FROM fixture_pm WHERE privmsgs_id=1');}
    elseif($race==='move'){$s->pdo->exec('UPDATE fixture_pm SET privmsgs_to_userid=9 WHERE privmsgs_id=1');}
    elseif($race==='already-correct'){$s->pdo->exec('UPDATE fixture_users SET user_new_privmsg=1,user_unread_privmsg=1 WHERE user_id=8');}
    else{$type=$race==='open'?5:($race==='read'?0:3);$s->pdo->exec('UPDATE fixture_pm SET privmsgs_type='.$type.' WHERE privmsgs_id=1');}
   };
   pm_counter_check(pm_counter_run()===($race==='already-correct'?1:2),'Actual changed rows after '.$race);
   foreach(array(8,9) as $recipient){foreach(array(1=>'user_new_privmsg',5=>'user_unread_privmsg') as $type=>$field){
    pm_counter_check(pm_counter_value('SELECT '.$field.' FROM fixture_users WHERE user_id='.$recipient)===pm_counter_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_to_userid='.$recipient.' AND privmsgs_type='.$type),'Current counts at write for '.$race);
   }}
  }
  foreach(array(2,100,101,205) as $count){
   pm_counter_fixture($engine);pm_counter_many($count);pm_counter_check(pm_counter_run()===$count,'All keyset pages repaired');
   $batches=0;foreach($pm_counter_server->queries as $sql){if(strpos($sql,'UPDATE fixture_users SET user_new_privmsg')===0){$batches++;preg_match('/user_id IN \(([^)]+)\)/',$sql,$m);pm_counter_check(count(explode(',',$m[1]))<=100,'At most 100 IDs per update');}}
   pm_counter_check($batches===(int)ceil($count/100)&&pm_counter_run()===0,'Bounded writes and repeat no-op');
  }
  foreach(array('lock','SELECT user_id FROM fixture_users','UPDATE fixture_users SET user_new_privmsg') as $failure){
   pm_counter_fixture($engine);$before=pm_counter_snapshot('SELECT * FROM fixture_users ORDER BY user_id');$pm_counter_server->failure=$failure;
   pm_counter_run($failure==='lock'?$lang['Attachment_storage_busy']:$lang['Maintenance_pm_counter_failed']);
   pm_counter_check($before===pm_counter_snapshot('SELECT * FROM fixture_users ORDER BY user_id'),'Failed initial query leaves counters intact');
   $pm_counter_server->failure='';pm_counter_check(pm_counter_run()===2,'Retry completes failed initial query');
  }
  foreach(array('revoked','demoted','lost-owner') as $race){
   pm_counter_fixture($engine);$pm_counter_server->hook=function($sql,$connection) use($race){if(strpos($sql,'UPDATE fixture_users SET user_new_privmsg')!==0){return;}$s=$GLOBALS['pm_counter_server'];$s->hook=null;if($race==='lost-owner'){$connection->sql_close();}else{$s->pdo->exec('UPDATE fixture_users SET '.($race==='revoked'?'user_active=0':'user_level=0').' WHERE user_id=1');}};
   pm_counter_run($race==='lost-owner'?$lang['Maintenance_pm_counter_failed']:$lang['Not_Authorised']);
   pm_counter_check(pm_counter_value('SELECT user_new_privmsg FROM fixture_users WHERE user_id=8')===99,'Lost owner/current authority prevents counter writes');
  }
  foreach(array('get','sid-array','wrong-sid','missing-sid','no-admin-session','logged-out','inactive') as $case){
   pm_counter_fixture($engine);$request=$_POST;$expected=$lang['Session_invalid'];
   if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($case==='sid-array'){$request['sid']=array();}elseif($case==='wrong-sid'){$request['sid']='bad';}elseif($case==='missing-sid'){unset($request['sid']);}
   elseif($case==='no-admin-session'){$userdata['session_admin']=false;$expected=$lang['Not_Authorised'];}elseif($case==='logged-out'){$userdata['session_logged_in']=false;$expected=$lang['Not_Authorised'];}
   else{$pm_counter_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');$expected=$lang['Not_Authorised'];}
   pm_counter_run($expected,$request);
   if($expected===$lang['Session_invalid']){pm_counter_check(!$pm_counter_server->queries,'Invalid form rejected before any connection query');}
  }
  $hash=md5('GeneralDB_Maintenanceadmin_db_maintenance.php');
  pm_counter_fixture($engine,20);$pm_counter_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$hash."')");pm_counter_check(pm_counter_run()===2,'Exact maintenance delegation allowed');
  foreach(array('none','wrong','revoked') as $grant){
   pm_counter_fixture($engine,20);if($grant!=='none'){$pm_counter_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".($grant==='wrong'?md5('UsersManageadmin_users.php'):$hash)."')");}
   if($grant==='revoked'){$pm_counter_server->hook=function($sql){if(strpos($sql,'UPDATE fixture_users SET user_new_privmsg')===0){$GLOBALS['pm_counter_server']->hook=null;$GLOBALS['pm_counter_server']->pdo->exec('DELETE FROM fixture_junior');}};}
   pm_counter_run($lang['Not_Authorised']);pm_counter_check(pm_counter_value('SELECT user_new_privmsg FROM fixture_users WHERE user_id=8')===99,'Current exact delegation required');
  }
  pm_counter_fixture($engine);$contended=false;$pm_counter_server->hook=function($sql) use(&$contended){if(strpos($sql,'SELECT user_id FROM fixture_users')===0){$GLOBALS['pm_counter_server']->hook=null;$other=new attach_mutation_lock(new PmCounterForum(),false);$contended=!$other->acquired;$other->release();}};pm_counter_run();pm_counter_check($contended,'Mailbox writer lock held before selecting counters');
  foreach(array('query','actor') as $failure){
   pm_counter_fixture($engine);pm_counter_many(205);$batches=0;$pm_counter_server->hook=function($sql) use(&$batches,$failure){if(strpos($sql,'UPDATE fixture_users SET user_new_privmsg')===0&&++$batches===2){$s=$GLOBALS['pm_counter_server'];$s->hook=null;if($failure==='actor'){$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}else{$s->failure='UPDATE fixture_users SET user_new_privmsg';}}};
   pm_counter_run($failure==='actor'?$lang['Not_Authorised']:$lang['Maintenance_pm_counter_failed']);
   pm_counter_check(pm_counter_value('SELECT COUNT(*) FROM fixture_users WHERE user_id>=100 AND user_new_privmsg=1')===105,'Confirmed first batch retained, failed second untouched');
   $pm_counter_server->failure='';$pm_counter_server->pdo->exec('UPDATE fixture_users SET user_active=1 WHERE user_id=1');pm_counter_check(pm_counter_run()===105,'Retry completes only remaining batches');
  }
  // Evaluate the actual counter stage, not the earlier independent PM repair stages.
  foreach(array('success','failure','invalid','empty') as $case){
   pm_counter_fixture($engine);if($case==='failure'){$pm_counter_server->failure='UPDATE fixture_users SET user_new_privmsg';}if($case==='invalid'){$_POST['sid']='bad';}if($case==='empty'){pm_counter_run();}
   $function='check_pm';$db=new PmCounterForum();$board_locks=array();$caught='';ob_start();try{eval($branch);}catch(PmCounterControllerFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
   pm_counter_check($board_locks===array(),'PM maintenance does not change global board availability');
   pm_counter_check(($caught!=='')===in_array($case,array('failure','invalid'),true),'Actual counter controller reports failure');
   if($caught===''){pm_counter_check(strpos($html,sprintf($lang['Maintenance_pm_counter_summary'],$case==='empty'?0:2))!==false,'Actual localized affected-account count');}
  }
  echo $engine.' '.$locale." PM counter maintenance passed.\n";
 }}
}finally{restore_error_handler();}
