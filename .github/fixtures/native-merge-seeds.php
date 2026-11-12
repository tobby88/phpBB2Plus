<?php
// Disposable native fixture only; no application or live configuration.
if(PHP_SAPI!=='cli'||!defined('IN_PHPBB')){return;}
class MergeSeedCommitPeer{
 var$inner;var$armed=false;var$ack=false;var$injected=false;
 function __construct($inner,$ack){$this->inner=$inner;$this->ack=$ack;}
 function __call($name,$args){return call_user_func_array(array($this->inner,$name),$args);}
 function sql_query($sql,$transaction=false){if($sql==='COMMIT'&&$this->armed){$this->armed=false;$this->injected=true;if($this->ack){$r=$this->inner->sql_query($sql,$transaction);mg_check($r,'Real seed commit before lost acknowledgement');}return false;}return$this->inner->sql_query($sql,$transaction);}
}
function mg_seed_idle(){mg_check($GLOBALS['peer']->sql_query('SET TRANSACTION READ WRITE'),'Seed helper released its transaction');}
function mg_seed_checks(){
 global$peer,$db;$checks=0;
 mg_fixture();$complete=mg_snap();mg_fixture();mg_check(mg_snap()===$complete,'Repeated complete canonical seed is byte-identical');$checks++;
 mg_reset();$basic=mg_snap();$failed=false;
 try{mg_fixture('root','both',function(){throw new RuntimeException('Synthetic seed callback');});}catch(RuntimeException$error){$failed=$error->getMessage()==='Synthetic seed callback';}
 mg_seed_idle();mg_check($failed&&mg_snap()===$basic,'Callback failure rolls back every extra seed row');$checks++;
 mg_sql("CREATE TRIGGER fixture_merge_seed_failure BEFORE INSERT ON fixture_bookmarks FOR EACH ROW BEGIN IF NEW.user_id=8 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic merge seed failure'; END IF; END");$failed=false;
 try{mg_fixture();}catch(Exception$error){$failed=strpos($error->getMessage(),'Synthetic merge seed failure')!==false;}
 finally{mg_sql('DROP TRIGGER fixture_merge_seed_failure');}
 mg_seed_idle();mg_check($failed&&mg_snap()===$basic,'Actual late SQL failure rolls back earlier preference/topology inserts');$checks++;
 foreach(array(false,true)as$ack){
  $inner=$peer;$proxy=new MergeSeedCommitPeer($inner,$ack);$peer=$proxy;$failed=false;
  try{mg_fixture('root','both',function()use($proxy){$proxy->armed=true;});}catch(RuntimeException$error){$failed=true;}finally{$peer=$inner;}
  mg_seed_idle();mg_check($failed&&$proxy->injected&&mg_snap()===($ack?$complete:$basic),'Failed/lost seed commit leaves exactly all-before or all-after data');$checks++;
 }
 mg_fixture();$before=mg_snap();mg_sql('START TRANSACTION');mg_sql("UPDATE fixture_config SET config_value='pending seed fixture' WHERE config_name='unrelated'");$denied=false;
 try{mg_fixture();}catch(RuntimeException$error){$denied=$error->getMessage()==='Fixture seed must not commit an outer transaction';}
 $pending=mg_rows("SELECT config_value FROM fixture_config WHERE config_name='unrelated'");$r=$db->sql_query("SELECT config_value FROM fixture_config WHERE config_name='unrelated'");$outside=$db->sql_fetchrow($r);$db->sql_freeresult($r);
 mg_check($denied&&$pending[0]['config_value']==='pending seed fixture'&&$outside['config_value']==='preserved','Existing pending peer transaction is neither committed nor rolled back');mg_sql('ROLLBACK');mg_check(mg_snap()===$before,'Outer rollback still restores original data');$checks++;
 mg_sql('SET autocommit=0');$denied=false;try{mg_fixture();}catch(RuntimeException$error){$denied=$error->getMessage()==='Fixture seed requires autocommit';}$state=mg_rows('SELECT @@SESSION.autocommit AS mode');mg_check($denied&&(int)$state[0]['mode']===0,'Existing non-autocommit mode preserved');mg_sql('ROLLBACK');mg_sql('SET autocommit=1');mg_check(mg_snap()===$before,'Rejected non-autocommit seed changes no rows');$checks++;
 mg_sql("SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES')");
 mg_sql('ALTER TABLE fixture_users ADD fixture_required INT NOT NULL');mg_fixture();$rows=mg_rows('SELECT fixture_required FROM fixture_users');mg_check(count($rows)===2&&$rows[0]['fixture_required']==='0'&&$rows[1]['fixture_required']==='0','Added required column invalidates cached metadata and gets original numeric fill');$checks++;
 mg_sql('ALTER TABLE fixture_users MODIFY fixture_required VARCHAR(16) NOT NULL');mg_fixture();$rows=mg_rows('SELECT fixture_required FROM fixture_users');mg_check(count($rows)===2&&$rows[0]['fixture_required']===''&&$rows[1]['fixture_required']==='','Type change refreshes required textual fill');$checks++;
 mg_sql('ALTER TABLE fixture_users DROP COLUMN fixture_required');mg_fixture();mg_check(mg_snap()===$complete&&mg_run()===200,'Column removal restores exact seed and successful actual merge after all setup failures');$checks++;
 mg_check($checks===10,'Complete native merge fixture seed regressions');echo 'Native merge fixture seeds: '.$checks." checks PASS; actual SQL/callback/commit failures, outer transactions, canonical rows and DDL cache invalidation.\n";
}
