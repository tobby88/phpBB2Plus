<?php
define('IN_PHPBB',true);if(isset($argv[1])&&$argv[1]==='legacy'){define('PHPBB_LEGACY_REQUEST_ESCAPED',true);}
$root=dirname(dirname(__DIR__));$phpbb_root_path=$root.'/phpBB2/';$table_prefix='fixture_';$phpEx='php';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'pafiledb/includes/pafiledb_constants.php';
require$phpbb_root_path.'pafiledb/includes/functions.php';require$phpbb_root_path.'pafiledb/includes/functions_settings_storage.php';require$phpbb_root_path.'pafiledb/includes/template.php';
function append_sid($url){return$url;}
function phpbb_admin_require_post_session(){$GLOBALS['sf_sessions']++;}
function phpbb_admin_session_field(){return'<input type="hidden" name="sid" value="settings-form-fixture" />';}
function phpbb_admin_html($value){return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function message_die($kind,$message){throw new RuntimeException('Unexpected terminal settings message: '.$message);}
function sf_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['sf_checks']++;}
// Only the writer boundary is replaced in this controller/rendering fixture.
// The native fixture exercises the actual writer, including failure boundaries.
function sf_rejected_save($post){$GLOBALS['sf_saves']++;pafiledb_settings_error($GLOBALS['sf_reject']);}
class SettingsFormTemplate extends pafiledb_Template{function compile_write(&$handle,$data){return false;}}
class SettingsFormDatabase{
 public$values=array(),$cursor=0,$queries=0;
 function sql_query($sql){if(!preg_match('/^SELECT\b/',$sql)){throw new RuntimeException('Unexpected settings form write');}$this->queries++;$this->cursor=0;return true;}
 function sql_fetchrow($r){$keys=array_keys($this->values);if(!isset($keys[$this->cursor])){return false;}$key=$keys[$this->cursor++];return array('config_name'=>$key,'config_value'=>$this->values[$key]);}
 function sql_freeresult($r){}
}
class acm{public$gets=0,$puts=0;function exists($key){return true;}function get($key){$this->gets++;return array('settings_dbname'=>'Stale shared title');}function put($key,$value){$this->puts++;}}
$db=new SettingsFormDatabase();$basic=file_get_contents($phpbb_root_path.'install/schemas/mysql_basic.sql');preg_match_all("/^INSERT INTO phpbb_pa_config(?:\\s*\\([^;\\r\\n]*\\))?\\s+VALUES\\s*\\('([^']*)',\\s*'([^']*)'\\);/m",$basic,$matches,PREG_SET_ORDER);foreach($matches as$m){$db->values[$m[1]]=$m[2];}$db->values['settings_dbname']='Fresh current configuration';
$sf_checks=$sf_sessions=$sf_saves=0;$source=file_get_contents($phpbb_root_path.'admin/admin_pa_settings.php');$a=strpos($source,'$submit = ');$b=strpos($source,"\n\$template->pparse('admin');");sf_check($a!==false&&$b>$a,'Actual settings controller/rendering boundaries');
$controller=substr($source,$a,$b-$a);$needle='$pafiledb_config = pafiledb_settings_save($_POST);';sf_check(substr_count($controller,$needle)===1,'One actual settings write boundary');$controller=str_replace($needle,'$pafiledb_config = sf_rejected_save($_POST);',$controller);sf_check(preg_match('/^function pa_size_select\(.*?^\}/ms',$source,$m)===1,'Actual unit-select renderer');eval($m[0]);
$common=file_get_contents($phpbb_root_path.'pafiledb/pafiledb_common.php');$a=strpos($common,'$cache = new acm();');$b=strpos($common,'$pafiledb_user = new user_info();',$a);sf_check($a!==false&&$b>$a,'Actual configuration bootstrap boundaries');eval(substr($common,$a,$b-$a));sf_check($pafiledb_config['settings_dbname']==='Fresh current configuration'&&$cache->gets===0&&$cache->puts===0,'Actual bootstrap ignores stale shared config and never republishes it');
$sf_numeric_locale=setlocale(LC_NUMERIC,0);
try{foreach(array('C',array('de_DE.UTF-8','German_Germany.1252','German'))as$locale){if(setlocale(LC_NUMERIC,$locale)===false){continue;}$display=pafiledb_settings_display_size('1048577');sf_check(strpos($display['value'],',')===false&&pafiledb_settings_size($display['value'],$display['unit'])==='1048577','Byte limit round trip remains exact under the current numeric locale');}}finally{setlocale(LC_NUMERIC,$sf_numeric_locale);}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach(array('english','german')as$language){$lang=array();foreach(array('lang_main','lang_pafiledb','lang_admin_pafiledb')as$file){require$phpbb_root_path.'language/lang_'.$language.'/'.$file.'.php';}
  foreach(array('get','PA_settings_invalid','PA_settings_pending')as$case){
   $sf_reject=$case;$sf_sessions=$sf_saves=0;$draft="Grüße 😀 O'Reilly \\ &amp; <script>bad</script>";$_POST=$case==='get'?array():array('submit'=>'Save','sid'=>'settings-form-fixture','settings_dbname'=>$draft,'upload_dir'=>'../invalid','max_file_size'=>'3000','max_size'=>'mb','no_comment_link_message'=>$draft);
   if(defined('PHPBB_LEGACY_REQUEST_ESCAPED')){$_POST=phpbb_addslashes_recursive($_POST);}
   $db->values['max_file_size']='2147483647';$template=new SettingsFormTemplate();$template->root=$phpbb_root_path.'templates/fisubsilversh';$template->cachedir=sys_get_temp_dir().'/codex_settings_no_cache/';$template->force_recompile=true;
   eval($controller);ob_start();try{$template->display('admin');$html=ob_get_contents();}finally{ob_end_clean();}
   sf_check($sf_sessions===($case==='get'?0:1)&&$sf_saves===($case==='get'?0:1),'Actual POST checks session once; GET never enters writer');
   sf_check(preg_match('/name="settings_dbname"[^>]*value="([^"]*)"/',$html,$m)===1&&html_entity_decode($m[1],ENT_QUOTES,'UTF-8')===($case==='get'?'Fresh current configuration':$draft),'Current title or rejected draft preserves Unicode, slashes and literal entities');
   sf_check(strpos($html,'<script>bad</script>')===false&&substr_count($html,'name="settings_file_page"')===1,'Actual settings template escapes drafts and has a single effective pagination input');
   sf_check(strpos($html,'name="sid" value="settings-form-fixture"')!==false,'Actual retry form retains session token');
   sf_check(preg_match('/name="max_file_size"[^>]*value="([^"]*)"/',$html,$m)===1&&$m[1]===($case==='get'?'2047.999999':'3000'),'Maximum-byte limit and rejected size retain the correct displayed value');
   if($case!=='get'){sf_check(strpos($html,$lang[$case])!==false&&strpos($html,'value="../invalid"')!==false&&preg_match('/value="mb" selected/',$html),'Localized rejection, invalid path and selected unit remain visible for correction');}
  }
 }
 echo 'Download settings form: ',$sf_checks,' actual controller/template/current-config/no-cache/Unicode/draft/token/size checks PASS (',defined('PHPBB_LEGACY_REQUEST_ESCAPED')?'legacy':'raw',").\n";
}finally{restore_error_handler();}
