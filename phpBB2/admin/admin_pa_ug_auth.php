<?php
/***************************************************************************
 * paFileDB user and group permission administration
 ***************************************************************************/

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if (!empty($setmodules))
{
	// Reached through the paFileDB permission navigation.
	return;
}

$no_page_header = true;
$phpbb_root_path = './../';
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
include($phpbb_root_path . 'pafiledb/pafiledb_common.' . $phpEx);
require_once $phpbb_root_path . 'pafiledb/includes/functions_permission_storage.php';
$permission_error='';$permission_ids=array();
$pafiledb->init();

function pa_admin_ug_request($key, $default = '')
{
	if (isset($_POST[$key]) && is_scalar($_POST[$key]))
	{
		return (string)phpbb_request_raw_value($_POST[$key]);
	}
	if (isset($_GET[$key]) && is_scalar($_GET[$key]))
	{
		return (string)phpbb_request_raw_value($_GET[$key]);
	}
	return (string) $default;
}

function pa_admin_ug_target($mode, $user_id, $group_id)
{
	global $db;
	if ($mode === 'user' || $mode === 'glb_user')
	{
		$user_id = max(0, intval($user_id));
		if (!$user_id || $user_id === ANONYMOUS)
		{
			return false;
		}
		$sql = 'SELECT user_id, username, user_level FROM ' . USERS_TABLE . " WHERE user_id = $user_id AND user_id <> " . ANONYMOUS;
		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, "Couldn't obtain user information", '', __LINE__, __FILE__, $sql);
		}
		$user = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if (!$user)
		{
			return false;
		}
		$sql = 'SELECT g.group_id FROM ' . GROUPS_TABLE . ' g, ' . USER_GROUP_TABLE . " ug WHERE ug.user_id = $user_id AND g.group_id = ug.group_id AND g.group_single_user = 1";
		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, "Couldn't obtain the user's personal group", '', __LINE__, __FILE__, $sql);
		}
		$personal_group = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if (!$personal_group)
		{
			return false;
		}
		return array('user_id' => intval($user['user_id']), 'group_id' => intval($personal_group['group_id']), 'name' => $user['username'], 'is_admin' => ($user['user_level'] == ADMIN) ? 1 : 0);
	}

	$group_id = max(0, intval($group_id));
	if (!$group_id)
	{
		return false;
	}
	$sql = 'SELECT group_id, group_name FROM ' . GROUPS_TABLE . " WHERE group_id = $group_id AND group_single_user = 0";
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, "Couldn't obtain group information", '', __LINE__, __FILE__, $sql);
	}
	$group = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $group ? array('user_id' => 0, 'group_id' => intval($group['group_id']), 'name' => $group['group_name'], 'is_admin' => 0) : false;
}

function pa_admin_ug_access($group_id)
{
	global $db;
	$group_id = intval($group_id);
	$sql = 'SELECT * FROM ' . PA_AUTH_ACCESS_TABLE . " WHERE group_id = $group_id";
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, "Couldn't obtain paFileDB permissions", '', __LINE__, __FILE__, $sql);
	}
	$rows = array();
	while ($row = $db->sql_fetchrow($result))
	{
        $cat=(int)$row['cat_id'];if(!isset($rows[$cat])){$rows[$cat]=$row;}
        foreach(array_merge(pafiledb_permission_fields(),array('auth_mod'),pafiledb_permission_fields(true))as$field){$rows[$cat][$field]=(!empty($rows[$cat][$field])||!empty($row[$field]))?1:0;}
	}
	$db->sql_freeresult($result);
	return $rows;
}

