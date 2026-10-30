<?php
define('IN_PHPBB',true);if(isset($argv[1])&&$argv[1]==='legacy'){define('PHPBB_LEGACY_REQUEST_ESCAPED',true);}
$root=dirname(dirname(__DIR__));$phpbb_root_path=$root.'/phpBB2/';$table_prefix='fixture_';$phpEx='php';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'pafiledb/includes/pafiledb_constants.php';
require$phpbb_root_path.'pafiledb/includes/functions.php';require$phpbb_root_path.'pafiledb/includes/functions_file_actions.php';require$phpbb_root_path.'pafiledb/includes/template.php';require$phpbb_root_path.'pafiledb/modules/pa_user_upload.php';
function append_sid($url,$html=true){return$url;}
function phpbb_admin_require_post_session(){$GLOBALS['pf_sessions']++;}
function phpbb_admin_session_field(){return'<input type="hidden" name="sid" value="file-form-fixture" />';}
function message_die($kind,$message){throw new RuntimeException('Unexpected terminal message: '.$message);}
function pf_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['pf_checks']++;}
class FileFormDatabase{
 public$rows=array(),$writes=0;
 function sql_query($sql){
  if(!preg_match('/^SELECT\b/i',$sql)){$this->writes++;throw new RuntimeException('Unexpected form database write');}
  if(strpos($sql,'fixture_pa_files')!==false){$this->rows=array($GLOBALS['pf_existing']);}
  elseif(strpos($sql,'fixture_pa_customdata')!==false){$this->rows=array();}
  elseif(strpos($sql,'fixture_pa_custom')!==false){$this->rows=array(array('custom_id'=>1,'custom_name'=>'Custom field','custom_description'=>'Custom description','data'=>'','field_type'=>INPUT,'regex'=>'','field_order'=>1));}
  elseif(strpos($sql,'fixture_pa_license')!==false){$this->rows=array(array('license_id'=>2,'license_name'=>'Synthetic license'));}
  else{$this->rows=array();}return true;
 }
 function sql_fetchrow($result){return array_shift($this->rows);}
 function sql_fetchrowset($result){return$this->rows;}
 function sql_freeresult($result){return true;}
}
trait FileFormPolicy{
 public$calls=array();
 function init(){
  $this->cat_rowset=array(9=>array('cat_id'=>9,'cat_parent'=>0,'cat_name'=>'Synthetic','cat_allow_file'=>1));
  $this->auth=array(9=>array('auth_upload'=>false,'auth_edit_file'=>true,'auth_mod'=>false));
 }
 function update_add_file($id=false,$fields=null){$this->calls[]=array($id,$fields instanceof custom_field);$this->error[]=$GLOBALS['lang']['PA_file_failed'];return null;}
 function jumpmenu_option($parent=0,$depth=0,$selected=array(),$files=false,$upload=false){return'<option value="9"'.(isset($selected[9])?' selected':'').'>Synthetic</option>';}
 function _pafiledb(){}
}
class FileFormAdmin extends pafiledb{use FileFormPolicy;}
class FileFormPublic extends pafiledb_user_upload{
 use FileFormPolicy;
 function display($title,$path){$GLOBALS['pf_rendered']=$path;$GLOBALS['pafiledb_template']->set_filenames(array('body'=>$path));}
}
class FileFormTemplate extends pafiledb_Template{function compile_write(&$handle,$data){return false;}}
class FileFormCache{function unload(){}}
$pf_checks=0;$pf_sessions=0;$source=file_get_contents($phpbb_root_path.'admin/admin_pa_file.php');$a=strpos($source,'function pa_admin_file_request_scalar(');$b=strpos($source,"\n\$pafiledb_template->display('admin');");
pf_check($a!==false&&$b>$a,'Actual admin file action/form boundaries');$admin=substr($source,$a,$b-$a);
// Named request helpers are declared once; the actual action body runs per case.
$helper_end=strpos($admin,'$cat_id = max(');eval(substr($admin,0,$helper_end));$admin=substr($admin,$helper_end);
$pf_checks=1;$pf_existing=array('file_id'=>'42','file_catid'=>'9','user_id'=>'8','file_name'=>'Old','file_desc'=>'Old','file_longdesc'=>'Old','file_creator'=>'Old','file_version'=>'Old','file_docsurl'=>'','file_posticon'=>'none','file_license'=>0,'file_pin'=>0,'file_dls'=>0,'file_approved'=>0,'file_ssurl'=>'','file_sshot_link'=>0,'file_dlurl'=>'https://old.example.invalid/file.zip','unique_name'=>'','file_dir'=>'');
$userdata=array('user_id'=>8,'user_level'=>USER,'session_id'=>'file-form-fixture','session_logged_in'=>true,'user_lang'=>'english','template_name'=>'fisubsilversh');
$theme=array('template_name'=>'fisubsilversh');$board_config=array('xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'default_lang'=>'english','sitename'=>'Synthetic board');$cache=new FileFormCache();$user_ip='7f000001';
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach(array('english','german')as$locale){$lang=array();foreach(array('lang_main','lang_pafiledb','lang_admin_pafiledb')as$file){require$phpbb_root_path.'language/lang_'.$locale.'/'.$file.'.php';}
  foreach(array('public','admin')as$surface){foreach(array('add','edit')as$kind){foreach(array("O'Reilly \\ &amp; </textarea><script>bad</script> Grüße 😀",'0')as$draft){
   $db=new FileFormDatabase();$pafiledb_functions=new pafiledb_functions();$pafiledb_config=array('max_file_size'=>1000,'settings_dbname'=>'Synthetic downloads','need_validation'=>1,'sort_method'=>'file_time','sort_order'=>'DESC','settings_file_page'=>20);$_SERVER['REQUEST_METHOD']='POST';$_GET=array();$_FILES=array();
   $_POST=array('mode'=>'do_add','submit'=>'1','sid'=>'file-form-fixture','cat_id'=>'9','name'=>$draft,'short_desc'=>$draft,'long_desc'=>$draft,'author'=>$draft,'version'=>$draft,'website'=>'https://example.invalid/?x=&amp;','screen_shot_url'=>'','download_url'=>'https://example.invalid/file.zip','field'=>array(1=>$draft),'pin'=>'1','approved'=>'1','sshot_link'=>'1','license'=>'2');
   if($kind==='edit'){$_POST['file_id']='42';}if(defined('PHPBB_LEGACY_REQUEST_ESCAPED')){$_POST=phpbb_addslashes_recursive($_POST);}$_REQUEST=$_POST;
   $pafiledb_template=new FileFormTemplate();$pafiledb_template->root=$phpbb_root_path.'templates/fisubsilversh';$pafiledb_template->cachedir=sys_get_temp_dir().'/codex_file_form_no_cache/';$pafiledb_template->force_recompile=true;
   if($surface==='admin'){$pafiledb=new FileFormAdmin();$pafiledb->init();$custom_field=new custom_field();$custom_field->init();eval($admin);$handle='admin';}
   else{$pafiledb=new FileFormPublic();$pafiledb->init();if($kind==='add'){$pafiledb->auth[9]['auth_upload']=true;}$pafiledb->main();$handle='body';}
   pf_check($pafiledb->calls===array(array($kind==='edit'?42:false,true)),'Actual '.$surface.' writer receives original target and actual custom fields');
   pf_check($db->writes===0,'Actual '.$surface.' form performs no separate custom-field write after rejected save');
   ob_start();try{$pafiledb_template->display($handle);$html=ob_get_contents();}finally{ob_end_clean();}
   pf_check(strpos($html,$lang['PA_file_failed'])!==false,'Actual '.$surface.' template shows localized failure');
   foreach(array('name','short_desc','author','version')as$field){pf_check(preg_match('/name="'.$field.'"[^>]*value="([^"]*)"/i',$html,$m)===1&&html_entity_decode($m[1],ENT_QUOTES,'UTF-8')===$draft,'Actual '.$surface.' draft retains '.$field);}
   pf_check(preg_match('/<textarea[^>]*name="long_desc"[^>]*>([\s\S]*?)<\/textarea>/i',$html,$m)===1&&html_entity_decode($m[1],ENT_QUOTES,'UTF-8')===$draft,'Actual '.$surface.' textarea retains exact rejected draft');
   pf_check(preg_match('/name="field\[1\]"[^>]*value="([^"]*)"/i',$html,$m)===1&&html_entity_decode($m[1],ENT_QUOTES,'UTF-8')===$draft,'Actual '.$surface.' custom scalar draft retains zero, entities, slash and Unicode');
   pf_check(strpos($html,'<script>bad</script>')===false,'Actual '.$surface.' draft cannot introduce markup');
   pf_check((strpos($html,'name="file_id" value="42"')!==false)===($kind==='edit'),'Actual '.$surface.' rejected edit never becomes create');
   pf_check(strpos($html,'name="sid" value="file-form-fixture"')!==false,'Actual '.$surface.' retry keeps session');
  }}}
 }
 echo 'Download file form: ',$pf_checks," actual public/ACP controller/template/rejected draft/custom-field/no-write checks PASS (",defined('PHPBB_LEGACY_REQUEST_ESCAPED')?'legacy':'raw',").\n";
}finally{restore_error_handler();}
