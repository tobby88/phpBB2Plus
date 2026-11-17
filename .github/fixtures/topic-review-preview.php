<?php
namespace ReviewPreview;
// Actual full review function and XS template; bootstrap, SQL result rows,
// authorization and final attachment output are controlled local fixtures.
function check($ok,$message){if(!$ok){throw new \RuntimeException($message);}$GLOBALS['rp_checks']++;}
function obtain_word_list(&$words,&$replacements){$words=$replacements=array();}
function create_date($format,$when,$zone){return \create_date($format,$when,$zone);}
function make_clickable($text){return \make_clickable($text);}
function get_icon_title($icon){return '';}
function get_calendar_title($time,$duration){return '[calendar]';}
function attach_setup_viewtopic_auth(&$unused,&$sql){}
function session_pagestart($ip,$forum){return $GLOBALS['userdata'];}
function init_userprefs($user){}
function auth($type,$forum,$user,$row){return $GLOBALS['rp_auth'];}
function init_display_review_attachments($auth){$GLOBALS['rp_attach_init'][]=$auth;}
function display_review_attachments($id,$flag,$auth){$GLOBALS['rp_attach_rows'][]=array($id,$flag,$auth);}
class Response extends \RuntimeException{}
function message_die($level,$message){throw new Response($message);}
class Rows {
 var $rows,$index=0;function __construct($rows){$this->rows=$rows;}
}
class Database {
 var $queries=array();
 function sql_query($sql){$this->queries[]=$sql;if(strpos($sql,'FROM '.TOPICS_TABLE.' t, '.FORUMS_TABLE.' f')!==false){return new Rows(array($GLOBALS['rp_forum']));}if(strpos($sql,'FROM '.POSTS_TABLE.' p, '.USERS_TABLE.' u, '.POSTS_TEXT_TABLE.' pt')!==false){return new Rows($GLOBALS['rp_posts']);}throw new \RuntimeException('Unexpected review SQL');}
 function sql_fetchrow($result){return isset($result->rows[$result->index])?$result->rows[$result->index++]:false;}
 function sql_freeresult($result){}
}
$GLOBALS['rp_checks']=0;$source=file_get_contents($GLOBALS['phpbb_root_path'].'includes/topic_review.php');
foreach(array('page_header','page_tail')as$file){$include='include($phpbb_root_path . \'includes/'.$file.'.\'.$phpEx);';check(substr_count($source,$include)===1,'exact bootstrap include boundary');$source=str_replace($include,'',$source);}
eval('namespace ReviewPreview;'.substr($source,5));
foreach(array('TOPICS_TABLE'=>'fixture_topics','FORUMS_TABLE'=>'fixture_forums','POSTS_TABLE'=>'fixture_posts','USERS_TABLE'=>'fixture_users','POSTS_TEXT_TABLE'=>'fixture_posts_text','AUTH_ALL'=>0,'GENERAL_MESSAGE'=>1,'GENERAL_ERROR'=>2)as$key=>$value){if(!defined($key)){define($key,$value);}}
$rp_views=0;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new \RuntimeException($message);}});
try{
 foreach(array('english','german')as$locale){foreach(array(true,false)as$inline){foreach(array('allowed','denied','missing')as$permission){foreach(array(false,true)as$logged){
  $lang=array();require $GLOBALS['phpbb_root_path'].'language/lang_'.$locale.'/lang_main.php';$board_config=array('xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'default_lang'=>$locale,'hide_removed_author_names'=>'1','default_dateformat'=>'Y-m-d','board_timezone'=>0,'allow_html'=>0,'allow_bbcode'=>1,'allow_smilies'=>0,'posts_per_page'=>10);$userdata=array('user_id'=>$logged?7:-1,'session_logged_in'=>$logged);$theme=array('template_name'=>'fisubsilversh','td_class1'=>'row1','td_class2'=>'row2','td_color1'=>'ffffff','td_color2'=>'eeeeee');$images=array('icon_minipost'=>'post.gif');$orig_word=$replacement_word=array();
  $rp_auth=array('auth_read'=>true,'auth_read_type'=>'fixture','auth_view'=>true,'auth_download'=>$permission==='allowed');if($permission==='missing'){$rp_auth=array('auth_read'=>true,'auth_read_type'=>'fixture');}$is_auth=$rp_auth;
  $rp_forum=array('forum_id'=>3,'topic_title'=>'Review title','topic_calendar_time'=>0,'topic_calendar_duration'=>0,'topic_first_post_id'=>10);$rp_posts=array();
  foreach(array(30,20,10)as$id){$rp_posts[]=array('post_id'=>$id,'user_id'=>$id===20?-1:8,'poster_id'=>$id===20?-1:8,'username'=>'Current User','post_username'=>$id===20?'Former Name':'','post_author_removed'=>$id===20?1:0,'post_time'=>1700000000,'post_subject'=>'Subject '.$id,'post_text'=>'Body '.$id.' Größe 😀','bbcode_uid'=>'','enable_html'=>0,'enable_smilies'=>0,'post_icon'=>0,'post_attachment'=>1,'post_deleted'=>$id===10?1:0);}
  $rp_posts[2]['post_text']='STALE_BODY_MUST_NOT_LEAK';$rp_posts[2]['post_subject']='STALE_SUBJECT_MUST_NOT_LEAK';$rp_posts[2]['username']='STALE_AUTHOR_MUST_NOT_LEAK';
  $db=new Database();$template=\msp_template();$template->set_filenames(array('reviewbody'=>'posting_topic_review.tpl'));$rp_attach_init=$rp_attach_rows=array();
  ob_start();try{topic_review(100,$inline);if($inline){$template->assign_block_vars('switch_inline_mode',array());$template->pparse('reviewbody');}$html=ob_get_contents();}finally{ob_end_clean();}
  $rows=$template->_tpldata['postrow.'];check(count($rows)===3,'all positions retained');check(!empty($rows[2]['IS_DELETED'])&&$rows[2]['DELETED_MESSAGE']===htmlspecialchars($lang['Post_deleted_placeholder'],ENT_QUOTES,'UTF-8'),'actual function emits one localized marker');check(array_intersect(array('MESSAGE','POSTER_NAME','POST_DATE','POST_SUBJECT','ATTACHMENTS'),array_keys($rows[2]))===array(),'deleted row carries no stale normal bindings');
  check(count($rp_attach_init)===1&&$rp_attach_init[0]===array('auth_view'=>$permission!=='missing','auth_download'=>$permission==='allowed'),'initialized attachment policy matches the current caller/standalone result');check(count($rp_attach_rows)===2&&$rp_attach_rows[0][0]===30&&$rp_attach_rows[1][0]===20,'deleted post never reaches attachment output');check($rp_attach_rows[0][2]===$rp_attach_init[0]&&$rp_attach_rows[1][2]===$rp_attach_init[0],'same explicit policy for each visible row');
  check($rows[1]['POSTER_NAME']===($logged?'Former Name':$lang['Former_member']),'former author policy retained');check(strpos($html,'MUST_NOT_LEAK')===false&&substr_count($html,$lang['Post_deleted_placeholder'])===1,'actual XS hides deleted details');check(strpos($html,'Body 30 Größe 😀')!==false&&strpos($html,'Body 20 Größe 😀')!==false,'inline and standalone review both render visible neighbors');check(strpos($html,'id="10"')!==false,'deleted post keeps its anchor');check(substr_count($html,'<table')===($inline?4:3)&&substr_count($html,'</table>')===($inline?4:3),'all review and normal-row tables balanced');$rp_views++;
 }}}}
 foreach(array('english','german')as$locale){$lang=array();require $GLOBALS['phpbb_root_path'].'language/lang_'.$locale.'/lang_main.php';$template=\msp_template();$template->set_filenames(array('reviewbody'=>'posting_topic_review.tpl'));$template->assign_block_vars('postrow',array('IS_DELETED'=>true,'U_POST_ID'=>10,'ROW_CLASS'=>'row1','DELETED_MESSAGE'=>$lang['Post_deleted_placeholder'],'MESSAGE'=>'STALE_BODY_MUST_NOT_LEAK','POSTER_NAME'=>'STALE_AUTHOR_MUST_NOT_LEAK','POST_DATE'=>'STALE_DATE_MUST_NOT_LEAK','POST_SUBJECT'=>'STALE_SUBJECT_MUST_NOT_LEAK','ATTACHMENTS'=>'STALE_ATTACHMENT_MUST_NOT_LEAK'));ob_start();try{$template->pparse('reviewbody');$html=ob_get_contents();}finally{ob_end_clean();}check(strpos($html,'MUST_NOT_LEAK')===false&&substr_count($html,$lang['Post_deleted_placeholder'])===1,'XS independently hides all stale extension bindings');$rp_views++;}
}finally{restore_error_handler();}
echo 'Topic review previews: ',$rp_views,' actual-function/XS views, ',$GLOBALS['rp_checks']," assertions PASS; inline/standalone, current attachment policy, EN/DE authors, compact deleted rows and visible neighbors; synthetic rows, no DB writes.\n";
