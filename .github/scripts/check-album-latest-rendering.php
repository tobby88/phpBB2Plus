<?php
// Actual latest-picture/comment formatters, synthetic read-only result rows.
define('IN_PHPBB',true);define('USERS_TABLE','fixture_users');define('POST_USERS_URL','u');
$table_prefix='fixture_';$source=dirname(dirname(__DIR__)).'/phpBB2/';
require $source.'includes/functions.php';require $source.'album_mod/album_constants.php';require $source.'album_mod/album_hierarchy_sql.php';
require_once $source.'album_mod/album_functions.php';
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
  $db->row=array('comment_id'=>73,'comment_user_id'=>$person[0],'comment_username'=>$person[2],'comment_time'=>0,'user_id'=>$person[0],'username'=>$person[1],'pic_title'=>'Photo &amp; <title>');$before=$db->freed;
  $html=album_get_last_comment_info(array(1));$name=htmlspecialchars($person[3],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8',true);
  al_case(strpos($html,'POSTER: '.($person[4]?'<a href="profile.php?mode=viewprofile&amp;u='.$person[0].'">'.$name.'</a>':$name))!==false,'Actual account versus historical guest comment label');
  al_case($db->freed===$before+1&&strpos($html,'album_showpage.php?comment_id=73#comment-73">Photo &amp; &lt;title&gt;</a>')!==false,'Latest comment link reaches the exact comment and preserves its escaped picture title');
 }
 $db->row=array();$before=$db->freed;$last=88;
 al_case(album_get_last_pic_info(array(1),$last)===''&&$last===0&&$db->freed===$before+1,'No picture frees real query result and clears last ID');
 $before=$db->freed;al_case(album_get_last_comment_info(array(1))===''&&$db->freed===$before+1,'No comment frees real query result');
 $before=count($db->queries);$last=88;al_case(album_get_last_pic_info(array(),$last)===''&&$last===0&&album_get_last_comment_info(array())===''&&count($db->queries)===$before,'Empty category list performs no query');
 $phpbb_root_path = $source;
 require_once $source.'includes/template.php';
 $theme = array('template_name'=>'fisubsilversh');
 $board_config += array('xs_use_cache'=>0, 'xs_auto_compile'=>0, 'xs_auto_recompile'=>0);
 foreach (array('english','german') as $locale) {
  $lang = array();
  require $source.'language/lang_'.$locale.'/lang_main.php';
  require $source.'language/lang_'.$locale.'/lang_main_album.php';
  foreach (array('combined','comment','rating','edit') as $mode) {
   $template = (new ReflectionClass('Template'))->newInstanceWithoutConstructor();
   $template->vars = &$template->_tpldata['.'][0];
   $template->load_config($source.'templates/fisubsilversh', false);
   $template->set_filenames(array('body'=>$mode==='edit'?'album_comment_body.tpl':'album_showpage_body.tpl'));
   $template->assign_vars(array('U_ALBUM_SEARCH'=>'album_search.php','L_ALBUM'=>$lang['Album'],
    'L_GO'=>$lang['Go'],'L_POSTER'=>$lang['Poster'],'L_POSTED'=>$lang['Posted'],
    'L_BACK_TO_TOP'=>$lang['Back_to_top'],'L_MESSAGE'=>$lang['Message'],'S_MAX_LENGTH'=>65,
    'L_PLEASE_RATE_IT'=>album_html_text($lang['Please_Rate_It']),'L_COMMENT_NO_TEXT'=>album_html_text($lang['Comment_no_text']),
    'L_COMMENT_TOO_LONG'=>album_html_text($lang['Comment_too_long']),'L_MORE_SMILIES'=>$lang['More_emoticons']));
   if ($mode !== 'rating') { $template->assign_block_vars('switch_comment_post',array()); }
   if ($mode === 'combined' || $mode === 'rating') {
    $block = $mode === 'rating' ? 'rate_switch_only' : 'switch_comment_post.rate_comment';
    $template->assign_block_vars($block,array());
    foreach (array(1,2) as $point) { $template->assign_block_vars($block.'.rate_row',array('POINT'=>$point)); }
   }
   if ($mode !== 'edit') {
    foreach (array('coment_switcharo_top','switch_comment','coment_switcharo_bottom') as $block) {
     $template->assign_block_vars($block,array());
    }
    $template->assign_block_vars('commentrow',array('ID'=>41,'U_MINI_POST'=>album_comment_link(41)));
   }
   ob_start();
   try { $template->pparse('body'); $rendered=ob_get_contents(); } finally { ob_end_clean(); }
   al_case(substr_count($rendered,'name="commentform"')===1 && substr_count($rendered,'onsubmit="return checkAlbumComment(this);"')===1 &&
    substr_count($rendered,'<form ')===substr_count($rendered,'</form>') && substr_count($rendered,'<table ')===substr_count($rendered,'</table>'),
    'Actual style renders one discussion form and closes its own forms and tables');
   al_case((strpos($rendered,'name="comment"')!==false)===($mode!=='rating') &&
    (strpos($rendered,'<option value="2">2</option>')!==false)===in_array($mode,array('combined','rating'),true),
    'Actual rating values and text control match available actions');
   al_case($mode==='edit' ? strpos($rendered,$lang['More_emoticons'])!==false :
    strpos($rendered,'href="album_showpage.php?comment_id=41#comment-41"')!==false &&
    strpos($rendered,'id="comment-41"')!==false && strpos($rendered,$lang['Back_to_top'])!==false,
    'Actual translated controls and visible comment fragment');
  }
 }
 echo'Album latest comments and discussion rendering: ',$cases,' cases; differences=',$failures,"; no DB, files or network.\n";
 if($failures){exit(1);}
}finally{restore_error_handler();error_reporting($reporting);}
