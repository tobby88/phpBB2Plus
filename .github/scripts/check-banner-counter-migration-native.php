<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_BANNER_OWNER_NATIVE')!=='1'){echo "Banner counter migration checks require an explicitly enabled disposable database.\n";return;}
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');require __DIR__.'/check-attachment-settings-storage.php';
require dirname(dirname(__DIR__)).'/update/banner_counter_migration.php';require $ats_source.'db/mysqli.php';
define('BANNERS_TABLE','fixture_banner');ats_load_function($ats_source.'admin/admin_banner.php','admin_banner_counter_storage_ready');
$port=getenv('PHPBB_BANNER_OWNER_PORT')?:'3306';ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&$port>0&&$port<=65535,'Loopback fixture port');
$host='127.0.0.1:'.$port;$password=getenv('PHPBB_BANNER_OWNER_PASSWORD')?:'';$fixture='codex_banner_counts_'.bin2hex(phpbb_random_bytes(8));
$control=new mysqli($host,'root',$password);ats_check(!$control->connect_errno&&$control->query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned counter schema');
$db=$peer=$config=null;$cases=0;
function bn_sql($sql){$r=$GLOBALS['native']->query($sql);ats_check($r!==false,'Owned counter migration SQL');return$r;}
function bn_rows($sql){return plus_storage_rows($GLOBALS['native'],$sql);}
function bn_state(){return array(plus_storage_metadata($GLOBALS['native'],'fixture_banner'),plus_storage_signature($GLOBALS['native'],'fixture_banner'),plus_banner_ids_digest($GLOBALS['native'],'fixture_banner'),bn_rows('SELECT * FROM fixture_stats ORDER BY banner_id'));}
function bn_create($variant,$populated=true){
    bn_sql('DROP TABLE IF EXISTS fixture_banner');bn_sql($GLOBALS['bn_schema']);
    $types=array('legacy'=>'MEDIUMINT UNSIGNED NOT NULL','int'=>'INT UNSIGNED NOT NULL','signed'=>'BIGINT NOT NULL','nullable'=>'INT UNSIGNED NULL DEFAULT NULL');
    if(isset($types[$variant])){bn_sql('ALTER TABLE fixture_banner MODIFY banner_click '.$types[$variant].',MODIFY banner_view '.$types[$variant]);}
    if($variant==='partial'){bn_sql('ALTER TABLE fixture_banner MODIFY banner_view MEDIUMINT UNSIGNED NOT NULL');}
    $comment=$GLOBALS['native']->real_escape_string("Counter ' \\ Grüße");
    $no_backslash=strpos(bn_rows('SELECT @@SESSION.sql_mode mode')[0]['mode'],'NO_BACKSLASH_ESCAPES')!==false;
    if($no_backslash){$comment=str_replace("'","''","Counter ' \\ Grüße");}
    $field=bn_rows("SHOW FULL COLUMNS FROM fixture_banner WHERE Field='banner_click'")[0];
    bn_sql('ALTER TABLE fixture_banner MODIFY banner_click '.$field['Type'].($field['Null']==='YES'?' NULL':' NOT NULL').($field['Default']!==null?' DEFAULT '.$field['Default']:'')." COMMENT '$comment',ADD custom_payload VARBINARY(20) NULL,ADD KEY custom_counter(banner_click,banner_view),AUTO_INCREMENT=900");
    if($populated){foreach(array(5,40)as$id){
        $fields=$values=array();foreach(bn_rows('SHOW FULL COLUMNS FROM fixture_banner')as$column){
            $field=$column['Field'];$fields[]=plus_storage_identifier($field);
            $value=$field==='banner_id'?(string)$id:(preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)/i',$column['Type'])?'0':"n$id O'Reilly \\ & Grüße");
            if($field==='custom_payload'){$values[]=$id===5?'NULL':"X'00FF5C27'";}else{$escaped=$no_backslash?str_replace("'","''",$value):$GLOBALS['native']->real_escape_string($value);$values[]="'$escaped'";}
        }bn_sql('INSERT INTO fixture_banner('.implode(',',$fields).')VALUES('.implode(',',$values).')');
    }
    if(in_array($variant,array('current','signed'),true)){bn_sql('UPDATE fixture_banner SET banner_click=9007199254740993,banner_view=9223372036854775807 WHERE banner_id=5');}
    else{bn_sql('UPDATE fixture_banner SET banner_click=16777215,banner_view=123456 WHERE banner_id=5');}}
    if($variant==='negative'){bn_sql('ALTER TABLE fixture_banner MODIFY banner_click BIGINT NOT NULL');bn_sql('UPDATE fixture_banner SET banner_click=-1 WHERE banner_id=5');}
    if($variant==='null'){bn_sql('ALTER TABLE fixture_banner MODIFY banner_view INT NULL');bn_sql('UPDATE fixture_banner SET banner_view=NULL WHERE banner_id=5');}
    if($variant==='decimal'){bn_sql('ALTER TABLE fixture_banner MODIFY banner_view DECIMAL(30,0) NOT NULL');}
    if($variant==='generated'){bn_sql('ALTER TABLE fixture_banner DROP banner_click,ADD banner_click BIGINT GENERATED ALWAYS AS (banner_view) STORED');}
    if($variant==='duplicate'){bn_sql('ALTER TABLE fixture_banner MODIFY banner_id MEDIUMINT UNSIGNED NOT NULL,DROP PRIMARY KEY');bn_sql('INSERT INTO fixture_banner SELECT * FROM fixture_banner LIMIT 1');}
    if($variant==='myisam'){bn_sql('ALTER TABLE fixture_banner ENGINE=MyISAM');}
}
function bn_denied($callback,$message){$before=bn_state();$denied=false;try{$callback();}catch(Exception$error){$denied=true;}ats_check($denied&&bn_state()===$before,$message);$GLOBALS['cases']++;}
function bn_cli($args){
    $command=escapeshellarg(PHP_BINARY);if(DIRECTORY_SEPARATOR==='\\'){$command.=' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll';}
    $command.=' '.escapeshellarg(dirname(dirname(__DIR__)).'/update/update_from_153a.php').' '.escapeshellarg('--config='.$GLOBALS['config']);foreach($args as$arg){$command.=' '.escapeshellarg($arg);}
    $p=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));ats_check(is_resource($p),'Actual counter updater CLI process');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return array(proc_close($p),$out,$err);
}
try{
    $db=new sql_db($host,'root',$password,$fixture,false);$native=$db->db_connect_id;$peer=new mysqli($host,'root',$password,$fixture);
    set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
    $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');ats_check(preg_match('/CREATE TABLE phpbb_banner \([\s\S]+?;/',$schema,$m)===1,'Actual canonical counter schema');$bn_schema=str_replace('phpbb_banner','fixture_banner',$m[0]);
    bn_sql('CREATE TABLE fixture_stats(banner_id INT,marker VARBINARY(8)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4');bn_sql("INSERT INTO fixture_stats VALUES(5,X'00FF'),(40,NULL)");bn_sql('CREATE TABLE fixture_canary(id INT) ENGINE=InnoDB');
    foreach(array('','STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,NO_BACKSLASH_ESCAPES,ANSI_QUOTES')as$mode){
        bn_sql("SET SESSION sql_mode='$mode'");
        foreach(array('legacy','int','signed','nullable','partial','current')as$variant){foreach(array(false,true)as$populated){
            bn_create($variant,$populated);$before=bn_state();$settings=bn_rows('SELECT @@SESSION.sql_mode mode,@@SESSION.lock_wait_timeout wait_timeout');
            $plan=plus_banner_counters_plan($native,'fixture_',true);ats_check(bn_state()===$before,'Counter plan is read-only');
            try{$count=plus_banner_counters_apply($native,'fixture_',true,true,1);}catch(Exception$error){throw new RuntimeException('Fixture '.$variant.' populated='.(int)$populated.': '.$error->getMessage());}
            ats_check($count===($variant==='current'?0:1)&&admin_banner_counter_storage_ready()&&plus_banner_counters_plan($native,'fixture_',true,true)===array(),'Runtime guard and canonical idempotent migration agree');
            $after=bn_state();ats_check($after[2]===$before[2]&&$after[3]===$before[3]&&plus_storage_counter_at_least($after[0]['AUTO_INCREMENT'],'900'),'All exact counts/payloads/stats and allocation floor preserved');
            ats_check(bn_rows('SELECT @@SESSION.sql_mode mode,@@SESSION.lock_wait_timeout wait_timeout')===$settings,'Caller SQL mode and wait limit restored');
            ats_check(plus_banner_counters_apply($native,'fixture_',true,true,1)===0&&bn_state()===$after,'Repeated apply makes no changes');$cases++;
        }}
        foreach(array('negative','null','decimal','generated','duplicate','myisam')as$variant){bn_create($variant);bn_denied(function()use($native){plus_banner_counters_apply($native,'fixture_',true,true,1);},'Unsupported source and every payload remain intact: '.$variant);}
    }
    bn_create('legacy');foreach(array(array(false,true),array(true,false),array(false,false))as$flags){bn_denied(function()use($native,$flags){plus_banner_counters_apply($native,'fixture_',$flags[0],$flags[1],1);},'Backup and maintenance confirmations mandatory');}
    $lock='plus_innodb_'.sha1($fixture);bn_sql("SELECT GET_LOCK('$lock',0)");bn_denied(function()use($native){plus_banner_counters_apply($native,'fixture_',true,true,1);},'Caller migration lock never consumed');bn_sql("DO RELEASE_LOCK('$lock')");
    ats_check($peer->query("SELECT GET_LOCK('$lock',0)")!==false,'Peer migration lock');bn_denied(function()use($native){plus_banner_counters_apply($native,'fixture_',true,true,1);},'Concurrent migration rejected');$peer->query("DO RELEASE_LOCK('$lock')");
    bn_sql('START TRANSACTION');bn_sql('INSERT INTO fixture_canary VALUES(1)');$denied=false;try{plus_banner_counters_apply($native,'fixture_',true,true,1);}catch(Exception$error){$denied=true;}
    ats_check($denied&&bn_rows('SELECT COUNT(*) n FROM fixture_canary')[0]['n']==='1'&&$peer->query('SELECT COUNT(*) n FROM fixture_canary')->fetch_assoc()['n']==='0','Caller pending transaction neither committed nor rolled back');bn_sql('ROLLBACK');$cases++;
    $peer->query('START TRANSACTION');$r=$peer->query('SELECT * FROM fixture_banner');$r->free();$started=microtime(true);bn_denied(function()use($native){plus_banner_counters_apply($native,'fixture_',true,true,1);},'Real metadata lock timeout preserves old schema/data');ats_check(microtime(true)-$started<8,'Metadata wait is bounded');$peer->rollback();ats_check(plus_banner_counters_apply($native,'fixture_',true,true,1)===1,'Retry after metadata lock release succeeds');$cases++;
    $config=tempnam(sys_get_temp_dir(),'banner_counts_');ats_check($config!==false,'Owned CLI configuration');file_put_contents($config,"<?php\n".'$dbhost='.var_export($host,true).';$dbuser=\'root\';$dbpasswd='.var_export($password,true).';$dbname='.var_export($fixture,true).';$table_prefix=\'fixture_\';$dbms=\'mysqli\';');
    bn_create('legacy');$before=bn_state();$cli=bn_cli(array('--banner-counters-only'));ats_check($cli[0]===0&&strpos($cli[1],'ALTER TABLE')!==false&&bn_state()===$before,'Actual targeted CLI dry run');$cases++;
    foreach(array(array('--banner-counters-only','--apply'),array('--banner-counters-only','--apply','--backup-confirmed'),array('--banner-counters-only','--banner-ids-only'),array('--banner-counter-only'))as$args){$cli=bn_cli($args);ats_check($cli[0]!==0&&bn_state()===$before,'Actual updater rejects mixed, misspelled and unconfirmed targeted modes');$cases++;}
    $cli=bn_cli(array('--banner-counters-only','--apply','--backup-confirmed','--maintenance-confirmed'));ats_check($cli[0]===0&&admin_banner_counter_storage_ready()&&strpos($cli[1],'Banner counter migration complete: 1 tables.')!==false,'Actual targeted CLI apply uses preserving migration');$cases++;
    echo 'Native banner counter migration: '.$cases." schema/preservation/lock/transaction/CLI cases PASS.\n";
}finally{
    if($config!==null&&is_file($config)){unlink($config);}if($peer){$peer->close();}if($db){$db->sql_close();}ats_check($control->query('DROP DATABASE '.$fixture),'Drop only owned fixture schema');$control->close();restore_error_handler();
}
