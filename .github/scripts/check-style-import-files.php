<?php
define('IN_PHPBB',true);
$source=dirname(dirname(__DIR__)).'/phpBB2/';require $source.'includes/php_compat.php';require $source.'includes/functions_acl_storage.php';require $source.'includes/functions_style_import_files.php';
require $source.'includes/functions_style_import_journal.php';
$checks=0;function sif_check($ok,$message){$GLOBALS['checks']++;if(!$ok){throw new RuntimeException($message);}}
function sif_denied($call){$denied=false;try{$call();}catch(PhpbbAclException $e){$denied=true;}sif_check($denied,'Failed publication is refused');}
$ftp_mode=getenv('PHPBB_STYLE_IMPORT_FTP')==='1';$base=$ftp_mode?getenv('PHPBB_STYLE_IMPORT_FTP_ROOT'):sys_get_temp_dir();
sif_check(is_string($base)&&is_dir($base)&&!is_link($base),'Fixture root exists');
$name='codex_style_publish_'.bin2hex(phpbb_random_bytes(8));$root=rtrim($base,'/\\').'/'.$name;
mkdir($root,0700);mkdir($root.'/templates',0700);mkdir($root.'/templates/fixture',0700);mkdir($root.'/templates/fixture/nested',0700);
$ftp=null;$link=false;
try{
 if($ftp_mode){$ftp=ftp_connect('127.0.0.1',33771,5);sif_check($ftp&&ftp_login($ftp,'fixture','fixture-only')&&ftp_pasv($ftp,true)&&ftp_chdir($ftp,'/'.$name),'Disposable loopback FTP connection');$files=new PhpbbStyleImportFtp($ftp,'127.0.0.1:33771/fixture');}
 else{$files=new PhpbbStyleImportLocal($root.'/templates');}
 $guard=function(){};$bytes="Grüße\0binary";
 $entries=array(array('filename'=>'first.tpl','typeflag'=>0,'offset'=>0,'size'=>strlen($bytes)),array('filename'=>'nested/second.tpl','typeflag'=>0,'offset'=>strlen($bytes),'size'=>0));
 phpbb_style_import_publish($files,'fixture',$entries,$bytes,$guard);
 sif_check(file_get_contents($root.'/templates/fixture/first.tpl')===$bytes&&filesize($root.'/templates/fixture/nested/second.tpl')===0,'Real transfer preserves binary bytes and empty file');
 sif_check($files->read('fixture/first.tpl',strlen($bytes))===$bytes&&$files->read('fixture/nested/second.tpl',0)==='','Bounded reads preserve binary and empty files');
 sif_denied(function()use($files,$bytes){$files->read('fixture/first.tpl',strlen($bytes)-1);});
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
  mkdir($root.'/private',0700);
  $journal=PhpbbStyleImportJournal::prepare($files,$root.'/private','loopback-fixture',array('fixture/first.tpl'=>$bytes,'fixture/nested/second.tpl'=>''),$guard);
  $id=$journal->id();$seal=$journal->seal();
  $files->put('fixture/first.tpl',$bytes,$guard,$journal->stage_tag(0,'new'));
  $journal=PhpbbStyleImportJournal::reopen($root.'/private',$id,$seal,'loopback-fixture');
  $journal->apply($files,$guard,true);
  sif_check(file_get_contents($root.'/templates/fixture/first.tpl')===$before&&!file_exists($root.'/templates/fixture/nested/second.tpl'),'Real FTP partial-batch rollback restores original');
  $stage='fixture/xs_import_'.$journal->stage_tag(0,'new').'.tmp';
  file_put_contents($root.'/templates/'.$stage,substr($bytes,0,3));
  $empty_stage=$files->stage_name('fixture/nested/second.tpl',$journal->stage_tag(1,'new'));file_put_contents($root.'/templates/'.$empty_stage,'');
  $journal->apply($files,$guard);
  sif_check(!file_exists($root.'/templates/'.$stage)&&!file_exists($root.'/templates/'.$empty_stage)&&file_get_contents($root.'/templates/fixture/first.tpl')===$bytes&&file_get_contents($root.'/templates/fixture/nested/second.tpl')==='','Real FTP resumes and cleans partial/empty stages');
  $journal->apply($files,$guard,true);
  file_put_contents($root.'/templates/'.$stage,'foreign');
  sif_denied(function()use($journal,$files,$guard){$journal->apply($files,$guard);});
  sif_check(file_get_contents($root.'/templates/'.$stage)==='foreign'&&file_get_contents($root.'/templates/fixture/first.tpl')===$before,'Real FTP refuses foreign stage without target changes');
  unlink($root.'/templates/'.$stage);
  $original_endpoint=$files->endpoint;$files->endpoint='other-account';
  sif_denied(function()use($journal,$files,$guard){$journal->apply($files,$guard);});$files->endpoint=$original_endpoint;
  if(getenv('PHPBB_STYLE_IMPORT_FTP_FAULTS')==='1'){
   // Explicit loopback fault-server mode: SIZE lies for the growing file;
   // RETR accepts but never sends the stalled file. Never target real hosts.
   foreach(array('growing-fixture.tpl','stalled-fixture.tpl') as $fault){
    file_put_contents($root.'/templates/fixture/'.$fault,$fault==='growing-fixture.tpl'?str_repeat('x',131072):'x');
    $fault_ftp=ftp_connect('127.0.0.1',33771,60);
    sif_check($fault_ftp&&ftp_login($fault_ftp,'fixture','fixture-only')&&ftp_pasv($fault_ftp,true)&&ftp_chdir($fault_ftp,'/'.$name),'Fault-injection connection stays local');
    $fault_files=new PhpbbStyleImportFtp($fault_ftp,'127.0.0.1:33771/fixture');$started=microtime(true);
    try{
     sif_denied(function()use($fault_files,$fault){$fault_files->read('fixture/'.$fault,1);});
     sif_check(microtime(true)-$started<40&&$fault_files->ftp===null,'Oversized/stalled active transfer is bounded and its connection closed');
    }finally{if($fault_files->ftp){ftp_close($fault_files->ftp);}unlink($root.'/templates/fixture/'.$fault);}
   }
  }
 }
 echo 'Style publication: '.$checks.' assertions, '.($ftp_mode?'real loopback FTP':'local filesystem')."\n";
}finally{
 if($ftp){ftp_close($ftp);}
 if(isset($journal)){foreach(scandir($journal->directory) as $file){if(preg_match('/^(?:manifest|[0-9]{4}-(?:old|new))\.backup\.php$/D',$file)){unlink($journal->directory.DIRECTORY_SEPARATOR.$file);}}rmdir($journal->directory);}
 if(is_dir($root.'/private')){rmdir($root.'/private');}
 foreach(array('/templates/fixture/nested/second.tpl','/templates/fixture/first.tpl','/templates/fixture/refused.tpl','/outside.txt') as $path){if(is_file($root.$path)||is_link($root.$path)){unlink($root.$path);}}
 foreach(array('/templates/fixture/nested','/templates/fixture','/templates','') as $path){if(is_dir($root.$path)){rmdir($root.$path);}}
}
