<?php
// Actual print loop/author policy with synthetic lookup responses, no SQL.
define('IN_PHPBB',true);define('ANONYMOUS',-1);define('USERS_TABLE','fixture_users');define('REMOVED_AUTHOR_NAMES_TABLE','fixture_removed_author_names');
error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
$app=dirname(dirname(__DIR__)).'/phpBB2/';
require$app.'includes/functions_author_visibility.php';require$app.'includes/functions_post_tombstone.php';
function pa_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['checks']++;}
class PrintAuthorLookupFixture {
 var $queries=array();
 function sql_escape($value){return str_replace("'","''",$value);}
 function sql_query($sql){
  if(strpos($sql,'SELECT n.author_user_id FROM fixture_removed_author_names n WHERE ')!==0){throw new RuntimeException('Unexpected database operation');}
  $this->queries[]=$sql;return(object)array('rows'=>strpos($sql,"n.author_name='Legacy former'")!==false?array(array('author_user_id'=>0)):array());
 }
 function sql_fetchrowset($result){return$result->rows;}function sql_freeresult($result){}
}
class PrintAuthorTemplateFixture {
 var $rows=array();
 function assign_block_vars($block,$values){$this->rows[]=array('block'=>$block,'values'=>$values);}
}
$checks=0;$cases=0;$keep=array();$source=file_get_contents($app.'printview.php');
$start=strpos($source,'for($i = 0; $i < $total_posts; $i++)');$end=strpos($source,"\t\$post_date =",$start);
pa_check($start!==false&&$end>$start,'Actual print loop, tombstone branch and author bindings');
$loop=substr($source,$start,$end-$start).'$template->assign_block_vars("fixture_byline",array("POSTER_NAME"=>$poster));}';
foreach(array('english','german')as$locale){$lang=array();require$app.'language/lang_'.$locale.'/lang_main.php';
 foreach(array(0,1)as$privacy){foreach(array(0,1)as$logged){
  $board_config=array('hide_removed_author_names'=>$privacy);$userdata=array('session_logged_in'=>$logged,'user_id'=>$logged?3:-1,'user_level'=>0);
  $mask=(bool)($privacy&&!$logged);$db=new PrintAuthorLookupFixture();$keep[]=$db;$template=new PrintAuthorTemplateFixture();
  $postrow=array();$expected=array();
  foreach(array(
   array(7,'Current & Member','',0,false,'Current &amp; Member'),
   array(-1,'Anonymous','Guest Example',0,false,'Guest Example'),
   array(-1,'Anonymous','Former Example',1,false,$mask?$lang['Former_member']:'Former Example'),
   array(-1,'Anonymous','Former Example',0,false,'Former Example'),
   array(-1,'Anonymous','',0,false,$lang['Guest']),
   array(-1,'Anonymous','<Guest & "Example">',0,false,'&lt;Guest &amp; &quot;Example&quot;&gt;'),
   array(-1,'Anonymous','Grüße 😀',0,false,'Grüße 😀'),
   array(-1,'Anonymous','Former Example',1,true,null),
   array(8,'Grüße 😀 &amp; Member','',0,false,'Grüße 😀 &amp; Member'),
   array(-1,'Anonymous','Legacy guest',null,false,'Legacy guest'),
   array(-1,'Anonymous','Legacy former',null,false,$mask?$lang['Former_member']:'Legacy former')
  )as$value){
   $row=array('user_id'=>$value[0],'poster_id'=>$value[0],'username'=>$value[1],'post_username'=>$value[2],'post_deleted'=>$value[4]?1:0);
   if($value[3]!==null){$row['post_author_removed']=$value[3];}$postrow[]=$row;$expected[]=$value[5];
  }
  $snapshot=serialize(array($postrow,$userdata,$board_config,$lang));$total_posts=count($postrow);eval($loop);
  pa_check(count($template->rows)===$total_posts,'Every actual print row is represented');
  foreach($expected as$index=>$name){$actual=$template->rows[$index];
   if($name===null){pa_check($actual['block']==='postrow'&&$actual['values']===array('IS_DELETED'=>true,'MESSAGE'=>htmlspecialchars($lang['Post_deleted_placeholder'],ENT_QUOTES,'UTF-8')),'Deleted placeholder never emits a byline or original author');}
   else{pa_check($actual['block']==='fixture_byline'&&$actual['values']===array('POSTER_NAME'=>$name),'Actual print byline follows current, guest, former and escaped-name policy');}
   $cases++;
  }
  pa_check(serialize(array($postrow,$userdata,$board_config,$lang))===$snapshot,'Rendering never changes account, post, viewer or privacy data');
  pa_check($mask?count($db->queries)>0:count($db->queries)===0,'Only guest privacy needs synthetic alias lookup; all operations are SELECT');
 }}
}
restore_error_handler();echo 'Print author bylines: ',$cases,' actual EN/DE rows, ',$checks," assertions PASS; member/guest privacy, genuine and former authors, aliases, UTF-8, escaping and deleted placeholders.\n";
