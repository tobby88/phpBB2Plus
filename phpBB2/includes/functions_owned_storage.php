<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
class PhpbbOwnedStorageFailure extends RuntimeException {}
// Owned connections, transaction lifetime and canonical storage for mutations.
abstract class PhpbbOwnedStore
{
 protected $connection=null;
 protected $identity;
 protected $lock;
 protected $held=false;
 protected $transaction=false;
 protected $failed=false;
 protected $actor;
 protected $finished=false;

 function __construct($database,$actor)
 {
  $this->actor=$actor;$candidate=null;
  try {
   if(!is_object($database)||!method_exists($database,'sql_dedicated_connection')){$this->storage_fail();}
   $source=$this->identity($database);$candidate=$database->sql_dedicated_connection();
   if(!is_object($candidate)||$candidate===$database||!isset($candidate->db_connect_id)||$candidate->db_connect_id===$database->db_connect_id){$candidate=null;$this->storage_fail();}
   $own=$this->identity($candidate);if($own['schema_name']!==$source['schema_name']||$own['id']===$source['id']){$candidate=null;$this->storage_fail();}
   $this->lock='attachment:'.md5($source['schema_name']."\0".ATTACHMENTS_TABLE);
   $owner=$this->direct_rows($candidate,"SELECT IS_USED_LOCK('".$this->lock."') owner");
   if(count($owner)!==1){$this->storage_fail();}
   if($owner[0]['owner']!==null&&(string)$owner[0]['owner']===$own['id']){$candidate=null;$this->storage_fail();}
   $this->connection=$candidate;$candidate=null;$this->identity=$own['id'];
   $lock=$this->direct_rows($this->connection,"SELECT GET_LOCK('".$this->lock."',3) held");
   if(count($lock)!==1||(string)$lock[0]['held']!=='1'){$this->storage_fail();}$this->held=true;
   // The one-shot SET rejects an already active foreign transaction.
   $this->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
   $this->query('START TRANSACTION READ ONLY');$this->query('COMMIT');
   $this->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
   $this->query("SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");
   $this->query('SET SESSION innodb_lock_wait_timeout=5');
   $this->query('START TRANSACTION');$this->transaction=true;
   $this->pin_tables();
  } catch(Exception$error){if($candidate){$candidate->sql_close();}$this->release();throw$error;}
  catch(Error$error){if($candidate){$candidate->sql_close();}$this->release();$this->storage_fail();}
 }
 protected function direct_rows($database,$sql)
 {
  $r=$database->sql_query($sql);if(!$r){$this->storage_fail();}
  try{$rows=$database->sql_fetchrowset($r);}finally{$database->sql_freeresult($r);}
  if(!is_array($rows)){$this->storage_fail();}return$rows;
 }
 protected function identity($database)
 {
  $rows=$this->direct_rows($database,'SELECT CONNECTION_ID() id,DATABASE() schema_name');
  if(count($rows)!==1||!is_string($rows[0]['schema_name'])||$rows[0]['schema_name']===''||!preg_match('/^[1-9][0-9]*$/D',(string)$rows[0]['id'])){$this->storage_fail();}
  return array('id'=>(string)$rows[0]['id'],'schema_name'=>$rows[0]['schema_name']);
 }
 protected function query($sql)
 {
  if(!$this->connection||$this->finished||$this->failed){$this->storage_fail();}
  try{
   $state=$this->direct_rows($this->connection,"SELECT CONNECTION_ID() id,IS_USED_LOCK('".$this->lock."') owner");
   if(count($state)!==1||(string)$state[0]['id']!==$this->identity||(string)$state[0]['owner']!==$this->identity){$this->storage_fail();}
   $r=$this->connection->sql_query($sql);if(!$r){$this->storage_fail();}return$r;
  }catch(Exception$error){$this->failed=true;throw$error;}catch(Error$error){$this->failed=true;$this->storage_fail();}
 }
 function rows($sql)
 {
  $r=$this->query($sql);try{$rows=$this->connection->sql_fetchrowset($r);}finally{$this->connection->sql_freeresult($r);}
  if(!is_array($rows)){$this->failed=true;$this->storage_fail();}return$rows;
 }
 protected function escape($value){return$this->connection->sql_escape($value);}
 protected function pin_tables()
 {
  foreach($this->storage_tables()as$table){
   if(!is_string($table)||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table)){$this->storage_fail();}
   $r=$this->query('SELECT * FROM '.$table.' LIMIT 0');$this->connection->sql_freeresult($r);$name=$this->escape($table);
   $meta=$this->rows("SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".$name."') AND TABLE_TYPE='BASE TABLE'");
   $legacy=$this->rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".$name."') AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci')");
   if(count($meta)!==1||$meta[0]['ENGINE']!=='InnoDB'||strtolower($meta[0]['ROW_FORMAT'])!=='dynamic'||$meta[0]['TABLE_COLLATION']!=='utf8mb4_unicode_ci'||$legacy){$this->storage_fail('Owned_storage_upgrade');}
  }
 }
 protected function storage_fail($key = 'Owned_storage_failed') { throw new PhpbbOwnedStorageFailure($key); }
 abstract protected function storage_tables();
 protected function commit()
 {
  $this->query('COMMIT');$this->transaction=false;$this->finished=true;
 }
 function release()
 {
  $connection=$this->connection;$this->connection=null;$this->finished=true;
  if($connection){if($this->transaction){try{$connection->sql_query('ROLLBACK');}catch(Exception$ignored){}catch(Error$ignored){}}$this->transaction=false;try{$connection->sql_close();}catch(Exception$ignored){}catch(Error$ignored){}}$this->held=false;
 }
}
