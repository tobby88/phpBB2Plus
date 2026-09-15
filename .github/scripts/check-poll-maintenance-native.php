<?php
if (PHP_SAPI!=='cli'||getenv('PHPBB_POLL_MAINTENANCE_NATIVE')!=='1') { echo "Native poll-maintenance checks require an explicitly enabled disposable database.\n"; return; }
date_default_timezone_set('UTC');
$fixture=file_get_contents(__DIR__.'/check-ajax-edit-native.php');$cut=strpos($fixture," foreach(array('member','moderator','root') as \$actor){");
if($cut===false){throw new RuntimeException('Canonical fixture boundary missing');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($fixture,5,$cut-5));
$head=str_replace('codex_ajax_atomic_','codex_poll_atomic_',$head);
$head=str_replace("array('users','sessions','forums','topics','posts','posts_text','user_group','auth_access','search_wordlist','search_wordmatch','config');\$ae_columns", "array('users','sessions','jr_admin_users','topics','vote_desc','vote_results','vote_voters');\$ae_columns",$head);
putenv('PHPBB_AJAX_EDIT_NATIVE=1');putenv('PHPBB_AJAX_EDIT_PORT='.(getenv('PHPBB_POLL_MAINTENANCE_PORT')?:'3306'));putenv('PHPBB_AJAX_EDIT_PASSWORD='.(getenv('PHPBB_POLL_MAINTENANCE_PASSWORD')?:''));
function mm_fixture($actor='root')
{
 global $ae_hook,$ae_queries,$ae_fail,$ae_ack,$ae_write,$ae_failwrite,$userdata,$phpEx;
 $ae_hook=null;$ae_queries=array();$ae_fail='';$ae_ack=false;$ae_write=$ae_failwrite=0;$GLOBALS['ae_error']=null;
 ae_sql('START TRANSACTION');foreach($GLOBALS['ae_tables'] as $s){ae_sql('DELETE FROM fixture_'.$s);}
 foreach(array(8,9) as $id){ae_insert('fixture_users',array('user_id'=>$id,'username'=>"Grüße O'Reilly 😀",'user_level'=>$id===8&&$actor==='root'?ADMIN:USER,'user_active'=>1));}
 ae_insert('fixture_sessions',array('session_id'=>'edit-sid','session_user_id'=>8,'session_logged_in'=>1,'session_admin'=>1));
 if($actor==='delegate'){ae_insert('fixture_jr_admin_users',array('user_id'=>8,'user_jr_admin'=>md5('GeneralDB_Maintenanceadmin_db_maintenance.php')));}
 foreach(array(10=>0,20=>1,30=>1,40=>0) as $id=>$flag){ae_insert('fixture_topics',array('topic_id'=>$id,'topic_vote'=>$flag));}
 foreach(array(1=>10,2=>99,3=>30,4=>40,5=>40) as $id=>$topic){ae_insert('fixture_vote_desc',array('vote_id'=>$id,'topic_id'=>$topic,'vote_text'=>$id===3?'<script>Grüße</script>':'Fixture poll','vote_start'=>1));}
 foreach(array(array(1,1,3),array(1,2,4),array(2,1,9),array(4,1,20),array(5,1,30),array(99,1,40)) as $r){ae_insert('fixture_vote_results',array('vote_id'=>$r[0],'vote_option_id'=>$r[1],'vote_option_text'=>'Retain answer identity','vote_result'=>$r[2]));}
 foreach(array(array(1,9),array(1,DELETED),array(1,77),array(2,9),array(99,9),array(3,9)) as $r){ae_insert('fixture_vote_voters',array('vote_id'=>$r[0],'vote_user_id'=>$r[1],'vote_user_ip'=>'c0000209'));}
 ae_sql('COMMIT');$userdata=array('user_id'=>8,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'edit-sid');
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'edit-sid');$GLOBALS['mm_exception']='';
}
function mm_submit()
{
 try{return dbmtnc_maintain_polls($GLOBALS['db'],$_POST);}catch(RuntimeException $e){$GLOBALS['mm_exception']=$e->getMessage();return 'error';}
}
function mm_revoke($actor){return $actor==='root'?'UPDATE fixture_users SET user_level=0 WHERE user_id=8':'DELETE FROM fixture_jr_admin_users WHERE user_id=8';}
function phpbb_admin_html($value){return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function throw_error($message){throw new RuntimeException($message);}
function lock_db(){throw new RuntimeException('Poll maintenance must not change global board availability');}
$body= <<<'PHP'
 require $phpbb_root_path.'includes/functions_maintenance_polls.php';
 require $phpbb_root_path.'language/lang_english/lang_dbmtnc.php';
 foreach(array('root','delegate') as $actor){
  mm_fixture($actor);$before=ae_snapshot();$out=mm_submit();ae_check(is_array($out),'Native maintenance success: '.$mm_exception.' '.json_encode($ae_error));$after=ae_snapshot();$writes=$ae_write;
  foreach(array('polls_removed'=>1,'options_removed'=>2,'voters_removed'=>2,'voters_anonymized'=>1,'topics_updated'=>3,'review_count'=>3) as $key=>$count){ae_check($out[$key]===$count,'Accurate committed result '.$key);}
  foreach(array('users','sessions','jr_admin_users') as $table){ae_check($before[$table]===$after[$table],'Maintenance never rewrites identity or permissions');}
  $expected_options=array_values(array_filter($before['vote_results'],function($r){return !in_array((int)$r['vote_id'],array(2,99),true);}));
  ae_check($expected_options===$after['vote_results'],'Every retained answer identity/text/result stays unchanged');
  $expected_voters=array();foreach($before['vote_voters'] as $r){if(in_array((int)$r['vote_id'],array(2,99),true)){continue;}if((int)$r['vote_user_id']===77){$r['vote_user_id']=(string)DELETED;}$expected_voters[]=$r;}
  usort($expected_voters,function($a,$b){return strcmp(json_encode($a),json_encode($b));});
  ae_check($expected_voters===$after['vote_voters'],'Preserve surviving and anonymous/deleted voter history including IPs');
  ae_check(array_map('intval',array_column($out['review'],'vote_id'))===array(3,4,5),'Optionless/conflicting source records retained for review');
  $again=mm_submit();ae_check(is_array($again)&&ae_snapshot()===$after,'Repeated completed maintenance has no data changes');
  foreach(array('polls_removed','options_removed','voters_removed','voters_anonymized','topics_updated') as $key){ae_check($again[$key]===0,'No duplicate maintenance count');}
  for($nth=1;$nth<=$writes;$nth++){mm_fixture($actor);$ae_failwrite=$nth;ae_check(mm_submit()==='error'&&ae_snapshot()===$before,'Failure at every write rolls back complete maintenance '.$actor.'/'.$nth);$cases++;}
  foreach(array(false,true) as $ack){mm_fixture($actor);$ae_ack=$ack;$ae_fail=$ack?'':'COMMIT';ae_check(mm_submit()==='error'&&ae_snapshot()===($ack?$after:$before),'Failed/lost acknowledgement leaves only complete before/after state');$ae_ack=false;$ae_fail='';$out=mm_submit();ae_check(is_array($out)&&ae_snapshot()===$after,'Safe current-state retry after failed/lost acknowledgement');$cases++;}
  foreach(array('DELETE FROM fixture_vote_desc','DELETE FROM fixture_vote_results','DELETE FROM fixture_vote_voters','UPDATE fixture_vote_voters','UPDATE fixture_topics','COMMIT') as $boundary){
   foreach(array(ae_revoke('session'),mm_revoke($actor)) as $change){
    mm_fixture($actor);$reached=$blocked=false;$independent=null;
    $ae_hook=function($sql)use($boundary,$change,&$reached,&$blocked,&$independent){if(strpos($sql,$boundary)!==0){return;}$GLOBALS['ae_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query($change)){$e=$GLOBALS['peer']->sql_error();ae_check((int)$e['code']===1205,'Only actual row lock may serialize revocation');$blocked=true;}else{$independent=ae_snapshot();}};
    $out=mm_submit();ae_check($reached,'Actual authority boundary reached');
    if($blocked){ae_check(is_array($out)&&ae_snapshot()===$after,'Locked authority serializes with complete maintenance');$serialized++;}
    else{ae_check($out==='error'&&ae_snapshot()===$independent,'Effective revocation rolls back all previous maintenance writes');}
    $cases++;
   }
  }
  mm_fixture($actor);$reached=false;$ae_hook=function($sql)use(&$reached){if(strpos($sql,'UPDATE fixture_topics')!==0){return;}$GLOBALS['ae_hook']=null;$reached=true;ae_sql('KILL CONNECTION '.(int)mysqli_thread_id($GLOBALS['ae_writer']->db_connect_id));};ae_check(mm_submit()==='error'&&$reached&&ae_snapshot()===$before,'Real connection loss rolls back all maintenance steps');$cases++;
  echo $actor." native poll maintenance transaction/failure/authority checks passed\n";
 }
 foreach(array('case','foreign','logout','inactive','session') as $kind){mm_fixture();ae_sql(ae_revoke($kind));$before=ae_snapshot();ae_check(mm_submit()==='error'&&ae_snapshot()===$before,'Exact current ACP session/account '.$kind);$cases++;}
 mm_fixture();ae_sql('UPDATE fixture_sessions SET session_admin=0');$before=ae_snapshot();ae_check(mm_submit()==='error'&&ae_snapshot()===$before,'Ordinary session cannot run ACP maintenance');$cases++;
 foreach(array('GET','missing','wrong','array') as $kind){mm_fixture();if($kind==='GET'){$_SERVER['REQUEST_METHOD']='GET';}elseif($kind==='missing'){unset($_POST['sid']);}else{$_POST['sid']=$kind==='array'?array('edit-sid'):'wrong';}$before=ae_snapshot();ae_check(mm_submit()==='error'&&ae_snapshot()===$before,'Confirmed POST required '.$kind);$cases++;}
 foreach(array('topic','parent','user','new-poll','removed-poll') as $race){
  mm_fixture();$reached=false;$boundary=$race==='topic'?'DELETE FROM fixture_vote_desc':($race==='parent'?'DELETE FROM fixture_vote_results':($race==='user'?'UPDATE fixture_vote_voters':'UPDATE fixture_topics'));
  $ae_hook=function($sql)use($race,$boundary,&$reached){if(strpos($sql,$boundary)!==0){return;}$GLOBALS['ae_hook']=null;$reached=true;
   if($race==='topic'){ae_insert('fixture_topics',array('topic_id'=>99,'topic_vote'=>1));}
   elseif($race==='parent'){ae_insert('fixture_vote_desc',array('vote_id'=>99,'topic_id'=>20,'vote_text'=>'Restored poll'));}
   elseif($race==='user'){ae_insert('fixture_users',array('user_id'=>77,'user_level'=>USER,'user_active'=>1));}
   elseif($race==='new-poll'){ae_insert('fixture_vote_desc',array('vote_id'=>77,'topic_id'=>20,'vote_text'=>'New poll'));ae_insert('fixture_vote_results',array('vote_id'=>77,'vote_option_id'=>1,'vote_option_text'=>'New answer'));}
   else{ae_sql('DELETE FROM fixture_vote_desc WHERE vote_id=1');}
  };
  $out=mm_submit();ae_check($reached&&is_array($out),'Independent restored/current source state '.$race.' '.$mm_exception);
  if($race==='topic'){ae_check($out['polls_removed']===0&&count(ae_rows('SELECT * FROM fixture_vote_results WHERE vote_id=2'))===1,'Restored topic retains poll results');}
  elseif($race==='parent'){ae_check(count(ae_rows('SELECT * FROM fixture_vote_results WHERE vote_id=99'))===1&&count(ae_rows('SELECT * FROM fixture_vote_voters WHERE vote_id=99'))===1,'Restored parent retains options and voters');}
  elseif($race==='user'){ae_check($out['voters_anonymized']===0&&count(ae_rows('SELECT * FROM fixture_vote_voters WHERE vote_user_id=77'))===1,'Restored account retains vote identity');}
  else{ae_check((int)ae_rows('SELECT topic_vote FROM fixture_topics WHERE topic_id='.($race==='new-poll'?20:10))[0]['topic_vote']===($race==='new-poll'?1:0),'Current poll state determines topic flag');}$cases++;
 }
 foreach($ae_tables as $s){mm_fixture();ae_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=ae_snapshot();ae_check(mm_submit()==='error'&&$mm_exception===$lang['Maintenance_poll_upgrade']&&ae_snapshot()===$before,'Every participant refuses old engine '.$s);ae_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 mm_fixture();ae_sql('ALTER TABLE fixture_vote_voters ROW_FORMAT=COMPACT');$before=ae_snapshot();ae_check(mm_submit()==='error'&&ae_snapshot()===$before,'Old row format refused');ae_sql('ALTER TABLE fixture_vote_voters ROW_FORMAT=DYNAMIC');$cases++;
 mm_fixture();ae_sql('ALTER TABLE fixture_vote_desc DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci');$before=ae_snapshot();ae_check(mm_submit()==='error'&&ae_snapshot()===$before,'Old table default refused');ae_sql('ALTER TABLE fixture_vote_desc DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$cases++;
 mm_fixture();ae_sql('ALTER TABLE fixture_vote_desc MODIFY vote_text TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');$before=ae_snapshot();ae_check(mm_submit()==='error'&&ae_snapshot()===$before,'Wrong text-column collation refused');ae_sql('ALTER TABLE fixture_vote_desc MODIFY vote_text TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$cases++;
 mm_fixture();for($id=100;$id<405;$id++){ae_insert('fixture_vote_desc',array('vote_id'=>$id,'topic_id'=>999,'vote_text'=>'Owned multi-batch orphan'));}$before=ae_snapshot();$ae_failwrite=2;ae_check(mm_submit()==='error'&&ae_snapshot()===$before,'Later candidate batch rolls back earlier hundred-row batch');$ae_failwrite=0;$out=mm_submit();ae_check(is_array($out)&&$out['polls_removed']===306,'Whole keyset-paged operation completes');$cases++;
 mm_fixture();$before=ae_snapshot();$caught=false;try{dbmtnc_poll_batches(new PhpbbAclDatabase($peer),VOTE_DESC_TABLE,'vote_id','1=1');}catch(PhpbbAclException $e){$caught=true;}ae_check($caught&&ae_snapshot()===$before,'Raw unowned helper cannot bypass transaction');$cases++;
 $controller=file_get_contents($phpbb_root_path.'admin/admin_db_maintenance.php');$a=strpos($controller,"case 'check_vote':");$b=strpos($controller,"case 'check_pm':",$a);ae_check($a!==false&&$b>$a,'Actual controller branch');$branch='switch($function){'.substr($controller,$a,$b-$a).'}';
 foreach(array('english','german') as $locale){require $phpbb_root_path.'language/lang_'.$locale.'/lang_dbmtnc.php';
  mm_fixture();$function='check_vote';ob_start();eval($branch);$html=ob_get_clean();ae_check(strpos($html,sprintf($lang['Maintenance_poll_summary'],1,2,2,1,3))!==false&&strpos($html,'&lt;script&gt;Grüße&lt;/script&gt;')!==false&&strpos($html,'<script>')===false,'Actual localized controller reports only committed counts and escapes review');$cases++;
  mm_fixture();$ae_ack=true;$caught='';ob_start();try{eval($branch);}catch(RuntimeException $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}$ae_ack=false;ae_check($caught===$lang['Maintenance_poll_unconfirmed']&&strpos($html,sprintf($lang['Maintenance_poll_summary'],1,2,2,1,3))===false,'Lost acknowledgement never renders success summary');$cases++;
 }
 foreach(array('commit-without-begin','write-without-begin','double-begin','write-after-commit') as $state){
  mm_fixture();$before=ae_snapshot();$owner=new attach_mutation_lock($db);ae_check($owner->acquired,'Lifecycle owns maintenance connection');$tx=new PhpbbPollMaintenanceDatabase($owner->connection,'Maintenance_poll_unconfirmed');$caught=false;
  try{
   if($state==='double-begin'){$tx->begin();$tx->sql_query('UPDATE fixture_topics SET topic_vote=1 WHERE topic_id=10');$tx->begin();}
   elseif($state==='commit-without-begin'){$tx->commit();}
   else{if($state==='write-after-commit'){$tx->begin();$tx->commit();}$tx->sql_query('DELETE FROM fixture_vote_desc');}
  }catch(PhpbbAclException $e){$caught=true;}finally{$tx->rollback();$owner->release();}
  ae_check($caught&&ae_snapshot()===$before,'Invalid transaction lifecycle cannot implicitly commit or mutate: '.$state);$cases++;
 }
 echo 'Native poll maintenance: '.$cases.' failure/session/storage/controller cases, '.$serialized." serialized changes passed.\n";
}finally{$ae_hook=null;$ae_fail='';$ae_ack=false;$ae_failwrite=0;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
PHP;
eval($head.$body);
