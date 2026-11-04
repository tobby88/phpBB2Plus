<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_BANNER_OWNER_NATIVE') !== '1') { echo "Banner migration checks require an explicitly enabled disposable database.\n"; return; }
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0'); require __DIR__ . '/check-attachment-settings-storage.php';
require dirname(dirname(__DIR__)) . '/update/banner_id_migration.php';
require $ats_source . 'db/mysqli.php';
define('BANNERS_TABLE','fixture_banner'); ats_load_function($ats_source . 'admin/admin_banner.php','admin_banner_id_storage_ready');
$port=getenv('PHPBB_BANNER_OWNER_PORT')?:'3306'; ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$password=getenv('PHPBB_BANNER_OWNER_PASSWORD')?:'';$host='127.0.0.1:'.$port;$fixture='codex_banner_ids_'.bin2hex(phpbb_random_bytes(8));
$control=new mysqli($host,'root',$password);ats_check(!$control->connect_errno&&$control->query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned migration schema');
$db=$peer=null;$config=null;$cases=0;
function bi_sql($sql){$r=mysqli_query($GLOBALS['native'],$sql);ats_check($r!==false,'Owned banner migration SQL: '.mysqli_error($GLOBALS['native']));return$r;}
function bi_rows($sql){return plus_storage_rows($GLOBALS['native'],$sql);}
function bi_state(){return array(plus_storage_signature($GLOBALS['native'],'fixture_banner'),plus_storage_metadata($GLOBALS['native'],'fixture_banner'),plus_banner_ids_digest($GLOBALS['native'],'fixture_banner'));}
function bi_create($variant,$rows=true){
    bi_sql('DROP TABLE IF EXISTS fixture_banner');$sql=$GLOBALS['bi_schema'];
    if($variant!=='current'&&$variant!=='floor'){$sql=str_replace('NOT NULL AUTO_INCREMENT','NOT NULL',$sql);}
    if(in_array($variant,array('legacy','default','auto','duplicate','zero','signed','foreign','compound','myisam'),true)){$sql=str_replace('PRIMARY KEY (banner_id)','INDEX (banner_id)',$sql);}
    if($variant==='auto'){$sql=str_replace('banner_id mediumint(8) UNSIGNED NOT NULL,','banner_id mediumint(8) UNSIGNED NOT NULL AUTO_INCREMENT,',$sql);}
    if($variant==='signed'){$sql=str_replace('banner_id mediumint(8) UNSIGNED','banner_id mediumint(8)',$sql);}
    if($variant==='default'){$sql=str_replace('banner_id mediumint(8) UNSIGNED NOT NULL,','banner_id mediumint(8) UNSIGNED NOT NULL DEFAULT 0,',$sql);}
    if($variant==='myisam'){$sql=str_replace('ENGINE=InnoDB','ENGINE=MyISAM',$sql);}
    $comment=" COMMENT '".mysqli_real_escape_string($GLOBALS['native'],"Banner ' \\ retained")."'";
    $sql=preg_replace_callback('/^(banner_id[^,\r\n]*),$/m',function($m)use($comment){return$m[1].$comment.',';},$sql);
    bi_sql($sql);
    bi_sql("ALTER TABLE fixture_banner ADD custom_value VARCHAR(40) NOT NULL DEFAULT 'kept', ADD KEY custom_lookup(banner_comment(12))");
    if($variant==='foreign'){bi_sql('ALTER TABLE fixture_banner ADD PRIMARY KEY(banner_comment)');}
    if($variant==='compound'){bi_sql('ALTER TABLE fixture_banner ADD PRIMARY KEY(banner_id,banner_comment)');}
    if($rows){foreach(array(5,40)as$id){
        $fields=$values=array();foreach(bi_rows('SHOW FULL COLUMNS FROM fixture_banner')as$column){
            $field=$column['Field'];$fields[]=plus_storage_identifier($field);
            $value=$field==='banner_id'?(string)$id:(preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)/i',$column['Type'])?'0':"n$id O'Reilly \\ & Grüße");
            $values[]="'".mysqli_real_escape_string($GLOBALS['native'],$value)."'";
        }bi_sql('INSERT INTO fixture_banner('.implode(',',$fields).')VALUES('.implode(',',$values).')');
    }}
    if($variant==='duplicate'){bi_sql('INSERT INTO fixture_banner SELECT * FROM fixture_banner LIMIT 1');}
    if($variant==='zero'){bi_sql('UPDATE fixture_banner SET banner_id=0 WHERE banner_id=5');}
    if($variant==='floor'){bi_sql('ALTER TABLE fixture_banner AUTO_INCREMENT=900');}
}
function bi_denied($callback,$message){$before=bi_state();$denied=false;try{$callback();}catch(Exception$e){$denied=true;}ats_check($denied&&bi_state()===$before,$message);$GLOBALS['cases']++;}
function bi_cli($arguments){
    $command=escapeshellarg(PHP_BINARY);
    if(DIRECTORY_SEPARATOR==='\\'){$command.=' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll';}
    $command.=' '.escapeshellarg(dirname(dirname(__DIR__)).'/update/update_from_153a.php').' '.escapeshellarg('--config='.$GLOBALS['config']);
    foreach($arguments as$argument){$command.=' '.escapeshellarg($argument);}
    $process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));ats_check(is_resource($process),'Owned CLI updater process');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return array(proc_close($process),$out,$err);
}
try{
    $db=new sql_db($host,'root',$password,$fixture,false);$native=$db->db_connect_id;$peer=new mysqli($host,'root',$password,$fixture);ats_check(!$peer->connect_errno,'Independent native peer');
    set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
    $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');ats_check(preg_match('/CREATE TABLE phpbb_banner \([\s\S]+?;/',$schema,$m)===1,'Actual canonical banner schema');$bi_schema=str_replace('phpbb_banner','fixture_banner',$m[0]);
    bi_sql('CREATE TABLE fixture_banner_stats(banner_id INT NOT NULL, payload TEXT NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');bi_sql("INSERT INTO fixture_banner_stats VALUES(5,'Historical clicks'),(40,'Historical visits')");
    bi_sql('CREATE TABLE fixture_canary(n INT NOT NULL) ENGINE=InnoDB');
    foreach(array('','NO_BACKSLASH_ESCAPES','STRICT_TRANS_TABLES','STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES')as$mode){
        bi_sql("SET SESSION sql_mode='$mode'");
        foreach(array('legacy','default','primary','auto','current','floor')as$variant){foreach(array(false,true)as$populated){
            bi_create($variant,$populated);$before=bi_state();$stats=bi_rows('SELECT * FROM fixture_banner_stats ORDER BY banner_id');$session=bi_rows('SELECT @@SESSION.sql_mode mode,@@SESSION.lock_wait_timeout wait_timeout');
            $plan=plus_banner_ids_plan($native,'fixture_',true);ats_check(bi_state()===$before,'Plan is strictly read-only');
            ats_check(plus_banner_ids_apply($native,'fixture_',true,true,1)===($variant==='floor'||($variant==='current'&&$populated)?0:1),'Expected DDL count');
            ats_check(admin_banner_id_storage_ready()&&plus_banner_ids_plan($native,'fixture_',true)===array(),'Runtime guard agrees with completed migration');
            ats_check(plus_storage_counter_at_least(plus_storage_metadata($native,'fixture_banner')['AUTO_INCREMENT'],'41'),'Historical statistics reserve old IDs even when the banner table is empty');
            ats_check(plus_banner_ids_digest($native,'fixture_banner')===$before[2]&&bi_rows('SELECT * FROM fixture_banner_stats ORDER BY banner_id')===$stats,'IDs, every payload and external stats references preserved');
            ats_check(bi_rows('SELECT @@SESSION.sql_mode mode,@@SESSION.lock_wait_timeout wait_timeout')===$session,'Caller session settings restored');
            if($variant==='floor'){ats_check(plus_storage_counter_at_least(plus_storage_metadata($native,'fixture_banner')['AUTO_INCREMENT'],'900'),'Existing allocation floor never lowered');}
            $after=bi_state();ats_check(plus_banner_ids_apply($native,'fixture_',true,true,1)===0&&bi_state()===$after,'Repeat apply changes nothing');$cases++;
        }}
        foreach(array('duplicate','zero','signed','foreign','compound','myisam')as$variant){bi_create($variant);
            bi_denied(function()use($native){plus_banner_ids_apply($native,'fixture_',true,true,1);},'Unsupported/ambiguous legacy table stays byte-for-byte intact');
            ats_check(!admin_banner_id_storage_ready(),'Runtime rejects unsupported storage');
        }
    }
    bi_create('floor');bi_sql('UPDATE fixture_banner_stats SET banner_id=1000 WHERE banner_id=5');$before=bi_state();$stats=bi_rows('SELECT * FROM fixture_banner_stats ORDER BY banner_id');
    ats_check(plus_banner_ids_apply($native,'fixture_',true,true,1)===1&&plus_storage_counter_at_least(plus_storage_metadata($native,'fixture_banner')['AUTO_INCREMENT'],'1001')
        &&plus_banner_ids_digest($native,'fixture_banner')===$before[2]&&bi_rows('SELECT * FROM fixture_banner_stats ORDER BY banner_id')===$stats,'Historical references raise an already canonical allocation floor without editing statistics');$cases++;
    bi_create('legacy');
    foreach(array(array(false,true),array(true,false),array(false,false))as$flags){bi_denied(function()use($native,$flags){plus_banner_ids_apply($native,'fixture_',$flags[0],$flags[1],1);},'Backup and maintenance confirmations mandatory');}
    $lock='plus_innodb_'.sha1($fixture);
    bi_sql("SELECT GET_LOCK('$lock',0)");bi_denied(function()use($native){plus_banner_ids_apply($native,'fixture_',true,true,1);},'Caller migration lock never consumed');ats_check(bi_rows("SELECT IS_USED_LOCK('$lock') owner")[0]['owner']===(string)mysqli_thread_id($native),'Caller lock still owned');bi_sql("DO RELEASE_LOCK('$lock')");
    ats_check($peer->query("SELECT GET_LOCK('$lock',0)")!==false,'Peer owns migration lock');bi_denied(function()use($native){plus_banner_ids_apply($native,'fixture_',true,true,1);},'Concurrent migration denied');$peer->query("DO RELEASE_LOCK('$lock')");
    bi_sql('START TRANSACTION');bi_sql('INSERT INTO fixture_canary VALUES(1)');$denied=false;try{plus_banner_ids_apply($native,'fixture_',true,true,1);}catch(Exception$e){$denied=true;}
    ats_check($denied&&bi_rows('SELECT COUNT(*) n FROM fixture_canary')[0]['n']==='1'&&$peer->query('SELECT COUNT(*) n FROM fixture_canary')->fetch_assoc()['n']==='0','Caller transaction neither committed nor rolled back');bi_sql('ROLLBACK');$cases++;
    $peer->query('START TRANSACTION');$held=$peer->query('SELECT * FROM fixture_banner');$held->free();$start=microtime(true);bi_denied(function()use($native){plus_banner_ids_apply($native,'fixture_',true,true,1);},'Metadata lock timeout preserves old storage');ats_check(microtime(true)-$start<10,'DDL wait remains bounded');$peer->rollback();ats_check(plus_banner_ids_apply($native,'fixture_',true,true,1)===1,'Retry after released metadata lock succeeds');
    $config=tempnam(sys_get_temp_dir(),'banner_ids_');ats_check($config!==false,'Owned CLI config');
    file_put_contents($config,"<?php\n".'$dbhost='.var_export($host,true).';$dbuser=\'root\';$dbpasswd='.var_export($password,true).';$dbname='.var_export($fixture,true).';$table_prefix=\'fixture_\';$dbms=\'mysqli\';');
    bi_create('legacy');$before=bi_state();$cli=bi_cli(array('--banner-ids-only'));ats_check($cli[0]===0&&strpos($cli[1],'ALTER TABLE')!==false&&bi_state()===$before,'Actual targeted CLI dry run: exit='.$cli[0].' '.$cli[2].' '.$cli[1]);$cases++;
    foreach(array(array('--banner-ids-only','--apply'),array('--banner-ids-only','--apply','--backup-confirmed'),array('--banner-ids-only','--storage-only'),array('--banner-id-only'))as$args){$cli=bi_cli($args);ats_check($cli[0]!==0&&bi_state()===$before,'Actual CLI flag guards prevent accidental broad or unconfirmed apply');$cases++;}
    $cli=bi_cli(array('--banner-ids-only','--apply','--backup-confirmed','--maintenance-confirmed'));ats_check($cli[0]===0&&admin_banner_id_storage_ready()&&strpos($cli[1],'Banner ID migration complete: 1 tables.')!==false,'Actual targeted CLI apply uses shared migration');$cases++;
    echo 'Native banner ID migration: '.$cases." schema/preservation/lock/transaction/CLI cases PASS.\n";
}finally{
    if($config!==null&&is_file($config)){unlink($config);}if($peer){$peer->close();}if($db){$db->sql_close();}$control->query('DROP DATABASE '.$fixture);$control->close();restore_error_handler();
}
