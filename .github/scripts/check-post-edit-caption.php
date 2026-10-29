<?php
define('IN_PHPBB',true);$root=dirname(dirname(__DIR__)).'/phpBB2/';
require$root.'includes/functions.php';require$root.'includes/functions_post_edit.php';
function edit_caption_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$cases=0;
foreach(array('english','german')as$locale){
 $lang=array();require$root.'language/lang_'.$locale.'/lang_main.php';
 $board_config=array('default_lang'=>$locale,'default_dateformat'=>'Y-m-d H:i','board_timezone'=>0);
 foreach(array(false,true)as$logged){$userdata=array('session_logged_in'=>$logged);
  foreach(array(1,2,11)as$count){
   $post=array('post_edit_count'=>$count,'post_edit_time'=>1700000000,'post_edit_user_id'=>8,'post_edit_username'=>"Mod <>&\"' Größe 😀");
   $html=phpbb_post_edit_caption($post,'Original-author');
   edit_caption_check(strpos($html,'Original-author')===false&&strpos($html,'Mod &lt;&gt;&amp;&quot;&#039; Größe 😀')!==false,'Actual editor is escaped once and replaces author attribution');
   edit_caption_check(strpos($html,'2023-11-14 ')!==false&&strpos($html,(string)$count)!==false,'Stored edit date and singular/plural count are rendered');$cases++;
  }
  foreach(array(array(),array('post_edit_user_id'=>null,'post_edit_username'=>null))as$unknown){
   $post=array_merge(array('post_edit_count'=>1,'post_edit_time'=>1700000000),$unknown);
   edit_caption_check(strpos(phpbb_post_edit_caption($post,'Former author &amp; old'),'Former author &amp; old')!==false,'Unknown historical editor uses the caller-provided label without a guessed identity or premature anonymization');$cases++;
  }
  edit_caption_check(phpbb_post_edit_caption(array('post_edit_count'=>0),'Original-author')==='','Unedited posts have no caption');$cases++;
 }
}
echo 'Post edit captions: ',$cases," cases PASS; both languages, actual editor/legacy fallback, Unicode escaping, time/count and unchanged privacy gate.\n";
