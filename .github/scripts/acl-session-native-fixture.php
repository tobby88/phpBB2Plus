<?php
// Native adapter for the ACL fixtures. Tables come from the actual installer,
// not translated SQLite DDL. Only a fresh, owned loopback database is accepted.
$aclSessionDsn=getenv('PHPBB_ACL_SESSION_TEST_DSN');
$aclSessionNative=$aclSessionDsn!==false&&$aclSessionDsn!=='';
if($aclSessionNative&&!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=codex_acl_session_[a-f0-9]{16};charset=utf8mb4$/D',$aclSessionDsn)){throw new RuntimeException('Owned ACL fixture required');}
$aclSessionSchema=array('fixture_users'=>'users','fixture_groups'=>'groups','fixture_memberships'=>'user_group','fixture_auth'=>'auth_access','fixture_forums'=>'forums','fixture_sessions'=>'sessions','fixture_junior'=>'jr_admin_users');
$aclSessionNativeEngine=null;$aclSessionPdo=null;
class NativeAclSessionForum {
 public $dbname='acl-session-fixture';
 function sql_dedicated_connection(){return new NativeAclSessionConnection($GLOBALS['mutation_server']);}
 function sql_query($sql){throw new RuntimeException('Unlocked ACL connection');}
}
class NativeAclSessionConnection extends sql_db {
 public $pdo;public $lease;
 function __construct($state){
  $this->state=$state;$this->pdo=new PDO($GLOBALS['aclSessionDsn'],'root',getenv('PHPBB_ACL_SESSION_TEST_PASSWORD')?:'',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
 }
 function sql_query($sql){
  if($this->closed){return false;}
  if(preg_match("/^SELECT GET_LOCK\('([^']+)', (0|10)\) AS acquired$/D",$sql,$m)){
   $rows=$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
   if((int)$rows[0]['acquired']===1){$this->state->owner=$this;$this->lease=$m[1];}return $this->result($rows);
  }
  mutation_check($this->state->owner===$this,'Native ACL query owns writer lock');
  if(is_callable($this->state->hook)){call_user_func($this->state->hook,$sql,$this);}
  if($this->closed||($this->state->failure!==''&&strpos($sql,$this->state->failure)===0)){return false;}
  $r=$this->pdo->query($sql);$this->affected=$r->rowCount();
  return preg_match('/^SELECT/',$sql)?$this->result($r->fetchAll(PDO::FETCH_ASSOC)):true;
 }
 function sql_escape($value){return substr($this->pdo->quote($value),1,-1);}
 function sql_close(){
  if($this->state->owner===$this){$this->pdo->query("SELECT RELEASE_LOCK('".$this->lease."')")->fetchColumn();$this->state->owner=null;}
  $this->closed=true;$this->db_connect_id=false;$this->pdo=null;
 }
}
function acl_session_fixture($engine,$actor,$scenario){
 global $mutation_server,$db,$aclSessionNative,$aclSessionSchema,$aclSessionPdo,$aclSessionNativeEngine,$aclSessionDsn;
 acl_fixture($actor);
 if($aclSessionNative){
  if(!$aclSessionPdo){$aclSessionPdo=new PDO($aclSessionDsn,'root',getenv('PHPBB_ACL_SESSION_TEST_PASSWORD')?:'',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));}
  $source=file_get_contents($GLOBALS['forum_root'].'install/schemas/mysql_schema.sql');
  foreach($aclSessionSchema as $target=>$original){
   if($aclSessionNativeEngine!==$engine){
    if(!preg_match('/CREATE TABLE phpbb_'.$original.' \([\s\S]*?\) ENGINE=InnoDB ROW_FORMAT=DYNAMIC[^;]*;/',$source,$m)){throw new RuntimeException('Canonical ACL schema missing');}
    $ddl=str_replace('phpbb_'.$original,$target,$m[0]);
    if($engine==='MyISAM'){$ddl=str_replace('ENGINE=InnoDB ROW_FORMAT=DYNAMIC','ENGINE=MyISAM',$ddl);}
    elseif($engine!=='InnoDB'){throw new RuntimeException('Unknown fixture engine');}
    $aclSessionPdo->exec('DROP TABLE IF EXISTS '.$target);$aclSessionPdo->exec($ddl);
   }else{$aclSessionPdo->exec('DELETE FROM '.$target);}
   $columns=$aclSessionPdo->query('SHOW COLUMNS FROM '.$target)->fetchAll(PDO::FETCH_ASSOC);
   foreach($mutation_server->pdo->query('SELECT * FROM '.$target)->fetchAll(PDO::FETCH_ASSOC) as $row){
    // Populate required unrelated installer fields in test data only.
    foreach($columns as $column){if(!array_key_exists($column['Field'],$row)&&$column['Null']==='NO'&&$column['Default']===null&&strpos($column['Extra'],'auto_increment')===false){$row[$column['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$column['Type'])?0:'';}}
    $q=$aclSessionPdo->prepare('INSERT INTO '.$target.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));
   }
  }
  $aclSessionNativeEngine=$engine;$mutation_server->pdo=$aclSessionPdo;$db=new NativeAclSessionForum();
 }else{$db=new GroupLogicForum();}
 $p=$mutation_server->pdo;
 if($actor===8){
  $mode=$scenario==='forum'?'forum':(strpos($scenario,'user')===0?'user':'group');
  $route=$mode==='forum'?'ForumsPermissionsadmin_forumauth.php':($mode==='user'?'Users':'Groups').'Permissionsadmin_ug_auth.php?mode='.$mode;
  $p->exec("INSERT INTO fixture_junior (user_id,user_jr_admin".($aclSessionNative?',admin_notes':'').") VALUES (8,'".md5($route)."'".($aclSessionNative?",''":'').")");
 }
 if($scenario==='group-delete'){$p->exec('UPDATE fixture_auth SET auth_mod=0 WHERE group_id=3');}
 if($scenario==='promote'||$scenario==='demote'){
  $p->exec('INSERT INTO fixture_auth (group_id,forum_id,auth_mod,auth_read) VALUES (4,2,0,1),(4,3,1,1)');
  if($scenario==='demote'){$p->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=9');}
 }
}
