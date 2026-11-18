<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ICONS_MIGRATION_NATIVE')!=='1'){echo "Native post icon migration requires an explicitly enabled disposable database.\n";return;}
define('IN_PHPBB',true);$root=dirname(dirname(__DIR__));require $root.'/update/post_icon_storage_migration.php';mysqli_report(MYSQLI_REPORT_OFF);
$cases=$assertions=0;
function im_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['assertions']++;}
function im_query($sql){return plus_storage_query($GLOBALS['db'],$sql);}
function im_rows($sql){return plus_storage_rows($GLOBALS['db'],$sql);}
function im_denied($body){try{$body();}catch(Exception$e){return true;}catch(Error$e){return true;}return false;}
function im_state(){return im_rows('SELECT CONNECTION_ID() id,@@SESSION.autocommit a,@@SESSION.sql_mode m,@@SESSION.innodb_strict_mode s,@@SESSION.lock_wait_timeout d,@@SESSION.innodb_lock_wait_timeout r,@@SESSION.'.($GLOBALS['mysql']?'transaction_isolation':'tx_isolation').' i');}
function im_reset(){
 foreach(array('fixture_post_icon_config','fixture_posts','fixture_topics')as$table){im_query('DROP TABLE IF EXISTS '.$table);}
 foreach(array('posts'=>'post','topics'=>'topic')as$table=>$stem){im_query('CREATE TABLE fixture_'.$table.' ('.$stem.'_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,'.$stem.'_icon TINYINT NULL DEFAULT NULL COMMENT \'Größe ä\',body VARCHAR(100),KEY icon_lookup('.$stem.'_icon)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC');im_query("INSERT INTO fixture_$table VALUES(1,NULL,'NULL ä 😀'),(2,-1,'Negative'),(3,127,'Maximum')");im_query('ALTER TABLE fixture_'.$table.' AUTO_INCREMENT=55');}
 file_put_contents($GLOBALS['folder'].'/data/icons.dat',serialize($GLOBALS['custom']));
}
function im_refs(){return array(plus_icon_storage_digest($GLOBALS['db'],'fixture_posts','post_id'),plus_icon_storage_digest($GLOBALS['db'],'fixture_topics','topic_id'));}
function im_apply(){return plus_icon_storage_apply($GLOBALS['db'],'fixture_',$GLOBALS['folder'],true,true,1);}
function im_install($sql){im_check(!preg_match('/^\s*(?:USE\s|(?:CREATE|DROP)\s+DATABASE\b|GRANT\s|DELIMITER\s)/mi',$sql),'Installer stays in owned schema');im_check(mysqli_multi_query($GLOBALS['db'],$sql),'Actual installer starts');do{if($r=mysqli_store_result($GLOBALS['db'])){mysqli_free_result($r);}if(!mysqli_more_results($GLOBALS['db'])){break;}im_check(mysqli_next_result($GLOBALS['db']),'Actual installer continues: '.mysqli_error($GLOBALS['db']));}while(true);}
function im_snapshot(){
 $out=array();foreach(im_rows('SHOW TABLES')as$row){$table=reset($row);$rows=im_rows('SELECT * FROM '.plus_storage_identifier($table));$records=array_map('serialize',$rows);sort($records,SORT_STRING);$out[$table]=array(im_rows('SHOW CREATE TABLE '.plus_storage_identifier($table)),hash('sha256',implode("\n",$records)));}ksort($out);return$out;
}
function im_config($prefix){
 $body="<?php\n";foreach(array('dbhost'=>'127.0.0.1:'.$GLOBALS['port'],'dbuser'=>$GLOBALS['account'],'dbpasswd'=>$GLOBALS['secret'],'dbname'=>$GLOBALS['schema'],'table_prefix'=>$prefix,'dbms'=>'mysqli')as$key=>$value){$body.='$'.$key.'='.var_export($value,true).";\n";}im_check(file_put_contents($GLOBALS['folder'].'/config.php',$body)===strlen($body)&&chmod($GLOBALS['folder'].'/config.php',0600),'Owned trusted CLI config');
}
function im_cli_start($arguments){
 $parts=array(PHP_BINARY);if(DIRECTORY_SEPARATOR===chr(92)){$parts=array_merge($parts,array('-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=php_mysqli.dll','-d','extension=php_openssl.dll'));}
 $parts=array_merge($parts,array('-d','date.timezone=UTC','-d','disable_functions=mail',$GLOBALS['root'].'/update/update_from_153a.php','--config='.$GLOBALS['folder'].'/config.php','--icon-source-root='.$GLOBALS['folder']),$arguments);
 $out=tempnam(sys_get_temp_dir(),'codex-icons-out-');$err=tempnam(sys_get_temp_dir(),'codex-icons-err-');
 try{im_check($out!==false&&$err!==false,'Owned child streams');$child=proc_open(implode(' ',array_map('escapeshellarg',$parts)),array(0=>array('pipe','r'),1=>array('file',$out,'w'),2=>array('file',$err,'w')),$pipes,null,null,array('bypass_shell'=>true));im_check(is_resource($child),'Actual CLI child');fclose($pipes[0]);return array($child,$out,$err);}catch(Exception$e){foreach(array($out,$err)as$file){if($file!==false&&is_file($file)){unlink($file);}}throw$e;}
}
function im_cli_finish($child){try{$code=proc_close($child[0]);return array($code,file_get_contents($child[1]),file_get_contents($child[2]));}finally{foreach(array($child[1],$child[2])as$file){if(is_file($file)){unlink($file);}}}}
function im_cli($arguments){return im_cli_finish(im_cli_start($arguments));}
$port=getenv('PHPBB_ICONS_MIGRATION_PORT')?:'3306';$password=getenv('PHPBB_ICONS_MIGRATION_PASSWORD')?:'';im_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback fixture port');
$token=bin2hex(phpbb_random_bytes(8));$schema='codex_post_icon_migration_'.$token;$account='codex_im_'.$token;$secret=bin2hex(phpbb_random_bytes(24));$folder=sys_get_temp_dir().'/'.$schema;
$control=mysqli_connect('127.0.0.1','root',$password,'',(int)$port);$db=null;$account_created=$schema_created=false;$dirs=array();
im_check($control!==false,'Own service control');
try{
 $version=mysqli_fetch_row(plus_storage_query($control,'SELECT VERSION()'))[0];$mysql=stripos($version,'MariaDB')===false;
 if(getenv('PHPBB_ICONS_MIGRATION_MYSQL')==='8.4'){im_check($mysql&&strpos($version,'8.4.')===0,'Actual requested MySQL8.4 service');}
 plus_storage_query($control,'CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$schema_created=true;
 plus_storage_query($control,"CREATE USER '$account'@'%' IDENTIFIED BY '$secret'");$account_created=true;plus_storage_query($control,'GRANT ALL ON '.$schema.".* TO '$account'@'%'");
 $db=mysqli_connect('127.0.0.1',$account,$secret,$schema,(int)$port);im_check($db&&mysqli_set_charset($db,'utf8mb4'),'Database-local account connects');
 $grants=im_rows('SHOW GRANTS');foreach($grants as$row){im_check(stripos(implode(' ',$row),'SUPER')===false&&stripos(implode(' ',$row),'VARIABLES_ADMIN')===false,'No SQL administration grant');}
 foreach(array('','data','includes')as$suffix){$path=$folder.($suffix===''?'':'/'.$suffix);im_check(mkdir($path,0700),'Owned root');$dirs[]=$path;}
 im_check(copy($root.'/phpBB2/includes/icon_defaults.php',$folder.'/includes/icon_defaults.php'),'Actual portable defaults');
 $custom=phpbb_icon_defaults($root.'/phpBB2');$custom['icons'][0]['img']='images/custom/preserved.gif';
 im_query('CREATE TABLE caller_marker(id INT PRIMARY KEY,value_text VARCHAR(20)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC');
 foreach(array('','ANSI_QUOTES','STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES','TRADITIONAL')as$mode){
  im_query("SET SESSION sql_mode='$mode'");im_reset();$before=im_refs();$hash=hash_file('sha256',$folder.'/data/icons.dat');$state=im_state();$result=im_apply();
  im_check($result===array('created'=>1,'references'=>2,'imported'=>1,'source'=>'serialized'),'Actual custom file import and reference widening');im_check(im_refs()===$before&&hash_file('sha256',$folder.'/data/icons.dat')===$hash&&im_state()===$state,'Every row/source byte and caller session preserved');
  $rows=im_rows('SELECT * FROM fixture_post_icon_config');im_check(count($rows)===1&&$rows[0]['config_value']===serialize($custom),'Exact icon order/auth/images/default maps retained');
  foreach(array('posts'=>'post','topics'=>'topic')as$table=>$stem){$column=null;foreach(im_rows('SHOW FULL COLUMNS FROM fixture_'.$table)as$c){if($c['Field']===$stem.'_icon'){$column=$c;}}im_check($column['Null']==='YES'&&$column['Default']===null&&$column['Comment']==='Größe ä'&&phpbb_icon_reference_type($column['Type']),'Reference metadata and full capacity mode='.$mode.' column='.json_encode($column));im_query("INSERT INTO fixture_$table (".$stem."_icon,body)VALUES(65535,'new')");im_check((string)mysqli_insert_id($db)==='55','Actual next allocation preserved');}
  $snapshot=im_snapshot();im_check(im_apply()===array('created'=>0,'references'=>0,'imported'=>0,'source'=>'database')&&im_snapshot()===$snapshot,'Repeat is byte-exact schema/data no-op');$cases++;
 }
 im_query("SET SESSION sql_mode='STRICT_ALL_TABLES'");im_reset();
 foreach(array('active','implicit')as$kind){im_query('DELETE FROM caller_marker');if($kind==='implicit'){im_query('SET autocommit=0');}else{im_query('START TRANSACTION');}im_query("INSERT INTO caller_marker VALUES(1,'private')");$state=im_state();im_check(im_denied(function(){im_apply();})&&im_state()===$state&&count(im_rows('SELECT * FROM caller_marker'))===1,'Pending caller retained');$other=mysqli_connect('127.0.0.1',$account,$secret,$schema,(int)$port);im_check(!plus_storage_rows($other,'SELECT * FROM caller_marker'),'Rejected migration never commits unrelated pending row');mysqli_close($other);im_query('ROLLBACK');if($kind==='implicit'){im_query('SET autocommit=1');}$cases++;}
 foreach(array('plus_innodb_'.sha1($schema),'attachment:'.md5($schema."\0fixture_attachments"))as$lease){im_rows("SELECT GET_LOCK('$lease',0)");im_check(im_denied(function(){im_apply();}),'Inherited lease refused');im_check(im_rows("SELECT IS_USED_LOCK('$lease') n")[0]['n']===(string)mysqli_thread_id($db),'Inherited lease retained without adoption');im_rows("SELECT RELEASE_LOCK('$lease')");im_check(im_rows("SELECT IS_FREE_LOCK('$lease') n")[0]['n']==='1','No recursive lock leaked');$cases++;}
 foreach(array('bad-bytes','defaults')as$kind){
  im_reset();if($kind==='bad-bytes'){file_put_contents($folder.'/data/icons.dat','corrupt');$before=im_snapshot();im_check(im_denied(function(){im_apply();})&&im_snapshot()===$before,'Invalid existing file refuses before all DDL');}
  else{unlink($folder.'/data/icons.dat');$result=im_apply();im_check($result['source']==='defaults'&&phpbb_icon_config_decode(im_rows('SELECT config_value FROM fixture_post_icon_config')[0]['config_value'])===phpbb_icon_defaults($root.'/phpBB2'),'Missing legacy file uses actual bundled defaults');} $cases++;
 }
 im_reset();im_apply();file_put_contents($folder.'/data/icons.dat','later-invalid-file');$before=im_snapshot();im_check(im_apply()['imported']===0&&im_snapshot()===$before&&file_get_contents($folder.'/data/icons.dat')==='later-invalid-file','Authoritative database never reimports a later legacy file');$cases++;
 require __DIR__.'/post-icon-migration-fixture.php';
 im_reset();im_config('fixture_');$before=im_snapshot();$child=im_cli(array('--post-icons-only'));im_check($child[0]===0&&$child[2]===''&&im_snapshot()===$before,'Actual scoped CLI dry run: '.$child[2]);$cases++;
 $child=im_cli(array('--post-icons-only','--apply','--backup-confirmed','--maintenance-confirmed'));im_check($child[0]===0&&$child[2]===''&&strpos($child[1],'Post icon storage complete:')!==false,'Actual scoped CLI apply: '.$child[2]);$before=im_snapshot();$child=im_cli(array('--post-icons-only','--apply','--backup-confirmed','--maintenance-confirmed'));im_check($child[0]===0&&$child[2]===''&&im_snapshot()===$before,'Actual scoped retry preserves all state');$cases++;
 im_install(file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql'));im_install(file_get_contents($root.'/phpBB2/install/schemas/mysql_basic.sql'));
 im_check(phpbb_icon_config_decode(im_rows('SELECT config_value FROM phpbb_post_icon_config')[0]['config_value'])===phpbb_icon_defaults($root.'/phpBB2'),'Fresh installer seed equals actual runtime defaults');$cases++;
 im_query('DROP TABLE phpbb_post_icon_config');im_query('ALTER TABLE phpbb_posts MODIFY post_icon TINYINT NULL DEFAULT NULL');im_query('ALTER TABLE phpbb_topics MODIFY topic_icon TINYINT NULL DEFAULT NULL');file_put_contents($folder.'/data/icons.dat',serialize($custom));im_config('phpbb_');
 $child=im_cli(array('--apply','--backup-confirmed','--maintenance-confirmed'));im_check($child[0]===0&&$child[2]===''&&strpos($child[1],'Database update complete.')!==false,'Actual full updater custom import: '.substr($child[2],0,900));im_check(im_rows('SELECT config_value FROM phpbb_post_icon_config')[0]['config_value']===serialize($custom),'Full updater imports existing source instead of inserting fresh defaults');$cases++;
 $before=im_snapshot();$child=im_cli(array('--apply','--backup-confirmed','--maintenance-confirmed'));im_check($child[0]===0&&$child[2]===''&&im_snapshot()===$before,'Actual complete updater retry is schema/data exact');$cases++;
 echo 'Post icon migration native: ',$cases,' cases, ',$assertions," assertions PASS; ordinary SQL account, all SQL modes, exact source/rows/defaults/next IDs, active callers, conflicting leases, observed source change, actual scoped/full updater and fresh installer.\n";
}finally{
 if($db){mysqli_close($db);}if($account_created){mysqli_query($control,"DROP USER '$account'@'%'");}if($schema_created){mysqli_query($control,'DROP DATABASE '.$schema);}mysqli_close($control);
 foreach(array('/config.php','/data/icons.dat','/includes/icon_defaults.php')as$file){if(is_file($folder.$file)){unlink($folder.$file);}}foreach(array_reverse($dirs)as$dir){if(is_dir($dir)){rmdir($dir);}}
}
