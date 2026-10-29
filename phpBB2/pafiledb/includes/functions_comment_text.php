<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(dirname(dirname(__FILE__))).'/includes/bbcode.php';
require_once dirname(dirname(dirname(__FILE__))).'/includes/functions_post.php';

class PaFileDbCommentTextFailure extends RuntimeException
{
 public $limit;
 function __construct($key,$limit=null){$this->limit=$limit;parent::__construct($key);}
}
function pafiledb_comment_text_fail($key,$limit=null){throw new PaFileDbCommentTextFailure($key,$limit);}
function pafiledb_comment_capacities($database)
{
 if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',PA_COMMENTS_TABLE)){pafiledb_comment_text_fail('PA_comment_upgrade');}
 $sql="SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_OCTET_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".PA_COMMENTS_TABLE."') AND COLUMN_NAME IN('comments_text','comments_title','comment_bbcode_uid')";
 if(method_exists($database,'rows')){$rows=$database->rows($sql);}else{$r=$database->sql_query($sql);if(!$r){pafiledb_comment_text_fail('PA_comment_upgrade');}try{$rows=$database->sql_fetchrowset($r);}finally{$database->sql_freeresult($r);}}
 if(!is_array($rows)||count($rows)!==3){pafiledb_comment_text_fail('PA_comment_upgrade');}$out=array();
 foreach($rows as$row){if(!in_array(strtolower($row['DATA_TYPE']),array('tinytext','text','mediumtext','longtext','char','varchar'),true)||!preg_match('/^[1-9][0-9]*$/D',(string)$row['CHARACTER_OCTET_LENGTH'])||(float)$row['CHARACTER_OCTET_LENGTH']>PHP_INT_MAX){pafiledb_comment_text_fail('PA_comment_upgrade');}$out[$row['COLUMN_NAME']]=(int)$row['CHARACTER_OCTET_LENGTH'];}
 return$out;
}
function pafiledb_comment_prepare($title,$message,$settings,$user,$capacities)
{
 global $board_config;
 foreach(array($title,$message)as$value){if(!is_string($value)||strpos($value,"\0")!==false||preg_match('//u',$value)!==1){pafiledb_comment_text_fail('PA_comment_invalid');}}
 $title=trim(str_replace(array("\r\n","\r"),"\n",$title));$message=trim(str_replace(array("\r\n","\r"),"\n",$message));
 if($message===''){pafiledb_comment_text_fail('Empty_message');}
 if(!isset($settings['max_comment_chars'])||!preg_match('/^[0-9]{1,10}$/D',(string)$settings['max_comment_chars'])||(float)$settings['max_comment_chars']>2147483647){pafiledb_comment_text_fail('PA_comment_invalid');}
 $limit=(int)$settings['max_comment_chars'];$length=preg_match_all('/./us',$message);if($length===false){pafiledb_comment_text_fail('PA_comment_invalid');}if($length>$limit){pafiledb_comment_text_fail('PA_comment_too_long',$limit);}
 if(strlen($title)>$capacities['comments_title']||strlen($message)>$capacities['comments_text']){pafiledb_comment_text_fail('PA_comment_storage_limit');}
 $flags=array();foreach(array('allow_html'=>'user_allowhtml','allow_bbcode'=>'user_allowbbcode','allow_smilies'=>'user_allowsmile')as$key=>$field){if(!isset($settings[$key])||!in_array((string)$settings[$key],array('0','1'),true)){pafiledb_comment_text_fail('PA_comment_invalid');}$flags[$key]=(string)$settings[$key]==='1'&&!empty($user[$field]);}
 $uid=$flags['allow_bbcode']?make_bbcode_uid():'';$old=$board_config;
 if(isset($settings['_html_tags'])){if(!is_string($settings['_html_tags'])){pafiledb_comment_text_fail('PA_comment_invalid');}$board_config['allow_html_tags']=$settings['_html_tags'];}
 try{$prepared=prepare_message($flags['allow_html']?addslashes($message):$message,$flags['allow_html'],$flags['allow_bbcode'],$flags['allow_smilies'],$uid);if($flags['allow_html']){$prepared=stripslashes($prepared);}}
 finally{$board_config=$old;}
 if(!is_string($prepared)||preg_match('//u',$prepared)!==1||strlen($prepared)>$capacities['comments_text']||strlen($uid)>$capacities['comment_bbcode_uid']){pafiledb_comment_text_fail('PA_comment_storage_limit');}
 return array('title'=>$title,'text'=>$prepared,'uid'=>$uid,'raw_text'=>$message,'raw_title'=>$title);
}
function pafiledb_comment_safe_html($text,$allow_html)
{
 if(!$allow_html){return htmlspecialchars($text,ENT_NOQUOTES|ENT_SUBSTITUTE,'UTF-8',false);}
 // Use the forum's maintained tag/attribute sanitizer while keeping already
 // stored entities intact. Reusing the input encoder here would double-escape.
 $pattern='#<[^\w<]*(\w+)((?:"[^"]*"|\'[^\']*\'|[^<>\'"])+)?>#';$matches=array();$parts=preg_split($pattern,$text);$count=preg_match_all($pattern,$text,$matches);
 if(!is_array($parts)||$count===false||preg_last_error()!==PREG_NO_ERROR){return htmlspecialchars($text,ENT_NOQUOTES|ENT_SUBSTITUTE,'UTF-8',false);}
 $out='';foreach($parts as$part){$out.=htmlspecialchars($part,ENT_NOQUOTES|ENT_SUBSTITUTE,'UTF-8',false);$tag=array(array_shift($matches[0]),array_shift($matches[1]),array_shift($matches[2]));$out.=clean_html($tag,false);}return$out;
}
function pafiledb_comment_filter_rendered($text,$settings)
{
 $original=$text;
 if(empty($settings['allow_comment_images'])){$replacement=htmlspecialchars(isset($settings['no_comment_image_message'])?(string)$settings['no_comment_image_message']:'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8',false);$text=preg_replace_callback('#<img\b[^>]*>#i',function()use($replacement){return$replacement;},$text);}
 if($text!==null&&empty($settings['allow_comment_links'])){$replacement=htmlspecialchars(isset($settings['no_comment_link_message'])?(string)$settings['no_comment_link_message']:'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8',false);$text=preg_replace_callback('#<a\b[^>]*>.*?</a\s*>#is',function()use($replacement){return$replacement;},$text);if($text!==null){$text=preg_replace('#</?a\b[^>]*>#i','',$text);}}
 return$text===null?htmlspecialchars($original,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8',false):$text;
}
function pafiledb_comment_render($text,$uid,$settings,$smilies=false,$words=array(),$replacements=array())
{
 $text=pafiledb_comment_safe_html((string)$text,!empty($settings['allow_html']));
 if(!empty($settings['allow_bbcode'])&&is_string($uid)&&preg_match('/^[a-z0-9]{1,10}$/Di',$uid)){$text=bbencode_second_pass($text,$uid);}
 if($words){$censored=preg_replace($words,$replacements,$text);if($censored!==null){$text=$censored;}}
 $text=pafiledb_comment_filter_rendered($text,$settings);
 if(!empty($settings['allow_comment_links'])){$text=make_clickable($text);}
 if($smilies&&!empty($settings['allow_smilies'])){$text=smilies_pass($text);}
 return str_replace("\n","\n<br />\n",$text);
}
