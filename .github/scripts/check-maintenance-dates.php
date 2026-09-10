<?php
// Reuse only the isolated SQL adapter/fixture declarations, not another suite's
// assertions or live application bootstrap. Native DSNs remain loopback-owned.
$fixtureSource=file_get_contents(__DIR__.'/check-maintenance-sessions.php');
$fixtureEnd=strpos($fixtureSource,"\n\$controller=file_get_contents(");
if($fixtureEnd===false){throw new RuntimeException('Shared SQL fixture boundary missing');}
eval(substr($fixtureSource,5,$fixtureEnd-5));
define('POSTS_TABLE','fixture_posts');define('PRIVMSGS_TABLE','fixture_pm');define('PRIVMSGS_PENDING_SENT_MAIL',6);
require_once $root.'includes/functions_maintenance_dates.php';
function date_fixture($engine,$actor=1) {
 reset_fixture($engine,$actor);$p=$GLOBALS['resetServer']->pdo;$future=time()+3600;
 $p->exec('ALTER TABLE fixture_users ADD user_emailtime INTEGER DEFAULT 0');
 $p->exec('ALTER TABLE fixture_users ADD user_last_login_try INTEGER DEFAULT 0');
 $p->exec('UPDATE fixture_users SET user_emailtime='.$future.',user_last_login_try='.$future);
 $p->exec('INSERT INTO fixture_users VALUES (-1,0,1,'.$future.','.$future.'),(0,0,1,'.$future.','.$future.'),(8,0,1,42,43)');
 $p->exec('ALTER TABLE fixture_search ADD search_time INTEGER DEFAULT 0');
 $p->exec('UPDATE fixture_search SET search_time='.$future.' WHERE search_id=1');
 $p->exec('UPDATE fixture_search SET search_time=45 WHERE search_id=2');
 foreach(array('posts'=>'post_id INTEGER PRIMARY KEY,post_time INTEGER,post_text TEXT',
  'pm'=>'privmsgs_id INTEGER PRIMARY KEY,privmsgs_date INTEGER,privmsgs_type INTEGER,privmsgs_write_payload TEXT,privmsgs_read_token VARCHAR(32),privmsgs_read_copy_id INTEGER,privmsgs_copy_token VARCHAR(32),body TEXT',
  'config'=>'board_disable INTEGER') as $name=>$definition) {
  $p->exec('DROP TABLE IF EXISTS fixture_'.$name);
  $p->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['resetNative']?' ENGINE='.$engine:''));
 }
 $p->exec('INSERT INTO fixture_config VALUES (0)');
 $p->exec("INSERT INTO fixture_posts VALUES (1,".$future.",'Grüße unverändert'),(2,44,'Past post')");
 $rows=array(
  array(1,1,null,'',0,null),array(2,1,'{"date":'.$future.'}','',0,null),
  array(3,0,null,str_repeat('c',32),4,null),array(4,6,null,'',0,str_repeat('c',32)),
  array(5,0,null,str_repeat('d',32),6,null),array(6,2,null,'',0,str_repeat('d',32)),
  array(7,2,null,'',0,str_repeat('e',32)),array(8,0,null,'',0,null),
  array(9,0,null,str_repeat('f',32),0,null),array(10,2,null,'',0,str_repeat('f',32)),
  array(11,6,null,'',0,null),array(12,1,'','',0,null),array(13,0,null,'',0,null),
  array(14,2,null,'',0,str_repeat('b',32)),array(15,0,null,str_repeat('g',32),14,null)
 );
 $insert=$p->prepare('INSERT INTO fixture_pm VALUES (?,?,?,?,?,?,?,?)');
 foreach($rows as $r){$insert->execute(array($r[0],$r[0]===8?46:$future,$r[1],$r[2],$r[3],$r[4],$r[5],'PN Grüße '.$r[0]));}
 return $future;
}
function date_value($sql){return (int)$GLOBALS['resetServer']->pdo->query($sql)->fetchColumn();}
function date_run($expected=''){
 $caught='';$result=null;
 try{$result=dbmtnc_reset_dates(new ResetForum(),$_POST);}catch(PhpbbAclException $e){$caught=$e->getMessage();}
 reset_check($caught===$expected,'Date repair outcome: '.$caught.' / '.$expected);
 reset_check($GLOBALS['resetServer']->owner===null,'Date repair released owner');
 reset_check(date_value('SELECT board_disable FROM fixture_config')===$GLOBALS['dateBoardState'],'Availability unchanged');
 return $result;
}
$controller=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($controller,"case 'reset_date':");$b=strpos($controller,"case 'reset_sessions':",$a);
reset_check($a!==false&&$b>$a,'Actual date controller branch found');$dateBranch='switch("reset_date"){'.substr($controller,$a,$b-$a).'}';
$writes=array('UPDATE fixture_posts','UPDATE fixture_pm','UPDATE fixture_users SET user_emailtime','UPDATE fixture_users SET user_last_login_try','DELETE FROM fixture_search');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach($resetNative?array('MyISAM','InnoDB'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
  $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
  foreach(array(0,1) as $dateBoardState){
   $future=date_fixture($engine);$resetServer->pdo->exec('UPDATE fixture_config SET board_disable='.$dateBoardState);
   $session=reset_own();$before=time();$out=date_run();$after=time();
   reset_check($out===array('posts'=>1,'pm'=>3,'email'=>2,'login'=>2,'search'=>1,'deferred'=>11),'All five actions and deferred count');
   foreach(array('SELECT post_time FROM fixture_posts WHERE post_id=1','SELECT privmsgs_date FROM fixture_pm WHERE privmsgs_id=1','SELECT user_emailtime FROM fixture_users WHERE user_id=20','SELECT user_last_login_try FROM fixture_users WHERE user_id=1') as $sql){$v=date_value($sql);reset_check($v>=$before&&$v<=$after,'Future dates set to current time');}
   foreach(array(2,3,4,5,6,9,10,11,12,14,15) as $id){reset_check(date_value('SELECT privmsgs_date FROM fixture_pm WHERE privmsgs_id='.$id)===$future,'Unfinished PM dates preserved '.$id);}
   foreach(array(7,13) as $id){reset_check(date_value('SELECT privmsgs_date FROM fixture_pm WHERE privmsgs_id='.$id)<=$after,'Finished copies/messages corrected '.$id);}
   reset_check(date_value('SELECT post_time FROM fixture_posts WHERE post_id=2')===44&&date_value('SELECT privmsgs_date FROM fixture_pm WHERE privmsgs_id=8')===46,'Past dates untouched');
   reset_check(date_value('SELECT user_emailtime FROM fixture_users WHERE user_id=-1')===$future&&date_value('SELECT user_last_login_try FROM fixture_users WHERE user_id=0')===$future,'Account sentinels untouched');
   reset_check(date_value('SELECT search_time FROM fixture_search WHERE search_id=2')===45&&reset_count('search')===1,'Only future cached search removed');
   reset_check(reset_own()===$session&&reset_count('sessions')===4&&reset_count('keys')===1,'Sessions and automatic login preserved');
   reset_check($resetServer->pdo->query('SELECT post_text FROM fixture_posts WHERE post_id=1')->fetchColumn()==='Grüße unverändert','Post content unchanged');
   foreach($resetServer->pdo->query('SELECT privmsgs_id,body FROM fixture_pm')->fetchAll(PDO::FETCH_ASSOC) as $r){reset_check($r['body']==='PN Grüße '.$r['privmsgs_id'],'PM content unchanged');}
   reset_check(date_run()===array('posts'=>0,'pm'=>0,'email'=>0,'login'=>0,'search'=>0,'deferred'=>11),'Repeat no-op except unresolved intents');
   $resetServer->pdo->exec("UPDATE fixture_pm SET privmsgs_write_payload=NULL,privmsgs_read_token='',privmsgs_read_copy_id=0,privmsgs_type=2 WHERE privmsgs_id<>8");
   $out=date_run();reset_check($out['pm']===11&&$out['deferred']===0,'Completed recovery permits later date repair');
  }
  $dateBoardState=0;
  foreach(array_merge(array('lock'),$writes) as $failure){foreach(array(false,true) as $lost){
   $future=date_fixture($engine);$resetServer->failure=$failure;$resetServer->lostAck=$lost;
   date_run($failure==='lock'?$lang['Attachment_storage_busy']:$lang['Maintenance_date_reset_failed']);
   $postApplied=$failure!=='lock'&&($failure!=='UPDATE fixture_posts'||$lost);
   reset_check((date_value('SELECT post_time FROM fixture_posts WHERE post_id=1')<$future)===$postApplied,'Partial writes preserved honestly');
   reset_check(date_value('SELECT privmsgs_date FROM fixture_pm WHERE privmsgs_id=2')===$future,'Interrupted repair preserves pending intent');
   $resetServer->failure='';date_run();reset_check(date_value('SELECT user_last_login_try FROM fixture_users WHERE user_id=20')<$future,'Retry finishes all stages');
  }}
  foreach(array('get','wrong-sid','nested-sid','inactive','demoted','no-session','no-admin-session','wrong-owner') as $case){
   $future=date_fixture($engine);$expected=$lang['Not_Authorised'];
   if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';$expected=$lang['Session_invalid'];}
   elseif($case==='wrong-sid'){$_POST['sid']='bad';$expected=$lang['Session_invalid'];}
   elseif($case==='nested-sid'){$_POST['sid']=array();$expected=$lang['Session_invalid'];}
   elseif($case==='inactive'){$resetServer->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
   elseif($case==='demoted'){$resetServer->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1');}
   elseif($case==='no-session'){$resetServer->pdo->exec('DELETE FROM fixture_sessions');}
   else{$resetServer->pdo->exec('UPDATE fixture_sessions SET '.($case==='wrong-owner'?'session_user_id=20':'session_admin=0'));}
   date_run($expected);reset_check(date_value('SELECT post_time FROM fixture_posts WHERE post_id=1')===$future,'Denied repair untouched');
  }
  $grant=md5('GeneralDB_Maintenanceadmin_db_maintenance.php');
  date_fixture($engine,20);$resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$grant."')");date_run();
  date_fixture($engine,20);$resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'wrong-route')");date_run($lang['Not_Authorised']);
  foreach($writes as $target){foreach(array('account','session','grant','lost-owner') as $race){
   $future=date_fixture($engine,$race==='grant'?20:1);
   if($race==='grant'){$resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$grant."')");}
   $snapshot=null;
   $resetServer->hook=function($sql,$connection)use($target,$race,&$snapshot){
    if(strpos($sql,$target)!==0){return;}$s=$GLOBALS['resetServer'];$s->hook=null;
    if($race==='account'){$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
    elseif($race==='session'){$s->pdo->exec('UPDATE fixture_sessions SET session_admin=0');}
    elseif($race==='grant'){$s->pdo->exec('DELETE FROM fixture_junior');}
    else{$connection->sql_close();}
    $snapshot=array();foreach(array('posts','pm','users','search') as $t){$snapshot[$t]=$s->pdo->query('SELECT * FROM fixture_'.$t)->fetchAll(PDO::FETCH_ASSOC);}
   };
   date_run($race==='lost-owner'?$lang['Maintenance_date_reset_failed']:$lang['Not_Authorised']);
   reset_check($snapshot!==null,'Late-change injection reached');
   foreach($snapshot as $t=>$rows){reset_check($rows===$resetServer->pdo->query('SELECT * FROM fixture_'.$t)->fetchAll(PDO::FETCH_ASSOC),'No writes after authority/owner loss '.$target.' '.$race);}
  }}
  date_fixture($engine);$resetServer->hook=function($sql){
   if(strpos($sql,'UPDATE fixture_pm')!==0){return;}$s=$GLOBALS['resetServer'];$s->hook=null;
   $s->pdo->exec("UPDATE fixture_pm SET privmsgs_write_payload='accepted late' WHERE privmsgs_id=1");
   $s->pdo->exec('UPDATE fixture_pm SET privmsgs_date=20 WHERE privmsgs_id=7');
  };$out=date_run();reset_check($out['pm']===1&&$out['deferred']===12,'Current pending/corrected PM state requalified inside statement');
  date_fixture($engine);$contended=false;$resetServer->hook=function($sql)use(&$contended){
   if(strpos($sql,'UPDATE fixture_posts')!==0){return;}$GLOBALS['resetServer']->hook=null;$other=new attach_mutation_lock(new ResetForum(),false);$contended=!$other->acquired;$other->release();
  };date_run();reset_check($contended,'Shared writer coordinates date repair');
  foreach(array('success','failure','invalid') as $case){
   date_fixture($engine);$db=new ResetForum();$caught='';
   if($case==='failure'){$resetServer->failure='UPDATE fixture_pm';}if($case==='invalid'){$_POST['sid']='bad';}
   ob_start();try{eval($dateBranch);}catch(ResetControllerFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
   reset_check(($caught!=='')===($case!=='success'),'Actual date controller failure status');
   reset_check(date_value('SELECT board_disable FROM fixture_config')===0,'Actual controller never disables board');
   if($case==='success'){reset_check(strpos($html,sprintf($lang['Maintenance_date_reset_summary'],1,3,2,2,1))!==false&&strpos($html,sprintf($lang['Maintenance_date_reset_deferred'],11))!==false,'Translated counts and deferral report');}
  }
  echo $engine.' '.$locale." date maintenance passed.\n";
 }}
}finally{restore_error_handler();}
