<?php
// Exercise the real import controller, with filesystem writes confined to our
// disposable directory. No database or live installation is used.
define('IN_PHPBB', true); define('IN_XS', true);
$source = dirname(dirname(__DIR__)) . '/phpBB2/';
$code = file_get_contents($source . 'admin/xs_include.php');
foreach (array('STYLE_HEADER_START','STYLE_HEADER_END','TAR_HEADER_PACK','TAR_HEADER_UNPACK','XS_MAX_STYLE_UPLOAD_BYTES','XS_MAX_STYLE_UNPACKED_BYTES','XS_MAX_STYLE_FILES','XS_MAX_ITEMS_PER_STYLE') as $constant) {
    preg_match('/define\(\'' . $constant . '\', ([^;]+);/', $code, $match); eval($match[0]);
}
// Load only unchanged production functions; do not execute ACP bootstrapping.
$tokens = token_get_all($code);
for ($i=0; $i<count($tokens); $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
    $j=$i+1; while (is_array($tokens[$j]) && $tokens[$j][0]===T_WHITESPACE) { $j++; }
    if (!is_array($tokens[$j]) || !in_array($tokens[$j][1], array('xs_get_style_header','xs_fix_dir','xs_tpl_name','xs_create_dir','xs_write_file','xs_in_array','pack_style','pack_dir'), true)) { continue; }
    $body=''; $depth=0; $started=false;
    for (; $i<count($tokens); $i++) {
        $token=$tokens[$i]; $body.=is_array($token)?$token[1]:$token;
        if ($token==='{') { $depth++; $started=true; } elseif ($token==='}') { $depth--; }
        if ($started && !$depth) { break; }
    }
    eval($body);
}
class ArchiveResult extends RuntimeException {}
function xs_error($message) { $GLOBALS['last_archive_error']=$message; throw new ArchiveResult('rejected'); }
function xs_message($title,$message) { throw new ArchiveResult('success'); }
function append_sid($url) { return $url; }
function sa_check($ok,$message) { $GLOBALS['checks']++; if (!$ok) { throw new RuntimeException($message); } }
function sa_entry($name,$contents='',$type='0',$size=null) {
    $length=strlen($contents);
    return pack(TAR_HEADER_PACK,$name,'100644','0','0',$size===null?decoct($length):$size,'0','0',$type,'','ustar','','','','','','','')
        .$contents.str_repeat("\0", (512-$length%512)%512);
}
function sa_archive($tar) {
    $names=array('fixture','comment','Fixture'); $lengths=''; foreach($names as $name){$lengths.=chr(strlen($name));}
    $size=strlen(STYLE_HEADER_START)+9+strlen($lengths)+strlen(implode('',$names))+strlen(STYLE_HEADER_END); $compressed=gzcompress($tar);
    return STYLE_HEADER_START.pack('NN',$size,$size+strlen($compressed)).chr(count($names)).$lengths.implode('',$names).STYLE_HEADER_END.$compressed;
}
function sa_import($archive,$local=true,$listing=false,$get='') {
    global $source,$owned,$lang,$phpEx;
    $phpbb_root_path=$source;
    $filename='input.style';file_put_contents($owned.'/'.$filename,$archive);clearstatcache();
    $write_local=$local;$write_local_dir=$owned.'/templates/';$list_only=$listing;$get_file=$get;$HTTP_POST_VARS=array('total'=>'0');$HTTP_GET_VARS=array();
    try { include $source.'admin/xs_include_import2.php'; }
    catch(ArchiveResult $e) { return $e->getMessage(); }
    throw new RuntimeException('Controller did not terminate');
}
$owned=sys_get_temp_dir().'/codex_style_archive_'.uniqid('',true);mkdir($owned,0700);mkdir($owned.'/templates',0700);mkdir($owned.'/templates/fixture',0700);
define('XS_TEMP_DIR',$owned.'/');$phpEx='php';$lang=array();include $source.'language/lang_english/lang_xs.php';$lang['Information']='Information';$checks=0;
try {
    $prefix=sa_entry('first.tpl','replacement');
    file_put_contents($owned.'/templates/fixture/first.tpl','preserve');
    sa_check(sa_import(sa_archive($prefix.sa_entry('../escape.tpl','bad'))) === 'rejected','Invalid suffix refused');
    sa_check(file_get_contents($owned.'/templates/fixture/first.tpl') === 'preserve','Invalid suffix must not overwrite a valid prefix file');
    $end=str_repeat("\0",1024);
    $bad=array(
        'traversal'=>sa_entry('../escape.tpl','bad').$end,
        'absolute'=>sa_entry('/escape.tpl','bad').$end,
        'absolute directory'=>sa_entry('/','','5').$end,
        'empty directory'=>sa_entry('','','5').$end,
        'backslash'=>sa_entry('dir\\file.tpl','bad').$end,
        'code'=>sa_entry('bad.PHP','bad').$end,
        'ini'=>sa_entry('dir/.user.ini','bad').$end,
        'config'=>sa_entry('dir/xs_config.cfg','bad').$end,
        'link'=>sa_entry('link','','2').$end,
        'directory payload'=>sa_entry('dir/','payload','5').$end,
        'invalid number'=>sa_entry('bad.tpl','','0','8').$end,
        'oversized'=>sa_entry('bad.tpl','','0',decoct(XS_MAX_STYLE_UPLOAD_BYTES+1)).$end,
        'duplicate'=>sa_entry('first.tpl','bad').$end,
        'case collision'=>sa_entry('FIRST.tpl','bad').$end,
        'parent file'=>sa_entry('first.tpl/child.tpl','bad').$end,
        'child before parent'=>sa_entry('parent/child.tpl','bad').sa_entry('parent','bad').$end,
        'space alias'=>sa_entry('dir /file.tpl','bad').$end,
        'device alias'=>sa_entry('con.txt','bad').$end,
        'embedded NUL'=>sa_entry("bad\0evil.tpl",'bad').$end,
        'invalid UTF8'=>sa_entry("bad\xff.tpl",'bad').$end,
        'short block'=>substr(sa_entry('bad.tpl','payload'),0,-1),
        'missing trailer'=>'',
        'half trailer'=>str_repeat("\0",512),
        'hidden tail'=>$end.sa_entry('bad.tpl','bad'),
        'too many'=>str_repeat(sa_entry('./','','5'),XS_MAX_STYLE_FILES).$end
    );
    foreach($bad as $label=>$suffix){
        foreach(array(array(true,false),array(false,false),array(true,true)) as $mode){
            sa_check(sa_import(sa_archive($prefix.$suffix),$mode[0],$mode[1],'first.tpl')==='rejected',$label.' rejected before all modes');
            sa_check(file_get_contents($owned.'/templates/fixture/first.tpl')==='preserve',$label.' preserves destination');
            sa_check(count(glob($owned.'/xs_import_*'))===0,$label.' creates no FTP staging files');
        }
    }
    $valid=sa_entry('./','','5').sa_entry('nested/','','5').sa_entry('nested/ok.tpl',"Grüße\0binary").sa_entry('empty.tpl').$end;
    sa_check(count(phpbb_style_archive_entries($valid))===4,'Valid archive manifest');
    $listed=sa_import(sa_archive($valid),true,true);
    sa_check($listed==='success'&&!file_exists($owned.'/templates/fixture/nested'),'Listing is entirely read-only, even with write_local set: '.($listed==='success'?'unexpected directory':$GLOBALS['last_archive_error']));
    sa_check(sa_import(sa_archive($valid))==='success','Root and nested directories from XS exports accepted');
    sa_check(file_get_contents($owned.'/templates/fixture/nested/ok.tpl')==="Grüße\0binary"&&filesize($owned.'/templates/fixture/empty.tpl')===0,'Binary bytes and empty files preserved');
    // Export using the real packer, including its historical zero checksums.
    $phpbb_root_path=$owned.'/';$template_dir='templates/';$pack_error='';$pack_list=array();$pack_replace=array();
    $exported=pack_style('fixture','fixture',array(array('style_name'=>'Fixture')),'round trip');
    sa_check($exported!==''&&$pack_error==='','Production packer creates a valid fixture');
    sa_check(sa_import($exported)==='success','Production export/import round trip');
    // Unique entries exercise the actual limit, rather than duplicate rejection.
    $many='';for($i=0;$i<XS_MAX_STYLE_FILES;$i++){$many.=sa_entry('item'.$i.'.tpl');}
    sa_check(count(phpbb_style_archive_entries($many.$end))===XS_MAX_STYLE_FILES,'Exact entry limit accepted');
    $denied=false;try{phpbb_style_archive_entries($many.sa_entry('extra.tpl').$end);}catch(RuntimeException $e){$denied=true;}
    sa_check($denied,'Entry limit enforced');
    echo 'Style archive: '.$checks." assertions passed\n";
} finally {
    foreach(array('/templates/fixture/nested/ok.tpl','/templates/fixture/empty.tpl','/templates/fixture/first.tpl','/input.style') as $path){if(is_file($owned.$path)){unlink($owned.$path);}}
    foreach(array('/templates/fixture/nested','/templates/fixture','/templates','') as $path){if(is_dir($owned.$path)){rmdir($owned.$path);}}
}
