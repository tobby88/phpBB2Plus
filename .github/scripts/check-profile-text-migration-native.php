<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_PROFILE_TEXT_NATIVE') !== '1') { echo "Profile text migration requires an explicitly enabled disposable database.\n"; return; }
require dirname(dirname(__DIR__)) . '/phpBB2/includes/php_compat.php';
require dirname(dirname(__DIR__)) . '/update/profile_text_storage_migration.php';
define('IN_PHPBB',true);define('USERS_TABLE','fixture_users');define('PROFILE_FIELDS_TABLE','fixture_profile_fields');
require dirname(dirname(__DIR__)) . '/phpBB2/db/mysqli.php';
function pt_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function pt_sql($sql){return plus_storage_query($GLOBALS['pt_db'],$sql);}
function pt_rows($sql){return plus_storage_rows($GLOBALS['pt_db'],$sql);}
function pt_seed($variant,$populated){
    foreach(array('profile_field_actions','profile_fields','users')as$suffix){pt_sql('DROP TABLE IF EXISTS fixture_'.$suffix);}
    $schema=file_get_contents(dirname(dirname(__DIR__)).'/phpBB2/install/schemas/mysql_schema.sql');
    pt_check(preg_match('/CREATE TABLE phpbb_profile_fields \([\s\S]+?;/',$schema,$m)===1,'Actual profile definition schema');
    pt_sql(str_replace('phpbb_profile_fields','fixture_profile_fields',$m[0]));
    pt_sql("CREATE TABLE fixture_users (user_id INT NOT NULL AUTO_INCREMENT,username VARCHAR(255) NOT NULL,custom_text VARCHAR(255) NULL DEFAULT NULL,retired_text VARCHAR(255) DEFAULT 'retained',binary_payload VARBINARY(8) NULL,PRIMARY KEY(user_id),KEY names(username(12))) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    pt_sql('CREATE TABLE fixture_profile_field_actions (field_column VARCHAR(64) NOT NULL,action_state VARCHAR(16) NOT NULL,column_signature CHAR(64) NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    pt_sql("INSERT INTO fixture_profile_field_actions VALUES('retired_text','retired','".str_repeat('a',64)."')");
    foreach(phpbb_profile_text_metadata_columns()as$field){pt_sql('ALTER TABLE fixture_profile_fields MODIFY '.plus_storage_identifier($field).' '.($field==='text_area_default'?'TEXT':'VARCHAR(255)').' NULL DEFAULT NULL');}
    pt_sql("INSERT INTO fixture_profile_fields(field_name,field_column,field_type,text_field_default)VALUES('Legacy label','custom_text',0,'&amp;')");
    if($populated){pt_sql("INSERT INTO fixture_users(user_id,username,custom_text,binary_payload)VALUES(5,'A ä 😀','&amp; quotes',X'00FF5C27'),(40,'B','0',NULL)");}
    pt_sql('ALTER TABLE fixture_users AUTO_INCREMENT=900');pt_sql('ALTER TABLE fixture_profile_fields AUTO_INCREMENT=700');
    if($variant==='literal') {pt_sql("ALTER TABLE fixture_users MODIFY custom_text VARCHAR(255) NOT NULL DEFAULT ('Grüße 😀') COMMENT 'keep comment'");}
    if($variant==='ascii-literal') {pt_sql("ALTER TABLE fixture_users MODIFY custom_text VARCHAR(255) NOT NULL DEFAULT ('Grüße text') COMMENT 'keep comment'");}
    if($variant==='quoted-literal') {$value="quote' ä trailing\\";$mode=pt_rows('SELECT @@SESSION.sql_mode mode')[0]['mode'];$escaped=in_array('NO_BACKSLASH_ESCAPES',explode(',',$mode),true)?str_replace("'","''",$value):$GLOBALS['pt_db']->real_escape_string($value);pt_sql("ALTER TABLE fixture_users MODIFY custom_text VARCHAR(255) NOT NULL DEFAULT ('$escaped') COMMENT 'keep comment'");}
    if($variant==='expression'){pt_sql('ALTER TABLE fixture_users MODIFY custom_text VARCHAR(255) DEFAULT (uuid())');}
    if($variant==='indexed'){pt_sql('ALTER TABLE fixture_users ADD KEY custom_index(custom_text)');}
    if($variant==='secondary-index'){pt_sql('ALTER TABLE fixture_users ADD KEY custom_index(username(12),custom_text)');}
    if($variant==='generated'){pt_sql('ALTER TABLE fixture_users DROP custom_text,ADD custom_text VARCHAR(255) GENERATED ALWAYS AS (username) STORED');}
    if($variant==='core'){pt_sql("UPDATE fixture_profile_fields SET field_column='username'");}
    if($variant==='missing'){pt_sql('ALTER TABLE fixture_users DROP custom_text');}
    if($variant==='conflict'){pt_sql("INSERT INTO fixture_profile_field_actions VALUES('custom_text','retired','".str_repeat('b',64)."')");}
    if($variant==='myisam'){pt_sql('ALTER TABLE fixture_users ENGINE=MyISAM');}
    if($variant==='longtext'){pt_sql('ALTER TABLE fixture_users MODIFY custom_text LONGTEXT NULL DEFAULT NULL');}
    if($variant==='legacy-map'){pt_sql("UPDATE fixture_profile_fields SET field_name='custom_text',field_column=NULL");}
}
function pt_state(){return array(plus_storage_signature($GLOBALS['pt_db'],'fixture_profile_fields'),plus_storage_signature($GLOBALS['pt_db'],'fixture_users'),plus_profile_text_digest($GLOBALS['pt_db'],'fixture_profile_fields','field_id'),plus_profile_text_digest($GLOBALS['pt_db'],'fixture_users','user_id'),pt_rows('SELECT * FROM fixture_profile_field_actions'));}
function pt_cli($arguments){
    $command=escapeshellarg(PHP_BINARY);
    if(DIRECTORY_SEPARATOR==='\\'){$command.=' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll -d extension=php_openssl.dll';}
    $command.=' '.escapeshellarg(dirname(dirname(__DIR__)).'/update/update_from_153a.php').' '.escapeshellarg('--config='.$GLOBALS['pt_config']);
    foreach($arguments as$argument){$command.=' '.escapeshellarg($argument);}
    $process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));pt_check(is_resource($process),'Owned updater child');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return array(proc_close($process),$out,$err);
}
$port=getenv('PHPBB_PROFILE_TEXT_PORT')?:'3306';pt_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$password=getenv('PHPBB_PROFILE_TEXT_PASSWORD')?:'';$fixture='codex_profile_text_'.bin2hex(phpbb_random_bytes(8));mysqli_report(MYSQLI_REPORT_OFF);
$control=new mysqli('127.0.0.1','root',$password,'',(int)$port);pt_check(!$control->connect_errno&&$control->query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');$pt_db=$driver=null;$pt_config=null;$cases=0;
try{
    $pt_db=new mysqli('127.0.0.1','root',$password,$fixture,(int)$port);pt_check(!$pt_db->connect_errno&&$pt_db->set_charset('utf8mb4'),'Owned connection');
    $driver=new sql_db('127.0.0.1:'.$port,'root',$password,$fixture,false);
    set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
    foreach(array('ordinary','literal','ascii-literal','longtext','legacy-map')as$variant){foreach(array(false,true)as$populated){
        if($variant==='literal'&&!$populated){continue;} // Explicit ambiguous-empty failure case below.
        pt_seed($variant,$populated);$before=pt_state();$plan=plus_profile_text_plan($pt_db,'fixture_',true,true);pt_check(count($plan)===($variant==='longtext'?1:2),'Expected widening scope '.$variant);
        $fields=pt_rows('SELECT * FROM fixture_profile_fields');pt_check(!phpbb_profile_text_storage_ready($driver,$fields),'Old physical capacity refused by actual runtime guard');
        pt_check(pt_state()===$before,'Dry run has no mutations');
        $mode=pt_rows('SELECT @@SESSION.sql_mode mode,@@SESSION.lock_wait_timeout wait_timeout')[0];
        pt_check(plus_profile_text_apply($pt_db,'fixture_',true,true,2)===count($plan),'Apply exact scope '.$variant);
        pt_check(!plus_profile_text_plan($pt_db,'fixture_',true,true)&&plus_profile_text_apply($pt_db,'fixture_',true,true,2)===0,'Idempotent completed plan');
        $after=pt_state();pt_check($after[2]===$before[2]&&$after[3]===$before[3]&&$after[4]===$before[4],'All row bytes and retired receipt preserved');
        pt_check(phpbb_profile_text_storage_ready($driver,$fields)&&phpbb_profile_text_storage_ready($driver,array(),true),'Actual runtime guards accept migrated value/metadata storage');
        pt_check(pt_rows("SHOW FULL COLUMNS FROM fixture_users WHERE Field='retired_text'")[0]['Type']==='varchar(255)','Retired physical storage untouched');
        pt_check(pt_rows('SELECT @@SESSION.sql_mode mode,@@SESSION.lock_wait_timeout wait_timeout')[0]===$mode,'Session settings restored');$cases++;
    }}
    foreach(array('expression','indexed','secondary-index','generated','core','missing','conflict','myisam')as$variant){pt_seed($variant,true);$before=pt_state();$denied=false;try{plus_profile_text_apply($pt_db,'fixture_',true,true,2);}catch(Exception$e){$denied=true;}pt_check($denied&&pt_state()===$before,'Unsupported state refused without mutation '.$variant);$cases++;}
    $saved_mode=pt_rows('SELECT @@SESSION.sql_mode mode')[0]['mode'];
    foreach(array(false,true)as$no_backslashes){foreach(array(false,true)as$populated){
        $mode=implode(',',array_diff(explode(',',$saved_mode),array('NO_BACKSLASH_ESCAPES'))).($no_backslashes?',NO_BACKSLASH_ESCAPES':'');pt_sql("SET SESSION sql_mode='$mode'");
        pt_seed('quoted-literal',$populated);$before=pt_state();$plan=plus_profile_text_plan($pt_db,'fixture_',true,true);pt_check(count($plan)===2,'Quoted literal dry run');
        pt_check(plus_profile_text_apply($pt_db,'fixture_',true,true,2)===2,'Quoted literal apply in actual SQL mode');
        pt_check(plus_profile_text_default($pt_db,'fixture_users','custom_text')==="quote' ä trailing\\"&&pt_state()[2]===$before[2]&&pt_state()[3]===$before[3],'Quote/backslash default and rows exact');$cases++;
    }}pt_sql("SET SESSION sql_mode='$saved_mode'");
    foreach(array(array(false,true),array(true,false))as$flags){pt_seed('ordinary',true);$before=pt_state();$denied=false;try{plus_profile_text_apply($pt_db,'fixture_',$flags[0],$flags[1],2);}catch(Exception$e){$denied=true;}pt_check($denied&&pt_state()===$before,'Explicit confirmations required');$cases++;}
    pt_seed('literal',false);$before=pt_state();$denied=false;try{plus_profile_text_apply($pt_db,'fixture_',true,true,2);}catch(Exception$e){$denied=strpos($e->getMessage(),'ambiguous')!==false;}pt_check($denied&&pt_state()===$before,'Ambiguous empty non-BMP default refused before any DDL');$cases++;
    pt_seed('ordinary',true);$before=pt_state();$lock='plus_innodb_'.sha1($fixture);pt_sql("DO GET_LOCK('$lock',0)");$denied=false;
    try{plus_profile_text_apply($pt_db,'fixture_',true,true,2);}catch(Exception$e){$denied=true;}
    pt_check($denied&&pt_state()===$before&&(string)pt_rows("SELECT IS_USED_LOCK('$lock') owner")[0]['owner']===(string)$pt_db->thread_id,'Never release or reuse caller-owned migration lock');pt_sql("DO RELEASE_LOCK('$lock')");$cases++;
    pt_sql('START TRANSACTION');$denied=false;try{plus_profile_text_apply($pt_db,'fixture_',true,true,2);}catch(Exception$e){$denied=true;}pt_check($denied,'Active caller transaction refused');pt_sql('ROLLBACK');pt_check(pt_state()===$before,'Caller transaction never committed by DDL');$cases++;
    $pt_config=tempnam(sys_get_temp_dir(),'phpbb-profile-text-');pt_check($pt_config!==false,'Exclusive owned CLI configuration');
    $values=array('dbhost'=>'127.0.0.1:'.$port,'dbuser'=>'root','dbpasswd'=>$password,'dbname'=>$fixture,'table_prefix'=>'fixture_','dbms'=>'mysqli');$config="<?php\n";
    foreach($values as$name=>$value){$config.='$'.$name.'='.var_export($value,true).";\n";}pt_check(file_put_contents($pt_config,$config)===strlen($config),'Owned CLI configuration written');
    foreach(array(array('--profile-text-storage-only'),array('--profile-text-storage-only','--apply'),array('--profile-text-storage-only','--apply','--backup-confirmed'),array('--profile-text-storage-only','--banner-counters-only'),array('--profile-text-storag-only','--apply','--backup-confirmed','--maintenance-confirmed'))as$args){$cli=pt_cli($args);pt_check(($cli[0]===0)===($args===array('--profile-text-storage-only'))&&pt_state()===$before,'Actual CLI preview, confirmations, exclusive mode and typo guards');$cases++;}
    $cli=pt_cli(array('--profile-text-storage-only','--apply','--backup-confirmed','--maintenance-confirmed'));pt_check($cli[0]===0&&strpos($cli[1],'Profile text migration complete: 2 tables.')!==false&&!plus_profile_text_plan($pt_db,'fixture_',true,true),'Actual targeted CLI applies shared preserving migration');pt_check(pt_state()[2]===$before[2]&&pt_state()[3]===$before[3],'Actual CLI keeps all payload bytes');$cases++;
    echo 'Native profile text migration: '.$cases." preservation, default, ownership, capacity and failure cases PASS.\n";
}finally{if($pt_config!==null&&is_file($pt_config)){unlink($pt_config);}if($driver){$driver->sql_close();}if($pt_db){$pt_db->close();}try{pt_check($control->query('DROP DATABASE '.$fixture),'Owned schema cleanup');}finally{$control->close();restore_error_handler();}}
