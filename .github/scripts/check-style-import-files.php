<?php
define('IN_PHPBB',true);
$source=dirname(dirname(__DIR__)).'/phpBB2/';require $source.'includes/php_compat.php';require $source.'includes/functions_acl_storage.php';require $source.'includes/functions_style_import_files.php';
$checks=0;function sif_check($ok,$message){$GLOBALS['checks']++;if(!$ok){throw new RuntimeException($message);}}
function sif_denied($call){$denied=false;try{$call();}catch(PhpbbAclException $e){$denied=true;}sif_check($denied,'Failed publication is refused');}
$ftp_mode=getenv('PHPBB_STYLE_IMPORT_FTP')==='1';$base=$ftp_mode?getenv('PHPBB_STYLE_IMPORT_FTP_ROOT'):sys_get_temp_dir();
sif_check(is_string($base)&&is_dir($base)&&!is_link($base),'Fixture root exists');
$name='codex_style_publish_'.bin2hex(phpbb_random_bytes(8));$root=rtrim($base,'/\\').'/'.$name;
mkdir($root,0700);mkdir($root.'/templates',0700);mkdir($root.'/templates/fixture',0700);mkdir($root.'/templates/fixture/nested',0700);
$ftp=null;$link=false;
try{
 if($ftp_mode){$ftp=ftp_connect('127.0.0.1',33771,5);sif_check($ftp&&ftp_login($ftp,'fixture','fixture-only')&&ftp_pasv($ftp,true)&&ftp_chdir($ftp,'/'.$name),'Disposable loopback FTP connection');$files=new PhpbbStyleImportFtp($ftp);}
 else{$files=new PhpbbStyleImportLocal($root.'/templates');}
 $guard=function(){};$bytes="Grüße\0binary";
 $entries=array(array('filename'=>'first.tpl','typeflag'=>0,'offset'=>0,'size'=>strlen($bytes)),array('filename'=>'nested/second.tpl','typeflag'=>0,'offset'=>strlen($bytes),'size'=>0));
 phpbb_style_import_publish($files,'fixture',$entries,$bytes,$guard);
 sif_check(file_get_contents($root.'/templates/fixture/first.tpl')===$bytes&&filesize($root.'/templates/fixture/nested/second.tpl')===0,'Real transfer preserves binary bytes and empty file');
 phpbb_style_import_publish($files,'fixture',$entries,'changed bytes',$guard);
 sif_check(file_get_contents($root.'/templates/fixture/first.tpl')===substr('changed bytes',0,strlen($bytes)),'Existing file replacement');
 $before=file_get_contents($root.'/templates/fixture/first.tpl');
 sif_denied(function()use($files,$entries,$bytes){phpbb_style_import_publish($files,'fixture',$entries,$bytes,function(){phpbb_acl_error('revoked');});});
 sif_check(file_get_contents($root.'/templates/fixture/first.tpl')===$before,'Revoked publisher leaves files unchanged');
 unlink($root.'/templates/fixture/nested/second.tpl');mkdir($root.'/templates/fixture/nested/second.tpl');
 try{sif_denied(function()use($files,$entries,$bytes,$guard){phpbb_style_import_publish($files,'fixture',$entries,$bytes,$guard);});sif_check(file_get_contents($root.'/templates/fixture/first.tpl')===$before,'Late target conflict does not overwrite first file');}finally{rmdir($root.'/templates/fixture/nested/second.tpl');}
 file_put_contents($root.'/outside.txt','outside');$link=@symlink($root.'/outside.txt',$root.'/templates/fixture/nested/second.tpl');
 if($link){sif_denied(function()use($files,$entries,$bytes,$guard){phpbb_style_import_publish($files,'fixture',$entries,$bytes,$guard);});sif_check(file_get_contents($root.'/outside.txt')==='outside'&&file_get_contents($root.'/templates/fixture/first.tpl')===$before,'Links neither followed nor replaced');unlink($root.'/templates/fixture/nested/second.tpl');$link=false;}
 if($ftp_mode){
  $bad=array(array('filename'=>'refused.tpl','typeflag'=>0,'offset'=>0,'size'=>strlen($bytes)));file_put_contents($root.'/templates/fixture/refused.tpl','preserve');
  sif_denied(function()use($files,$bad,$bytes,$guard){phpbb_style_import_publish($files,'fixture',$bad,$bytes,$guard);});
  sif_check(file_get_contents($root.'/templates/fixture/refused.tpl')==='preserve','FTP failed rename preserves old target');
  sif_check(count(glob($root.'/templates/fixture/xs_import_*'))===0,'FTP staging files removed after failure');
 }
 echo 'Style publication: '.$checks.' assertions, '.($ftp_mode?'real loopback FTP':'local filesystem')."\n";
}finally{
 if($ftp){ftp_close($ftp);}
 foreach(array('/templates/fixture/nested/second.tpl','/templates/fixture/first.tpl','/templates/fixture/refused.tpl','/outside.txt') as $path){if(is_file($root.$path)||is_link($root.$path)){unlink($root.$path);}}
 foreach(array('/templates/fixture/nested','/templates/fixture','/templates','') as $path){if(is_dir($root.$path)){rmdir($root.$path);}}
}