function pa_admin_ug_related($mode, $target)
{
	global $db, $phpEx;
	if ($mode === 'user' || $mode === 'glb_user')
	{
		$id = intval($target['user_id']);
		$sql = 'SELECT g.group_id AS item_id, g.group_name AS item_name FROM ' . GROUPS_TABLE . ' g, ' . USER_GROUP_TABLE . " ug WHERE ug.user_id = $id AND g.group_id = ug.group_id AND g.group_single_user = 0 ORDER BY g.group_name";
		$link_mode = ($mode === 'glb_user') ? 'glb_group' : 'group';
		$key = POST_GROUPS_URL;
	}
	else
	{
		$id = intval($target['group_id']);
		$sql = 'SELECT u.user_id AS item_id, u.username AS item_name FROM ' . USERS_TABLE . ' u, ' . USER_GROUP_TABLE . " ug WHERE ug.group_id = $id AND u.user_id = ug.user_id AND u.user_id <> " . ANONYMOUS . ' ORDER BY u.username';
		$link_mode = ($mode === 'glb_group') ? 'glb_user' : 'user';
		$key = POST_USERS_URL;
	}
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, "Couldn't obtain permission memberships", '', __LINE__, __FILE__, $sql);
	}
	$items = array();
	while ($row = $db->sql_fetchrow($result))
	{
		$items[] = '<a href="' . append_sid("admin_pa_ug_auth.$phpEx?mode=$link_mode&amp;$key=" . intval($row['item_id'])) . '">' . phpbb_admin_html($row['item_name']) . '</a>';
	}
	$db->sql_freeresult($result);
	return $items;
}

function pa_admin_display_category_auth($parent = 0, $depth = 0)
{
	global $pafiledb, $pafiledb_template, $phpEx, $cat_auth_fields, $optionlist_mod, $optionlist_acl_adv, $permission_ids;
	if (!isset($pafiledb->subcat_rowset[$parent]))
	{
		return;
	}
	$pre = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
	foreach ($pafiledb->subcat_rowset[$parent] as $cat_id => $cat_data)
	{
        if(isset($permission_ids[$cat_id])){continue;}$permission_ids[$cat_id]=(int)$cat_id;
		$pafiledb_template->assign_block_vars('cat_row', array('CAT_NAME' => phpbb_admin_html($cat_data['cat_name']), 'IS_HIGHER_CAT' => empty($cat_data['cat_allow_file']), 'PRE' => $pre, 'U_CAT' => append_sid("admin_pa_catauth.$phpEx?cat_id=" . intval($cat_id)), 'S_MOD_SELECT' => $optionlist_mod[$cat_id]));
		for ($i = 0; $i < count($cat_auth_fields); $i++)
		{
			$pafiledb_template->assign_block_vars('cat_row.aclvalues', array('S_ACL_SELECT' => $optionlist_acl_adv[$cat_id][$i]));
		}
		pa_admin_display_category_auth($cat_id, $depth + 1);
	}
}

function is_moderator($group_id)
{
	static $cache = array();
	global $db;
	$group_id = intval($group_id);
	if (!isset($cache[$group_id]))
	{
		$sql = 'SELECT group_id FROM ' . PA_AUTH_ACCESS_TABLE . " WHERE group_id = $group_id AND auth_mod = 1";
		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, "Couldn't check paFileDB moderator status", '', __LINE__, __FILE__, $sql);
		}
		$cache[$group_id] = $db->sql_fetchrow($result) ? 1 : 0;
		$db->sql_freeresult($result);
	}
	return $cache[$group_id];
}

$allowed_modes = array('user', 'group', 'glb_user', 'glb_group');
$mode = pa_admin_ug_request('mode', 'user');
$mode = in_array($mode, $allowed_modes, true) ? $mode : 'user';
$user_id = max(0, intval(pa_admin_ug_request(POST_USERS_URL, 0)));
$group_id = max(0, intval(pa_admin_ug_request(POST_GROUPS_URL, 0)));
if (($mode === 'user' || $mode === 'glb_user') && isset($_POST['username']))
{
	$this_userdata = get_userdata(phpbb_admin_post_string('username'), true);
	if (!is_array($this_userdata))
	{
		message_die(GENERAL_MESSAGE, $lang['No_such_user']);
	}
	$user_id = intval($this_userdata['user_id']);
}

