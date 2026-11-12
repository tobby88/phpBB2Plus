<?php
error_reporting(E_ALL);
if(!defined('ANONYMOUS')){define('ANONYMOUS',-1);}
require_once $phpbb_root_path.'includes/bbcode.php';
require_once $phpbb_root_path.'includes/functions_post_tombstone.php';
$msp_source=file_get_contents($phpbb_root_path.'modcp.php');
$msp_start=strpos($msp_source,'for($i = 0; $i < $total_posts; $i++)');
$msp_end=$msp_start===false?false:strpos($msp_source,"\$template->pparse('split_body');",$msp_start);
if($msp_start===false||$msp_end===false){throw new RuntimeException('Actual split preview loop unavailable');}
$msp_loop=substr($msp_source,$msp_start,$msp_end-$msp_start);
$msp_checks=0;$msp_views=0;$msp_failed=array();
function msp_check($ok,$label){$GLOBALS['msp_checks']++;if(!$ok){$GLOBALS['msp_failed'][$label]=true;}}
function msp_template()
{
 global $phpbb_root_path;
 $template=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];$template->load_config($phpbb_root_path.'templates/fisubsilversh',false);$template->set_filenames(array('split_body'=>'modcp_split.tpl'));return $template;
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach(array('english','german')as$locale){foreach(array(false,true)as$logged){
  $lang=array();require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';
  $board_config=array('xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'default_lang'=>$locale,'hide_removed_author_names'=>'1','default_dateformat'=>'Y-m-d H:i','board_timezone'=>0,'allow_html'=>0,'allow_bbcode'=>1,'allow_smilies'=>0);
  $userdata=array('user_id'=>$logged?7:-1,'session_logged_in'=>$logged);$theme=array('template_name'=>'fisubsilversh','td_color1'=>'ffffff','td_color2'=>'eeeeee','td_class1'=>'row1','td_class2'=>'row2');
  $orig_word=$replacement_word=array();$topic_title='Fixture topic';$split_first_post_id=10;
  $postrow=array();foreach(array(10,20,30,40,50)as$id){$postrow[]=array('post_id'=>$id,'poster_id'=>-1,'username'=>'Anonymous','post_username'=>'','post_author_removed'=>'0','post_deleted'=>'0','post_time'=>1700000000,'post_subject'=>'Subject '.$id,'post_text'=>'Body '.$id.' Größe 😀','bbcode_uid'=>'','enable_html'=>0,'enable_smilies'=>0);}
  $postrow[0]['poster_id']=8;$postrow[0]['username']='Current Größe &amp; User';
  $postrow[1]['post_username']='Former Größe &amp; O&#039;Reilly';$postrow[1]['post_author_removed']='1';
  $postrow[2]['post_username']='Real Größe &amp; O&#039;Reilly';
  $postrow[4]['post_deleted']='1';$postrow[4]['username']='OLD_KEY_MUST_NOT_LEAK';$postrow[4]['post_username']='OLD_ALIAS_MUST_NOT_LEAK';$postrow[4]['post_text']='OLD_BODY_MUST_NOT_LEAK';$postrow[4]['post_subject']='OLD_SUBJECT_MUST_NOT_LEAK';
  $total_posts=count($postrow);$template=msp_template();eval($msp_loop);
  $rows=$template->_tpldata['postrow.'];msp_check(count($rows)===5,'one row per source post');
  $expected=array($postrow[0]['username'],$logged?$postrow[1]['post_username']:$lang['Former_member'],$postrow[2]['post_username'],$lang['Guest']);
  foreach($expected as$index=>$name){msp_check($rows[$index]['POSTER_NAME']===$name,'actual current/former/guest author labels');}
  msp_check(!empty($rows[4]['IS_DELETED'])&&isset($rows[4]['DELETED_MESSAGE'])&&$rows[4]['DELETED_MESSAGE']===htmlspecialchars($lang['Post_deleted_placeholder'],ENT_QUOTES,'UTF-8'),'controller produces compact deleted marker');
  msp_check(array_intersect(array('MESSAGE','POSTER_NAME','S_SPLIT_CHECKBOX','POST_DATE','POST_SUBJECT'),array_keys($rows[4]))===array(),'deleted controller skips stale body/identity/actions');
  ob_start();try{$template->pparse('split_body');$html=ob_get_contents();}finally{ob_end_clean();}
  msp_check(strpos($html,'MUST_NOT_LEAK')===false&&substr_count($html,$lang['Post_deleted_placeholder'])===1,'actual XS renders only one deleted marker');
  msp_check(strpos($html,'Body 10 Größe 😀')!==false&&strpos($html,'Body 30 Größe 😀')!==false,'ordinary neighbor bodies retained');
  msp_check(strpos($html,'name="post_id_list[]" value="10"')===false&&strpos($html,'name="post_id_list[]" value="20"')!==false&&strpos($html,'name="post_id_list[]" value="50"')===false,'first/deleted posts have no selection control');
  // Defensive template behavior if an extension supplies stale normal fields.
  $template=msp_template();$template->assign_block_vars('postrow',array('IS_DELETED'=>true,'POST_ID'=>50,'ROW_CLASS'=>'row1','DELETED_MESSAGE'=>$lang['Post_deleted_placeholder'],'POSTER_NAME'=>'STALE_NAME_MUST_NOT_LEAK','MESSAGE'=>'STALE_BODY_MUST_NOT_LEAK','POST_DATE'=>'STALE_DATE_MUST_NOT_LEAK','POST_SUBJECT'=>'STALE_SUBJECT_MUST_NOT_LEAK','S_SPLIT_CHECKBOX'=>'STALE_ACTION_MUST_NOT_LEAK'));
  ob_start();try{$template->pparse('split_body');$stale=ob_get_contents();}finally{ob_end_clean();}
  msp_check(strpos($stale,'MUST_NOT_LEAK')===false&&substr_count($stale,$lang['Post_deleted_placeholder'])===1,'XS independently suppresses stale deleted-row fields');$msp_views++;
 }}
}finally{restore_error_handler();}
if($msp_failed){throw new RuntimeException('Moderation split preview failed: '.implode('; ',array_keys($msp_failed)));}
echo 'Moderation split previews: ',$msp_views,' actual-controller/XS views, ',$msp_checks," assertions PASS; EN/DE, former/current/real-guest authors, compact deletion markers, stale-data suppression and ordinary selections.\n";
