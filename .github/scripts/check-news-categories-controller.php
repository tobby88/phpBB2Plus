<?php
// Actual controller, NewsModule and category selection; synthetic SELECT replies.
define('IN_PHPBB',true);define('PAGE_INDEX',0);define('AUTH_ALL',0);define('AUTH_LIST_ALL',0);
foreach(array('NEWS','TOPICS','FORUMS','USERS','POSTS_TEXT','POSTS')as$name){define($name.'_TABLE','fixture_'.strtolower($name));}
error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
$app=dirname(dirname(__DIR__)).'/phpBB2/';$phpbb_root_path=$app;$phpEx='php';
require$app.'includes/php_compat.php';require$app.'includes/functions.php';require$app.'includes/news.php';
function nc_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['checks']++;}
function session_pagestart($ip,$page){nc_check(func_num_args()===2&&$ip==='127.0.0.1'&&$page===PAGE_INDEX,'Actual category session starts without undefined legacy argument');return array('user_id'=>-1,'session_logged_in'=>false);}
function auth($type,$forum,$user){return array(1=>array('auth_view'=>true,'auth_read'=>true),99=>array('auth_view'=>false,'auth_read'=>false));}
class NewsCategorySelectFixture {
 var $categories;var $counts;var $queries=array();
 function __construct($categories,$counts){$this->categories=$categories;$this->counts=$counts;}
 function sql_query($sql){
  $this->queries[]=$sql;$flat=preg_replace('/\s+/',' ',trim($sql));
  if(strpos($flat,'SELECT n.* FROM fixture_news AS n ')===0){$rows=$this->categories;}
  elseif(strpos($flat,'SELECT COUNT( t.topic_id ) AS a_count ')===0){
   nc_check(strpos($flat,'f.forum_id NOT IN (99)')!==false,'Existing ACL-scoped article count is retained');
   nc_check(preg_match('/AND t\.news_id = ([0-9]+)(?: |$)/',$flat,$match)===1,'Actual category count query');$rows=array(array('a_count'=>isset($this->counts[$match[1]])?$this->counts[$match[1]]:0));
  }else{throw new RuntimeException('Unexpected database operation');}
  return(object)array('rows'=>$rows);
 }
 function sql_fetchrow($result){return$result->rows?array_shift($result->rows):false;}function sql_freeresult($result){}
}
class NewsCategoryTemplateFixture {
 var $vars=array();var $rows=array();var $files=array();var $parsed=array();
 function assign_vars($vars){$this->vars=array_merge($this->vars,$vars);}function set_filenames($files){$this->files=$files;}
 function assign_block_vars($block,$vars){nc_check($block==='news_cats','Actual public category block');$this->rows[]=$vars;}function pparse($handle){$this->parsed[]=$handle;}
}
$checks=0;$cases=0;$source=file_get_contents($app.'news_cats.php');
nc_check(preg_match('/\$userdata = session_pagestart\([^;]+;/',$source,$session)===1,'Actual category session call');
$a=strpos($source,'$content = new NewsModule(');$b=strpos($source,'// Page Footer',$a);nc_check($a!==false&&$b>$a,'Actual public category controller');$body=substr($source,$a,$b-$a);
nc_check(is_file($app.'templates/fisubsilversh/images/news/ximian.gif'),'Distributed real category asset exists under the style');
$layout=file_get_contents($app.'templates/fisubsilversh/news_cats.tpl');nc_check(strpos($layout,'{news_cats.CATEGORY_IMG}')!==false&&substr_count($layout,'{news_cats.L_CATEGORY}')===2,'Actual category template uses the safe URL and label in image/text contexts');
$samples=array(
 array('images/news','fisubsilversh',array(),array(),array()),
 array('images/news','fisubsilversh',array(array('news_id'=>1,'news_image'=>'ximian.gif','news_category'=>'Grüße 😀')),array(1=>3),array(array('Grüße 😀','templates/fisubsilversh/images/news/ximian.gif',3))),
 array('images/news','fisubsilversh',array(array('news_id'=>1,'news_image'=>'ximian.gif','news_category'=>'Visible'),array('news_id'=>2,'news_image'=>'www.gif','news_category'=>'Hidden')),array(1=>1,2=>0),array(array('Visible','templates/fisubsilversh/images/news/ximian.gif',1))),
 array('images/news','fisubsilversh',array(array('news_id'=>1,'news_image'=>'www.gif','news_category'=>'<Category & "Example">'),array('news_id'=>2,'news_image'=>'ximian.gif','news_category'=>'Stored &amp; readable')),array(1=>2,2=>4),array(array('&lt;Category &amp; &quot;Example&quot;&gt;','templates/fisubsilversh/images/news/www.gif',2),array('Stored &amp; readable','templates/fisubsilversh/images/news/ximian.gif',4))),
 array('images/news','fisubsilversh',array(array('news_id'=>1,'news_image'=>'../bad.gif','news_category'=>'Traversal'),array('news_id'=>2,'news_image'=>'bad.svg','news_category'=>'Unsupported')),array(1=>1,2=>1),array(array('Traversal','images/spacer.gif',1),array('Unsupported','images/spacer.gif',1))),
 array('../news','fisubsilversh',array(array('news_id'=>1,'news_image'=>'ximian.gif','news_category'=>'Legacy path')),array(1=>1),array(array('Legacy path','images/spacer.gif',1))),
 array('images/news','../fisubsilversh',array(array('news_id'=>1,'news_image'=>'ximian.gif','news_category'=>'Legacy style')),array(1=>1),array(array('Legacy style','images/spacer.gif',1)))
);
foreach(array('english','german')as$locale){$lang=array();require$app.'language/lang_'.$locale.'/lang_main.php';
 foreach($samples as$sample){
  $board_config=array('server_name'=>'forum.example.test','server_port'=>443,'script_path'=>'forum','cookie_secure'=>1,'news_path'=>$sample[0],'news_index_file'=>'news_index.php','news_base_url'=>'');
  $theme=array('template_name'=>$sample[1],'td_color1'=>'fff','td_color2'=>'eee','td_class1'=>'row1','td_class2'=>'row2');$_SERVER=$HTTP_SERVER_VARS=array('HTTPS'=>'on','SERVER_PORT'=>443);
  $db=new NewsCategorySelectFixture($sample[2],$sample[3]);$template=new NewsCategoryTemplateFixture();$user_ip='127.0.0.1';eval($session[0]);
  $snapshot=serialize(array($board_config,$userdata,$theme,$lang,$sample));eval($body);
  nc_check(count($template->rows)===count($sample[4]),'Actual visible category count and empty result');
  foreach($sample[4]as$index=>$expected){$row=$template->rows[$index];
   nc_check($row['L_CATEGORY']===$expected[0]&&$row['CATEGORY_IMG']==='https://forum.example.test/forum/'.$expected[1]&&$row['TOPIC_COUNT']===$expected[2],'Actual escaped category label, style asset URL and article count');
   nc_check($row['ROW_CLASS']===($index%2?'row2':'row1'),'Visible rows keep their alternating style');
   nc_check(!isset($row['U_NEWS_EDIT'])&&!isset($row['U_NEWS_DELETE']),'Public categories contain no stale admin actions');
  }
  nc_check($template->files===array('body'=>'news_cats.tpl')&&$template->parsed===array('body'),'Actual public template is rendered once');
  nc_check(!isset($template->vars['S_HIDDEN_FIELDS'])&&!isset($template->vars['S_NEWS_ACTION']),'Copied unused admin form variables are absent');
  nc_check(count($db->queries)===1+count($sample[2]),'Existing category read/count flow is unchanged');
  nc_check(serialize(array($board_config,$userdata,$theme,$lang,$sample))===$snapshot,'Rendering leaves config, user, theme, translations and source categories unchanged');$cases++;
 }
}
restore_error_handler();echo 'News categories: ',$cases,' actual EN/DE controller cases, ',$checks," assertions PASS; standard session, style assets, safe labels, empty lists, ACL count flow and unchanged data.\n";
