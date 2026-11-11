<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ARCADE_LOG_REDACTION_NATIVE')!=='1'){echo "Arcade log cleanup checks require an explicitly enabled disposable database.\n";return;}
require __DIR__.'/arcade-summary-fixture.php';require $as_app.'db/mysqli.php';
require_once dirname(dirname(__DIR__)).'/update/arcade_protocol_log_redaction.php';
function lr_rows($sql){$r=$GLOBALS['db']->sql_query($sql);as_check($r!==false,'Own log cleanup SQL');try{return $GLOBALS['db']->sql_fetchrowset($r);}finally{$GLOBALS['db']->sql_freeresult($r);}}
function lr_snapshot(){return lr_rows('SELECT * FROM fixture_ina_log ORDER BY record_no');}
function lr_seed()
{
    global $db,$lr_expected;
    as_check($db->sql_query('START TRANSACTION')&&$db->sql_query('DELETE FROM fixture_ina_log'),'Own fixture seed transaction');$lr_expected=array();
    for($id=0;$id<=620;$id++){
        $value='game_name=>Grüße😀 score=>'.$id.' ';
        if($id===0||($id>4&&$id%97===0)){$value.='sid=>'.str_repeat('a',32);}
        elseif($id===1){$value.='arcade_hash=>'.str_repeat('b',32);}
        elseif($id===2){$value="password=>Unicode Grüße 😀 ' quoted fakefield=>private tail";}
        elseif($id===3){$value=null;}
        elseif($id===4){$value='arcade_hash=>[redacted]';}
        $literal=$value===null?'NULL':"'".$db->sql_escape($value)."'";
        as_check($db->sql_query("INSERT INTO fixture_ina_log(record_no,user_id,name,value,date) VALUES($id,2,'GAME',$literal,".(1000+$id).')'),'Own historical log row');
        if($id===0||($id>4&&$id%97===0)){$lr_expected[$id]='game_name=>Grüße😀 score=>'.$id.' sid=>[redacted]';}
        elseif($id===1){$lr_expected[$id]='game_name=>Grüße😀 score=>1 arcade_hash=>[redacted]';}
        elseif($id===2){$lr_expected[$id]='[redacted legacy request containing credential fields]';}
    }
    as_check($db->sql_query('COMMIT'),'Own fixture seed commit');
}
function lr_apply($backup=true,$maintenance=true,$wait=1,$expected=null)
{
    try{return array('error'=>false,'result'=>plus_arcade_log_redaction_apply($GLOBALS['db']->db_connect_id,'fixture_',$backup,$maintenance,$wait,$expected));}
    catch(Exception$error){return array('error'=>true,'key'=>$error->getMessage());}
}
function lr_cli($arguments)
{
    $parts=array(PHP_BINARY);
    if(DIRECTORY_SEPARATOR==='\\'){$parts=array_merge($parts,array('-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=php_mysqli.dll'));}
    $parts[]=dirname(dirname(__DIR__)).'/update/update_from_153a.php';$parts[]='--config='.$GLOBALS['lr_config'];
    $command=implode(' ',array_map('escapeshellarg',array_merge($parts,$arguments)));$pipes=array();
    $process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));as_check(is_resource($process),'Own isolated updater child');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return array(proc_close($process),$out,$err);
}
$db=$control=null;$created=false;$lr_config=null;$lr_config_owned=false;$schema='codex_arc_log_'.bin2hex(phpbb_random_bytes(8));$cases=0;
try{
    $port=getenv('PHPBB_ARCADE_GAME_PORT')?:'3306';as_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback port');$host='127.0.0.1:'.$port;$password=getenv('PHPBB_ARCADE_GAME_PASSWORD')?:'';
    $control=new sql_db($host,'root',$password,'',false);as_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own redaction schema');$created=true;
    $db=new sql_db($host,'root',$password,$schema,false);$canonical=file_get_contents($as_app.'install/schemas/mysql_schema.sql');as_check(preg_match('/CREATE TABLE phpbb_ina_log \(.*?\) ENGINE=InnoDB[^;]*;/s',$canonical,$m)===1,'Exact canonical log schema');as_check($db->sql_query(str_replace('phpbb_ina_log','fixture_ina_log',$m[0])),'Own log table');
    as_check($db->sql_query("SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'NO_AUTO_VALUE_ON_ZERO')"),'Legacy zero log identity mode');
    lr_seed();$before=lr_snapshot();$plan=plus_arcade_log_redaction_plan($db->db_connect_id,'fixture_');as_check($plan['rows']===621&&$plan['redacted_rows']===9&&lr_snapshot()===$before,'Readonly complete paged preview includes zero key');$cases++;
    $r=lr_apply(true,true,1,$plan['before_sha256']);as_check(!$r['error']&&$r['result']['rows']===621&&$r['result']['redacted_rows']===9,'Apply all and only credential-bearing rows matching the backed-up snapshot');$expected=$before;foreach($expected as&$row){$id=(int)$row['record_no'];if(isset($lr_expected[$id])){$row['value']=$row[3]=$lr_expected[$id];}}unset($row);as_check(lr_snapshot()===$expected,'Every identity, account, timestamp, name and unrelated UTF-8 payload retained across page boundaries');$cases++;
    $after=lr_snapshot();$r=lr_apply();as_check(!$r['error']&&$r['result']['redacted_rows']===0&&lr_snapshot()===$after,'Repeated cleanup is an exact no-op');$cases++;
    lr_seed();as_check($db->sql_query("ALTER TABLE fixture_ina_log ADD CONSTRAINT fixture_fail_redaction CHECK(value NOT LIKE '%score=>194 sid=>[redacted]%')"),'Own middle-batch failure constraint');$before=lr_snapshot();as_check(lr_apply()['error']&&lr_snapshot()===$before,'Real SQL failure after earlier updates rolls back every log value');as_check($db->sql_query('ALTER TABLE fixture_ina_log DROP CONSTRAINT fixture_fail_redaction'),'Remove only own fault constraint');$cases++;
    foreach(array(array(false,true,1),array(true,false,1),array(true,true,0),array(true,true,31),array(1,true,1))as$flags){$before=lr_snapshot();$r=lr_apply($flags[0],$flags[1],$flags[2]);as_check($r['error']&&lr_snapshot()===$before,'Typed confirmations and bounded wait required');$cases++;}
    as_check($db->sql_query('START TRANSACTION')&&$db->sql_query("UPDATE fixture_ina_log SET name='Caller' WHERE record_no=1"),'Own borrowed transaction');as_check(lr_apply()['error']&&lr_rows('SELECT @@SESSION.in_transaction active')[0]['active']==='1','Migration refuses rather than commits a caller transaction');as_check($db->sql_query('ROLLBACK')&&lr_snapshot()===$before,'Caller owns rollback');$cases++;
    $lock='plus_innodb_'.sha1($schema);as_check(lr_rows("SELECT GET_LOCK('$lock',0) held")[0]['held']==='1','Own existing caller lease');as_check(lr_apply()['error']&&lr_rows("SELECT IS_USED_LOCK('$lock') owner")[0]['owner']===(string)mysqli_thread_id($db->db_connect_id),'Do not release caller-owned migration lease');as_check(lr_rows("SELECT RELEASE_LOCK('$lock') released")[0]['released']==='1','Caller releases own lease');$cases++;
    as_check($control->sql_query("SELECT GET_LOCK('$lock',0)"),'Independent migration lease');as_check(lr_apply()['error']&&lr_snapshot()===$before,'Other migration prevents all writes');as_check($control->sql_query("SELECT RELEASE_LOCK('$lock')"),'Independent peer lease released');$cases++;
    as_check($db->sql_query('ALTER TABLE fixture_ina_log ENGINE=MyISAM'),'Own legacy engine');as_check(lr_apply()['error']&&lr_snapshot()===$before,'Never partially redact nontransactional logs');as_check($db->sql_query('ALTER TABLE fixture_ina_log ENGINE=InnoDB ROW_FORMAT=DYNAMIC'),'Restore own modern engine');$cases++;
    as_check($db->sql_query("CREATE TRIGGER fixture_log_trigger BEFORE UPDATE ON fixture_ina_log FOR EACH ROW SET NEW.name='Changed by trigger'"),'Own trigger side effect');as_check(lr_apply()['error']&&lr_snapshot()===$before,'Custom log triggers require review before any write');as_check($db->sql_query('DROP TRIGGER fixture_log_trigger'),'Remove own trigger');$cases++;
    as_check($db->sql_query("SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'NO_BACKSLASH_ESCAPES')"),'Alternate valid SQL mode');$mode=lr_rows('SELECT @@SESSION.sql_mode mode')[0]['mode'];$r=lr_apply();as_check(!$r['error']&&$r['result']['redacted_rows']===9&&lr_rows('SELECT @@SESSION.sql_mode mode')[0]['mode']===$mode,'Prepared values preserve quotes/UTF-8 and caller SQL mode');$cases++;
    $r=plus_arcade_log_redaction_apply($db->db_connect_id,'absent_',true,true,1);as_check(!$r['present']&&$r['rows']===0,'Missing optional Arcade table is not created');$cases++;
    lr_seed();$before=lr_snapshot();$lr_config=sys_get_temp_dir().'/codex_arc_log_'.bin2hex(phpbb_random_bytes(8)).'.php';$handle=fopen($lr_config,'xb');as_check(is_resource($handle),'Exclusive own updater config');$lr_config_owned=true;$text="<?php\n";
    foreach(array('dbms'=>'mysqli','dbhost'=>'127.0.0.1:'.$port,'dbname'=>$schema,'dbuser'=>'root','dbpasswd'=>$password,'table_prefix'=>'fixture_')as$key=>$value){$text.='$'.$key.'='.var_export($value,true).";\n";}
    try{as_check(fwrite($handle,$text)===strlen($text),'Write only synthetic own updater connection');}finally{fclose($handle);}
    $r=lr_cli(array('--arcade-log-privacy-only'));as_check($r[0]===0&&strpos($r[1],'9 existing rows')!==false&&lr_snapshot()===$before,'Actual targeted CLI preview is read-only: '.$r[2]);$cases++;
    foreach(array(array('--arcade-log-privacy-only','--arcade-name-storage-only'),array('--arcade-log-privacy-onyl'),array('--arcade-log-privacy-only','--apply'),array('--arcade-log-privacy-only','--apply','--backup-confirmed'))as$args){$r=lr_cli($args);as_check($r[0]===2&&lr_snapshot()===$before,'Actual CLI rejects conflicting modes, typos and missing confirmations before writes');$cases++;}
    $r=lr_cli(array('--arcade-log-privacy-only','--apply','--backup-confirmed','--maintenance-confirmed'));as_check($r[0]===0&&strpos($r[1],'9 rows redacted')!==false&&plus_arcade_log_redaction_plan($db->db_connect_id,'fixture_')['redacted_rows']===0,'Actual targeted CLI applies only historical credential redaction: '.$r[2]);$cases++;
    $after=lr_snapshot();$r=lr_cli(array('--arcade-log-privacy-only','--apply','--backup-confirmed','--maintenance-confirmed'));as_check($r[0]===0&&strpos($r[1],'0 rows redacted')!==false&&lr_snapshot()===$after,'Actual CLI rerun is an exact no-op');$cases++;
    as_check(count(lr_rows('SHOW TABLES'))===1,'Targeted cleanup never creates any other forum table');
    lr_seed();$before=lr_snapshot();as_check(lr_apply(true,true,1,str_repeat('0',64))['error']&&lr_snapshot()===$before,'A changed backup snapshot rolls back all already processed values');$cases++;
    foreach(array('short',false,array())as$invalid){as_check(lr_apply(true,true,1,$invalid)['error']&&lr_snapshot()===$before,'Malformed backup digest rejected without writes');$cases++;}
    $waits=lr_rows('SELECT @@SESSION.lock_wait_timeout ddl_wait,@@SESSION.innodb_lock_wait_timeout row_wait');
    as_check($control->sql_query('START TRANSACTION'),'Independent own row-lock holder');$locked=$control->sql_query('SELECT * FROM '.$schema.'.fixture_ina_log WHERE record_no=0 FOR UPDATE');as_check($locked!==false,'Peer holds actual log row');$control->sql_freeresult($locked);
    try{$r=lr_apply();as_check($r['error'],'Actual row conflict returns after bounded wait');}finally{as_check($control->sql_query('ROLLBACK'),'Release only own row lock');}
    as_check(lr_snapshot()===$before&&lr_rows('SELECT @@SESSION.lock_wait_timeout ddl_wait,@@SESSION.innodb_lock_wait_timeout row_wait')===$waits,'Row-lock refusal preserves all values and restores both caller waits');$cases++;
    as_check(mysqli_query($control->db_connect_id,'LOCK TABLES '.$schema.'.fixture_ina_log WRITE')!==false,'Independent own metadata/table lock');
    try{$r=lr_apply();as_check($r['error'],'Actual metadata/table conflict returns after bounded wait');}finally{as_check(mysqli_query($control->db_connect_id,'UNLOCK TABLES')!==false,'Release only own table lock');}
    as_check(lr_snapshot()===$before&&lr_rows('SELECT @@SESSION.lock_wait_timeout ddl_wait,@@SESSION.innodb_lock_wait_timeout row_wait')===$waits,'Metadata-lock refusal preserves all values and restores both waits');$cases++;
    as_check(!lr_apply()['error']&&lr_rows('SELECT @@SESSION.lock_wait_timeout ddl_wait,@@SESSION.innodb_lock_wait_timeout row_wait')===$waits,'Successful migration also restores both caller waits');$cases++;
    echo 'Native Arcade log redaction: '.$cases.' cases, '.$as_checks." assertions PASS; paged real rollback/idempotence, zero identity, metadata retention, SQL modes, leases and updater-safe confirmations.\n";
}finally{if($db){$db->sql_close();}if($created){as_check(preg_match('/^codex_arc_log_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('DROP DATABASE '.$schema),'Drop only own redaction schema');}if($control){$control->sql_close();}if($lr_config_owned&&is_file($lr_config)){as_check(realpath(dirname($lr_config))===realpath(sys_get_temp_dir())&&preg_match('/^codex_arc_log_[a-f0-9]{16}\.php$/D',basename($lr_config))&&unlink($lr_config),'Remove only own synthetic updater config');}}