$cat_auth_fields = array('auth_view', 'auth_read', 'auth_view_file', 'auth_edit_file', 'auth_delete_file', 'auth_upload', 'auth_download', 'auth_rate', 'auth_email', 'auth_view_comment', 'auth_post_comment', 'auth_edit_comment', 'auth_delete_comment');
$global_auth_fields = array('auth_search', 'auth_stats', 'auth_toplist', 'auth_viewall');
$field_names = array('auth_view' => $lang['View'], 'auth_read' => $lang['Read'], 'auth_view_file' => $lang['View_file'], 'auth_edit_file' => $lang['Edit_file'], 'auth_delete_file' => $lang['Delete_file'], 'auth_upload' => $lang['Upload'], 'auth_download' => $lang['Download_file'], 'auth_rate' => $lang['Rate'], 'auth_email' => $lang['Email'], 'auth_view_comment' => $lang['View_comment'], 'auth_post_comment' => $lang['Post_comment'], 'auth_edit_comment' => $lang['Edit_comment'], 'auth_delete_comment' => $lang['Delete_comment']);
$global_names = array('auth_search' => $lang['Auth_search'], 'auth_stats' => $lang['Auth_stats'], 'auth_toplist' => $lang['Auth_toplist'], 'auth_viewall' => $lang['Auth_viewall']);

$permission_menu = array(append_sid("admin_pa_catauth.$phpEx") => $lang['Cat_Permissions'], append_sid("admin_pa_ug_auth.$phpEx?mode=user") => $lang['User_Permissions'], append_sid("admin_pa_ug_auth.$phpEx?mode=group") => $lang['Group_Permissions'], append_sid("admin_pa_ug_auth.$phpEx?mode=glb_user") => $lang['User_Global_Permissions'], append_sid("admin_pa_ug_auth.$phpEx?mode=glb_group") => $lang['Group_Global_Permissions']);
foreach ($permission_menu as $url => $label)
{
	$pafiledb_template->assign_block_vars('pertype', array('U_NAME' => $url, 'L_NAME' => $label));
}

$is_global = ($mode === 'glb_user' || $mode === 'glb_group');
$is_user = ($mode === 'user' || $mode === 'glb_user');
$target = ($user_id || $group_id) ? pa_admin_ug_target($mode, $user_id, $group_id) : false;

