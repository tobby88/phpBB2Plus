<?php
/***************************************************************************
 *                            admin_forumauth.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: admin_forumauth.php,v 1.23.2.4 2002/05/21 16:52:08 psotfx Exp $
 *
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if( !empty($setmodules) )
{
	$filename = basename(__FILE__);
	$module['Download']['Permissions'] = $filename;

	return;
}

//
// Load default header
//
$no_page_header = TRUE;
$phpbb_root_path = './../';
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);

include($phpbb_root_path . 'pafiledb/pafiledb_common.'.$phpEx);
require_once $phpbb_root_path . 'pafiledb/includes/functions_permission_storage.php';
$permission_ids=array();$permission_error='';

if (!function_exists('admin_display_cat_auth'))
{
	function admin_display_cat_auth($cat_parent = 0, $depth = 0)
	{
		global $pafiledb, $phpbb_root_path, $pafiledb_template, $phpEx;
		global $cat_auth_fields, $cat_auth_const, $cat_auth_levels, $lang, $permission_ids, $permission_error;
		$pre = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
		if(isset($pafiledb->subcat_rowset[$cat_parent]))
		{
			foreach($pafiledb->subcat_rowset[$cat_parent] as $sub_cat_id => $cat_data)
			{
                if(isset($permission_ids[$sub_cat_id])){continue;}$permission_ids[$sub_cat_id]=(int)$sub_cat_id;
				$pafiledb_template->assign_block_vars('cat_row', array(
					'CATEGORY_NAME' => phpbb_admin_html($cat_data['cat_name']),
					'IS_HIGHER_CAT' => ($cat_data['cat_allow_file']) ? false : true,
					'PRE' => $pre,
					'U_CAT' => append_sid("admin_pa_catauth.$phpEx?cat_parent=$sub_cat_id"))
				);

				for($j = 0; $j < count($cat_auth_fields); $j++)
				{
					$custom_auth[$j] = '&nbsp;<select name="' . $cat_auth_fields[$j] . '[' . $sub_cat_id . ']' . '">';

					for($k = 0; $k < count($cat_auth_levels); $k++)
					{
						$selected = (($permission_error!==''?pafiledb_permission_draft($_POST,$cat_auth_fields[$j],$sub_cat_id,$cat_data[$cat_auth_fields[$j]],true):$cat_data[$cat_auth_fields[$j]]) == $cat_auth_const[$k]) ? ' selected="selected"' : '';
						$custom_auth[$j] .= '<option value="' . $cat_auth_const[$k] . '"' . $selected . '>' . $lang['Category_' . $cat_auth_levels[$k]] . '</option>';
					}
					$custom_auth[$j] .= '</select>&nbsp;';

					$pafiledb_template->assign_block_vars('cat_row.cat_auth_data', array(
						'S_AUTH_LEVELS_SELECT' => $custom_auth[$j])
					);
				}
				admin_display_cat_auth($sub_cat_id, $depth + 1);
			}
			return;
		}
		return;
	}
}

$pafiledb->init();

$cat_auth_fields = array('auth_view', 'auth_read', 'auth_view_file', 'auth_edit_file', 'auth_delete_file', 'auth_upload', 'auth_download', 'auth_rate', 'auth_email', 'auth_view_comment', 'auth_post_comment', 'auth_edit_comment', 'auth_delete_comment');

$field_names = array(
	'auth_view' => $lang['View'],
	'auth_read' => $lang['Read'],
	'auth_view_file' => $lang['View_file'],
// MX Addon
	'auth_edit_file' => $lang['Edit_file'],
	'auth_delete_file' => $lang['Delete_file'],
// End
	'auth_upload' => $lang['Upload'],
	'auth_download' => $lang['Download_file'],
	'auth_rate' => $lang['Rate'],
	'auth_email' => $lang['Email'],
	'auth_view_comment' => $lang['View_comment'],
	'auth_post_comment' => $lang['Post_comment'],
	'auth_edit_comment' => $lang['Edit_comment'],
	'auth_delete_comment' => $lang['Delete_comment']);

$cat_auth_levels = array('ALL', 'REG', 'PRIVATE', 'MOD', 'ADMIN');
$cat_auth_const = array(AUTH_ALL, AUTH_REG, AUTH_ACL, AUTH_MOD, AUTH_ADMIN);

$view_input=array_merge($_GET,$_POST);
$cat_parent = (isset($view_input['cat_parent']) && is_scalar($view_input['cat_parent'])) ? max(0,intval($view_input['cat_parent'])) : 0;
if ($cat_parent && !isset($pafiledb->cat_rowset[$cat_parent]))
{
	$cat_parent = 0;
}

if(isset($view_input['cat_id']) && is_scalar($view_input['cat_id']) && (int)$view_input['cat_id']>0)
{
	$cat_id=max(0,intval($view_input['cat_id']));
	if (!$cat_id || !isset($pafiledb->cat_rowset[$cat_id]))
	{
		message_die(GENERAL_ERROR, 'Invalid download category.');
	}
}
else
{
	unset($cat_id);
}


//
// Start program proper
//
if ((isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD']==='POST') || isset($_POST['submit']))
{
    phpbb_admin_require_post_session();
    try { pafiledb_category_permissions_save($_POST); }
    catch(PaFileDbFileActionFailure$error){$key=$error->getMessage();$permission_error=isset($lang[$key])?$lang[$key]:$lang['PA_permissions_failed'];}
    catch(Exception$error){$permission_error=$lang['PA_permissions_failed'];}
    catch(Error$error){$permission_error=$lang['PA_permissions_failed'];}
    if($permission_error===''){
        $message=$lang['Category_auth_updated'].'<br /><br />'.sprintf($lang['Click_return_catauth'],'<a href="'.append_sid('admin_pa_catauth.'.$phpEx).'">','</a>');message_die(GENERAL_MESSAGE,$message);
    }
}

//

//
// Output the authorisation details if an id was
// specified
//
$pafiledb_template->set_filenames(array(
	'body' => 'admin/pa_auth_cat_body.tpl')
);

$permissions_menu = array(append_sid("admin_pa_catauth.$phpEx") => $lang['Cat_Permissions'], 
						  append_sid("admin_pa_ug_auth.$phpEx?mode=user") => $lang['User_Permissions'], 
						  append_sid("admin_pa_ug_auth.$phpEx?mode=group") => $lang['Group_Permissions'],
						  append_sid("admin_pa_ug_auth.$phpEx?mode=glb_user") => $lang['User_Global_Permissions'],
						  append_sid("admin_pa_ug_auth.$phpEx?mode=glb_group") => $lang['Group_Global_Permissions']);
						  
foreach($permissions_menu as $url => $l_name)
{
	$pafiledb_template->assign_block_vars('pertype', array(
		'U_NAME' => $url,
		'L_NAME' => $l_name)
	);
}


//
// Output values of individual
// fields
//
for($j = 0; $j < count($cat_auth_fields); $j++)
{
	$cell_title = $field_names[$cat_auth_fields[$j]];	
	$pafiledb_template->assign_block_vars('cat_auth_titles', array(
		'CELL_TITLE' => $cell_title)
	);
}
if(empty($cat_id))
{
	admin_display_cat_auth($cat_parent);
	$cat_name = '';
}
elseif(!empty($cat_id))
{
	$pafiledb_template->assign_block_vars('cat_row', array(
		'CATEGORY_NAME' => phpbb_admin_html($pafiledb->cat_rowset[$cat_id]['cat_name']),
		'IS_HIGHER_CAT' => ($pafiledb->cat_rowset[$cat_id]['cat_allow_file']) ? false : true,
		'U_CAT' => append_sid("admin_pa_catauth.$phpEx?cat_parent={$pafiledb->cat_rowset[$cat_id]['cat_parent']}"))
	);

	for($j = 0; $j < count($cat_auth_fields); $j++)
	{
		$custom_auth[$j] = '&nbsp;<select name="' . $cat_auth_fields[$j] . '[' . $cat_id . ']' . '">';

		for($k = 0; $k < count($cat_auth_levels); $k++)
		{
			$selected = (($permission_error!==''?pafiledb_permission_draft($_POST,$cat_auth_fields[$j],$cat_id,$pafiledb->cat_rowset[$cat_id][$cat_auth_fields[$j]],true):$pafiledb->cat_rowset[$cat_id][$cat_auth_fields[$j]]) == $cat_auth_const[$k]) ? ' selected="selected"' : '';
			$custom_auth[$j] .= '<option value="' . $cat_auth_const[$k] . '"' . $selected . '>' . $lang['Category_' . $cat_auth_levels[$k]] . '</option>';
		}
		$custom_auth[$j] .= '</select>&nbsp;';

		$pafiledb_template->assign_block_vars('cat_row.cat_auth_data', array(
			'S_AUTH_LEVELS_SELECT' => $custom_auth[$j])
		);
	}
	$permission_ids[$cat_id]=(int)$cat_id;
	$cat_name = phpbb_admin_html($pafiledb->cat_rowset[$cat_id]['cat_name']);
}
$s_auth_context=phpbb_admin_session_field().'<input type="hidden" name="permission_form" value="1" /><input type="hidden" name="cat_parent" value="'.$cat_parent.'" />'.(isset($cat_id)?'<input type="hidden" name="cat_id" value="'.$cat_id.'" />':'');
$s_hidden_fields='<input type="hidden" name="permission_ids" value="'.implode(',',$permission_ids).'" />';
$s_column_span = count($cat_auth_fields) + 2;

$pafiledb_template->assign_vars(array(
	'CATEGORY_NAME' => $cat_name,

	'L_CATEGORY' => $lang['Category'], 
	'L_AUTH_TITLE' => $lang['Auth_Control_Category'],
	'L_AUTH_EXPLAIN' => $lang['Category_auth_explain'],
	'L_SUBMIT' => $lang['Submit'],
	'L_RESET' => $lang['Reset'],

    'ERROR' => phpbb_admin_html($permission_error), 'S_AUTH_CONTEXT' => $s_auth_context,
	'S_CATAUTH_ACTION' => append_sid("admin_pa_catauth.$phpEx"),
	'S_COLUMN_SPAN' => $s_column_span,
	'S_HIDDEN_FIELDS' => $s_hidden_fields)
);



include('./page_header_admin.'.$phpEx);

$pafiledb_template->display('body');

$pafiledb->_pafiledb();
$cache->unload();

include('./page_footer_admin.'.$phpEx);

?>
