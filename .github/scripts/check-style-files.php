<?php
define('IN_PHPBB',true);
$source=dirname(dirname(__DIR__)).'/phpBB2/';
require $source.'includes/php_compat.php';require $source.'includes/functions_style_removal.php';require $source.'includes/functions_style_files.php';
$checks=0;function sf_check($ok,$message){$GLOBALS['checks']++;if(!$ok){throw new RuntimeException($message);}}
function sf_denied($call){$denied=false;try{$call();}catch(PhpbbAclException $e){$denied=true;}sf_check($denied,'Unsafe/failed cleanup refused');}
$owned=sys_get_temp_dir().'/codex_style_files_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
mkdir($owned,0700);mkdir($owned.'/templates',0700);mkdir($owned.'/templates/legacy',0700);mkdir($owned.'/templates/legacy/nested',0700);mkdir($owned.'/templates/fisubsilversh',0700);
file_put_contents($owned.'/outside.txt','preserve');file_put_contents($owned.'/templates/fisubsilversh/default.txt','preserve');
file_put_contents($owned.'/templates/legacy/.hidden','fixture');file_put_contents($owned.'/templates/legacy/nested/file.txt','fixture');
$symlink=@symlink($owned.'/outside.txt',$owned.'/templates/legacy/link');
try{
 $fs=new PhpbbStyleLocalFiles($owned.'/templates');$guard=function(){};
 foreach(array('fisubsilversh','fiSubSilverSH','../outside','legacy.','legacy/../x') as $bad){sf_denied(function()use($fs,$bad,$guard){$fs->remove($bad,$guard);});}
 sf_check(is_file($owned.'/templates/fisubsilversh/default.txt'),'Reserved files preserved');
 sf_denied(function()use($fs){$fs->remove('legacy',function(){phpbb_acl_error('revoked');});});
 sf_check(is_file($owned.'/templates/legacy/.hidden'),'No filesystem effect before authority check');
 $fs->remove('legacy',$guard);sf_check(!file_exists($owned.'/templates/legacy')&&file_get_contents($owned.'/outside.txt')==='preserve','Unused tree removed, symlink target untouched');
 $fs->remove('legacy',$guard);sf_check(!file_exists($owned.'/templates/legacy'),'Absent-tree retry succeeds');
 class StyleFakeFtp extends PhpbbStyleFtpFiles {
  var $mode='mlsd';var $tree;var $writes=array();var $fail=false;var $bad=null;
  function __construct($mode){$this->mode=$mode;$this->tree=array('/forum'=>array('templates'=>'dir'),'/forum/templates'=>array('legacy'=>'dir','fisubsilversh'=>'dir'),'/forum/templates/legacy'=>array('.hidden'=>'file','nested'=>'dir','link'=>'link'),'/forum/templates/legacy/nested'=>array('file.txt'=>'file'));}
  function invoke($operation,$argument=null){
   if($operation==='pwd'){return '/forum';}
   if($operation==='list'){
    sf_check(strpos($argument,'/forum')===0&&strpos($argument,'/link')===false,'FTP never lists an outside path or follows link');
    if(!isset($this->tree[$argument])){return false;}$rows=array();
    foreach($this->tree[$argument] as $name=>$type){
     if($this->mode==='mlsd'){$rows[]=array('name'=>$name,'type'=>$type==='link'?'OS.unix=slink:/outside':$type);}
     else{$rows[]=($type==='dir'?'drwxr-xr-x':($type==='link'?'lrwxrwxrwx':'-rw-r--r--')).' 1 owner group 1 Sep 25 12:00 '.$name.($type==='link'?' -> /outside':'');}
    }
    if($this->bad!==null&&$argument==='/forum/templates/legacy'){$rows[]=$this->bad;}
    return array($this->mode,$rows);
   }
   $this->writes[]=array($operation,$argument);if($this->fail){return false;}
   sf_check(strpos($argument,'/forum/templates/legacy')===0,'FTP mutations confined to selected tree');
   $parent=substr($argument,0,strrpos($argument,'/'));$name=substr($argument,strrpos($argument,'/')+1);
   if(!isset($this->tree[$parent][$name])||($operation==='dir'&&!empty($this->tree[$argument]))){return false;}
   unset($this->tree[$parent][$name]);if($operation==='dir'){unset($this->tree[$argument]);}return true;
  }
 }
 foreach(array('mlsd','unix') as $mode){
  $ftp=new StyleFakeFtp($mode);$ftp->remove('legacy',$guard);sf_check(!isset($ftp->tree['/forum/templates']['legacy'])&&isset($ftp->tree['/forum/templates']['fisubsilversh']),'Both listing formats remove only unused template');
  sf_check(count($ftp->writes)===5,'Hidden entries, nested file, link and directories checked');$ftp->remove('legacy',$guard);
  $ftp=new StyleFakeFtp($mode);$ftp->fail=true;sf_denied(function()use($ftp,$guard){$ftp->remove('legacy',$guard);});sf_check(count($ftp->writes)===1,'FTP stops at first failed destructive operation');
  $ftp=new StyleFakeFtp($mode);$ftp->tree['/forum']['templates']='link';sf_denied(function()use($ftp,$guard){$ftp->remove('legacy',$guard);});sf_check(!$ftp->writes,'Symlink templates root never traversed');
  $ftp=new StyleFakeFtp($mode);$ftp->tree['/forum/templates']['legacy']='link';sf_denied(function()use($ftp,$guard){$ftp->remove('legacy',$guard);});sf_check(!$ftp->writes,'Symlink selected root never traversed');
  $ftp=new StyleFakeFtp($mode);$ftp->bad=$mode==='mlsd'?array('name'=>'../outside','type'=>'file'):'-rw-r--r-- 1 owner group 1 Sep 25 12:00 ../outside';sf_denied(function()use($ftp,$guard){$ftp->remove('legacy',$guard);});sf_check(!$ftp->writes,'Malformed listing rejected before any delete');
  $ftp=new StyleFakeFtp($mode);$calls=0;sf_denied(function()use($ftp,&$calls){$ftp->remove('legacy',function()use(&$calls){if(++$calls===3){phpbb_acl_error('revoked');}});});sf_check(count($ftp->writes)===1,'Authority checked again before each deletion');
 }
 echo 'Style filesystem checks: '.$checks.' assertions; local symlink '.($symlink?'tested':'not available on this host')."; FTP protocol adapter simulated, no network\n";
}finally{
 foreach(array('/templates/legacy/link','/templates/legacy/.hidden','/templates/legacy/nested/file.txt','/templates/fisubsilversh/default.txt','/outside.txt') as $path){if(is_link($owned.$path)||is_file($owned.$path)){unlink($owned.$path);}}
 foreach(array('/templates/legacy/nested','/templates/legacy','/templates/fisubsilversh','/templates','') as $path){if(is_dir($owned.$path)){rmdir($owned.$path);}}
}