if (isset($_POST['permission_form']) || isset($_POST['submit']))
{
    phpbb_admin_require_post_session();
    try { pafiledb_group_permissions_save($_POST); }
    catch(PaFileDbFileActionFailure$error){$key=$error->getMessage();$permission_error=isset($lang[$key])?$lang[$key]:$lang['PA_permissions_failed'];}
    catch(Exception$error){$permission_error=$lang['PA_permissions_failed'];}
    catch(Error$error){$permission_error=$lang['PA_permissions_failed'];}
    if($permission_error===''){
        $return_text=$is_user?$lang['Click_return_userauth']:$lang['Click_return_groupauth'];message_die(GENERAL_MESSAGE,$lang['Auth_updated'].'<br /><br />'.sprintf($return_text,'<a href="'.append_sid('admin_pa_ug_auth.'.$phpEx.'?mode='.$mode).'">','</a>'));
    }
    if(!$target){message_die(GENERAL_ERROR,phpbb_admin_html($permission_error));}
}
if ($target)
{
	$access = pa_admin_ug_access($target['group_id']);
	$related = pa_admin_ug_related($mode, $target);
	$related = count($related) ? implode(', ', $related) : $lang['None'];
	$span = 0;
	if (!$is_global)
	{
		$optionlist_acl_adv = array();
		$optionlist_mod = array();
		foreach ($pafiledb->cat_rowset as $cat_id => $cat_data)
		{
			$cat_id = intval($cat_id);
			$row = isset($access[$cat_id]) ? $access[$cat_id] : array();
			$is_mod = $permission_error!==''?(bool)pafiledb_permission_draft($_POST,'moderator',$cat_id,!empty($row['auth_mod'])?1:0):!empty($row['auth_mod']);
			foreach ($cat_auth_fields as $index => $field)
			{
				$select = '&nbsp;';
				if ($cat_data[$field] == AUTH_ACL)
				{
					$current = $permission_error!==''?(bool)pafiledb_permission_draft($_POST,'private_'.$field,$is_global?null:$cat_id,!empty($row[$field])?1:0):!empty($row[$field]);
					$select = '<select name="private_' . $field . '[' . $cat_id . ']">';
					$select .= (!empty($target['is_admin']) || $is_mod) ? '<option value="1">' . $lang['ON'] . '</option>' : '<option value="1"' . ($current ? ' selected="selected"' : '') . '>' . $lang['ON'] . '</option><option value="0"' . (!$current ? ' selected="selected"' : '') . '>' . $lang['OFF'] . '</option>';
					$select .= '</select>';
				}
				$optionlist_acl_adv[$cat_id][$index] = $select;
			}
			$optionlist_mod[$cat_id] = '<select name="moderator[' . $cat_id . ']"><option value="1"' . ($is_mod ? ' selected="selected"' : '') . '>' . $lang['Is_Moderator'] . '</option><option value="0"' . (!$is_mod ? ' selected="selected"' : '') . '>' . $lang['Not_Moderator'] . '</option></select>';
		}
		foreach ($cat_auth_fields as $field)
		{
			$pafiledb_template->assign_block_vars('acltype', array('L_UG_ACL_TYPE' => $field_names[$field]));
			$span++;
		}
		pa_admin_display_category_auth();
	}
	else
	{
		$row = isset($access[0]) ? $access[0] : array();
		$moderator = is_moderator($target['group_id']);
		$pafiledb_template->assign_block_vars('cat_row', array('CAT_NAME' => $is_user ? $lang['User_Global_Permissions'] : $lang['Group_Global_Permissions'], 'IS_HIGHER_CAT' => false, 'PRE' => '', 'U_CAT' => append_sid("admin_pa_settings.$phpEx")));
		foreach ($global_auth_fields as $field)
		{
			$select = '&nbsp;';
			if (isset($pafiledb_config[$field]) && $pafiledb_config[$field] == AUTH_ACL)
			{
				$current = $permission_error!==''?(bool)pafiledb_permission_draft($_POST,'private_'.$field,$is_global?null:$cat_id,!empty($row[$field])?1:0):!empty($row[$field]);
				$select = '<select name="private_' . $field . '">';
				$select .= (!empty($target['is_admin']) || $moderator) ? '<option value="1">' . $lang['ON'] . '</option>' : '<option value="1"' . ($current ? ' selected="selected"' : '') . '>' . $lang['ON'] . '</option><option value="0"' . (!$current ? ' selected="selected"' : '') . '>' . $lang['OFF'] . '</option>';
				$select .= '</select>';
			}
			$pafiledb_template->assign_block_vars('cat_row.aclvalues', array('S_ACL_SELECT' => $select));
			$pafiledb_template->assign_block_vars('acltype', array('L_UG_ACL_TYPE' => $global_names[$field]));
			$span++;
		}
	}

	include('./page_header_admin.' . $phpEx);
	$pafiledb_template->set_filenames(array('body' => 'admin/pa_auth_ug_body.tpl'));
	$name = phpbb_admin_html($target['name']);
	if ($is_user)
	{
		$pafiledb_template->assign_vars(array('USER' => true, 'USERNAME' => $name, 'USER_LEVEL' => $lang['User_Level'], 'USER_GROUP_MEMBERSHIPS' => $lang['Group_memberships'] . ' : ' . $related));
	}
	else
	{
		$pafiledb_template->assign_vars(array('USER' => false, 'USERNAME' => $name, 'GROUP_MEMBERSHIP' => $lang['Usergroup_members'] . ' : ' . $related));
	}
	$hidden = '<input type="hidden" name="mode" value="' . phpbb_admin_html($mode) . '" />';
	$hidden .= $is_user ? '<input type="hidden" name="' . POST_USERS_URL . '" value="' . intval($target['user_id']) . '" />' : '<input type="hidden" name="' . POST_GROUPS_URL . '" value="' . intval($target['group_id']) . '" />';
    $auth_context=$hidden.phpbb_admin_session_field().'<input type="hidden" name="permission_form" value="1" />';
    $hidden='<input type="hidden" name="permission_ids" value="'.($is_global?'0':implode(',',$permission_ids)).'" />';
	$pafiledb_template->assign_vars(array('ERROR'=>phpbb_admin_html($permission_error),'S_AUTH_CONTEXT'=>$auth_context,'SHOW_MOD' => !$is_global, 'L_USER_OR_GROUPNAME' => $is_user ? $lang['Username'] : $lang['Group_name'], 'L_AUTH_TITLE' => $is_user ? $lang['Auth_Control_User'] : $lang['Auth_Control_Group'], 'L_AUTH_EXPLAIN' => $is_user ? $lang['User_auth_explain'] : $lang['Group_auth_explain'], 'L_MODERATOR_STATUS' => $lang['Moderator_status'], 'L_PERMISSIONS' => $lang['Permissions'], 'L_SUBMIT' => $lang['Submit'], 'L_RESET' => $lang['Reset'], 'L_CAT' => $is_global ? ($is_user ? $lang['User_Global_Permissions'] : $lang['Group_Global_Permissions']) : $lang['Category'], 'U_USER_OR_GROUP' => append_sid("admin_pa_ug_auth.$phpEx"), 'S_COLUMN_SPAN' => $span + ($is_global ? 1 : 2), 'S_AUTH_ACTION' => append_sid("admin_pa_ug_auth.$phpEx"), 'S_HIDDEN_FIELDS' => $hidden));
}
else
{
	include('./page_header_admin.' . $phpEx);
	$pafiledb_template->set_filenames(array('body' => $is_user ? 'admin/user_select_body.tpl' : 'admin/auth_select_body.tpl'));
	if ($is_user)
	{
		$pafiledb_template->assign_vars(array('L_FIND_USERNAME' => $lang['Find_username'], 'U_SEARCH_USER' => append_sid("../search.$phpEx?mode=searchuser")));
	}
	else
	{
		$sql = 'SELECT group_id, group_name FROM ' . GROUPS_TABLE . ' WHERE group_single_user = 0 ORDER BY group_name';
		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, "Couldn't get group list", '', __LINE__, __FILE__, $sql);
		}
		$select = '<select name="' . POST_GROUPS_URL . '">';
		while ($row = $db->sql_fetchrow($result))
		{
			$select .= '<option value="' . intval($row['group_id']) . '">' . phpbb_admin_html($row['group_name']) . '</option>';
		}
		$db->sql_freeresult($result);
		$pafiledb_template->assign_vars(array('S_AUTH_SELECT' => $select . '</select>'));
	}
	$type = $is_user ? 'USER' : 'AUTH';
	$hidden = '<input type="hidden" name="mode" value="' . phpbb_admin_html($mode) . '" />' . phpbb_admin_session_field();
	$pafiledb_template->assign_vars(array('L_' . $type . '_TITLE' => $is_user ? $lang['Auth_Control_User'] : $lang['Auth_Control_Group'], 'L_' . $type . '_EXPLAIN' => $is_user ? $lang['User_auth_explain'] : $lang['Group_auth_explain'], 'L_' . $type . '_SELECT' => $is_user ? $lang['Select_a_User'] : $lang['Select_a_Group'], 'L_LOOK_UP' => $is_user ? $lang['Look_up_User'] : $lang['Look_up_Group'], 'L_CREATE_USER' => '', 'S_HIDDEN_FIELDS' => $hidden, 'S_' . $type . '_ACTION' => append_sid("admin_pa_ug_auth.$phpEx")));
}

$pafiledb_template->display('body');
$pafiledb->_pafiledb();
$cache->unload();
include('./page_footer_admin.' . $phpEx);
?>
