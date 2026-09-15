<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_MODERATION_COMMIT_NATIVE')!=='1'){echo "Moderation commit checks require an explicitly enabled disposable database.\n";return;}
$mc_kind=isset($argv[1])?$argv[1]:'';$mc_prefixes=array('state'=>'sn','move'=>'mn','split'=>'sp','merge'=>'mg');
if(!isset($mc_prefixes[$mc_kind])){throw new RuntimeException('Choose state, move, split or merge');}
$mc_prefix=$mc_prefixes[$mc_kind];$mc_env=strtoupper($mc_kind);
putenv('PHPBB_'.$mc_env.'_NATIVE=1');putenv('PHPBB_'.$mc_env.'_PORT='.(getenv('PHPBB_MODERATION_COMMIT_PORT')?:'3306'));putenv('PHPBB_'.$mc_env.'_PASSWORD='.(getenv('PHPBB_MODERATION_COMMIT_PASSWORD')?:''));
$mc_source=file_get_contents(__DIR__.'/check-topic-'.$mc_kind.'-native.php');$mc_cut=strpos($mc_source," foreach(array('root','moderator') as \$actor){");
if($mc_cut===false){throw new RuntimeException('Canonical fixture boundary missing');}
$mc_head=str_replace('__DIR__',var_export(__DIR__,true),substr($mc_source,5,$mc_cut-5));
$mc_head=str_replace('codex_'.$mc_kind.'_atomic_','codex_moderation_commit_',$mc_head);
class ModerationCommitConnection {
 var $inner;var $db_connect_id;var $committed=false;
 function __construct($inner){$this->inner=$inner;$this->db_connect_id=$inner->db_connect_id;}
 function __call($name,$args){return call_user_func_array(array($this->inner,$name),$args);}
 function sql_query($sql,$transaction=false){
  if($this->committed){$GLOBALS['mc_after_queries'][]=$sql;}
  $r=$this->inner->sql_query($sql,$transaction);
  if($sql==='COMMIT'&&$r){$this->committed=true;if(is_callable($GLOBALS['mc_after'])){call_user_func($GLOBALS['mc_after'],$this);}}
  return $r;
 }
}
class ModerationCommitDatabase {
 var $inner;var $dbname;
 function __construct($inner){$this->inner=$inner;$this->dbname=$inner->dbname;}
 function __call($name,$args){return call_user_func_array(array($this->inner,$name),$args);}
 function sql_dedicated_connection(){return new ModerationCommitConnection($this->inner->sql_dedicated_connection());}
}
function mc_fixture($actor,$variant){
 $kind=$GLOBALS['mc_kind'];$prefix=$GLOBALS['mc_prefix'];
 if($kind==='state'){sn_reset($actor,$variant);}elseif($kind==='merge'){mg_fixture($actor,$variant[0]);}else{call_user_func($prefix.'_reset',$actor);}
 $GLOBALS['mc_after']=null;$GLOBALS['mc_after_queries']=array();
}
function mc_run($variant){
 switch($GLOBALS['mc_kind']){case 'state':return sn_run($variant);case 'move':return mn_run($variant);case 'split':return sp_run($variant);default:return mg_run($variant[1]);}
}
function mc_owner($connection){
 switch($GLOBALS['mc_kind']){
  case 'state':return new PhpbbTopicStateDatabase($connection,3,'lock');
  case 'move':return new PhpbbTopicMoveDatabase($connection,3,4);
  case 'split':return new PhpbbTopicSplitDatabase($connection,3,4);
  default:$tx=new PhpbbTopicMergeDatabase($connection);$tx->forums=array(3,4);return $tx;
 }
}
$mc_body= <<<'PHP'
 $db=new ModerationCommitDatabase($db);$mc_after=null;$mc_after_queries=array();
 if(!function_exists('plus_storage_tables')){require dirname(dirname(__DIR__)).'/update/innodb_migration.php';}
 $migrated=plus_storage_tables($canonical,'fixture_');
 foreach($GLOBALS[$mc_prefix.'_tables'] as $table){mc_check(in_array('fixture_'.$table,$migrated,true),'Updater covers every canonical fixture participant');}
 $variants=$mc_kind==='state'?array('lock','unlock','sticky','announce','normalise'):($mc_kind==='move'?array(false,true):($mc_kind==='split'?array('selected','after'):array(array('none',false),array('none',true),array('source',false),array('source',true),array('target',false),array('target',true),array('both',false),array('both',true))));
 $probe=getenv('PHPBB_MODERATION_COMMIT_PROBE')==='1';if($probe){$variants=array($variants[0]);}
 foreach($probe?array('root'):array('root','moderator') as $actor){foreach($variants as $variant){
  mc_fixture($actor,$variant);$expected_out=mc_run($variant);mc_check($expected_out!=='error','Baseline operation');$expected=mc_snap();
  foreach(array('session','permission','disconnect') as $change){
   mc_fixture($actor,$variant);$reached=false;
   $mc_after=function($connection)use($change,$actor,&$reached){$reached=true;if($change==='disconnect'){mc_sql('KILL CONNECTION '.(int)mysqli_thread_id($connection->db_connect_id));}else{mc_sql(mc_revoke($change==='session'?'missing':($actor==='root'?'role':'grant')));}};
   $out=mc_run($variant);$actual=mc_snap();$after=$expected;foreach(array('users','sessions','auth_access') as $table){$after[$table]=$actual[$table];}
   mc_check($reached&&$actual===$after,'Whole confirmed operation including audit/counters retained');
   if($probe){echo $mc_kind.'/'.$change.': committed=true; response='.($out==='error'?'error':'success')."\n";}
   else{mc_check($out===$expected_out,'Confirmed operation retains correct response after '.$change);mc_check(!$mc_after_queries,'No post-confirmation SQL or implicit rollback');}$cases++;
  }
 }}
 if(!$probe){
  $variant=$variants[0];
  foreach(array('commit-without-begin','write-without-begin','double-begin','write-after-rollback','write-after-commit') as $state){
   mc_fixture('root',$variant);$before=mc_snap();$lock=new attach_mutation_lock($db);mc_check($lock->acquired,'Lifecycle owner acquired');$tx=mc_owner($lock->connection);$caught=false;
   $write="UPDATE fixture_topics SET topic_title='Lifecycle draft' WHERE topic_id=100";
   try{
    if($state==='commit-without-begin'){$tx->commit();}
    elseif($state==='double-begin'){$tx->begin();$tx->sql_query($write);$tx->begin();}
    else{if($state==='write-after-rollback'){$tx->begin();$tx->rollback();}if($state==='write-after-commit'){$tx->begin();$tx->commit();}$tx->sql_query($write);}
   }catch(RuntimeException $e){$caught=true;}finally{$tx->rollback();$lock->release();}
   mc_check($caught&&mc_snap()===$before,'Lifecycle cannot implicitly commit or write outside owner: '.$state);$cases++;
  }
  $other=$schema.'_other';mc_check($control->sql_query('CREATE DATABASE '.$other),'Owned comparison schema');
  try{
   mc_check($control->sql_query('CREATE TABLE '.$other.'.fixture_sessions (session_id CHAR(32)) ENGINE=MyISAM DEFAULT CHARSET=latin1'),'Unrelated legacy metadata');
   foreach(array(''=>true,'ENGINE=MyISAM'=>false,'ROW_FORMAT=COMPACT'=>false,'DEFAULT CHARACTER SET latin1'=>false,'MODIFY session_id CHAR(32) CHARACTER SET latin1 NOT NULL'=>false,'MODIFY session_id CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL'=>false) as $ddl=>$allowed){
    mc_sql('ALTER TABLE fixture_sessions ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');mc_fixture('root',$variant);if($ddl!==''){mc_sql('ALTER TABLE fixture_sessions '.$ddl);}$before=mc_snap();
    $lock=new attach_mutation_lock($db);$tx=mc_owner($lock->connection);$ok=false;
    try{$tx->begin();$ok=true;}catch(RuntimeException $e){}finally{$tx->rollback();$lock->release();}
    mc_check($ok===$allowed&&mc_snap()===$before,'Exact storage policy without changes '.$ddl);
    $query='';foreach($GLOBALS[$mc_prefix.'_queries'] as $sql){if(strpos($sql,'SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.')===0&&strpos($sql,"AND TABLE_NAME='fixture_sessions'")!==false){$query=$sql;}}
    $scope="c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='fixture_sessions'";
    mc_check($query!==''&&strpos($query,$scope)!==false,'Explicit bounded metadata scan');mc_check(mc_rows($query)===mc_rows(str_replace($scope,'c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME',$query)),'Same policy as original correlated query');$cases++;
   }
  }finally{$control->sql_query('DROP DATABASE '.$other);}
  // Reuse the real controller/cache matrices with an independent session
  // revocation AFTER each acknowledged commit. Failed/lost acknowledgements
  // must still take their original error path; previews remain read-only.
  mc_sql('ALTER TABLE fixture_sessions ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
  $controller_start=strpos($mc_source," $".'controller=file_get_contents(');$controller_end=strpos($mc_source," echo 'Native topic ",$controller_start);
  mc_check($controller_start!==false&&$controller_end>$controller_start,'Canonical real-controller matrix boundary');
  $controller_body=substr($mc_source,$controller_start,$controller_end-$controller_start);$controller_runs=0;
  $mc_after=function($connection)use(&$controller_runs){$controller_runs++;mc_sql(mc_revoke('missing'));};
  eval($controller_body);$mc_after=null;mc_check($controller_runs>0,'Real controllers exercised after committed session revocation');$cases+=$controller_runs;
 }
 echo $mc_kind.' native moderation commit: '.$cases." acknowledgement/lifecycle/metadata cases passed.\n";
}finally{$mc_after=null;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
PHP;
$mc_body=str_replace(array('mc_check(','mc_snap(','mc_sql(','mc_revoke(','mc_rows('),array($mc_prefix.'_check(',$mc_prefix.'_snap(',$mc_prefix.'_sql(',$mc_prefix.'_revoke(',$mc_prefix.'_rows('),$mc_body);
$mc_body=str_replace('__DIR__',var_export(__DIR__,true),$mc_body);
eval($mc_head.$mc_body);
