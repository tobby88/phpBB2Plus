<?php
define('IN_PHPBB',true);if(isset($argv[1])&&$argv[1]==='legacy'){define('PHPBB_LEGACY_REQUEST_ESCAPED',true);}
$root=dirname(dirname(__DIR__));$phpbb_root_path=$root.'/phpBB2/';$table_prefix='fixture_';$phpEx='php';$theme=array('template_name'=>'fisubsilversh');
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'pafiledb/includes/pafiledb_constants.php';require$phpbb_root_path.'pafiledb/includes/functions_license_storage.php';require$phpbb_root_path.'includes/template.php';
function append_sid($url){return$url;}
function phpbb_admin_require_post_session(){$GLOBALS['lf_sessions']++;}
function phpbb_admin_session_field(){return'<input type="hidden" name="sid" value="license-form-fixture" />';}
function phpbb_admin_html($value){return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function message_die($kind,$message){throw new RuntimeException('Unexpected license form terminal message: '.$message);}
function lf_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['lf_checks']++;}
function lf_rejected_save($action,$post){$GLOBALS['lf_saves']++;pafiledb_file_fail('PA_license_failed');}
class LicenseFormTemplate extends Template{
 function __construct(){global$board_config;$board_config=array('xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'default_lang'=>'english');$this->vars=&$this->_tpldata['.'][0];$this->load_config($GLOBALS['phpbb_root_path'].'templates/fisubsilversh',false);}
}
class LicenseFormDatabase{
 public$cursor=0,$name;
 function sql_query($sql){if(!preg_match('/^SELECT \* FROM fixture_pa_license(?: WHERE license_id = 4)?$/D',$sql)){throw new RuntimeException('Unexpected license form query');}$this->cursor=0;return true;}
 function sql_fetchrow($result){return$this->cursor++===0?array('license_id'=>'4','license_name'=>$this->name,'license_text'=>'Stored text'):false;}
 function sql_freeresult($result){}
}
$lf_checks=$lf_sessions=$lf_saves=0;$source=file_get_contents($phpbb_root_path.'admin/admin_pa_license.php');$a=strpos($source,"\$row = '';");$b=strpos($source,"include('./page_footer_admin.");lf_check($a!==false&&$b>$a,'Actual license controller boundaries');$controller=substr($source,$a,$b-$a);
lf_check(preg_match_all('/^function pa_license_(?:form_scalar|admin_save)\(.*?^\}/ms',$controller,$functions)===2,'Actual normalizer and error adapter extracted');
foreach($functions[0]as$function){$controller=str_replace($function,'',$controller);$function=str_replace('pafiledb_license_save($action, $_POST)','lf_rejected_save($action, $_POST)',$function);eval($function);}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach(array('english','german')as$language){$lang=array();foreach(array('lang_main','lang_pafiledb','lang_admin_pafiledb')as$file){require$phpbb_root_path.'language/lang_'.$language.'/'.$file.'.php';}
  foreach(array('add','edit','delete','list','edit-form')as$case){
   $draft="O'Reilly \\ &amp; Grüße 😀 <b>literal</b>";$db=new LicenseFormDatabase();$db->name=$draft;$lf_sessions=$lf_saves=0;$_GET=array();
   $_POST=$case==='list'?array():($case==='edit-form'?array('license'=>'edit','edit'=>'form','select'=>'4'):array('license'=>$case,$case=>'do','sid'=>'license-form-fixture','id'=>'4','select'=>array(4=>'yes'),'form'=>array('name'=>$draft,'text'=>$draft)));
   if(defined('PHPBB_LEGACY_REQUEST_ESCAPED')){$_POST=phpbb_addslashes_recursive($_POST);}$template=new LicenseFormTemplate();
   ob_start();try{eval($controller);$html=ob_get_contents();}finally{ob_end_clean();}
   $write=in_array($case,array('add','edit','delete'),true);lf_check($lf_sessions===($write?1:0)&&$lf_saves===($write?1:0),'Only explicit writes reach both POST guard and writer');
   lf_check(strpos($html,'name="sid" value="license-form-fixture"')!==false&&strpos($html,'<b>literal</b>')===false,'Actual ACP XS template retains token and escapes stored and draft values');
   if($write){lf_check(strpos($html,$lang['PA_license_failed'])!==false,'Actual XS renderer displays localized uncertain-save error');}
   if($case==='add'||$case==='edit'||$case==='edit-form'){
    lf_check(preg_match('/name="form\[name\]"[^>]*value="([^"]*)"/',$html,$match)===1&&html_entity_decode($match[1],ENT_QUOTES,'UTF-8')===$draft,'Actual rejected form keeps exact Unicode, backslashes and literal entity name');
    if($write){lf_check(preg_match('/<textarea[^>]*>(.*?)<\/textarea>/s',$html,$match)===1&&html_entity_decode($match[1],ENT_QUOTES,'UTF-8')===$draft,'Rejected license text survives intact');}
   }
   if($case==='delete'){lf_check(strpos($html,'name="select[4]" value="yes" checked="checked"')!==false,'Rejected deletion retains its selection');}
  }
 }
 echo 'Download license form: ',$lf_checks,' actual ACP XS/controller/error/draft/selection/token/raw/legacy checks PASS (',defined('PHPBB_LEGACY_REQUEST_ESCAPED')?'legacy':'raw',").\n";
}finally{restore_error_handler();}
