<?php
// Actual latest-picture/comment formatters, synthetic read-only result rows.
define('IN_PHPBB',true);define('USERS_TABLE','fixture_users');define('POST_USERS_URL','u');
$table_prefix='fixture_';$source=dirname(dirname(__DIR__)).'/phpBB2/';
require $source.'includes/functions.php';require $source.'album_mod/album_constants.php';require $source.'album_mod/album_hierarchy_sql.php';
$common=file_get_contents($source.'album_mod/album_common.php');
if(preg_match('/^function album_html_text\(.*?^\}/ms',$common,$helper)!==1){throw new RuntimeException('Actual Album text formatter');}eval($helper[0]);
function al_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function append_sid($url){return$url;}
class AlbumLatestDatabase{
 var$row=array();var$freed=0;var$queries=array();
 function sql_query($sql){al_check(preg_match('/^SELECT [pc]\./',$sql)===1,'Latest display performs only its real picture/comment SELECT');$this->queries[]=$sql;return'fixture-result';}
 function sql_numrows($result){return$this->row?1:0;}
 function sql_fetchrow($result){return$this->row;}
 function sql_freeresult($result){$this->freed++;return true;}
}
$db=new AlbumLatestDatabase();$phpEx='php';$board_config=array('default_dateformat'=>'Y','board_timezone'=>0,'default_lang'=>'english');date_default_timezone_set('UTC');
$lang=array('Guest'=>'GUEST','Poster'=>'POSTER','Pic_Title'=>'TITLE');
$album_data=array('keys'=>array(1=>0),'data'=>array(array('cat_approval'=>0)));
$album_config=array('last_pic_title_length'=>1,'fullpic_popup'=>false);$cases=0;$failures=0;
$reporting=error_reporting(E_ALL);set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
function al_case($ok,$message){$GLOBALS['cases']++;if(!$ok){$GLOBALS['failures']++;echo'Latest Album mismatch: ',$message,"\n";}}
try{
 foreach(array(array('Äpfel','Ä...'),array('😀Foto','😀...'),array('漢字','漢...'),array('A','A'),array('&Auml;pfel','Ä...'),array('&amp;lt;','&...'),array('0','0'))as$title){
  $db->row=array('pic_id'=>28,'pic_title'=>$title[0],'pic_user_id'=>7,'pic_username'=>'','pic_time'=>0,'pic_cat_id'=>1,'user_id'=>7,'username'=>'Member');$before=$db->freed;$last=null;
  $html=album_get_last_pic_info(array(1),$last);
  $expected=htmlspecialchars($title[1],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8',true);
  al_case(strpos($html,'pic_id=28">'.$expected.'</a>')!==false&&preg_match('//u',$html)===1,'Unicode/one-boundary title '.$title[0]);
  al_case($last===28&&$db->freed===$before+1&&strpos($html,'profile.php?mode=viewprofile&amp;u=7">Member</a>')!==false,'Latest picture retains actual account, navigation and result cleanup');
 }
 foreach(array(1=>'A...',2=>'AÄ...',3=>'AÄ😀...',4=>'AÄ😀漢...',5=>'AÄ😀漢&...',6=>'AÄ😀漢&&...',7=>'AÄ😀漢&&l...',8=>'AÄ😀漢&&lt...',9=>'AÄ😀漢&&lt;',25=>'AÄ😀漢&&lt;',0=>'A...',201=>'AÄ😀漢&&lt;')as$limit=>$text){
  $album_config['last_pic_title_length']=$limit;$db->row=array('pic_id'=>28,'pic_title'=>'AÄ😀漢&amp;&amp;lt;','pic_user_id'=>7,'pic_username'=>'','pic_time'=>0,'pic_cat_id'=>1,'user_id'=>7,'username'=>'Member');$last=null;
  $html=album_get_last_pic_info(array(1),$last);$expected=htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8',true);
  al_case(strpos($html,'pic_id=28">'.$expected.'</a>')!==false&&preg_match('//u',$html)===1,'Bounded character limit and exactly one historical entity decode');
 }
 foreach(array(array('', ''),array(null,''),array("\xc3","\xef\xbf\xbd"),array('&quot;','"'),array('&#39;',"'"),array('<b>😀</b>','<b>😀</b>'),array('&amp;lt;b&amp;gt;','&lt;b&gt;'))as$title){
  $album_config['last_pic_title_length']=200;$db->row=array('pic_id'=>28,'pic_title'=>$title[0],'pic_user_id'=>7,'pic_username'=>'','pic_time'=>0,'pic_cat_id'=>1,'user_id'=>7,'username'=>'Member');$last=null;
  $html=album_get_last_pic_info(array(1),$last);$expected=htmlspecialchars($title[1],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8',true);
  al_case(strpos($html,'pic_id=28">'.$expected.'</a>')!==false&&preg_match('//u',$html)===1,'Empty/null/malformed-byte/quote/markup/entity title stays valid escaped UTF-8');
 }
 $album_config['fullpic_popup']=true;$db->row=array('pic_id'=>28,'pic_title'=>'Picture','pic_user_id'=>7,'pic_username'=>'','pic_time'=>0,'pic_cat_id'=>1,'user_id'=>7,'username'=>'Member');$last=null;
 al_case(strpos(album_get_last_pic_info(array(1),$last),'album_pic.php?pic_id=28" target="_blank" rel="noopener noreferrer">Picture</a>')!==false,'Full-picture popup retains its route and opener protection');
 $album_config['fullpic_popup']=false;
 foreach(array(array(7,'Member','','Member',true),array(7,'0','','0',true),array(null,null,'Old guest','Old guest',false),array(null,null,'0','0',false),array(null,null,'','GUEST',false),array(-1,'Anonymous','Guest &amp; name','Guest & name',false))as$person){
  $db->row=array('comment_pic_id'=>28,'comment_user_id'=>$person[0],'comment_username'=>$person[2],'comment_time'=>0,'user_id'=>$person[0],'username'=>$person[1],'pic_id'=>28,'pic_cat_id'=>1,'pic_title'=>'Photo &amp; <title>');$before=$db->freed;
  $html=album_get_last_comment_info(array(1));$name=htmlspecialchars($person[3],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8',true);
  al_case(strpos($html,'POSTER: '.($person[4]?'<a href="profile.php?mode=viewprofile&amp;u='.$person[0].'">'.$name.'</a>':$name))!==false,'Actual account versus historical guest comment label');
  al_case($db->freed===$before+1&&strpos($html,'album_showpage.php?pic_id=28">Photo &amp; &lt;title&gt;</a>')!==false,'Comment navigation, one stored-text boundary and result cleanup');
 }
 $db->row=array();$before=$db->freed;$last=88;
 al_case(album_get_last_pic_info(array(1),$last)===''&&$last===0&&$db->freed===$before+1,'No picture frees real query result and clears last ID');
 $before=$db->freed;al_case(album_get_last_comment_info(array(1))===''&&$db->freed===$before+1,'No comment frees real query result');
 $before=count($db->queries);$last=88;al_case(album_get_last_pic_info(array(),$last)===''&&$last===0&&album_get_last_comment_info(array())===''&&count($db->queries)===$before,'Empty category list performs no query');
 echo'Latest Album rendering: ',$cases,' cases; differences=',$failures,"; no DB, files or network.\n";
 if($failures){exit(1);}
}finally{restore_error_handler();error_reporting($reporting);}
