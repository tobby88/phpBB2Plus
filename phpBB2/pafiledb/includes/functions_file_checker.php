<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_file_actions.php';
require_once dirname(__FILE__) . '/functions_file_reference.php';

function pafiledb_checker_key($identity,$paths)
{
    return $identity===null?null:$paths[$identity['scope']]."\0".$identity['name'];
}
function pafiledb_checker_physical_key($directory,$name,$root,$paths)
{
    if(!is_string($directory)||preg_match('/[\x00-\x1f\x7f]/',$directory)||!PaFileDbFileStorage::filename($name)){return null;}
    $resolved=realpath($root.'/'.$directory);if($resolved===false){return null;}$resolved=str_replace('\\','/',$resolved);
    foreach($paths as$path){if(DIRECTORY_SEPARATOR===chr(92)?strcasecmp($resolved,$path)===0:$resolved===$path){return$path."\0".$name;}}
    return null;
}
function pafiledb_checker_rows($storage,$sql,$callback)
{
    $result=$storage->sql_query($sql);try{while($row=$storage->sql_fetchrow($result)){call_user_func($callback,$row);}}finally{$storage->sql_freeresult($result);}
}
function pafiledb_checker_add_bytes($left,$right)
{
    // Keep an exact total even on 32-bit PHP with several large downloads.
    $left=(string)$left;$right=(string)$right;$carry=0;$out='';
    for($a=strlen($left)-1,$b=strlen($right)-1;$a>=0||$b>=0||$carry;$a--,$b--){$sum=($a>=0?(int)$left[$a]:0)+($b>=0?(int)$right[$b]:0)+$carry;$out=($sum%10).$out;$carry=(int)floor($sum/10);}
    return$out;
}

// The same lease as uploads/recovery keeps pending physical publications and
// committed references stable throughout this report. The checker SQL channel
// rejects every DML statement and cannot prepare a durable file operation.
function pafiledb_file_check($root=null,$board_url=null)
{
    global$db,$table_prefix;
    $storage=null;
    try{
        if(!defined('IN_ADMIN')||!IN_ADMIN||PA_FILE_JOURNAL_TABLE!==$table_prefix.'pa_file_journal'||PA_MIRRORS_TABLE!==$table_prefix.'pa_mirrors'){pafiledb_file_fail('Not_Authorised');}
        $actor=pafiledb_mutation_actor();$storage=new PaFileDbFileCoordinator($db,$table_prefix);$storage->begin_checker();
        $authority=new PaFileDbFileAuthority($storage,$table_prefix,$actor,'checker');$authority->select('checker',array());$storage->guard_commit(function()use($authority){return$authority->check();});
        if($root===null){$root=dirname(dirname(__DIR__));}if($board_url===null){$board_url=get_formated_url();}
        $origin=is_string($board_url)?@parse_url($board_url):false;if(!$origin||empty($origin['host'])||empty($origin['scheme'])||!in_array(strtolower($origin['scheme']),array('http','https'),true)||isset($origin['user'])||isset($origin['pass'])||isset($origin['query'])||isset($origin['fragment'])){pafiledb_file_fail('PA_checker_failed');}
        $directories=pafiledb_file_directories($authority->settings());foreach($directories as&$relative){$relative=rtrim($relative,'/');}unset($relative);
        $files=new PaFileDbFileStorage(function($context)use($authority){return in_array($context['action'],array('roots','inventory'),true)&&$authority->check();},$directories,$root);
        $root=realpath($root);$paths=array();$owned=array();
        foreach($directories as$scope=>$directory){$paths[$scope]=str_replace('\\','/',realpath($root.'/'.$directory));foreach($files->inventory($scope)as$name=>$bytes){$key=$paths[$scope]."\0".$name;if(!isset($owned[$key])){$owned[$key]=array('scope'=>$scope,'name'=>$name,'bytes'=>$bytes,'used'=>false);}}}
        $report=array('missing_files'=>array(),'missing_screenshots'=>array(),'unreferenced'=>array(),'bytes'=>'0');
        pafiledb_checker_rows($storage,'SELECT file_id,unique_name,file_dir,file_dlurl,file_ssurl FROM '.PA_FILES_TABLE.' ORDER BY file_id',function($row)use(&$owned,&$report,$root,$paths,$directories,$board_url){
            $key=pafiledb_checker_physical_key($row['file_dir'],$row['unique_name'],$root,$paths);
            if($key!==null){if(isset($owned[$key])){$owned[$key]['used']=true;}else{$report['missing_files'][]=array('file_id'=>(int)$row['file_id'],'url'=>$row['file_dlurl']);}}
            $key=pafiledb_checker_key(pafiledb_file_url_identity($row['file_dlurl'],$board_url,$directories),$paths);if($key!==null&&isset($owned[$key])){$owned[$key]['used']=true;}
            $key=pafiledb_checker_key(pafiledb_file_url_identity($row['file_ssurl'],$board_url,$directories),$paths);
            if($key!==null){if(isset($owned[$key])){$owned[$key]['used']=true;}else{$report['missing_screenshots'][]=(int)$row['file_id'];}}
        });
        pafiledb_checker_rows($storage,'SELECT mirror_id,unique_name,file_dir,file_dlurl FROM '.PA_MIRRORS_TABLE.' ORDER BY mirror_id',function($row)use(&$owned,$root,$paths,$directories,$board_url){
            $key=pafiledb_checker_physical_key($row['file_dir'],$row['unique_name'],$root,$paths);if($key!==null&&isset($owned[$key])){$owned[$key]['used']=true;}
            $key=pafiledb_checker_key(pafiledb_file_url_identity($row['file_dlurl'],$board_url,$directories),$paths);if($key!==null&&isset($owned[$key])){$owned[$key]['used']=true;}
        });
        pafiledb_checker_rows($storage,'SELECT file_scope,file_dir,filename FROM '.PA_FILE_JOURNAL_TABLE.' WHERE item_state=0 ORDER BY operation_id,item_index',function($row)use(&$owned,$root,$paths,$directories){
            if(!isset($directories[$row['file_scope']])||$row['file_dir']!==$directories[$row['file_scope']]||!PaFileDbFileStorage::filename($row['filename'])){pafiledb_file_fail('PA_file_upgrade');}
            $key=pafiledb_checker_physical_key($row['file_dir'],$row['filename'],$root,$paths);if($key!==null&&isset($owned[$key])){$owned[$key]['used']=true;}
        });
        foreach($owned as$item){if($item['used']){continue;}$report['bytes']=pafiledb_checker_add_bytes($report['bytes'],$item['bytes']);$report['unreferenced'][]=$directories[$item['scope']].'/'.$item['name'];}
        sort($report['unreferenced'],SORT_STRING);$authority->check();$storage->commit();return$report;
    }finally{if($storage){$storage->release();}}
}
