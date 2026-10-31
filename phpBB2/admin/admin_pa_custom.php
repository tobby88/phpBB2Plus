<?php
/*
  paFileDB 3.0
  ©2001/2002 PHP Arena
  Written by Todd
  todd@phparena.net
  http://www.phparena.net
  Keep all copyright links on the script visible
  Please read the license included with this script for more information.
*/

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }

if( !empty($setmodules) )
{
	$file = basename(__FILE__);
// MX Addon
    // Module discovery runs before the paFileDB language pack is loaded.
    // Store the language key; Junior Admin resolves it after loading all
    // AdminCP language files.
    $module['Download']['Mfieldtitle'] = $file;
//    $module['Download'][$lang['Afield']] = $file."?mode=add";
//    $module['Download'][$lang['Efield']] = $file."?mode=edit";
//    $module['Download'][$lang['Dfield']] = $file."?mode=delete";
	return;
}

$phpbb_root_path = "./../";

require($phpbb_root_path . 'extension.inc');

require('./pagestart.' . $phpEx);

include($phpbb_root_path . 'pafiledb/pafiledb_common.'.$phpEx);
include($phpbb_root_path . 'pafiledb/includes/functions_field.'.$phpEx);

require_once $phpbb_root_path . 'pafiledb/includes/functions_custom_storage.php';
$custom_field = new custom_field();
// The ACP only needs definitions, not every file's stored field values.
$custom_field->init(0);
$request=array_merge($_GET,$_POST);$custom_error='';$draft=false;$field_id=0;$field_type=PA_FIELD_INPUT;
$mode=isset($request['mode'])&&is_string($request['mode'])?$request['mode']:'select';
if(!in_array($mode,array('select','add','addfield','edit','delete','do_add'),true)){$mode='select';$custom_error=$lang['PA_custom_invalid'];}
try{
 $field_id=PaFileDbFileAuthority::id(isset($request['field_id'])?$request['field_id']:0,true);
 if(isset($request['field_type'])){$field_type=PaFileDbFileAuthority::id($request['field_type'],true);}
 if(!in_array($field_type,array(PA_FIELD_INPUT,PA_FIELD_TEXTAREA,PA_FIELD_RADIO,PA_FIELD_SELECT,PA_FIELD_SELECT_MULTIPLE,PA_FIELD_CHECKBOX),true)){pafiledb_file_fail('PA_custom_invalid');}
}catch(PaFileDbFileActionFailure$error){$custom_error=$lang['PA_custom_invalid'];$mode='select';$field_id=0;$field_type=PA_FIELD_INPUT;}
if($field_id&&isset($custom_field->field_rowset[$field_id])){$field_type=(int)$custom_field->field_rowset[$field_id]['field_type'];}
$field_ids=(isset($_POST['field_ids']) && is_array($_POST['field_ids']))?$_POST['field_ids']:array();
$submit=isset($_POST['submit']);
if($submit){
 phpbb_admin_require_post_session();
 if(in_array($mode,array('do_add','delete'),true)&&(!isset($_POST['mode'])||$_POST['mode']!==$mode)){$custom_error=$lang['PA_custom_invalid'];}
 if($custom_error===''&&in_array($mode,array('do_add','delete'),true)){
  try{pafiledb_custom_save($mode==='delete'?'delete':'save',$_POST);}
  catch(PaFileDbFileActionFailure$error){$key=$error->getMessage();$custom_error=isset($lang[$key])?$lang[$key]:$lang['PA_custom_failed'];}
  catch(Exception$error){$custom_error=$lang['PA_custom_failed'];}
  catch(Error$error){$custom_error=$lang['PA_custom_failed'];}
  if($custom_error===''){
   $key=$mode==='delete'?'Fieldsdel':($field_id?'Fieldedited':'Fieldadded');
   $message=$lang[$key].'<br /><br />'.sprintf($lang['Click_return'],'<a href="'.append_sid('admin_pa_custom.'.$phpEx).'">','</a>').'<br /><br />'.sprintf($lang['Click_return_admin_index'],'<a href="'.append_sid('index.'.$phpEx.'?pane=right').'">','</a>');message_die(GENERAL_MESSAGE,$message);
  }
  if($mode==='do_add'){$mode='addfield';$draft=true;}
 }
}
if($mode==='do_add'){$mode='select';$custom_error=$lang['PA_custom_invalid'];}
if($mode==='addfield'&&$field_id&&!isset($custom_field->field_rowset[$field_id])&&!$draft){$mode='edit';$field_id=0;$custom_error=$lang['PA_custom_missing'];}
$templates=array('select'=>'pa_admin_field.tpl','add'=>'pa_admin_select_field_type.tpl','addfield'=>'pa_admin_field_add.tpl','edit'=>'pa_admin_select_field.tpl','delete'=>'pa_admin_field_delete.tpl');
$pafiledb_template->set_filenames(array('admin'=>'admin/'.$templates[$mode]));
$titles=array('select'=>'Mfieldtitle','add'=>'Afieldtitle','addfield'=>$field_id?'Efieldtitle':'Afieldtitle','edit'=>'Efieldtitle','delete'=>'Dfieldtitle');
$s_hidden_fields='';
if($mode==='add'||$mode==='edit'){$s_hidden_fields='<input type="hidden" name="mode" value="addfield" />';}
elseif($mode==='delete'){$s_hidden_fields='<input type="hidden" name="mode" value="delete" />';}
elseif($mode==='addfield'){$s_hidden_fields='<input type="hidden" name="mode" value="do_add" /><input type="hidden" name="field_id" value="'.$field_id.'" /><input type="hidden" name="field_type" value="'.$field_type.'" />';}
$s_hidden_fields.=phpbb_admin_session_field();
$pafiledb_template->assign_vars(array('L_FIELD_TITLE'=>$lang[$titles[$mode]],'L_FIELD_EXPLAIN'=>$lang['Fieldexplain'],'ERROR'=>phpbb_admin_html($custom_error),'S_HIDDEN_FIELDS'=>$s_hidden_fields,'S_FIELD_ACTION'=>append_sid('admin_pa_custom.'.$phpEx)));
if($mode==='addfield'){
 $data=array('custom_name'=>'','custom_description'=>'','data'=>'','regex'=>'','field_order'=>'0');
 if($field_id&&isset($custom_field->field_rowset[$field_id])){$data=$custom_field->get_field_data($field_id);}
 if($draft){
  foreach(array('field_name'=>'custom_name','field_desc'=>'custom_description','data'=>'data','regex'=>'regex','field_order'=>'field_order')as$key=>$binding){$raw=isset($_POST[$key])&&is_string($_POST[$key])?phpbb_request_raw_value($_POST[$key]):'';$data[$binding]=in_array($key,array('regex','field_order'),true)?$raw:phpbb_admin_html($raw);}
 }
 // Existing name/description/options are stored encoded. Decode exactly once,
 // then encode for the actual input context, also protecting legacy raw values.
 foreach(array('custom_name','custom_description','data')as$key){$data[$key]=phpbb_admin_html(html_entity_decode((string)$data[$key],ENT_QUOTES,'UTF-8'));}
 $pafiledb_template->assign_vars(array(
  'L_FIELD_NAME'=>$lang['Fieldname'],'L_FIELD_NAME_INFO'=>$lang['Fieldnameinfo'],'L_FIELD_DESC'=>$lang['Fielddesc'],'L_FIELD_DESC_INFO'=>$lang['Fielddescinfo'],'L_FIELD_DATA'=>$lang['Field_data'],'L_FIELD_DATA_INFO'=>$lang['Field_data_info'],'L_FIELD_REGEX'=>$lang['Field_regex'],'L_FIELD_REGEX_INFO'=>sprintf($lang['Field_regex_info'],'<a href="https://www.php.net/manual/en/function.preg-match.php" target="_blank" rel="noopener noreferrer">','</a>'),'L_FIELD_ORDER'=>$lang['Field_order'],
  'DATA'=>!in_array($field_type,array(PA_FIELD_INPUT,PA_FIELD_TEXTAREA),true),'REGEX'=>in_array($field_type,array(PA_FIELD_INPUT,PA_FIELD_TEXTAREA),true),'ORDER'=>$field_id!==0,
  'FIELD_NAME'=>$data['custom_name'],'FIELD_DESC'=>$data['custom_description'],'FIELD_DATA'=>$data['data'],'FIELD_REGEX'=>phpbb_admin_html($data['regex']),'FIELD_ORDER'=>phpbb_admin_html($data['field_order'])));
}elseif($mode==='add'){
 $types=array(PA_FIELD_INPUT=>$lang['Input'],PA_FIELD_TEXTAREA=>$lang['Textarea'],PA_FIELD_RADIO=>$lang['Radio'],PA_FIELD_SELECT=>$lang['Select'],PA_FIELD_SELECT_MULTIPLE=>$lang['Select_multiple'],PA_FIELD_CHECKBOX=>$lang['Checkbox']);$options='<select name="field_type">';foreach($types as$key=>$value){$options.='<option value="'.$key.'">'.phpbb_admin_html($value).'</option>';}$options.='</select>';$pafiledb_template->assign_vars(array('S_SELECT_FIELD_TYPE'=>$options));
}else{
 foreach($custom_field->field_rowset as$id=>$definition){$pafiledb_template->assign_block_vars('field_row',array('FIELD_ID'=>(int)$id,'FIELD_NAME'=>phpbb_admin_html(html_entity_decode($definition['custom_name'],ENT_QUOTES,'UTF-8')),'FIELD_DESC'=>phpbb_admin_html(html_entity_decode($definition['custom_description'],ENT_QUOTES,'UTF-8')),'FIELD_CHECKED'=>isset($field_ids[$id])&&$field_ids[$id]==='yes'?' checked="checked"':''));}
}
$pafiledb_template->display('admin');
$pafiledb->_pafiledb();$cache->unload();
include('./page_footer_admin.'.$phpEx);
