<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_file_storage.php';

// A reference describes an owned basename, never a capability to change it.
// Keep reports and journal recovery consistent across old HTTP/www URLs,
// relative references and percent-encoded Unicode names.
function pafiledb_file_url_identity($value,$board_url,$directories)
{
    if(!is_string($value)||$value===''||preg_match('/[\x00-\x1f\x7f\\\\]/',$value)){return null;}
    $parts=@parse_url($value);$board=@parse_url($board_url);if(!$parts||!$board||isset($parts['user'])||isset($parts['pass'])){return null;}
    if(isset($parts['host'])){
        if(empty($board['host'])||(isset($parts['scheme'])&&!in_array(strtolower($parts['scheme']),array('http','https'),true))||
            strcasecmp(preg_replace('/^www\./i','',$parts['host']),preg_replace('/^www\./i','',$board['host']))!==0||
            (isset($parts['port'])&&!in_array($parts['port'],array(80,443,isset($board['port'])?$board['port']:0),true))){return null;}
    }elseif(isset($parts['scheme'])){return null;}
    $path=isset($parts['path'])?rawurldecode($parts['path']):'';
    if(preg_match('//u',$path)!==1||preg_match('/[\x00-\x1f\x7f\\\\]/',$path)){return null;}
    $base=isset($board['path'])?rtrim(rawurldecode($board['path']),'/'):'';
    if($path===''||$path[0]!=='/'){$path=$base.'/'.$path;}
    $segments=array();foreach(explode('/',$path)as$part){if($part===''||$part==='.'){continue;}if($part==='..'){array_pop($segments);}else{$segments[]=$part;}}$path='/'.implode('/',$segments);
    foreach($directories as$scope=>$relative){
        foreach(array($relative.'/',$base.'/'.$relative.'/')as$prefix){if(strpos($path,$prefix)===0){$name=substr($path,strlen($prefix));if(PaFileDbFileStorage::filename($name)){return array('scope'=>$scope,'name'=>$name);}}}
    }
    return null;
}
