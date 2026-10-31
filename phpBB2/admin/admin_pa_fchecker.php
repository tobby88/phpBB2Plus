<?php
/*
  paFileDB 3.1
  ©2001/2002 PHP Arena
  Written by Todd
  todd@phparena.net
  http://www.phparena.net
  Keep all copyright links on the script visible
  Please read the license included with this script for more information.
  This script was programmed by Andrew Langland <andy@razza.org>
*/
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }

if( !empty($setmodules) )
{
	$file = basename(__FILE__);
	$module['Download']['Fchecker'] = $file;
	return;
}

$phpbb_root_path = "./../";

require($phpbb_root_path . 'extension.inc');

require('./pagestart.' . $phpEx);

include($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_admin_pafiledb.' . $phpEx);

include($phpbb_root_path . 'pafiledb/pafiledb_common.'.$phpEx);

require_once $phpbb_root_path.'pafiledb/includes/functions_file_checker.php';

$report=null;$checker_error='';
if(isset($_POST['safety'])&&$_POST['safety']==='1'){
    phpbb_admin_require_post_session();
    try{$report=pafiledb_file_check();}
    catch(PaFileDbFileActionFailure$error){$key=$error->getMessage();$checker_error=isset($lang[$key])?$lang[$key]:$lang['PA_checker_failed'];}
    catch(PaFileDbMutationFailure$error){$checker_error=$lang['PA_checker_failed'];}
    catch(Exception$error){$checker_error=$lang['PA_checker_failed'];}
    catch(Error$error){$checker_error=$lang['PA_checker_failed'];}
}
$template->set_filenames(array('admin'=>'admin/pa_admin_file_checker.tpl'));
$template->assign_vars(array('L_FILE_CHECKER'=>$lang['File_checker'],'L_FCHECKER_EXPLAIN'=>$lang['File_checker_explain'],'ERROR'=>phpbb_admin_html($checker_error),'S_HIDDEN_FIELDS'=>phpbb_admin_session_field(),'S_CHECKER_FILE_ACTION'=>append_sid('admin_pa_fchecker.'.$phpEx)));
if($report!==null){
    $template->assign_block_vars('check',array());
    $template->assign_vars(array('L_FILE_CHECKER_SP1'=>$lang['Checker_sp1'],'L_FILE_CHECKER_SP2'=>$lang['Checker_sp2'],'L_FILE_CHECKER_SP3'=>$lang['Checker_sp3'],'L_FILE_CHECKER_SAVED'=>$lang['Checker_saved']));
    foreach($report['missing_files']as$item){$template->assign_block_vars('check.check_step1',array('DEL_DURL'=>'#'.$item['file_id'].' '.phpbb_admin_html($item['url'])));}
    foreach($report['missing_screenshots']as$id){$template->assign_block_vars('check.check_step2',array('DEL_SSURL'=>'#'.$id));}
    foreach($report['unreferenced']as$path){$template->assign_block_vars('check.check_step3',array('DEL_FILE'=>phpbb_admin_html($path)));}
    $bytes=$report['bytes'];$size=(string)$bytes.' Bytes';
    foreach(array(1073741824=>'GiB',1048576=>'MiB',1024=>'KiB')as$unit=>$label){if($bytes>=$unit){$size=number_format($bytes/$unit,2,'.','').' '.$label;break;}}
    $template->assign_vars(array('SAVED'=>$size));
}else{
    $template->assign_block_vars('perform',array());
    $template->assign_vars(array('L_FILE_PERFORM'=>$lang['File_checker_perform'],'L_FILE_SAFTEY'=>$lang['File_saftey']));
}
$template->pparse('admin');
include('./page_footer_admin.'.$phpEx);
