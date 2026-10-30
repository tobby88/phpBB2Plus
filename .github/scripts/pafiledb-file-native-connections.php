<?php
// Shared observed native connections for the coordinator and real HTTP upload
// fixtures. All mutations still go through the production database driver.
class PaCoordinatorConnection{
 public$inner,$db_connect_id,$role,$source,$writable=false;
 function __construct($inner,$source,$role){$this->inner=$inner;$this->db_connect_id=$inner->db_connect_id;$this->source=$source;$this->role=$role;}
 function __call($method,$args){return call_user_func_array(array($this->inner,$method),$args);}
 function sql_query($sql,$transaction=false){
  if(is_callable($this->source->on_query)&&call_user_func($this->source->on_query,$sql,$this)===false){return false;}
  if($sql==='START TRANSACTION'){$this->writable=true;}
  if($sql==='COMMIT'&&$this->writable&&$this->source->lost===$this->role){$this->source->lost=0;$r=$this->inner->sql_query($sql,$transaction);pc_check($r!==false,'Actual own lost-ack COMMIT');$this->writable=false;return false;}
  if($sql===$this->source->fail){$this->source->fail='';return false;}
  $r=$this->inner->sql_query($sql,$transaction);if($r&&($sql==='COMMIT'||$sql==='ROLLBACK')){$this->writable=false;}return$r;
 }
 function sql_close(){$this->source->closed++;return$this->inner->sql_close();}
}
class PaCoordinatorDatabase extends sql_db{
 public$factories=0,$closed=0,$kind='',$first=null,$fail='',$lost=0,$on_query=null;
 function sql_dedicated_connection(){
  $this->factories++;if($this->kind==='same'){return$this;}if($this->kind==='second-same'&&$this->factories===2){return$this->first;}
  $db=parent::sql_dedicated_connection();
  if($this->kind==='no-backslash'){$db->sql_query("SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'");}
  if($this->kind==='foreign-transaction'){$db->sql_query('START TRANSACTION');$db->sql_query("INSERT INTO fixture_pa_files(file_id,file_catid,real_name,file_dir,file_name)VALUES(99,9,'pending.txt','fixture/','Foreign pending work')");}
  $result=new PaCoordinatorConnection($db,$this,$this->factories);if($this->factories===1){$this->first=$result;}return$result;
 }
}
