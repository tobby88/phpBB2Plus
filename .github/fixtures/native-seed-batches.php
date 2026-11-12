<?php
class NativeSeedFailurePeer {
 var $inner;var $needle;var $hit=false;
 function __construct($inner,$needle){$this->inner=$inner;$this->needle=$needle;}
 function __call($method,$args){return call_user_func_array(array($this->inner,$method),$args);}
 function sql_query($sql,$transaction=false){if(!$this->hit&&strpos($sql,$this->needle)===0){$this->hit=true;return false;}return $this->inner->sql_query($sql,$transaction);}
}
function native_seed_batch_run($phase,$actor){
 if($phase==='posting'){ps_fixture($actor);}elseif($phase==='deletion'){pd_fixture($actor);}else{tr_fixture('forum_purge',$actor);}
}
function native_seed_batch_checks(){
 $checks=0;
 foreach(array('root','moderator')as$actor){foreach(array('posting','deletion','removal')as$phase){
  native_seed_batch_run($phase,$actor);$complete=tr_snapshot();
  if($phase==='posting'){ae_fixture($actor);$needle='UPDATE fixture_topics SET topic_replies=1';}elseif($phase==='deletion'){ps_fixture($actor);$needle='INSERT INTO fixture_attachments (';}else{pd_fixture($actor);$needle='INSERT INTO fixture_vote_voters (';}
  $before=tr_snapshot();$original=$GLOBALS['peer'];$failure=new NativeSeedFailurePeer($original,$needle);$GLOBALS['peer']=$failure;$denied=false;
  try{native_seed_batch_run($phase,$actor);}catch(RuntimeException$error){$denied=strpos($error->getMessage(),'Fixture SQL:')===0;}finally{$GLOBALS['peer']=$original;}
  $state=ae_rows('SELECT @@SESSION.in_transaction AS active_transaction');ae_check($failure->hit&&$denied&&(int)$state[0]['active_transaction']===0&&tr_snapshot()===$before,'Failed actual seed phase rolls back its writes and closes the transaction: '.$phase.'/'.$actor);$checks++;
  native_seed_batch_run($phase,$actor);$state=ae_rows('SELECT @@SESSION.in_transaction AS active_transaction');ae_check((int)$state[0]['active_transaction']===0&&tr_snapshot()===$complete,'Successful actual seed phase retains exact original rows before application tests: '.$phase.'/'.$actor);$checks++;
 }}
 echo 'Native fixture seed batches: ',$checks," checks PASS; actual posting/deletion/removal setup, root/moderator rows, mid-seed rollback, exact successful data and closed transactions.\n";
}
native_seed_batch_checks();
