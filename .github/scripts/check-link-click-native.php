<?php
// Real controller, canonical schema and independent MySQL/MariaDB connections.
// Only response headers/exit are captured; no live links, HTTP, mail or accounts.
if(PHP_SAPI!=='cli'||getenv('PHPBB_LINK_CLICK_NATIVE')!=='1'){echo "Link clicks require explicitly enabled disposable storage.\n";return;}
if(!in_array('mail',array_map('trim',explode(',',ini_get('disable_functions'))),true)){throw new RuntimeException('Real mail must be disabled');}
function lc_controller_source($root){$source=file_get_contents($root.'links.php');$a=strpos($source,'// Count and forward');$b=strpos($source,'// Output the basic page',$a);if($a===false||$b<=$a){throw new RuntimeException('Actual public click controller boundary');}$body=substr($source,$a,$b-$a);if(substr_count($body,'header(')!==4){throw new RuntimeException('All four actual headers must be captured');}return str_replace('header(','lc_header(',$body);}
if(getenv('PHPBB_LINK_CLICK_WORKER')==='1'){
 define('IN_PHPBB',true);$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';$phpEx='php';$table_prefix='fixture_';
 require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'db/mysqli.php';
 $fixture=getenv('PHPBB_LINK_CLICK_FIXTURE');$port=getenv('PHPBB_LINK_CLICK_PORT');
 if(!preg_match('/^codex_link_click_[a-f0-9]{16}$/D',$fixture)||!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Worker only accepts parent-owned loopback fixtures');}
 $db=new sql_db('127.0.0.1:'.$port,'root',getenv('PHPBB_LINK_CLICK_PASSWORD'),$fixture,false);if(!$db->db_connect_id){throw new RuntimeException('Worker fixture connection');}
 $_SERVER['REQUEST_METHOD']='GET';$_GET=array('action'=>'go','link_id'=>'28');$user_ip='7f000001';$lc_headers=array();echo "ready\n";flush();
 try{try{eval(lc_controller_source($phpbb_root_path));$result=false;}catch(LinkClickRedirect$exit){$result=$exit->getMessage();}echo json_encode(array('target'=>$result));}finally{$db->sql_close();}return;
}
function lc_classes(){
 class LinkClickNativeDatabase extends KpDatabase{
  function sql_dedicated_connection(){
   $GLOBALS['lc_factories']++;
   if($GLOBALS['kp_factory']==='alias'){return new KpOwnerConnection($this);}
   if($GLOBALS['kp_factory']==='busy'){return new KpOwnerConnection($GLOBALS['peer']);}
   $candidate=parent::sql_dedicated_connection();
   if(is_object($candidate)&&$candidate!==$this){
    $r=$candidate->connection->sql_query('SELECT CONNECTION_ID() id');$row=$candidate->connection->sql_fetchrow($r);$candidate->connection->sql_freeresult($r);$GLOBALS['lc_ids'][]=(int)$row['id'];
    if($GLOBALS['kp_factory']==='autocommit'){$candidate->connection->sql_query('SET autocommit=0');}
    if($GLOBALS['kp_factory']==='foreign'){$candidate->connection->sql_query('USE information_schema');}
   }
   return$candidate;
  }
 }
}
class LinkClickRedirect extends RuntimeException{}
function lc_header($header,$replace=true,$code=null){$GLOBALS['lc_headers'][]=array($header,$replace,$code);if(strpos($header,'Location: ')===0){throw new LinkClickRedirect(substr($header,10));}}
function lc_reset(){
 lr_reset();kp_sql("INSERT INTO fixture_links(link_id,link_title,link_desc,link_category,link_url,link_joined,link_hits,user_id,user_ip,last_user_ip,link_active) VALUES(28,'Synthetic','Keep',1,'https://old.example.invalid/?a=1&b=2',123,7,4,'7f000001','7f000002',1)");
 $_SERVER['REQUEST_METHOD']='GET';$_GET=array('action'=>'go','link_id'=>'28');$GLOBALS['user_ip']='7f000001';$GLOBALS['lc_fault']=null;$GLOBALS['lc_factories']=0;
}
function lc_run(){
 global$db,$phpbb_root_path,$phpEx,$user_ip,$lc_body,$kp_hook,$lc_fault,$lc_headers;
 $lc_headers=array();$GLOBALS['lc_ids']=array();$owned=array();$offset=count($GLOBALS['kp_owner_connections']);$queries=array();
 $kp_hook=function($phase,$sql,$owner)use(&$owned,&$queries){
  if($phase==='before'){$queries[]=$sql;if($sql==='SET SESSION lock_wait_timeout=1'){$owned[spl_object_hash($owner)]=true;}}
  return is_callable($GLOBALS['lc_fault'])?call_user_func($GLOBALS['lc_fault'],$phase,$sql,$owner):null;
 };
 try{try{eval($lc_body);$result=false;}catch(LinkClickRedirect$exit){$result=$exit->getMessage();}}
 finally{
  $kp_hook=null;
  // A rejected factory result is deliberately not closed by the service until
  // ownership is established. Drop this fixture's artificial strong references
  // and prove the otherwise unreferenced connection is actually reclaimed.
  for($i=$offset;$i<count($GLOBALS['kp_owner_connections']);$i++){$owner=$GLOBALS['kp_owner_connections'][$i];if(isset($owned[spl_object_hash($owner)])){kp_check(!$owner->connection->db_connect_id,'Every accepted owner explicitly closed before response');}$GLOBALS['kp_owner_connections'][$i]=null;}
  $GLOBALS['kp_owner_connections']=array_values(array_filter($GLOBALS['kp_owner_connections']));unset($owner);
  if($GLOBALS['lc_ids']){kp_check(!kp_rows('SELECT ID FROM information_schema.PROCESSLIST WHERE ID IN ('.implode(',',$GLOBALS['lc_ids']).')'),'Rejected fresh and accepted owned connections do not leak');}
 }
 $GLOBALS['lc_queries']=$queries;return$result;
}
function lc_state(){return kp_rows('SELECT * FROM fixture_links ORDER BY link_id');}
function lc_suite(){
 global$kp_source,$db,$peer,$kp_factory,$lc_body,$lc_fault,$user_ip;
 require$kp_source.'includes/functions_link_click.php';
 $lc_body=lc_controller_source($kp_source);
 $cases=$failures=0;$url='https://old.example.invalid/?a=1&b=2';
 foreach(array('GET','HEAD','POST','PUT','DELETE','')as$method){lc_reset();$_SERVER['REQUEST_METHOD']=$method;$before=lc_state();$result=lc_run();kp_check($result===(in_array($method,array('GET','HEAD'),true)?$url:false),'Actual HTTP method decision '.$method);kp_check($GLOBALS['lc_headers'][0]===array('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0',true,null)&&$GLOBALS['lc_headers'][2]===array('Referrer-Policy: no-referrer',true,null),'Actual headers prevent cached redirects and referrer leakage');if($method==='GET'){kp_check(lc_state()[0]['link_hits']==='8','GET counts once');}else{kp_check(lc_state()===$before,'Non-GET never counts');}$cases++;}
 foreach(array(null,array('28'),true,false,28.5,'0','-1','028','28e0','28junk','28 ',' 28','16777216','4294967295')as$value){lc_reset();$_GET['link_id']=$value;$before=lc_state();kp_check(lc_run()===false&&$GLOBALS['lc_factories']===0&&lc_state()===$before,'Malformed ID never selects another row');$cases++;}
 foreach(array(array('go'),'GO',true,'other')as$value){lc_reset();$_GET['action']=$value;kp_check(lc_run()===false&&$GLOBALS['lc_factories']===0&&!$GLOBALS['lc_headers'],'Malformed action is not a redirect route');$cases++;}
 foreach(array('same-ip','case-ip','invalid-ip','array-ip','missing','inactive','script','credentials','control','entity-url','http-url')as$kind){
  lc_reset();$expected=$url;$count=true;
  if($kind==='same-ip'){kp_sql("UPDATE fixture_links SET last_user_ip='7f000001'");$count=false;}
  elseif($kind==='case-ip'){$user_ip='ABCDEF12';kp_sql("UPDATE fixture_links SET last_user_ip='abcdef12'");$count=false;}
  elseif($kind==='invalid-ip'){$user_ip='invalid';$count=false;}elseif($kind==='array-ip'){$user_ip=array('7f000001');$count=false;}
  elseif($kind==='missing'){kp_sql('DELETE FROM fixture_links');$expected=false;$count=false;}elseif($kind==='inactive'){kp_sql('UPDATE fixture_links SET link_active=0');$expected=false;$count=false;}
  else{$value=$kind==='script'?'javascript:alert(1)':($kind==='credentials'?'https://name:pass@example.invalid/':($kind==='control'?"https://example.invalid/\r\nX-Test:bad":($kind==='entity-url'?'https://example.invalid/?a=1&amp;b=2':'http://example.invalid/')));kp_sql("UPDATE fixture_links SET link_url='".$peer->sql_escape($value)."'");$expected=$kind==='entity-url'?'https://example.invalid/?a=1&b=2':($kind==='http-url'?$value:false);$count=$expected!==false;}
  $before=lc_state();kp_check(lc_run()===$expected,'Current destination/IP decision '.$kind);$after=lc_state();if($count){$before[0]['link_hits']='8';$before[0]['last_user_ip']=strtolower($user_ip);}kp_check($after===$before,'Only a valid new-IP visit changes the exact expected fields '.$kind);$cases++;
 }
 foreach(array('0'=>'1','9'=>'10','99'=>'100','2147483647'=>'2147483648','4294967294'=>'4294967295','4294967295'=>'4294967295')as$hits=>$next){lc_reset();kp_sql('UPDATE fixture_links SET link_hits='.$hits);kp_check(lc_run()===$url&&lc_state()[0]['link_hits']===(string)$next,'Unsigned count exact/saturating: '.$hits);$cases++;}
 lc_reset();kp_check(lc_run()===$url&&lc_run()===$url&&lc_state()[0]['link_hits']==='8','Sequential same-IP visits do not count twice');$cases++;
 // Two actual request processes start while the parent holds only this row.
 // Both must redirect, and the second must see the first committed IP/count.
 lc_reset();$workers=array();$old_worker=getenv('PHPBB_LINK_CLICK_WORKER');$old_fixture=getenv('PHPBB_LINK_CLICK_FIXTURE');$old_port=getenv('PHPBB_LINK_CLICK_PORT');putenv('PHPBB_LINK_CLICK_WORKER=1');putenv('PHPBB_LINK_CLICK_FIXTURE='.$db->dbname);putenv('PHPBB_LINK_CLICK_PORT='.$GLOBALS['port']);kp_sql('START TRANSACTION');kp_sql('SELECT * FROM fixture_links WHERE link_id=28 FOR UPDATE');
 try{
  $command=escapeshellarg(PHP_BINARY).' -d date.timezone=UTC -d disable_functions=mail';
  if(DIRECTORY_SEPARATOR==='\\'){$command.=' -n -d extension_dir='.escapeshellarg(ini_get('extension_dir')).' -d extension=php_mysqli.dll -d extension=php_openssl.dll';}
  $command.=' '.escapeshellarg(__FILE__);
  for($i=0;$i<2;$i++){$pipes=array();$process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));kp_check(is_resource($process),'Start actual concurrent request worker');fclose($pipes[0]);$workers[]=array($process,$pipes);kp_check(fgets($pipes[1])==="ready\n",'Worker ready before releasing parent row lock');}
  kp_sql('COMMIT');foreach($workers as&$worker){$out=stream_get_contents($worker[1][1]);$err=stream_get_contents($worker[1][2]);fclose($worker[1][1]);fclose($worker[1][2]);$code=proc_close($worker[0]);$worker[0]=null;kp_check($code===0&&$err===''&&json_decode($out,true)===array('target'=>$url),'Both actual concurrent controllers redirect successfully');}unset($worker);kp_check(lc_state()[0]['link_hits']==='8','Concurrent same-IP requests count exactly once');$cases++;
 }finally{kp_sql('ROLLBACK');foreach($workers as$worker){if(is_resource($worker[0])){foreach(array(1,2)as$n){if(is_resource($worker[1][$n])){fclose($worker[1][$n]);}}proc_terminate($worker[0]);proc_close($worker[0]);}}putenv('PHPBB_LINK_CLICK_WORKER'.($old_worker===false?'':'='.$old_worker));putenv('PHPBB_LINK_CLICK_FIXTURE'.($old_fixture===false?'':'='.$old_fixture));putenv('PHPBB_LINK_CLICK_PORT'.($old_port===false?'':'='.$old_port));}
 foreach(array('deactivate','delete','url','same-ip')as$change){lc_reset();$fired=false;$lc_fault=function($phase,$sql)use(&$fired,$change){if($phase!=='before'||$fired||$sql!=='SELECT * FROM fixture_links WHERE link_id=28 FOR UPDATE'){return;}$fired=true;if($change==='deactivate'){kp_sql('UPDATE fixture_links SET link_active=0');}elseif($change==='delete'){kp_sql('DELETE FROM fixture_links');}elseif($change==='url'){kp_sql("UPDATE fixture_links SET link_url='https://new.example.invalid/'");}else{kp_sql("UPDATE fixture_links SET link_hits=link_hits+1,last_user_ip='7f000001'");}};$result=lc_run();$rows=lc_state();kp_check($fired&&$result===($change==='url'?'https://new.example.invalid/':($change==='same-ip'?$url:false)),'Peer change before locked decision is current '.$change);kp_check(!$rows||$rows[0]['link_hits']===($change==='url'||$change==='same-ip'?'8':'7'),'Concurrent same-IP count deduplicates/no inactive count');$cases++;}
 lc_reset();$timeout=kp_rows('SELECT @@SESSION.innodb_lock_wait_timeout seconds')[0]['seconds'];kp_sql('SET SESSION innodb_lock_wait_timeout=1');$locked=false;$lc_fault=function($phase,$sql)use(&$locked){if($phase==='after'&&!$locked&&$sql==='SELECT * FROM fixture_links WHERE link_id=28 FOR UPDATE'){$r=$GLOBALS['peer']->sql_query("UPDATE fixture_links SET link_active=0 WHERE link_id=28");$error=$GLOBALS['peer']->sql_error();kp_check($r===false&&(int)$error['code']===1205,'Peer cannot change decision row until commit');$locked=true;}};try{kp_check(lc_run()===$url&&$locked&&lc_state()[0]['link_hits']==='8','Native decision row is locked');$cases++;}finally{$lc_fault=null;kp_sql('SET SESSION innodb_lock_wait_timeout='.(int)$timeout);}
 lc_reset();$mutex='attachment:'.md5($db->dbname."\0".ATTACHMENTS_TABLE);kp_check(kp_rows("SELECT GET_LOCK('".$mutex."',0) acquired")[0]['acquired']==='1','Peer holds actual forum writer mutex');try{kp_check(lc_run()===$url&&lc_state()[0]['link_hits']==='8','Link click does not acquire unrelated forum-wide mutex');$cases++;}finally{kp_sql("SELECT RELEASE_LOCK('".$mutex."')");}
 lc_reset();kp_sql('START TRANSACTION');kp_sql("UPDATE fixture_links SET link_title='Peer pending' WHERE link_id=28");try{kp_check(lc_run()===$url&&lc_state()[0]['link_hits']==='7','A real writer lock timeout preserves navigation through an independent committed read');$cases++;}finally{kp_sql('ROLLBACK');}
 // Every real primary query fails before/after execution, including lost COMMIT
 // acknowledgements. Navigation may recover, the counter is never retried.
 lc_reset();kp_check(lc_run()===$url,'Record actual successful primary SQL');$primary=$GLOBALS['lc_queries'];
 foreach(array('before','after')as$phase){foreach($primary as$position=>$statement){lc_reset();$at=0;$lc_fault=function($event,$sql)use($phase,$position,&$at){if($event===$phase){return++$at===$position+1?false:null;}};kp_check(lc_run()===$url,'Optional count fault still follows a fresh valid destination '.$phase.' '.$position);$expected=$phase==='after'&&$statement==='COMMIT'?'8':'7';kp_check(lc_state()[0]['link_hits']===$expected,'Count rollback/lost acknowledgement is exact and not retried '.$phase.' '.$position);$failures++;}}
 lc_reset();$_SERVER['REQUEST_METHOD']='HEAD';kp_check(lc_run()===$url,'Record actual read-only SQL');$reads=$GLOBALS['lc_queries'];
 foreach(array('before','after')as$phase){foreach($reads as$position=>$statement){lc_reset();$_SERVER['REQUEST_METHOD']='HEAD';$at=0;$before=lc_state();$lc_fault=function($event,$sql)use($phase,$position,&$at){return$event===$phase&&++$at===$position+1?false:null;};kp_check(lc_run()===($statement==='ROLLBACK'?$url:false)&&lc_state()===$before,'No redirect without an independently confirmed read; cleanup is optional '.$phase.' '.$position);$failures++;}}
 foreach(array('inactive','delete','changed-url')as$change){lc_reset();$fired=false;$lc_fault=function($phase,$sql)use(&$fired,$change){if($phase!=='before'||$fired||strpos($sql,'UPDATE fixture_links SET ')!==0){return;}$fired=true;return false;};$result=lc_run();kp_check($result===$url&&$fired&&lc_state()[0]['link_hits']==='7','Failed count remains optional');$lc_fault=null;
  // Change is committed before the fallback reader's SELECT, after the failed
  // writer has released its row lock, never by mutating a borrowed connection.
  lc_reset();$failed=false;$changed=false;$lc_fault=function($phase,$sql)use(&$failed,&$changed,$change){if($phase==='before'&&strpos($sql,'UPDATE fixture_links SET ')===0){$failed=true;return false;}if($failed&&!$changed&&$phase==='before'&&strpos($sql,'SELECT link_id,link_url,link_active FROM ')===0){$changed=true;if($change==='inactive'){kp_sql('UPDATE fixture_links SET link_active=0');}elseif($change==='delete'){kp_sql('DELETE FROM fixture_links');}else{kp_sql("UPDATE fixture_links SET link_url='https://new.example.invalid/'");}}};kp_check(lc_run()===($change==='changed-url'?'https://new.example.invalid/':false)&&$changed,'Fallback confirms a new current target '.$change);$cases++;
 }
 foreach(array('hits','ip','url','active')as$drift){lc_reset();$assignment=$drift==='hits'?'NEW.link_hits=999':($drift==='ip'?"NEW.last_user_ip='7f000099'":($drift==='url'?"NEW.link_url='https://drift.example.invalid/'":'NEW.link_active=0'));kp_sql('CREATE TRIGGER fixture_click_drift BEFORE UPDATE ON fixture_links FOR EACH ROW SET '.$assignment);try{$before=lc_state();kp_check(lc_run()===$url&&lc_state()===$before,'Trigger drift rolls back and independently navigates '.$drift);$cases++;}finally{kp_sql('DROP TRIGGER fixture_click_drift');}}
 foreach(array('engine','collation')as$format){lc_reset();kp_sql($format==='engine'?'ALTER TABLE fixture_links ENGINE=MyISAM':'ALTER TABLE fixture_links DEFAULT COLLATE utf8mb4_bin');try{$before=lc_state();kp_check(lc_run()===$url&&lc_state()===$before,'Legacy format is navigation-only until migrated '.$format);$cases++;}finally{kp_sql('ALTER TABLE fixture_links ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT COLLATE utf8mb4_unicode_ci');}}
 foreach(array('','STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES','ANSI_QUOTES','TRADITIONAL')as$mode){lc_reset();$set=false;$lc_fault=function($phase,$sql,$owner)use($mode,&$set){if($phase==='before'&&!$set&&strpos($sql,'SET SESSION sql_mode=')===0){$set=true;kp_check($owner->connection->sql_query("SET SESSION sql_mode='".$mode."'")!==false,'Inherited SQL mode');}};kp_check(lc_run()===$url&&$set&&lc_state()[0]['link_hits']==='8','Native SQL mode '.$mode);$cases++;}
 kp_sql('CREATE TABLE fixture_lc_caller(id INT PRIMARY KEY,marker INT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC');kp_sql('INSERT INTO fixture_lc_caller VALUES(1,7),(2,7)');
 foreach(array('normal','missing','borrow','alias','busy','autocommit','foreign')as$factory){lc_reset();kp_check($db->sql_query('START TRANSACTION')&&$db->sql_query('UPDATE fixture_lc_caller SET marker=8 WHERE id=1'),'Caller owns pending work');if($factory==='busy'){kp_sql('START TRANSACTION');kp_sql('UPDATE fixture_lc_caller SET marker=9 WHERE id=2');}
  $kp_factory=$factory;try{$result=lc_run();kp_check($result===($factory==='normal'?$url:false),'Factory guard '.$factory);$r=$db->sql_query('SELECT marker FROM fixture_lc_caller WHERE id=1');$pending=$db->sql_fetchrow($r);$db->sql_freeresult($r);kp_check($pending['marker']==='8'&&$db->db_connect_id&&$peer->db_connect_id,'Caller pending work/connections preserved '.$factory);if($factory==='busy'){kp_check(kp_rows('SELECT marker FROM fixture_lc_caller WHERE id=2')[0]['marker']==='9','Borrowed pending peer work remains owned by peer');}$cases++;}finally{if($factory==='busy'){kp_sql('ROLLBACK');}$kp_factory='normal';$db->sql_query('ROLLBACK');}kp_check(kp_rows('SELECT marker FROM fixture_lc_caller WHERE id=1')[0]['marker']==='7','Owner never commits unrelated caller work');
 }
 lc_reset();$kp_factory='busy';kp_sql('START TRANSACTION');kp_sql("UPDATE fixture_links SET link_title='Peer pending'");$lc_fault=function($phase,$sql){return$phase==='before'&&strpos($sql,'SELECT DATABASE()')===0?false:null;};try{kp_check(lc_run()===false&&lc_state()[0]['link_title']==='Peer pending'&&$peer->db_connect_id,'Even a failed guard must not roll back or close borrowed pending work');$cases++;}finally{$kp_factory='normal';$lc_fault=null;kp_sql('ROLLBACK');}
 lc_reset();kp_check($db->sql_query('START TRANSACTION'),'Caller snapshot starts');$r=$db->sql_query('SELECT link_url FROM fixture_links WHERE link_id=28');$old=$db->sql_fetchrow($r);$db->sql_freeresult($r);kp_sql("UPDATE fixture_links SET link_url='https://current.example.invalid/'");try{kp_check(lc_run()==='https://current.example.invalid/','Owned decision escapes old caller repeatable-read snapshot');$r=$db->sql_query('SELECT link_url FROM fixture_links WHERE link_id=28');$still=$db->sql_fetchrow($r);$db->sql_freeresult($r);kp_check($still['link_url']===$old['link_url'],'Caller retains its original snapshot');$cases++;}finally{$db->sql_query('ROLLBACK');}
 kp_check(phpbb_link_click_read($db,'28e0')===false&&phpbb_link_visit_url(array(array('link_active'=>'1','link_url'=>"https://example.invalid/\xc3")))===false,'Read API validates IDs and URL data is valid UTF-8');$cases++;
 echo 'Link clicks native: '.$cases.' cases, '.$failures." SQL failures PASS; actual redirect/headers, strict IDs, current targets, exact bounded counters, real row locks, independent optional fallback, owned connections and preserved caller work.\n";
}
// Import existing reset helpers and the canonical KB schema owner, replacing
// only its suite dispatch, factory class and explicit fixture environment.
$lc_source=file_get_contents(__DIR__.'/check-link-registration-native.php');
$lc_replacements=array('lr_classes();$db = new LinkNativeDatabase('=>'lc_classes();$db = new LinkClickNativeDatabase(', ' lr_suite();return; $cases = 0;'=>' lc_suite();return; $cases = 0;', 'PHPBB_LINK_NATIVE'=>'PHPBB_LINK_CLICK_NATIVE','PHPBB_LINK_PORT'=>'PHPBB_LINK_CLICK_PORT','PHPBB_LINK_PASSWORD'=>'PHPBB_LINK_CLICK_PASSWORD','codex_link_register_'=>'codex_link_click_');
foreach($lc_replacements as$before=>$after){if(strpos($lc_source,$before)===false){throw new RuntimeException('Canonical fixture boundary missing: '.$before);}$lc_source=str_replace($before,$after,$lc_source);}
$lc_code='';foreach(token_get_all($lc_source)as$t){$lc_code.=is_array($t)?($t[0]===T_DIR?var_export(__DIR__,true):$t[1]):$t;}eval(substr($lc_code,5));
