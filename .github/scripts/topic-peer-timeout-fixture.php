<?php
if(PHP_SAPI!=='cli'||!defined('IN_PHPBB')){return;}
function tw_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['tw_checks']++;}
function tw_rows($database,$sql){$r=$database->sql_query($sql);tw_check($r!==false,'Own peer-wait statement succeeds');try{return$database->sql_fetchrowset($r);}finally{$database->sql_freeresult($r);}}
function tp_peer_wait_checks(){
 global$tn_peer,$db,$host,$password,$schema;
 $GLOBALS['tw_checks']=0;$cases=0;$waits=array();$locker=null;
 $state_sql='SELECT CONNECTION_ID() id,@@SESSION.autocommit automatic,@@SESSION.sql_mode sql_mode,@@SESSION.innodb_lock_wait_timeout row_wait,@@GLOBAL.innodb_lock_wait_timeout global_wait';
 $caller=tw_rows($db,$state_sql);$peer=tw_rows($tn_peer,$state_sql);
 tw_check((string)$peer[0]['row_wait']==='1'&&(string)$peer[0]['id']!==(string)$caller[0]['id'],'Only the independent disposable peer has a bounded wait');$cases++;
 tp_prepare(true);$before=tp_snapshot();$locker=new sql_db($host,'root',$password,$schema,false);
 try{
  tw_check($locker->sql_query('START TRANSACTION')===true,'Own competing row transaction');tw_rows($locker,"SELECT config_name FROM fixture_config WHERE config_name='session_length' FOR UPDATE");
  tw_check($tn_peer->sql_query('START TRANSACTION')===true,'Own requesting row transaction');$started=microtime(true);
  $r=$tn_peer->sql_query("SELECT config_name FROM fixture_config WHERE config_name='session_length' FOR UPDATE");$elapsed=microtime(true)-$started;$error=$tn_peer->sql_error();$waits[]=$elapsed;
  tw_check($r===false&&(int)$error['code']===1205&&$elapsed<5.0,'Actual native row conflict times out promptly with1205');$cases++;
 }finally{$tn_peer->sql_query('ROLLBACK');$locker->sql_query('ROLLBACK');$locker->sql_close();$locker=null;}
 tw_check(tp_snapshot()===$before,'The genuine failed row request leaves all data unchanged');
 $locker=new sql_db($host,'root',$password,$schema,false);
 try{
  tw_check($locker->sql_query('START TRANSACTION')===true&&$locker->sql_query('INSERT INTO fixture_topics_watch(topic_id,user_id) VALUES(100,7)')===true,'Own uncommitted indexed insert');
  tw_check($tn_peer->sql_query('START TRANSACTION')===true,'Own requesting indexed transaction');$started=microtime(true);
  $r=$tn_peer->sql_query('SELECT * FROM fixture_topics_watch WHERE user_id=7 AND topic_id=100 FOR UPDATE NOWAIT');$elapsed=microtime(true)-$started;$error=$tn_peer->sql_error();$waits[]=$elapsed;
  tw_check($r===false&&in_array((int)$error['code'],array(1205,3572),true)&&$elapsed<5.0,'Actual indexed NOWAIT conflict remains native and bounded');
  tw_check(count(tw_rows($locker,'SELECT * FROM fixture_topics_watch WHERE user_id=7 AND topic_id=100'))===1&&!tw_rows($db,'SELECT * FROM fixture_topics_watch WHERE user_id=7 AND topic_id=100'),'Competing insert remains private and pending');$cases++;
 }finally{$tn_peer->sql_query('ROLLBACK');$locker->sql_query('ROLLBACK');$locker->sql_close();$locker=null;}
 tw_check(tp_snapshot()===$before&&tw_rows($db,$state_sql)===$caller,'Caller session and original data survive both peer conflicts');$cases++;
 $fresh=$db->sql_dedicated_connection();
 try{$next=tw_rows($fresh,$state_sql);tw_check((string)$next[0]['row_wait']===(string)$caller[0]['global_wait']&&(string)$next[0]['global_wait']===(string)$caller[0]['global_wait'],'Fresh workers retain the original server timeout, not a global test setting');}
 finally{$fresh->sql_close();}
 tw_check(tp_released(),'Every actual worker and row lease is released');$cases++;
 echo 'Native topic fixture peer waits: ',$cases,' cases, ',$GLOBALS['tw_checks'],' assertions PASS; actual row/indexed-insert conflicts, bounded native waits, original caller and server defaults preserved; ',json_encode(array('max_wait_seconds'=>round(max($waits),3),'production_access'=>false,'real_mail_sent'=>false)),"\n";
}
