<?php
// Actual page branches and canonical installer tables. Only redirects/terminal
// responses use sinks; all SQL, edits and read transitions are real native SQL.
if(PHP_SAPI!=='cli'||getenv('PHPBB_PM_VIEW_NATIVE')!=='1'){echo "PM view checks require explicitly enabled disposable storage.\n";return;}
$pv_mode=isset($argv[1])?$argv[1]:'';if(!in_array($pv_mode,array('body','mailbox'),true)){throw new RuntimeException('Explicit PM view mode required');}
putenv('PHPBB_PM_NOTIFY_NATIVE=1');putenv('PHPBB_PM_NOTIFY_PORT='.(getenv('PHPBB_PM_VIEW_PORT')?:'3306'));putenv('PHPBB_PM_NOTIFY_PASSWORD='.(getenv('PHPBB_PM_VIEW_PASSWORD')?:''));
class PvRedirect extends RuntimeException{}
function pv_prepare($kind,$role=0,$locale='english'){
 global$userdata,$board_config,$pn_id,$pv_folder;
 pd_prepare($locale);$actor=in_array($kind,array('inbox','savebox-in','quote','reply'),true)?3:4;
 kp_sql('UPDATE fixture_users SET user_level='.$role.' WHERE user_id='.$actor);pd_login($actor,$role);$_SERVER['REQUEST_METHOD']='GET';$_POST=array();
 $board_config['max_sentbox_privmsgs']=0;$board_config['topics_per_page']=2;$pv_folder=in_array($kind,array('savebox-in','savebox-out'),true)?'savebox':(in_array($kind,array('inbox','outbox','sentbox'),true)?$kind:'outbox');
 $types=array('sentbox'=>PRIVMSGS_SENT_MAIL,'savebox-in'=>PRIVMSGS_SAVED_IN_MAIL,'savebox-out'=>PRIVMSGS_SAVED_OUT_MAIL);
 if(isset($types[$kind])){kp_sql('UPDATE fixture_privmsgs SET privmsgs_type='.$types[$kind].' WHERE privmsgs_id='.$pn_id);}
}
function pv_load($kind,$start=0,$days=0){
 global$db,$userdata,$board_config,$lang,$phpEx,$pn_id,$pv_code,$pv_folder,$pv_last_revision;
 $privmsg_id=$privmsgs_id=$pn_id;$user_id=3;$folder=$pv_folder;$submit_msgdays=$days!==0;$_POST=$days?array('msgdays'=>$days):array();$_GET=array();$pm_write_revision='previous-form-token';$privmsg=false;
 if($kind==='page'||$kind==='count'){
  eval($pv_code['list-sql']);if($kind==='count'){eval($pv_code['count']);return array('total'=>(int)$pm_total,'all'=>(int)$pm_all_total);}
  eval($pv_code['page']);return$pm_rows;
 }
 eval($pv_code[$kind]);$pv_last_revision=$pm_write_revision;
 if(in_array($kind,array('preview','recipient','flood'),true)){return$pm_rows?$pm_rows[0]:false;}
 if($kind==='edit-submit'){return$row;}
 return$privmsg;
}
function pv_result($kind,$start=0,$days=0){try{return array('ok'=>true,'value'=>pv_load($kind,$start,$days),'error'=>'');}catch(Exception$error){return array('ok'=>false,'value'=>null,'error'=>$error instanceof PvRedirect?'absent':$error->getMessage());}}
function pv_change($kind){
 global$userdata,$pn_id;
 if($kind==='block'){kp_sql('UPDATE fixture_users SET user_blocktime='.(time()+3600).' WHERE user_id='.(int)$userdata['user_id']);}
 elseif($kind==='allow'){kp_sql('UPDATE fixture_users SET user_allow_pm=0 WHERE user_id='.(int)$userdata['user_id']);}
 elseif($kind==='inactive'){kp_sql('UPDATE fixture_users SET user_active=0 WHERE user_id='.(int)$userdata['user_id']);}
 elseif($kind==='deleted-user'){kp_sql('DELETE FROM fixture_users WHERE user_id='.(int)$userdata['user_id']);}
 elseif($kind==='foreign-parent'){kp_sql('UPDATE fixture_privmsgs SET privmsgs_from_userid=2,privmsgs_to_userid=2 WHERE privmsgs_id='.$pn_id);}
 else{pd_revoke($kind);}
}
function pv_extract($source,$start,$end){$a=strpos($source,$start);$b=$a===false?false:strpos($source,$end,$a);kp_check($a!==false&&$b>$a,'Exact actual PN controller boundary: '.$start);return substr($source,$a,$b-$a);}
function pv_suite(){
 global$pv_mode,$pv_code,$kp_source,$pn_open,$pn_claim_pending,$pn_after_claim,$pn_queries,$db,$userdata,$pn_id,$pn_nonce,$pv_last_revision,$pd_factory,$pd_busy,$kp_hook,$pd_root_queries,$pd_root_fault;
 require_once$kp_source.'attach_mod/includes/functions_delete.php';require$kp_source.'includes/functions_pm_controller.php';$pn_open=0;$pn_claim_pending=false;$pn_after_claim=null;$pn_queries=array();$cases=$faults=0;
 if(!function_exists('append_sid')){function append_sid($url,$unused=false){return$url;}}if(!function_exists('redirect')){function redirect($url){throw new PvRedirect($url);}}
 if(getenv('PHPBB_PM_VIEW_MYSQL')==='8.4'){$rows=kp_rows('SELECT VERSION() version');kp_check(preg_match('/^8\.4\./',$rows[0]['version'])&&stripos($rows[0]['version'],'MariaDB')===false,'Actual MySQL8.4 required');}
 $source=str_replace("\r\n","\n",file_get_contents($kp_source.'privmsg.php'));$pv_code=array();
 $pv_code['read']=pv_extract($source,"\tswitch( \$folder )","\t\$privmsg['user_icq'] =");
 $pv_code['edit']=pv_extract($source,'$sql = "SELECT pm.*, pmt.privmsgs_bbcode_uid',"\t\t\tif ( \$privmsg_bbcode_enabled )");
 $pv_code['quote']=pv_extract($source,'$sql = "SELECT pm.privmsgs_subject, pm.privmsgs_date, pmt.privmsgs_bbcode_uid',"\t\t\t\$orig_word =");$pv_code['reply']=$pv_code['quote'];
 $pv_code['preview']=pv_extract($source,'$sql = "SELECT u.user_id, u.user_sig',"\t\t\tif ( \$postrow =");
 $pv_code['recipient']=pv_extract($source,'$sql = "SELECT username, user_absence',"\t\t\tif ( \$row =");
 $pv_code['flood']=pv_extract($source,'$sql = "SELECT MAX(privmsgs_date)',"\t\tif ( \$pm_rows )");
 $pv_code['edit-submit']=pv_extract($source,"\$sql = 'SELECT privmsgs_from_userid","\t\tunset(\$row);");
 $pv_code['list-sql']=pv_extract($source,'$sql_tot = "SELECT COUNT(privmsgs_id)',"//\n// Get messages");
 $pv_code['count']=pv_extract($source,'$pm_counts = phpbb_pm_display($sql_tot);',"//\n// Build select box");
 kp_check(preg_match('/^\$pm_rows = phpbb_pm_display\(\$sql, false, true\);$/m',$source,$m)===1,'Actual ordered mailbox controller call');$pv_code['page']=$m[0];
 $kinds=$pv_mode==='body'?array('read','edit','quote','reply','preview','recipient','flood','edit-submit'):array('page','count');
 foreach(array('english','german')as$locale){foreach(array(0,2,1)as$role){foreach($kinds as$kind){
  pv_prepare($kind,$role,$locale);$before=kp_snapshot();$r=pv_result($kind);kp_check($r['ok']&&$r['value']!==false&&$pn_open===0,'Ordinary actual controller query: '.$kind.'/'.$role.'/'.$locale);
  if($kind!=='read'){kp_check(kp_snapshot()===$before,'Pure display changes no persistent data');}$cases++;
 }}}
 foreach($kinds as$kind){foreach(array('missing','foreign','logout','case','replacement','inactive','deleted-user','block','allow')as$change){
  pv_prepare($kind);$db->sql_query('START TRANSACTION');foreach(array('users','sessions','privmsgs','privmsgs_text')as$table){$q=$db->sql_query('SELECT * FROM fixture_'.$table);$db->sql_freeresult($q);}
  $db->sql_query("INSERT INTO fixture_config(config_name,config_value) VALUES('view-caller-marker','pending')");
  try{pv_change($change);$before=kp_snapshot();$r=pv_result($kind);$allowed=$change==='allow'&&in_array($kind,array('read','page','count'),true);
   kp_check($r['ok']===$allowed&&($allowed||$r['error']===$GLOBALS['lang']['Not_Authorised'])&&kp_snapshot()===$before&&$pn_open===0,'Current authority ignores caller snapshot: '.$kind.'/'.$change);
   $q=$db->sql_query("SELECT config_value FROM fixture_config WHERE config_name='view-caller-marker'");$row=$db->sql_fetchrow($q);$db->sql_freeresult($q);kp_check($row['config_value']==='pending'&&!kp_rows("SELECT * FROM fixture_config WHERE config_name='view-caller-marker'")&&$db->db_connect_id,'Caller remains open and private');
  }finally{$db->sql_query('ROLLBACK');}$cases++;
 }}
 if($pv_mode==='body'){
  foreach(array('read','edit','quote','reply')as$kind){foreach(array(0,2,1)as$role){
   pv_prepare($kind,$role);$viewer=$userdata;$db->sql_query('START TRANSACTION');foreach(array('privmsgs','privmsgs_text','users','sessions')as$table){$q=$db->sql_query('SELECT * FROM fixture_'.$table);$db->sql_freeresult($q);}
   try{pd_login(4);$_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>$userdata['session_id']);$data=pn_data();$data['text']='Genuine current body Grüße 😀';$data['subject']='Current subject 😀';phpbb_pm_write_message(str_repeat('e',32),$pn_id,$pn_nonce,$data,0);pd_login($viewer['user_id'],$role);$_SERVER['REQUEST_METHOD']='GET';$_POST=array();$before=kp_snapshot();$r=pv_result($kind);
    kp_check($r['ok']&&$r['value']['privmsgs_text']===$data['text']&&$r['value']['privmsgs_subject']===$data['subject']&&kp_snapshot()===$before&&$pn_open===0,'Actual view ignores old body after real edit: '.$kind);
    if($kind==='edit'){kp_check($pv_last_revision===str_repeat('e',32),'Edit token bound to displayed current body, replacing earlier form token');}
   }finally{$db->sql_query('ROLLBACK');}$cases++;
  }}
  foreach(array('inbox','outbox','sentbox','savebox-in','savebox-out')as$folder){pv_prepare($folder);$r=pv_result('read');kp_check($r['ok']&&$r['value']['privmsgs_text']===pn_data()['text']&&$pn_open===0,'Every legitimate mailbox body: '.$folder);$cases++;}
  foreach(array('read','edit','quote','reply','preview','edit-submit')as$kind){foreach(array('foreign-parent','parent','pending','staging')as$change){pv_prepare($kind);pv_change($change);$before=kp_snapshot();$r=pv_result($kind);kp_check((!$r['ok']||$r['value']===false)&&kp_snapshot()===$before&&$pn_open===0,'Absent/foreign/partial/staging source is never displayed: '.$kind.'/'.$change);$cases++;}}
  foreach(array('edit','quote','reply')as$kind){foreach(array(PRIVMSGS_NEW_MAIL,PRIVMSGS_UNREAD_MAIL,PRIVMSGS_READ_MAIL,PRIVMSGS_SENT_MAIL,PRIVMSGS_SAVED_IN_MAIL,PRIVMSGS_SAVED_OUT_MAIL,6)as$type){pv_prepare($kind);kp_sql('UPDATE fixture_privmsgs SET privmsgs_type='.$type.' WHERE privmsgs_id='.$pn_id);$r=pv_result($kind);$allowed=in_array($type,$kind==='edit'?array(PRIVMSGS_NEW_MAIL,PRIVMSGS_UNREAD_MAIL):array(PRIVMSGS_NEW_MAIL,PRIVMSGS_UNREAD_MAIL,PRIVMSGS_READ_MAIL,PRIVMSGS_SAVED_IN_MAIL),true);kp_check(($r['ok']&&$r['value']!==false)===$allowed&&$pn_open===0,'Exact compose parent type: '.$kind.'/'.$type);$cases++;}}
 }else{
  foreach(array('inbox','outbox','sentbox','savebox-in','savebox-out')as$folder){pv_prepare($folder);$before=kp_snapshot();$rows=pv_load('page');$count=pv_load('count');kp_check(count($rows)===1&&$rows[0]['privmsgs_id']===(string)$pn_id&&$count===array('total'=>1,'all'=>1)&&kp_snapshot()===$before&&$pn_open===0,'Count/page scope for every mailbox: '.$folder);$cases++;}
  foreach(array('foreign-parent','parent','pending','staging')as$change){pv_prepare('outbox');pv_change($change);$before=kp_snapshot();kp_check(pv_load('page')===array()&&pv_load('count')===array('total'=>0,'all'=>0)&&kp_snapshot()===$before&&$pn_open===0,'Valid empty mailbox is distinct from denied authority: '.$change);$cases++;}
  pv_prepare('outbox');$seed=kp_rows('SELECT * FROM fixture_privmsgs WHERE privmsgs_id='.$pn_id)[0];unset($seed['privmsgs_id']);$seed['privmsgs_date']=time();$values=array();for($i=0;$i<7;$i++){$seed['privmsgs_write_token']=sprintf('%032x',$i+1);$seed['privmsgs_subject']='Tied Grüße 😀 '.$i;$fields=array();foreach($seed as$value){$fields[]=$value===null?'NULL':"'".$GLOBALS['peer']->sql_escape($value)."'";}$values[]='('.implode(',',$fields).')';}kp_sql('INSERT INTO fixture_privmsgs('.implode(',',array_keys($seed)).') VALUES'.implode(',',$values));kp_sql('UPDATE fixture_privmsgs SET privmsgs_date='.(time()-10*86400).' WHERE privmsgs_id='.$pn_id);$before=kp_snapshot();$seen=array();for($offset=0;$offset<8;$offset+=2){$rows=pv_load('page',$offset);foreach($rows as$row){$seen[]=(int)$row['privmsgs_id'];}}$expected=kp_rows('SELECT privmsgs_id FROM fixture_privmsgs ORDER BY privmsgs_date DESC,privmsgs_id DESC');$ids=array();foreach($expected as$row){$ids[]=(int)$row['privmsgs_id'];}kp_check($seen===$ids&&count(array_unique($seen))===8&&pv_load('page',100)===array()&&pv_load('count')===array('total'=>8,'all'=>8)&&pv_load('count',0,1)===array('total'=>7,'all'=>8)&&count(pv_load('page',0,1))===2&&kp_snapshot()===$before&&$pn_open===0,'Actual page ordering, limits, ties, empty offset and date-filter/all counts');$cases++;
  pv_prepare('outbox');$db->sql_query('START TRANSACTION');$q=$db->sql_query('SELECT * FROM fixture_privmsgs');$db->sql_freeresult($q);try{kp_sql('DELETE FROM fixture_privmsgs');kp_check(pv_load('page')===array()&&pv_load('count')===array('total'=>0,'all'=>0)&&$pn_open===0,'Completed deletion reflected despite caller RR');}finally{$db->sql_query('ROLLBACK');}$cases++;
 }
 foreach($kinds as$kind){foreach(array('alias','throw','busy','busy-autocommit')as$factory){pv_prepare($kind);$busy=in_array($factory,array('busy','busy-autocommit'),true);if($busy){$pd_busy=$db->sql_dedicated_connection();$pd_busy->sql_query($factory==='busy'?'START TRANSACTION':'SET autocommit=0');$pd_busy->sql_query("UPDATE fixture_users SET username='Pending view fixture' WHERE user_id=2");}$pd_factory=$factory;$before=kp_snapshot();try{$r=pv_result($kind);kp_check(!$r['ok']&&$r['error']===$GLOBALS['lang']['PM_cleanup_failed']&&kp_snapshot()===$before&&$db->db_connect_id,'Unsafe factory fails without data: '.$kind.'/'.$factory);if($busy){$q=$pd_busy->sql_query('SELECT username FROM fixture_users WHERE user_id=2');$row=$pd_busy->sql_fetchrow($q);$pd_busy->sql_freeresult($q);kp_check($row['username']==='Pending view fixture'&&kp_rows('SELECT username FROM fixture_users WHERE user_id=2')[0]['username']!=='Pending view fixture','Borrowed work remains private and open');}}finally{$pd_factory='normal';if($busy){$pd_busy->sql_query('ROLLBACK');$pd_busy->sql_close();}}kp_check($pn_open===0,'Release only owned connections');$cases++;}}
 foreach($kinds as$kind){foreach(array('SELECT @@SESSION.autocommit','SET TRANSACTION READ ONLY','SELECT pm_view.*')as$needle){foreach(array('before','after')as$phase){pv_prepare($kind);$before=kp_snapshot();$reached=false;$kp_hook=function($event,$sql)use($needle,$phase,&$reached){if($event===$phase&&strpos($sql,$needle)===0){$reached=true;return false;}};try{$r=pv_result($kind);kp_check($reached&&!$r['ok']&&$r['error']===$GLOBALS['lang']['PM_cleanup_failed']&&kp_snapshot()===$before&&$pn_open===0,'SQL failure sanitized, no persistent mutation: '.$kind.'/'.$needle.'/'.$phase);}finally{$kp_hook=null;}$faults++;}}}
 foreach($kinds as$kind){pv_prepare($kind);$before=kp_snapshot();$pd_root_queries=array();$pd_root_fault='SELECT ';try{$r=pv_result($kind);kp_check($r['ok']&&$pd_root_queries===array()&&kp_snapshot()===$before&&$pn_open===0,'Actual display never queries caller: '.$kind);}finally{$pd_root_fault='';}$cases++;}
 foreach(array('anonymous','actor-array','sid-empty','sid-array','sid-long')as$bad){pv_prepare('outbox');if($bad==='anonymous'){$userdata['session_logged_in']=false;}elseif($bad==='actor-array'){$userdata['user_id']=array(4);}elseif($bad==='sid-empty'){$userdata['session_id']='';}elseif($bad==='sid-array'){$userdata['session_id']=array('a');}else{$userdata['session_id']=str_repeat('a',33);}$pd_factory='throw';try{$denied=false;try{phpbb_pm_display_rows($db,'SELECT privmsgs_id FROM fixture_privmsgs',true);}catch(PhpbbAclException$error){$denied=$error->getMessage()===$GLOBALS['lang']['Not_Authorised'];}kp_check($denied&&$pn_open===0,'Malformed actor denied before factory');}finally{$pd_factory='normal';}$cases++;}
 echo 'Native PM views '.$pv_mode.': '.$cases.' cases, '.$faults." SQL faults PASS; actual controller queries, current session/account/parent, real edits, isolated callers, safe readers and EN/DE roles.\n";
}
$pv_source=file_get_contents(__DIR__.'/check-pm-download-native.php');if(substr_count($pv_source,'pd_suite();return;')!==1){throw new RuntimeException('Exact canonical native fixture boundary');}$pv_source=str_replace('pd_suite();return;','pv_suite();return;',$pv_source);$pv_eval='';foreach(token_get_all($pv_source)as$t){if(is_array($t)&&$t[0]===T_OPEN_TAG){continue;}$pv_eval.=is_array($t)?($t[0]===T_DIR?var_export(__DIR__,true):$t[1]):$t;}eval($pv_eval);
