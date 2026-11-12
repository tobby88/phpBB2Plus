<?php
if(PHP_SAPI!=='cli'){exit(2);}
define('IN_PHPBB',true);$table_prefix='fixture_';$phpEx='php';$nt_app=dirname(dirname(__DIR__)).'/phpBB2/';$phpbb_root_path=$nt_app;
require$nt_app.'includes/php_compat.php';require$nt_app.'includes/constants.php';
// Keep the actual helpers and parser; replace only error delivery and unrelated
// icon/attachment sinks so this fixture never bootstraps a forum or writes files.
$nt_functions=file_get_contents($nt_app.'includes/functions.php');$nt_functions=str_replace(array('function message_die(','function get_icon_title(','__FILE__'),array('function nt_unused_message_die(','function nt_unused_get_icon_title(',var_export($nt_app.'includes/functions.php',true)),$nt_functions);eval(substr($nt_functions,5));
require$nt_app.'includes/news.php';require$nt_app.'includes/template.php';
$nt_cases=$nt_checks=0;$nt_attachment_ids=array();
function nt_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['nt_checks']++;}
class NewsReaderFailure extends RuntimeException{}
function message_die($level,$message){throw new NewsReaderFailure($message);}
function append_sid($url,$html=true){return$url;}
function get_icon_title($icon,$empty=0,$type=-1,$admin=false){return'';}
function init_display_post_attachments($flag,$row=array()){$GLOBALS['nt_attachment_ids'][]=(int)$row['post_id'];}
function display_portal_news_attachments($id){$GLOBALS['nt_attachment_ids'][]=(int)$id;}
class NewsTextRows{var $rows;var $offset=0;function __construct($rows){$this->rows=$rows;}}
class NewsTextDatabase{
 function sql_query($sql,$transaction=false){
  if(strpos($sql,'SELECT * FROM '.ACRONYMS_TABLE)===0||strpos($sql,'SELECT c.group_color, u.user_id')===0){return new NewsTextRows(array());}
  if(strpos($sql,'SELECT u.user_color_group, u.username, u.user_id, cg.group_color')===0){return new NewsTextRows(array(array('user_id'=>7,'username'=>'Current &amp; member','user_color_group'=>0,'group_color'=>'')));}
  throw new RuntimeException('Unexpected rendering SQL');
 }
 function sql_fetchrow($r){return isset($r->rows[$r->offset])?$r->rows[$r->offset++]:false;}
 function sql_fetchrowset($r){return$r->rows;}function sql_freeresult($r){return true;}
}
class NewsCommentRows{var $rows;function __construct($rows){$this->rows=$rows;}function fetchPosts($id,$start=0){return$this->rows;}function fetchPostsCount($id){return count($this->rows);}function setItemCount($size){}function getItemCount(){return count($this->rows);}}
function nt_context($locale,$logged){
 global$lang,$board_config,$userdata,$theme,$plus_config,$phpbb_root_path,$phpEx;
 $phpbb_root_path=$GLOBALS['nt_app'];$phpEx='php';$lang=array();$lang_extend_admin=false;require$phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';require$phpbb_root_path.'language/lang_'.$locale.'/lang_news.php';
 $board_config=array('default_lang'=>$locale,'default_dateformat'=>'Y-m-d','board_timezone'=>0,'hide_removed_author_names'=>1,'allow_bbcode'=>1,'allow_smilies'=>0,'allow_html'=>0,'allow_sig'=>0,'news_item_trim'=>8,'news_path'=>'images/news','xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0);
 $userdata=array('user_id'=>$logged?8:ANONYMOUS,'session_logged_in'=>$logged,'user_dateformat'=>'Y-m-d','user_timezone'=>0,'user_allowhtml'=>0);$theme=array('template_name'=>'fisubsilversh');$plus_config=array('enable_shorturls'=>0);
}
function nt_template($file){global$phpbb_root_path;$t=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$t->vars=&$t->_tpldata['.'][0];$t->load_config($phpbb_root_path.'templates/fisubsilversh',false);$t->set_filenames(array('news'=>$file));$t->assign_vars(array('INDEX_FILE'=>'news_index.php'));return$t;}
function nt_module($template){$n=(new ReflectionClass('NewsModule'))->newInstanceWithoutConstructor();$n->template=$template;$n->root_path='https://forum.example.invalid/';$n->phpEx='php';$n->config=$GLOBALS['board_config'];$n->name='news';$n->index_file='news_index.php';$n->syndication=false;return$n;}
function nt_html($template){ob_start();try{$template->pparse('news');return ob_get_contents();}finally{ob_end_clean();}}
function nt_rows(){
 $rows=array();foreach(array(10,11,12,13)as$id){$rows[]=array('topic_id'=>100+$id,'post_id'=>$id,'topic_title'=>'Context &amp; '.$id,'post_subject'=>'Subject '.$id,'topic_time'=>1700000000,'post_time'=>1700000000,'topic_views'=>9,'topic_replies'=>4,'topic_icon'=>0,'topic_type'=>0,'topic_attachment'=>0,'post_attachment'=>0,'news_id'=>1,'news_category'=>'News &amp; category','news_image'=>'general.gif','bbcode_uid'=>'','post_text'=>'Visible body '.$id,'user_id'=>7,'poster_id'=>7,'username'=>'Current &amp; member','post_username'=>'','post_author_removed'=>0,'post_deleted'=>0,'user_posts'=>2,'user_from'=>'','user_regdate'=>1,'user_sig'=>'','user_sig_bbcode_uid'=>'','enable_sig'=>0,'enable_html'=>0,'enable_smilies'=>0);}
 $rows[1]['user_id']=$rows[1]['poster_id']=ANONYMOUS;$rows[1]['post_username']='Real guest';$rows[2]['user_id']=$rows[2]['poster_id']=ANONYMOUS;$rows[2]['post_username']='Former alias';$rows[2]['post_author_removed']=1;
 $rows[3]['user_id']=$rows[3]['poster_id']=ANONYMOUS;$rows[3]['post_deleted']=1;$rows[3]['post_author_removed']=1;foreach(array('post_username','username','post_subject','post_text','news_image')as$key){$rows[3][$key]='OLD_'.$key.'_MUST_NOT_LEAK';}$rows[3]['post_attachment']=1;return$rows;
}
function nt_render_suite(){
 global$db,$nt_cases,$nt_attachment_ids;$db=new NewsTextDatabase();
 foreach(array('english','german')as$locale){foreach(array(false,true)as$logged){nt_context($locale,$logged);$rows=nt_rows();$label=$GLOBALS['lang']['Post_deleted_placeholder'];
  foreach(array(false,true)as$abstract){foreach(array('news.tpl','portal_body.tpl','news_200_rss_body.tpl')as$file){$t=nt_template($file);$n=nt_module($t);$n->syndication=$file==='news_200_rss_body.tpl';$nt_attachment_ids=array();$n->prepareArticles($rows,$abstract);$bound=$t->_tpldata['articles.'];nt_check(count($bound)===4&&!empty($bound[3]['IS_DELETED'])&&$bound[3]['BODY']===htmlspecialchars($label,ENT_QUOTES,'UTF-8'),'Actual deleted news first post becomes one marker');nt_check(!in_array(13,$nt_attachment_ids,true),'Deleted news cannot enter attachment rendering');nt_check($bound[1]['L_POSTER']==='Real guest'&&$bound[2]['L_POSTER']===($logged?'Former alias':$GLOBALS['lang']['Former_member']),'News article bylines preserve real guests and existing former-author policy');nt_check(strpos($bound[0]['U_COMMENTS'],'viewtopic.php?t=110')!==false,'News comment link uses the forum topic parameter');$html=nt_html($t);nt_check(substr_count($html,$label)===1&&strpos($html,'MUST_NOT_LEAK')===false,'Actual news/portal/RSS template hides all stale deleted details');nt_check(strpos($html,'Context &amp; 10')!==false,'Neighboring article title survives');if($n->syndication){nt_check(substr_count($html,'<pubDate>')===3,'RSS omits the deleted article date');}$nt_cases++;}}
  foreach(array('news.tpl','portal_body.tpl')as$file){$t=nt_template($file);$n=nt_module($t);$n->data=new NewsCommentRows($rows);$n->renderComments(110);$bound=$t->_tpldata['comments.'];nt_check(count($bound)===4&&$n->item_count===4&&!empty($bound[3]['IS_DELETED']),'Actual comment count/order retains the deletion position');$html=nt_html($t);nt_check(substr_count($html,$label)===1&&strpos($html,'MUST_NOT_LEAK')===false,'Actual news and portal comments hide stale byline/body/subject/date');nt_check(strpos($html,'Visible body 10')!==false&&strpos($html,'Real guest')!==false,'Ordinary neighboring comments remain');$nt_cases++;}
  $n=nt_module(nt_template('news.tpl'));nt_check($n->parseMessage('','')==='','Empty historical body remains a string without PHP warnings');$nt_cases++;
 }}
}
function nt_export_suite(){
 global$lang,$board_config,$userdata,$nt_cases;
 $source=file_get_contents($GLOBALS['nt_app'].'export.php');$start=strpos($source,'for($i = 0; $i < $total_posts; $i++)');$end=strpos($source,'$search = array(',$start);nt_check($start!==false&&$end>$start,'Actual complete export post loop');$loop=substr($source,$start,$end-$start);
 foreach(array('english','german')as$locale){foreach(array(false,true)as$logged){nt_context($locale,$logged);$postrow=nt_rows();$total_posts=count($postrow);$soubor='';eval($loop);nt_check(strpos($soubor,'MUST_NOT_LEAK')===false&&substr_count($soubor,$lang['Post_deleted_placeholder'])===1,'Export contains exactly the compact deletion marker without stale fields');nt_check(strpos($soubor,'Visible body 10')!==false&&strpos($soubor,'Real guest:')!==false,'Ordinary exported rows retain content and real guest');nt_check(strpos($soubor,($logged?'Former alias':$lang['Former_member']).':')!==false,'Actual export preserves the existing former-author privacy policy');$nt_cases++;}}
}
error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
