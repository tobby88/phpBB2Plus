<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PROFILE_TEXT_NATIVE')!=='1'){echo "Full profile updater requires an explicitly enabled disposable database.\n";return;}
$root=dirname(dirname(__DIR__));require $root.'/phpBB2/includes/php_compat.php';
require $root.'/update/profile_text_storage_migration.php';
function pfu_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function pfu_install($db,$sql){
    pfu_check(!preg_match('/^\s*(?:USE\s|(?:CREATE|DROP)\s+DATABASE\b|GRANT\s|DELIMITER\s)/mi',$sql),'Installer stays in selected owned schema');
    pfu_check(mysqli_multi_query($db,$sql),'Actual canonical installer SQL starts: '.mysqli_error($db));
    do{if($result=mysqli_store_result($db)){mysqli_free_result($result);}if(!mysqli_more_results($db)){break;}pfu_check(mysqli_next_result($db),'Actual canonical installer SQL continues: '.mysqli_error($db));}while(true);
}
function pfu_cli($arguments){
    $cmd=escapeshellarg(PHP_BINARY);if(DIRECTORY_SEPARATOR==='\\'){$cmd.=' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll -d extension=php_openssl.dll';}
    $cmd.=' '.escapeshellarg($GLOBALS['root'].'/update/update_from_153a.php').' '.escapeshellarg('--config='.$GLOBALS['config']);foreach($arguments as$arg){$cmd.=' '.escapeshellarg($arg);}
    $stdout=tempnam(sys_get_temp_dir(),'phpbb-profile-out-');$stderr=tempnam(sys_get_temp_dir(),'phpbb-profile-err-');
    try{
        pfu_check($stdout!==false&&$stderr!==false,'Owned child output files');
        // A verbose failed updater cannot block on an unread stderr pipe.
        $child=proc_open($cmd,array(0=>array('pipe','r'),1=>array('file',$stdout,'w'),2=>array('file',$stderr,'w')),$pipes,null,null,array('bypass_shell'=>true));pfu_check(is_resource($child),'Owned full updater child');fclose($pipes[0]);
        $code=proc_close($child);return array($code,file_get_contents($stdout),file_get_contents($stderr));
    }finally{foreach(array($stdout,$stderr)as$file){if($file!==false&&is_file($file)){unlink($file);}}}
}
$port=getenv('PHPBB_PROFILE_TEXT_PORT')?:'3306';pfu_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback fixture port');$password=getenv('PHPBB_PROFILE_TEXT_PASSWORD')?:'';
mysqli_report(MYSQLI_REPORT_OFF);$schema='codex_profile_full_'.bin2hex(phpbb_random_bytes(8));$control=new mysqli('127.0.0.1','root',$password,'',(int)$port);$db=null;$config=null;
pfu_check(!$control->connect_errno&&$control->query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned full updater schema');
try{
    $db=new mysqli('127.0.0.1','root',$password,$schema,(int)$port);pfu_check(!$db->connect_errno&&$db->set_charset('utf8mb4'),'Owned full updater connection');
    pfu_install($db,file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql'));pfu_install($db,file_get_contents($root.'/phpBB2/install/schemas/mysql_basic.sql'));
    plus_storage_query($db,'ALTER TABLE phpbb_users ADD custom_upgrade_value VARCHAR(255) NULL DEFAULT NULL');
    plus_storage_query($db,'ALTER TABLE phpbb_profile_fields MODIFY text_field_default VARCHAR(255) NULL DEFAULT NULL,MODIFY radio_button_default VARCHAR(255) NULL DEFAULT NULL');
    plus_storage_query($db,"INSERT INTO phpbb_profile_fields(field_name,field_column,field_type,text_field_maxlen,text_field_default)VALUES('Upgrade fixture','custom_upgrade_value',0,255,'&amp;')");
    $values=array('user_id'=>'900001','username'=>'Synthetic upgrade fixture','custom_upgrade_value'=>"Grüße 😀 &amp; \\ 0");
    foreach(plus_storage_rows($db,'SHOW COLUMNS FROM phpbb_users')as$c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?'0':'';}}
    $names=$encoded=array();foreach($values as$key=>$value){$names[]=plus_storage_identifier($key);$encoded[]="'".mysqli_real_escape_string($db,$value)."'";}plus_storage_query($db,'INSERT INTO phpbb_users('.implode(',',$names).')VALUES('.implode(',',$encoded).')');
    $before=plus_storage_rows($db,'SELECT custom_upgrade_value FROM phpbb_users WHERE user_id=900001');$fields=plus_storage_rows($db,"SELECT * FROM phpbb_profile_fields WHERE field_column='custom_upgrade_value'");
    $config=tempnam(sys_get_temp_dir(),'phpbb-profile-full-');pfu_check($config!==false,'Owned full updater config');$body="<?php\n";foreach(array('dbhost'=>'127.0.0.1:'.$port,'dbuser'=>'root','dbpasswd'=>$password,'dbname'=>$schema,'table_prefix'=>'phpbb_','dbms'=>'mysqli')as$key=>$value){$body.='$'.$key.'='.var_export($value,true).";\n";}pfu_check(file_put_contents($config,$body)===strlen($body),'Owned config written');
    $result=pfu_cli(array('--apply','--backup-confirmed','--maintenance-confirmed'));pfu_check($result[0]===0&&strpos($result[1],'Database update complete.')!==false,'Actual full updater succeeded: '.substr($result[2],0,900));
    pfu_check(!plus_profile_text_plan($db,'phpbb_',true,true),'Full updater completed profile storage migration');
    pfu_check(plus_storage_rows($db,'SELECT custom_upgrade_value FROM phpbb_users WHERE user_id=900001')===$before&&plus_storage_rows($db,"SELECT * FROM phpbb_profile_fields WHERE field_column='custom_upgrade_value'")===$fields,'Full updater preserved exact custom values/default metadata');
    $version=plus_storage_rows($db,"SELECT config_value FROM phpbb_config WHERE config_name='version'");pfu_check(count($version)===1&&$version[0]['config_value']==='.0.23','Full updater finalizes canonical identity after profile storage');
    echo "Native full profile updater: canonical installer, legacy profile widths, actual full apply, preserved values/defaults and final identity PASS.\n";
}finally{if($config!==null&&is_file($config)){unlink($config);}if($db){$db->close();}try{pfu_check($control->query('DROP DATABASE '.$schema),'Owned full updater schema removed');}finally{$control->close();}}
