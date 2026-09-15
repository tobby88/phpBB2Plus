<?php
// Real canonical InnoDB fixtures; no forum configuration or live database.
if(PHP_SAPI!=='cli'||getenv('PHPBB_INTERACTIVE_NATIVE')!=='1'){echo "Interactive storage checks require an explicitly enabled disposable database.\n";return;}
$ix_kind=isset($argv[1])?$argv[1]:'';
if(!in_array($ix_kind,array('ajax','vote'),true)){throw new RuntimeException('Choose ajax or vote');}
$ix_prefix=$ix_kind==='ajax'?'ae':'pv';$ix_env=$ix_kind==='ajax'?'AJAX_EDIT':'VOTE';
putenv('PHPBB_'.$ix_env.'_NATIVE=1');putenv('PHPBB_'.$ix_env.'_PORT='.(getenv('PHPBB_INTERACTIVE_PORT')?:'3306'));putenv('PHPBB_'.$ix_env.'_PASSWORD='.(getenv('PHPBB_INTERACTIVE_PASSWORD')?:''));
$ix_source=file_get_contents(__DIR__.($ix_kind==='ajax'?'/check-ajax-edit-native.php':'/check-poll-vote-native.php'));
$ix_cut=strpos($ix_source,$ix_kind==='ajax'?" foreach(array('member','moderator','root') as \$actor){":" foreach(array('guest','member','moderator','root') as \$actor){");
if($ix_cut===false){throw new RuntimeException('Canonical fixture boundary missing');}
$ix_head=str_replace('__DIR__',var_export(__DIR__,true),substr($ix_source,5,$ix_cut-5));
$ix_head=str_replace(array('codex_ajax_atomic_','codex_vote_atomic_'),'codex_interactive_',$ix_head);
class InteractiveNativeConnection {
 var $inner;var $db_connect_id;var $committed=false;
 function __construct($inner){$this->inner=$inner;$this->db_connect_id=$inner->db_connect_id;}
 function __call($name,$args){return call_user_func_array(array($this->inner,$name),$args);}
 function sql_query($sql,$transaction=false){
  if($this->committed){$GLOBALS['ix_after_queries'][]=$sql;}
  $r=$this->inner->sql_query($sql,$transaction);
  if($sql==='COMMIT'&&$r){$this->committed=true;if(is_callable($GLOBALS['ix_after'])){call_user_func($GLOBALS['ix_after'],$this);}}
  return $r;
 }
}
class InteractiveNativeDatabase {
 var $inner;var $dbname;
 function __construct($inner){$this->inner=$inner;$this->dbname=$inner->dbname;}
 function __call($name,$args){return call_user_func_array(array($this->inner,$name),$args);}
 function sql_query($sql,$transaction=false){
  $failure=isset($GLOBALS['ix_read_failure'])?$GLOBALS['ix_read_failure']:'';
  if(($failure==='state'&&strpos($sql,'SELECT vd.*,')===0)||($failure==='results'&&strpos($sql,'SELECT vd.vote_id,')===0)){return false;}
  if($failure==='empty'&&strpos($sql,'SELECT vd.vote_id,')===0){$sql=str_replace('WHERE vd.topic_id','WHERE 1=0 AND vd.topic_id',$sql);}
  return $this->inner->sql_query($sql,$transaction);
 }
 function sql_dedicated_connection(){return new InteractiveNativeConnection($this->inner->sql_dedicated_connection());}
}
function ix_fixture($actor){call_user_func($GLOBALS['ix_prefix'].'_fixture',$actor);$GLOBALS['ix_after']=null;$GLOBALS['ix_after_queries']=array();$GLOBALS['ix_read_failure']='';}
function ix_submit($field='text'){
 try{return $GLOBALS['ix_kind']==='ajax'?phpbb_ajax_edit_post($GLOBALS['ix_database'],10,$field,ae_value($field)):phpbb_cast_poll_vote($GLOBALS['ix_database'],100,1);}
 catch(RuntimeException $e){return 'error';}
}
function ix_success($out){return $GLOBALS['ix_kind']==='ajax'?is_array($out):$out==='Vote_cast';}
$ix_body= <<<'PHP'
 $ix_database=new InteractiveNativeDatabase($db);$ix_after=null;$ix_after_queries=array();
 $ix_actors=$ix_kind==='ajax'?array('member','moderator','root'):array('guest','member','moderator','root');
 foreach($ix_actors as $actor){foreach($ix_kind==='ajax'?array('subject','text'):array('vote') as $field){
  ix_fixture($actor);ix_check(ix_success(ix_submit($field)),'Baseline complete operation');$after=ix_snapshot();
  foreach(array('session','permission','disconnect') as $change){
   ix_fixture($actor);$reached=false;
   $ix_after=function($connection)use($change,$actor,&$reached){
    $reached=true;
    if($change==='disconnect'){ix_sql('KILL CONNECTION '.(int)mysqli_thread_id($connection->db_connect_id));}
    else{ix_sql(ix_revoke($change==='session'?'session':($actor==='root'?'role':($actor==='moderator'?'grant':'read'))));}
   };
   $out=ix_submit($field);$actual=ix_snapshot();$expected=$after;
   foreach(array('users','sessions','auth_access','forums') as $s){$expected[$s]=$actual[$s];}
   ix_check($reached&&$actual===$expected,'Confirmed operation retains exactly its committed data');
   if(getenv('PHPBB_INTERACTIVE_PROBE')==='1'){echo $ix_kind.'/'.$actor.'/'.$field.'/'.$change.': committed=true; response='.(ix_success($out)?'success':'error')."\n";}
   else{ix_check(ix_success($out),'Acknowledged commit cannot become a failed mutation after '.$change);ix_check(!$ix_after_queries,'No fallible SQL after confirmed commit');}
   $cases++;
  }
 }}
 if(getenv('PHPBB_INTERACTIVE_PROBE')!=='1'){
  if($ix_kind==='vote'){
   $ajax=file_get_contents($phpbb_root_path.'ajax.php');$a=strpos($ajax,'function ajax_scalar_value(');$b=strpos($ajax,'// Get SID and check it',$a);ix_check($a!==false&&$b>$a,'Actual request decoding');eval(substr($ajax,$a,$b-$a));
   $a=strpos($ajax,"\t// Get topic_id",strpos($ajax,'// Voting/Viewing of polls'));$b=strpos($ajax,'$vote_options = count($vote_info);',$a);ix_check($a!==false&&$b>$a,'Actual vote and results transport');$branch=substr($ajax,$a,$b-$a);$real_db=$db;$db=$ix_database;
   try{foreach(array('english','german') as $locale){require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';foreach(array('Vote_cast','Already_voted','view','ack') as $status){foreach(array('state','results','empty') as $failure){
    ix_fixture('member');if($status==='Already_voted'){ix_check(ix_success(ix_submit()),'Prior recorded vote');}$before=ix_snapshot();$ix_read_failure=$failure;
    $mode=$status==='view'?'view_poll':'vote_poll';$HTTP_POST_VARS=array('t'=>100,'vote_option_id'=>1);$HTTP_GET_VARS=array();$topic_id=100;$response=null;
    if($status==='ack'){$pv_ack=true;}
    try{eval($branch);}catch(PollNativeResponse $e){$response=$e->value;}
    ix_check(is_array($response)&&$response['result']===AJAX_ERROR,'Result refresh failure uses normal AJAX notice');
    if(in_array($status,array('Vote_cast','Already_voted'),true)){ix_check($response['error_msg']===$lang[$status].' '.$lang['Poll_results_unavailable'],'Confirmed vote distinguished from refresh failure');}
    else{ix_check(strpos($response['error_msg'],$lang['Poll_results_unavailable'])===false,'No false confirmation for view or unconfirmed commit');}
    $ix_read_failure='';$pv_ack=false;
    if($status==='view'){ix_check(ix_snapshot()===$before,'Read-only failure never casts a vote');}else{$saved=ix_snapshot();ix_check(count($saved['vote_voters'])===2&&ix_submit()==='Already_voted'&&ix_snapshot()===$saved,'Refresh failure never duplicates or discards vote');}$cases++;
   }}}}finally{$db=$real_db;$ix_read_failure='';$pv_ack=false;}
   $notice=$lang['Poll_results_unavailable'];unset($lang['Poll_results_unavailable']);
   ix_check(strpos(phpbb_poll_response_error('refresh failure','Vote_cast'),$lang['Vote_cast'])===0,'Older in-flight language array retains confirmation without warnings');$lang['Poll_results_unavailable']=$notice;$cases++;
  }
  foreach(array('commit-without-begin','write-without-begin','double-begin','write-after-rollback','write-after-commit') as $state){
   ix_fixture('root');$before=ix_snapshot();$owner=new attach_mutation_lock($ix_database);ix_check($owner->acquired,'Lifecycle owns connection');
   $tx=$ix_kind==='ajax'?new PhpbbAjaxStorageDatabase($owner->connection):new PhpbbPollVoteDatabase($owner->connection,100);
   if($ix_kind==='ajax'){$tx->context=array('post_id'=>10,'topic_id'=>100,'forum_id'=>3,'poster_id'=>9);}
   $write=$ix_kind==='ajax'?"UPDATE fixture_topics SET topic_title='Lifecycle draft' WHERE topic_id=100":'UPDATE fixture_vote_results SET vote_result=9 WHERE vote_option_id=1';$caught=false;
   try{
    if($state==='commit-without-begin'){$tx->commit();}
    elseif($state==='double-begin'){$tx->begin();$tx->sql_query($write);$tx->begin();}
    else{if($state==='write-after-rollback'){$tx->begin();$tx->rollback();}if($state==='write-after-commit'){$tx->begin();$tx->commit();}$tx->sql_query($write);}
   }catch(RuntimeException $e){$caught=true;}finally{$tx->rollback();$owner->release();}
   ix_check($caught&&ix_snapshot()===$before,'Invalid lifecycle leaves no partial/implicit commit: '.$state);$cases++;
  }
  $other=$schema.'_other';ix_check($control->sql_query('CREATE DATABASE '.$other),'Owned comparison schema');
  try{
   ix_check($control->sql_query('CREATE TABLE '.$other.'.fixture_users (username VARCHAR(20)) ENGINE=MyISAM DEFAULT CHARSET=latin1'),'Unrelated legacy metadata');
   $variants=array(array('fixture_sessions','',true),array('fixture_sessions','ENGINE=MyISAM',false),array('fixture_sessions','ROW_FORMAT=COMPACT',false),array('fixture_sessions','DEFAULT CHARACTER SET latin1',false),array('fixture_sessions','MODIFY session_id CHAR(32) CHARACTER SET latin1 NOT NULL',false),array('fixture_sessions','MODIFY session_id CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL',false));
   if($ix_kind==='ajax'){foreach(array('utf8mb4 COLLATE utf8mb4_bin'=>true,'utf8mb4 COLLATE utf8mb4_unicode_ci'=>true,'utf8mb4 COLLATE utf8mb4_general_ci'=>false,'latin1'=>false) as $definition=>$allowed){$variants[]=array('fixture_search_wordlist','MODIFY word_text VARCHAR(50) CHARACTER SET '.$definition.' NOT NULL',$allowed);}}
   foreach($variants as $variant){
    list($table,$ddl,$allowed)=$variant;
    ix_sql('ALTER TABLE fixture_sessions ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    if($ix_kind==='ajax'){ix_sql('ALTER TABLE fixture_search_wordlist CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
    ix_fixture('root');if($ddl!==''){ix_sql('ALTER TABLE '.$table.' '.$ddl);}$before=ix_snapshot();
    $lock=new attach_mutation_lock($ix_database);$tx=$ix_kind==='ajax'?new PhpbbAjaxStorageDatabase($lock->connection):new PhpbbPollVoteDatabase($lock->connection,100);$ok=false;
    try{$tx->begin();$ok=true;}catch(RuntimeException $e){}finally{$tx->rollback();$lock->release();}
    ix_check($ok===$allowed&&ix_snapshot()===$before,'Exact storage policy without data mutations: '.$ddl);
    $query='';foreach($GLOBALS[$ix_prefix.'_queries'] as $sql){if(strpos($sql,'SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.')===0&&strpos($sql,"AND TABLE_NAME='".$table."'")!==false){$query=$sql;}}
    $scope="c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='".$table."'";
    ix_check($query!==''&&strpos($query,$scope)!==false,'Metadata scan explicitly bounds current schema/table');
    ix_check(ix_rows($query)===ix_rows(str_replace($scope,'c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME',$query)),'Equivalent correlated and literal column policy');$cases++;
   }
  }finally{$control->sql_query('DROP DATABASE '.$other);}
 }
 echo $ix_kind.' native interactive storage: '.$cases." acknowledgement/lifecycle/metadata cases passed.\n";
}finally{$ix_after=null;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
PHP;
$ix_body=str_replace(array('ix_check(','ix_snapshot(','ix_sql(','ix_revoke(','ix_rows('),array($ix_prefix.'_check(',$ix_prefix.'_snapshot(',$ix_prefix.'_sql(',$ix_prefix.'_revoke(',$ix_prefix.'_rows('),$ix_body);
eval($ix_head.$ix_body);
