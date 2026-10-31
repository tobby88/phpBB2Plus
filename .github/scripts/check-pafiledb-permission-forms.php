<?php
define('IN_PHPBB',true);if(isset($argv[1])&&$argv[1]==='legacy'){define('PHPBB_LEGACY_REQUEST_ESCAPED',true);}
$root=dirname(dirname(__DIR__));$phpbb_root_path=$root.'/phpBB2/';$phpEx='php';$table_prefix='fixture_';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'pafiledb/includes/pafiledb_constants.php';require$phpbb_root_path.'pafiledb/includes/functions.php';require$phpbb_root_path.'pafiledb/includes/functions_permission_storage.php';require$phpbb_root_path.'pafiledb/includes/template.php';
function append_sid($url){return$url;}
function phpbb_admin_session_field(){return'<input type="hidden" name="sid" value="permission-form-fixture" />';}
function phpbb_admin_require_post_session(){$GLOBALS['pfm_tokens']++;}
function phpbb_admin_html($value){return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function phpbb_admin_post_string($key){return isset($_POST[$key])?(string)phpbb_request_raw_value($_POST[$key]):'';}
function get_userdata($name,$by_name=false){return array('user_id'=>8);}
function message_die($level,$message){throw new RuntimeException('Unexpected permission form terminal message: '.$message);}
function pfm_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['pfm_checks']++;}
function pfm_reject($post){$GLOBALS['pfm_saves']++;pafiledb_file_fail($GLOBALS['pfm_rejection']);}
class PermissionFormTemplate extends pafiledb_Template{function compile_write(&$handle,$data){return false;}}
class PermissionFormModel{
 public$cat_rowset=array(),$subcat_rowset=array();
 function init(){ $this->cat_rowset=$this->subcat_rowset=array();$category=$GLOBALS['pfm_controller']==='category';foreach($category?array(1,9,10):array(9,10)as$id){$row=array('cat_id'=>$id,'cat_parent'=>$category&&$id!==1?1:0,'cat_name'=>$GLOBALS['pfm_name'],'cat_allow_file'=>1);foreach(pafiledb_permission_fields()as$field){$row[$field]=$field==='auth_view'?AUTH_ALL:AUTH_ACL;}$this->cat_rowset[$id]=$row;$this->subcat_rowset[$row['cat_parent']][$id]=$row;} }
 function _pafiledb(){}function unload(){}
}
class PermissionFormDatabase{
 public$rows=array();
 function sql_query($sql){$sql=preg_replace('/\s+/',' ',trim($sql));if(strpos($sql,'SELECT ')!==0){throw new RuntimeException('Permission form attempted a database write');}$this->rows=array();
  if(strpos($sql,'SELECT user_id, username, user_level FROM fixture_users')===0){$this->rows=array(array('user_id'=>8,'username'=>$GLOBALS['pfm_name'],'user_level'=>USER));}
  elseif(strpos($sql,'SELECT g.group_id FROM fixture_groups')===0){$this->rows=array(array('group_id'=>31));}
  elseif(strpos($sql,'SELECT group_id, group_name FROM fixture_groups')===0){$this->rows=array(array('group_id'=>22,'group_name'=>$GLOBALS['pfm_name']));}
  elseif(strpos($sql,'SELECT * FROM fixture_pa_auth')===0){$first=array_fill_keys(array_merge(pafiledb_permission_fields(),array('auth_mod'),pafiledb_permission_fields(true)),0);$first['cat_id']=9;$first['auth_download']=1;$second=$first;$second['auth_download']=0;$global=$first;$global['cat_id']=0;$global['auth_search']=1;$global['auth_download']=0;$this->rows=array($first,$second,$global);}
  return true;
 }
 function sql_fetchrow($r){return array_shift($this->rows);}function sql_freeresult($r){}
}
function pfm_selected($html,$name,$value){return preg_match('/<select name="'.preg_quote($name,'/').'">(?:(?!<\/select>).)*<option value="'.$value.'" selected/s',$html)===1;}
$pfm_checks=0;$pfm_name="Own O'Reilly \\ &amp; Grüße 😀 <b>Literal</b>";$controllers=array();
foreach(array('category'=>'admin_pa_catauth.php','grants'=>'admin_pa_ug_auth.php')as$kind=>$file){$source=file_get_contents($phpbb_root_path.'admin/'.$file);$start=strpos($source,$kind==='category'?'$permission_ids=array();':"\$permission_error='';");$end=strpos($source,"include('./page_footer_admin.");pfm_check($start!==false&&$end>$start,'Actual permission controller boundaries');$body=substr($source,$start,$end-$start);$body=preg_replace('/include\(\x27\.\/page_header_admin\.\x27\s*\.\s*\$phpEx\);/','',$body,-1,$removed);pfm_check($removed>=1,'Only actual common header boundary removed');
 $needle=$kind==='category'?'pafiledb_category_permissions_save($_POST)':'pafiledb_group_permissions_save($_POST)';pfm_check(substr_count($body,$needle)===1,'One owned whole-batch write boundary');$body=str_replace($needle,'pfm_reject($_POST)',$body);
 if($kind==='grants'){$a=strpos($body,'function pa_admin_ug_request(');$b=strpos($body,'$allowed_modes = array(',$a);pfm_check($a!==false&&$b>$a,'Actual read-only group helpers');eval(substr($body,$a,$b-$a));$body=substr($body,0,$a).substr($body,$b);}$controllers[$kind]=$body;
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach(array('english','german')as$locale){$lang=array();foreach(array('lang_main','lang_admin','lang_pafiledb','lang_admin_pafiledb')as$file){require$phpbb_root_path.'language/lang_'.$locale.'/'.$file.'.php';}
  foreach(array('category','grants')as$pfm_controller){$cases=$pfm_controller==='category'?array('get','single','reject','truncated'):array('user','group','glb_user','glb_group','reject-group','reject-global','truncated','lookup');
   foreach($cases as$case){$pfm_tokens=$pfm_saves=0;$pfm_rejection=$case==='truncated'?'PA_permissions_incomplete':'PA_permissions_failed';$_GET=$_POST=$_REQUEST=array();$reject=strpos($case,'reject')===0||$case==='truncated';
    if($pfm_controller==='category'){
     $_GET=$case==='single'?array('cat_id'=>'9'):array('cat_parent'=>'1');
     if($reject){$_POST=array('cat_parent'=>'1','permission_form'=>'1','sid'=>'permission-form-fixture','permission_ids'=>'9,10');foreach(pafiledb_permission_fields()as$field){$_POST[$field]=array(9=>(string)AUTH_ADMIN,10=>(string)AUTH_MOD);}if($case==='truncated'){unset($_POST['permission_ids']);}else{$_POST['submit']='Save';}}
    }else{
     $mode=$case==='reject-global'?'glb_group':(in_array($case,array('reject-group','truncated'),true)?'group':($case==='lookup'?'user':$case));$_GET=array('mode'=>$mode,POST_USERS_URL=>'8',POST_GROUPS_URL=>'22');
     if($reject){$_POST=$_GET+array('sid'=>'permission-form-fixture','permission_form'=>'1','permission_ids'=>$case==='reject-global'?'0':'9,10','moderator'=>array(9=>'0',10=>'1'));foreach(pafiledb_permission_fields()as$field){$_POST['private_'.$field]=array(9=>'0',10=>'1');}if($case==='reject-global'){foreach(pafiledb_permission_fields(true)as$field){$_POST['private_'.$field]='0';}}if($case==='truncated'){unset($_POST['permission_ids']);}else{$_POST['submit']='Save';}}
     elseif($case==='lookup'){$_POST=array('mode'=>'user','username'=>$pfm_name,'submituser'=>'Look up');$_GET=array();}
    }
    if(defined('PHPBB_LEGACY_REQUEST_ESCAPED')){$_POST=phpbb_addslashes_recursive($_POST);}$_SERVER['REQUEST_METHOD']=$_POST?'POST':'GET';$db=new PermissionFormDatabase();$pafiledb=new PermissionFormModel();$cache=new PermissionFormModel();$pafiledb_config=array_fill_keys(pafiledb_permission_fields(true),AUTH_ACL);
    $pafiledb_template=new PermissionFormTemplate();$pafiledb_template->root=$phpbb_root_path.'templates/fisubsilversh';$pafiledb_template->cachedir=sys_get_temp_dir().'/codex_permission_form_no_cache/';$pafiledb_template->force_recompile=true;
    ob_start();try{eval($controllers[$pfm_controller]);$html=ob_get_contents();}finally{ob_end_clean();}
    pfm_check($pfm_saves===($reject?1:0)&&$pfm_tokens===($reject?1:0),'Actual POST saves and truncated forms reach the guard once; GET and lookup never write');
    pfm_check(strpos($html,'name="sid" value="permission-form-fixture"')!==false&&strpos($html,'<b>Literal</b>')===false,'Actual PA forms carry a token and escape category/user/group names');
    pfm_check(strpos($html,'name="sid"')<strpos($html,'<select name=')&&strpos($html,'name="permission_ids"')>strrpos($html,'</select>'),'Nonce/context precede controls and final inventory follows all controls');
    $global=$pfm_controller==='grants'&&($case==='glb_user'||$case==='glb_group'||$case==='reject-global');$ids=$global?'0':($case==='single'?'9':'9,10');pfm_check(strpos($html,'name="permission_ids" value="'.$ids.'"')!==false,'Manifest identifies exactly the rendered category scope');
    if($reject){pfm_check(strpos($html,$lang[$pfm_rejection])!==false,'Rejected or incomplete form displays its localized error');}
    if($pfm_controller==='category'){pfm_check(pfm_selected($html,'auth_download[9]',$reject?(string)AUTH_ADMIN:(string)AUTH_ACL),'Rejected category level remains selected for correction');}
    elseif($global){pfm_check(pfm_selected($html,'private_auth_search',$case==='reject-global'?'0':'1'),'Global draft/current grant survives actual rendering');}
    else{pfm_check(pfm_selected($html,'private_auth_download[9]',$reject?'0':'1'),'Rejected category ACL draft survives and duplicate stored rows display effective OR access');}
   }
  }
 }
 echo 'Download permission forms: ',$pfm_checks,' actual category/user/group/global/lookup/truncation/scope/draft/token/OR/escaped-render checks PASS (',defined('PHPBB_LEGACY_REQUEST_ESCAPED')?'legacy':'raw',").\n";
}finally{restore_error_handler();}
