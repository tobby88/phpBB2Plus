<?php
define('IN_PHPBB',true);if(isset($argv[1])&&$argv[1]==='legacy'){define('PHPBB_LEGACY_REQUEST_ESCAPED',true);}
$root=dirname(dirname(__DIR__));$phpbb_root_path=$root.'/phpBB2/';$table_prefix='fixture_';$phpEx='php';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'pafiledb/includes/pafiledb_constants.php';require$phpbb_root_path.'pafiledb/includes/functions_custom_storage.php';require$phpbb_root_path.'pafiledb/includes/template.php';
function append_sid($url){return$url;}
function phpbb_admin_require_post_session(){$GLOBALS['caf_sessions']++;}
function phpbb_admin_session_field(){return'<input type="hidden" name="sid" value="field-form-fixture" />';}
function phpbb_admin_html($value){return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function message_die($kind,$message){throw new RuntimeException('Unexpected custom-field terminal message: '.$message);}
function caf_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['caf_checks']++;}
function caf_rejected_save($action,$post){$GLOBALS['caf_saves']++;pafiledb_file_fail('PA_custom_failed');}
class CustomAdminFormTemplate extends pafiledb_Template{function compile_write(&$handle,$data){return false;}}
class CustomAdminFormDatabase{
 public$rows=array(),$cursor=0,$definitions=array();
 function sql_query($sql){$sql=preg_replace('/\s+/',' ',trim($sql));if($sql==='SELECT * FROM fixture_pa_custom ORDER BY field_order ASC'){$this->rows=$this->definitions;}elseif($sql==='SELECT * FROM fixture_pa_customdata WHERE customdata_file=0'){$this->rows=array();}else{throw new RuntimeException('Unexpected custom admin query or unscoped file-data read');}$this->cursor=0;return true;}
 function sql_fetchrow($r){return isset($this->rows[$this->cursor])?$this->rows[$this->cursor++]:false;}
 function sql_freeresult($r){}
}
class CustomAdminFormEnd{function _pafiledb(){}function unload(){}}
$caf_checks=0;$source=file_get_contents($phpbb_root_path.'admin/admin_pa_custom.php');$a=strpos($source,'$custom_field = new custom_field();');$b=strpos($source,"include('./page_footer_admin.");caf_check($a!==false&&$b>$a,'Actual custom controller boundaries');$controller=substr($source,$a,$b-$a);$needle="pafiledb_custom_save(\$mode==='delete'?'delete':'save',\$_POST)";caf_check(substr_count($controller,$needle)===1,'Single owned batch write boundary');$controller=str_replace($needle,"caf_rejected_save(\$mode==='delete'?'delete':'save',\$_POST)",$controller);
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach(array('english','german')as$language){$lang=array();foreach(array('lang_main','lang_pafiledb','lang_admin_pafiledb')as$file){require$phpbb_root_path.'language/lang_'.$language.'/'.$file.'.php';}
  foreach(array('new-0','new-1','new-2','new-3','new-4','new-5','edit-input','edit-choice','reject-add','reject-edit','reject-delete','missing','invalid','query-only-write')as$case){
   $caf_sessions=$caf_saves=0;$caf_draft="O'Reilly \\ &amp; Grüße 😀 <b>literal</b>";$encoded=phpbb_admin_html($caf_draft);$regex='^["\'&a-z]+$';$db=new CustomAdminFormDatabase();foreach(array(4=>PA_FIELD_INPUT,5=>PA_FIELD_SELECT_MULTIPLE)as$id=>$type){$db->definitions[]=array('custom_id'=>(string)$id,'custom_name'=>$encoded,'custom_description'=>$encoded,'data'=>serialize(array('0',$encoded)),'regex'=>$regex,'field_type'=>(string)$type,'field_order'=>(string)$id);}
   $_GET=$_POST=array();$_REQUEST=array('mode'=>'delete','field_ids'=>array(4=>'yes'));$write=strpos($case,'reject-')===0;
   if(strpos($case,'new-')===0){$_POST=array('mode'=>'addfield','field_id'=>'0','field_type'=>substr($case,-1),'submit'=>'Next');}
   elseif($case==='edit-input'||$case==='edit-choice'){$_GET=array('mode'=>'addfield','field_id'=>$case==='edit-input'?'4':'5');}
   elseif($write){$_POST=array('mode'=>$case==='reject-delete'?'delete':'do_add','submit'=>'Save','field_id'=>$case==='reject-edit'?'4':'0','field_type'=>'0','field_name'=>$caf_draft,'field_desc'=>$caf_draft,'data'=>$caf_draft,'regex'=>$regex,'field_order'=>'4','field_ids'=>array(4=>'yes'));}
   elseif($case==='missing'){$_GET=array('mode'=>'addfield','field_id'=>'999');}
   elseif($case==='invalid'){$_GET=array('mode'=>array('bad'),'field_id'=>array('bad'));}
   else{$_GET=array('mode'=>'delete');$_POST=array('submit'=>'Save','field_ids'=>array(4=>'yes'));}
   if(defined('PHPBB_LEGACY_REQUEST_ESCAPED')){$_POST=phpbb_addslashes_recursive($_POST);}$pafiledb_template=new CustomAdminFormTemplate();$pafiledb_template->root=$phpbb_root_path.'templates/fisubsilversh';$pafiledb_template->cachedir=sys_get_temp_dir().'/codex_custom_admin_no_cache/';$pafiledb_template->force_recompile=true;$pafiledb=new CustomAdminFormEnd();$cache=new CustomAdminFormEnd();
   ob_start();try{eval($controller);$html=ob_get_contents();}finally{ob_end_clean();}
   caf_check($caf_saves===($write?1:0)&&$caf_sessions===(isset($_POST['submit'])?1:0),'Actual controller writes only explicit POST actions and guards submitted transitions');
   caf_check(strpos($html,'name="sid" value="field-form-fixture"')!==false&&strpos($html,'<b>literal</b>')===false,'Actual PA template retains tokens and escapes stored/draft values: '.$case);
   if($write){caf_check(strpos($html,$lang['PA_custom_failed'])!==false,'Localized uncertain-write error is visible');}
   if(strpos($case,'new-')===0){caf_check(preg_match('/name="field_name"[^>]*value=""/',$html)===1&&strpos($html,'name="field_type" value="'.substr($case,-1).'"')!==false,'All six new-field forms have initialized empty defaults and the correct type');}
   if(in_array($case,array('edit-input','edit-choice','reject-add','reject-edit'),true)){caf_check(preg_match('/name="field_name"[^>]*value="([^"]*)"/',$html,$m)===1&&html_entity_decode($m[1],ENT_QUOTES,'UTF-8')===$caf_draft,'Existing and rejected names preserve Unicode, slashes and literal entities');}
   if(in_array($case,array('edit-input','reject-add','reject-edit'),true)){caf_check(preg_match('/name="regex"[^>]*value="([^"]*)"/',$html,$m)===1&&html_entity_decode($m[1],ENT_QUOTES,'UTF-8')===$regex,'Regex quotes cannot escape their actual attribute context');}
   if($case==='edit-choice'){caf_check(preg_match('/<textarea[^>]*>(.*?)<\/textarea>/s',$html,$m)===1&&html_entity_decode($m[1],ENT_QUOTES,'UTF-8')==='0'."\n".$caf_draft,'Choice editor decodes stored entities exactly once');}
   if($case==='reject-delete'){caf_check(strpos($html,'name="field_ids[4]" value="yes" checked="checked"')!==false,'Rejected batch deletion retains selected fields');}
   if($case==='missing'){caf_check(strpos($html,$lang['PA_custom_missing'])!==false,'Missing edit target produces a field list and useful error');}
   if($case==='query-only-write'){caf_check(strpos($html,$lang['PA_custom_invalid'])!==false,'A GET action cannot supply an absent POST mutation intent');}
  }
 }
 echo 'Download custom admin forms: ',$caf_checks,' actual controller/PA-template/new-six-types/Unicode/drafts/regex/selection/token/scoped-read checks PASS (',defined('PHPBB_LEGACY_REQUEST_ESCAPED')?'legacy':'raw',").\n";
}finally{restore_error_handler();}
