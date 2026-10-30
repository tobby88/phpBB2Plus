<?php
// Run the real category controller after its bootstrap, with only the writer
// boundary replaced. Native fixtures separately exercise the real writer.
define('IN_PHPBB',true);
if(isset($argv[1])&&$argv[1]==='legacy'){define('PHPBB_LEGACY_REQUEST_ESCAPED',true);}
$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';$phpEx='php';
require$phpbb_root_path.'includes/php_compat.php';
require$phpbb_root_path.'pafiledb/includes/template.php';
function append_sid($url,$html=true){return$url;}
function phpbb_admin_require_post_session(){$GLOBALS['form_session_checks']++;}
function phpbb_admin_session_field(){return'<input type="hidden" name="sid" value="category-fixture" />';}
function cf_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['cf_checks']++;}
class CategoryFormPolicy{
 public$error=array(),$cat_rowset=array(),$calls=array();
 function update_add_cat($id=false){$this->calls[]=$id;$this->error[]= $GLOBALS['lang']['PA_category_tree_invalid'];return null;}
 function jumpmenu_option($parent=0,$depth=0,$selected=array()){
  $html='';foreach(array(2,4)as$id){$html.='<option value="'.$id.'"'.(isset($selected[$id])?' selected':'').'>Category'.$id.'</option>'; }return$html;
 }
}
class CategoryFormTemplate extends pafiledb_Template{function compile_write(&$handle,$data){return false;}}
$source=file_get_contents($phpbb_root_path.'admin/admin_pa_category.php');
$cf_checks=0;
$start=strpos($source,'$mode = (isset(');$end=strpos($source,"\n\$pafiledb_template->display('admin');");
cf_check($start!==false&&$end>$start,'Real controller bootstrap and rendering boundaries');
$controller=substr($source,$start,$end-$start);
$userdata=array('user_lang'=>'english','template_name'=>'fisubsilversh');$theme=array('template_name'=>'fisubsilversh');
$board_config=array('xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'default_lang'=>'english');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach(array('english','german')as$language){
  $lang=array();foreach(array('lang_main','lang_admin_pafiledb')as$file){require$phpbb_root_path.'language/lang_'.$language.'/'.$file.'.php';}
  foreach(array('add','edit')as$kind){foreach(array('Grüße 😀',"O'Reilly \\ &amp; </textarea><script>bad</script>",'0')as$draft){
   $pafiledb=new CategoryFormPolicy();$pafiledb->cat_rowset=array(2=>array('cat_name'=>'Old name','cat_desc'=>'Old description','cat_parent'=>0,'cat_allow_file'=>1,'cat_allow_comments'=>0,'cat_allow_ratings'=>1));
   $_POST=array('mode'=>'do_add','cat_name'=>$draft,'cat_desc'=>$draft,"cat_parent"=>'4','cat_allow_file'=>'0','cat_allow_comments'=>'1','cat_allow_ratings'=>'0');
   if($kind==='edit'){$_POST['cat_id']='2';}
   if(defined('PHPBB_LEGACY_REQUEST_ESCAPED')){$_POST=phpbb_addslashes_recursive($_POST);}
   $_REQUEST=$_POST;$form_session_checks=0;
   $pafiledb_template=new CategoryFormTemplate();$pafiledb_template->root=$phpbb_root_path.'templates/fisubsilversh';$pafiledb_template->cachedir=sys_get_temp_dir().'/codex_category_no_cache/';$pafiledb_template->force_recompile=true;
   eval($controller);
   cf_check($form_session_checks===1&&$pafiledb->calls===array($kind==='edit'?2:false),'Real action validates POST and retains original writer target');
   cf_check($mode===$kind&&($kind!=='edit'||$cat_id===2),'Rejected action stays in the corresponding form with its target ID');
   ob_start();try{$pafiledb_template->display('admin');$html=ob_get_contents();}finally{ob_end_clean();}
   cf_check(strpos($html,$lang['PA_category_tree_invalid'])!==false,'Localized rejection is visible in actual template');
   cf_check(preg_match('/name="cat_name"[^>]*value="([^"]*)"/i',$html,$m)===1&&html_entity_decode($m[1],ENT_QUOTES,'UTF-8')===$draft,'Rejected name round trip retains Unicode, slashes and literal entities');
   cf_check(preg_match('/name="cat_desc"[^>]*value="([^"]*)"/i',$html,$m)===1&&html_entity_decode($m[1],ENT_QUOTES,'UTF-8')===$draft,'Rejected description round trip retains exact draft');
   cf_check(strpos($html,'<script>bad</script>')===false,'Posted draft cannot introduce markup');
   cf_check(preg_match('/name="mode" value="do_add"/',$html)===1&&strpos($html,'name="sid" value="category-fixture"')!==false,'Retry keeps save action and current session field');
   cf_check((strpos($html,'name="cat_id" value="2"')!==false)===($kind==='edit'),'Retry keeps edit ID and never turns edit into create');
   cf_check(preg_match('/<option value="4" selected/',$html)===1,'Posted parent selection retained');
   foreach(array('cat_allow_file'=>'0','cat_allow_comments'=>'1','cat_allow_ratings'=>'0')as$field=>$selected){cf_check(preg_match('/name="'.$field.'"[^>]*value="'.$selected.'"[^>]*checked/i',$html)===1,'Posted toggle retained: '.$field);}
  }}
 }
 echo 'Download category form: ',$cf_checks,' real controller/template rejection, target and Unicode draft checks PASS (',defined('PHPBB_LEGACY_REQUEST_ESCAPED')?'legacy':'raw',").\n";
}finally{restore_error_handler();}
