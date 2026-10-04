<?php
// Actual CT method and mysqli; only controlled SQL failures/interleavings are injected.
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');require __DIR__.'/check-attachment-settings-storage.php';
require $ats_source.'language/lang_english/lang_cback_ctracker.php';
require $ats_source.'ctracker/classes/class_ct_database.php';
if(PHP_SAPI!=='cli'||getenv('PHPBB_CT_LOGIN_IP_NATIVE')!=='1'){echo "Login IP checks require an explicitly enabled disposable database.\n";return;}
require $ats_source.'db/mysqli.php';$port=getenv('PHPBB_CT_LOGIN_IP_PORT')?:'3306';$password=getenv('PHPBB_CT_LOGIN_IP_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
class CtLoginIpDatabase extends sql_db {
 var $failure='';var $queries=array();var $peer_after_write=false;var $peer_completed=false;
 function sql_query($sql='',$tx=false){
  $this->queries[]=$sql;
  if(($this->failure==='write'&&strpos($sql,'UPDATE fixture_users')===0)||($this->failure==='read'&&strpos($sql,'SELECT ct_last_ip')===0)){return false;}
  $r=parent::sql_query($sql,$tx);
  if($r&&$this->peer_after_write&&!$this->peer_completed&&strpos($sql,'UPDATE fixture_users')===0){
   $this->peer_completed=true;ats_check($GLOBALS['ct_ip_peer']->sql_query("UPDATE fixture_users SET ct_last_ip=ct_last_used_ip,ct_last_used_ip='192.0.2.4' WHERE user_id=2"),'Independent atomic peer write');
  }
  return $r;
 }
}
$host='127.0.0.1:'.$port;$schema='codex_ct_login_ip_'.bin2hex(phpbb_random_bytes(8));$control=new sql_db($host,'root',$password,'',false);ats_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
$db=new CtLoginIpDatabase($host,'root',$password,$schema,false);$ct_ip_peer=new sql_db($host,'root',$password,$schema,false);
function ct_ip_row(){ $r=$GLOBALS['ct_ip_peer']->sql_query('SELECT ct_last_ip,ct_last_used_ip FROM fixture_users WHERE user_id=2');ats_check($r,'Current native IP row');$row=$GLOBALS['ct_ip_peer']->sql_fetchrow($r);$GLOBALS['ct_ip_peer']->sql_freeresult($r);return $row; }
function ct_ip_reset(){global$db,$ct_ip_peer,$userdata;$db->failure='';$db->queries=array();$db->peer_after_write=$db->peer_completed=false;ats_check($ct_ip_peer->sql_query('DELETE FROM fixture_users'),'Reset owned table');ats_check($ct_ip_peer->sql_query("INSERT INTO fixture_users VALUES(2,'192.0.2.1','192.0.2.2')"),'Owned IP pair');$userdata=array('user_id'=>2,'ct_last_ip'=>'stale','ct_last_used_ip'=>'stale');}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $schema_sql=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');ats_check(preg_match('/ct_last_ip\s+varchar\(\s*45\s*\)/i',$schema_sql)&&preg_match('/ct_last_used_ip\s+varchar\(\s*45\s*\)/i',$schema_sql),'Fresh IPv6-capable schema');
 ats_check($ct_ip_peer->sql_query('CREATE TABLE fixture_users(user_id INT PRIMARY KEY,ct_last_ip VARCHAR(45),ct_last_used_ip VARCHAR(45)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),'Native disposable IP table');
 $reflection=new ReflectionClass('ct_database');$config=$reflection->newInstanceWithoutConstructor();$cases=0;
 foreach(array('','NO_BACKSLASH_ESCAPES','ANSI_QUOTES')as$mode){ats_check($db->sql_query("SET SESSION sql_mode='$mode'"),'Current SQL mode');
  foreach(array('192.0.2.3','2001:db8::3')as$ip){ct_ip_reset();$config->user_ip_value=$ip;$config->set_user_ip('2');$row=ct_ip_row();ats_check($row['ct_last_ip']==='192.0.2.2'&&$row['ct_last_used_ip']===$ip,'Actual atomic IPv4/IPv6 pair');ats_check($userdata['ct_last_ip']===$row['ct_last_ip']&&$userdata['ct_last_used_ip']===$row['ct_last_used_ip'],'Current target cache does not reuse stale/guest IP');$cases++;}
  ct_ip_reset();$config->user_ip_value='192.0.2.3';$db->failure='write';$before=$userdata;$denied=false;try{$config->set_user_ip(2);}catch(AttachSettingsExit$e){$denied=true;}$row=ct_ip_row();ats_check($denied&&$row['ct_last_ip']==='192.0.2.1'&&$row['ct_last_used_ip']==='192.0.2.2'&&$userdata===$before,'Failed single statement cannot partially advance IP pair/cache');$cases++;
  ct_ip_reset();$config->user_ip_value='192.0.2.3';$db->failure='read';$before=$userdata;$denied=false;try{$config->set_user_ip(2);}catch(AttachSettingsExit$e){$denied=true;}$row=ct_ip_row();ats_check($denied&&$row['ct_last_ip']==='192.0.2.2'&&$row['ct_last_used_ip']==='192.0.2.3'&&$userdata===$before,'Failed cache refresh does not fabricate old/current IPs or undo an atomic pair');$cases++;
  ct_ip_reset();$config->user_ip_value='192.0.2.3';$db->peer_after_write=true;$config->set_user_ip(2);$row=ct_ip_row();ats_check($db->peer_completed&&$row['ct_last_ip']==='192.0.2.3'&&$row['ct_last_used_ip']==='192.0.2.4','Independent login cannot be overwritten by split old query');ats_check($userdata['ct_last_ip']==='192.0.2.3'&&$userdata['ct_last_used_ip']==='192.0.2.4','Cached pair matches actual post-peer state');$cases++;
  ct_ip_reset();$userdata=array('user_id'=>-1,'ct_last_ip'=>'guest','ct_last_used_ip'=>'guest');$before=$userdata;$config->set_user_ip(2);ats_check($userdata===$before&&ct_ip_row()['ct_last_used_ip']==='192.0.2.3','A different current account/guest cache remains untouched');$cases++;
  foreach(array(0,-1,8388608,'02','2 OR 1',array(2),null,true,2.0)as$bad){ct_ip_reset();$before=$userdata;$denied=false;try{$config->set_user_ip($bad);}catch(AttachSettingsExit$e){$denied=true;}ats_check($denied&&$db->queries===array()&&$userdata===$before&&ct_ip_row()['ct_last_used_ip']==='192.0.2.2','Malformed target cannot touch account/audit state');$cases++;}
  foreach(array('invalid',"192.0.2.3'",array('192.0.2.3'),null)as$bad){ct_ip_reset();$config->user_ip_value=$bad;$denied=false;try{$config->set_user_ip(2);}catch(AttachSettingsExit$e){$denied=true;}ats_check($denied&&$db->queries===array()&&ct_ip_row()['ct_last_used_ip']==='192.0.2.2','Malformed address cannot touch pair');$cases++;}
 }
 ct_ip_reset();$config->user_ip_value='192.0.2.3';$db->sql_query('START TRANSACTION');$config->set_user_ip(2);ats_check(ct_ip_row()['ct_last_used_ip']==='192.0.2.2','IP update cannot commit caller transaction');$db->sql_query('ROLLBACK');ats_check(ct_ip_row()['ct_last_used_ip']==='192.0.2.2','Caller retains rollback ownership');$cases++;
 echo 'Native CrackerTracker login IP: '.$cases." atomic/failure/identity cases passed.\n";
}finally{$db->sql_close();$ct_ip_peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
