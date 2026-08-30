<?php

/***************************************************************************
 *                            admin_board_extend.php
 *                            ----------------------
 *	begin			: 10/08/2003
 *	copyright		: Ptirhiik
 *	email			: admin@rpgnet-fr.com
 *	version			: 1.0.4 - 28/09/2003
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
	$file = basename(__FILE__);
	$module['General']['Configuration_extend'] = $file;
	return;
}

//
// Let's set the root dir for phpBB
//
$phpbb_root_path = "./../";
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);

//
// get all the mods settings
//
$mods = array();
$dir = @opendir($phpbb_root_path . 'includes/mods_settings');
while( $dir !== false && ($file = @readdir($dir)) !== false )
{
	if( preg_match("/^mod_.*?\." . $phpEx . "$/", $file) )
	{
		include($phpbb_root_path . 'includes/mods_settings/' . $file);
	}
}
if ($dir !== false)
{
	@closedir($dir);
}

// menu_id
$menu_id = 0;
if ( isset($_GET['menu']) || isset($_POST['menu_id']) )
{
	$menu_id = isset($_POST['menu_id']) ? intval($_POST['menu_id']) : intval($_GET['menu']);
}

// mod_id
$mod_id = 0;
if ( isset($_GET['mod']) || isset($_POST['mod_id']) )
{
	$mod_id = isset($_POST['mod_id']) ? intval($_POST['mod_id']) : intval($_GET['mod']);
}

// sub_id
$sub_id = 0;
if ( isset($_GET['msub']) || isset($_POST['sub_id']) )
{
	$sub_id = isset($_POST['sub_id']) ? intval($_POST['sub_id']) : intval($_GET['msub']);
}

// menu
$menu_keys = array();
$menu_sort = array();

// mods
$mod_keys = array();
$mod_sort = array();

// fields
$sub_keys = array();
$sub_sort = array();

// process
foreach ($mods as $menu_name => $menu)
{
	// check if there is some config fields in the mods under this menu
	$found = false;

	// menu
	foreach ($menu['data'] as $mod_name => $mod)
	{
		if ($found) { break; }
		// sub menu
		foreach ($mod['data'] as $sub_name => $sub)
		{
			if ($found) { break; }
			// fields
			foreach ($sub['data'] as $field_name => $field)
			{
				if ( !isset($field['user_only']) || !$field['user_only'] )
				{
					$found=true;
					break;
				}
			}
		}
	}

	// menu ok
	if ( $found )
	{
		$i = count($menu_keys);
		$menu_keys[$i] = $menu_name;
		$menu_sort[$i] = $menu['sort'];

		// init mod level
		$mod_keys[$i] = array();
		$mod_sort[$i] = array();

		foreach ($menu['data'] as $mod_name => $mod)
		{
			// check if there is some config fields
			$found = false;
			foreach ($mod['data'] as $sub_name => $sub)
			{
				foreach ($sub['data'] as $field_name => $field)
				{
					if ( !isset($field['user_only']) || !$field['user_only'] )
					{
						$found=true;
						break;
					}
				}
			}
			if ($found)
			{
				$j = count($mod_keys[$i]);
				$mod_keys[$i][$j] = $mod_name;
				$mod_sort[$i][$j] = $mod['sort'];

				// init sub levels
				$sub_keys[$i][$j] = array();
				$sub_sort[$i][$j] = array();

				// sub names
				foreach ($mod['data'] as $sub_name => $sub)
				{
					if ( !empty($sub_name) )
					{
						// check if there is some config fields in this level
						$found = false;
						foreach ($sub['data'] as $field_name => $field)
						{
							if ( !isset($field['user_only']) || !$field['user_only'] )
							{
								$found=true;
								break;
							}
						}
						if ($found)
						{
							$sub_keys[$i][$j][] = $sub_name;
							$sub_sort[$i][$j][] = $sub['sort'];
						}
					}
				}
				@array_multisort($sub_sort[$i][$j], $sub_keys[$i][$j]);
			}
		}
		@array_multisort($mod_sort[$i], $mod_keys[$i], $sub_sort[$i], $sub_keys[$i]);
	}
}
@array_multisort($menu_sort, $menu_keys, $mod_sort, $mod_keys, $sub_sort, $sub_keys);

// fix menu id
if ( !isset($menu_keys[$menu_id]) )
{
	$menu_id = 0;
}

if ( !isset($menu_keys[$menu_id]) || empty($mod_keys[$menu_id]) )
{
	message_die(GENERAL_ERROR, 'No extended configuration modules are available.');
}

// fix mod id
if ( !isset($mod_keys[$menu_id][$mod_id]) )
{
	$mod_id = 0;
}

// fix sub id
$available_subs = isset($sub_keys[$menu_id][$mod_id]) && is_array($sub_keys[$menu_id][$mod_id]) ? $sub_keys[$menu_id][$mod_id] : array();
if ( $sub_id >= count($available_subs) )
{
	$sub_id = 0;
}

// menu name
$menu_name = $menu_keys[$menu_id];

// mod name
$mod_name = $mod_keys[$menu_id][$mod_id];

// sub name
$sub_name = isset($available_subs[$sub_id]) ? $available_subs[$sub_id] : '';

// buttons
$submit = isset($_POST['submit']);

// get the real value of board_config
$sql = "SELECT * FROM " . CONFIG_TABLE;
if ( !$result = $db->sql_query($sql) ) message_die(CRITICAL_ERROR, 'Could not query config information', '', __LINE__, __FILE__, $sql);
$config = array();
while ($row = $db->sql_fetchrow($result))
{
	$config[ $row['config_name'] ] = $row['config_value'];
}

// validate
if ($submit)
{
	phpbb_admin_require_post_session();

	// init for error
	$error = false;
	$error_msg = '';

	// format and verify data
	foreach ($mods[$menu_name]['data'][$mod_name]['data'][$sub_name]['data'] as $field_name => $field)
	{
		if (isset($_POST[$field_name]))
		{
			if (!is_scalar($_POST[$field_name]))
			{
				$error = true;
				$msg = mods_settings_get_lang($field['lang_key']);
				$error_msg .= (empty($error_msg) ? '' : '<br />') . $lang['Error'] . ':&nbsp;' . phpbb_admin_html($msg);
				$post_value = '';
			}
			else
			{
				$post_value = (string) $_POST[$field_name];
			}
			switch ($field['type'])
			{
				case 'LIST_RADIO':
				case 'LIST_DROP':
					$$field_name = $post_value;
					$allowed_values = array_map('strval', $field['values']);
					if (!in_array($$field_name, $allowed_values, true))
					{
						$error = true;
						$msg = mods_settings_get_lang( $mods[$menu_name]['data'][$mod_name]['data'][$sub_name]['data'][$field_name]['lang_key'] );
						$error_msg = (empty($error_msg) ? '' : '<br />') . $lang['Error'] . ':&nbsp;' . $msg;
					}
					break;
				case 'TINYINT':
				case 'SMALLINT':
				case 'MEDIUMINT':
				case 'INT':
					$$field_name = intval($post_value);
					break;
				case 'VARCHAR':
				case 'TEXT':
				case 'DATEFMT':
					$$field_name = trim($post_value);
					break;
				case 'HTMLVARCHAR':
				case 'HTMLTEXT':
					$$field_name = trim($post_value);
					break;
				default:
					$$field_name = '';
					if ( !empty($field['chk_func']) && function_exists($field['chk_func']) )
					{
						$$field_name = $field['chk_func']($field_name, $post_value);
					}
					else
					{
						message_die(GENERAL_ERROR, 'Unknown type of config data : ' . $field_name, '', __LINE__, __FILE__, '');
					}
					break;
			}
			if ($error)
			{
				$message = $error_msg . '<br /><br />' . sprintf($lang['Click_return_config'], '<a href="' . append_sid("./admin_board_extend.$phpEx?menu=$menu_id&mod=$mod_id&msub=$sub_id") . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid("./index.$phpEx?pane=right") . '">', '</a>');
				message_die(GENERAL_MESSAGE, $message);
			}
		}
	}

	// save data
	foreach ($mods[$menu_name]['data'][$mod_name]['data'][$sub_name]['data'] as $field_name => $field)
	{
		if (isset($$field_name))
		{
			// update
			$sql = "UPDATE " . CONFIG_TABLE . " 
			SET config_value = '" . $db->sql_escape((string) $$field_name) . "'
					WHERE config_name = '" . $db->sql_escape($field_name) . "'";
			if ( !$db->sql_query($sql) )
			{
				message_die(GENERAL_ERROR, 'Failed to update general configuration for ' . $field_name, '', __LINE__, __FILE__, $sql);
			}
		}
		if ( isset($_POST[$field_name . '_over']) && !empty($field['user']) && isset($userdata[ $field['user'] ]) )
		{
			$override_value = is_scalar($_POST[$field_name . '_over']) ? intval($_POST[$field_name . '_over']) : 0;
			// update
			$sql = "UPDATE " . CONFIG_TABLE . " 
					SET config_value = '" . $override_value . "'
					WHERE config_name = '" . $db->sql_escape($field_name . '_over') . "'";
			if ( !$db->sql_query($sql) )
			{
				message_die(GENERAL_ERROR, 'Failed to update general configuration for ' . $field_name, '', __LINE__, __FILE__, $sql);
			}
		}
	}

	// send an update message
	$message = $lang['Config_updated'] . '<br /><br />' . sprintf($lang['Click_return_config'], '<a href="' . append_sid("./admin_board_extend.$phpEx?menu=$menu_id&mod=$mod_id&msub=$sub_id") . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid("./index.$phpEx?pane=right") . '">', '</a>');
	message_die(GENERAL_MESSAGE, $message);
}


// template
$template->set_filenames(array(
	'body' => 'admin/board_config_extend_body.tpl')
);

// header
$template->assign_vars(array(
	'L_TITLE'			=> $lang['Configuration_extend'],
	'L_TITLE_EXPLAIN'	=> $lang['Config_explain'],
	'L_MOD_NAME'		=> mods_settings_get_lang($menu_name) . ' - ' . mods_settings_get_lang($mod_name) . ( !empty($sub_name) ? ' - ' . mods_settings_get_lang($sub_name) : '' ),
	'L_SUBMIT'			=> $lang['Submit'],
	'L_RESET'			=> $lang['Reset'],
	)
);

// send menu
for ($i = 0; $i < count($menu_keys); $i++)
{
	$l_menu = $menu_keys[$i];
	if ( count($mod_keys[$i]) == 1 )
	{
		$l_menu = $mod_keys[$i][0];
		if ( count($sub_keys[$i][0]) == 1 )
		{
			$l_menu = $sub_keys[$i][0][0];
		}
	}
	$template->assign_block_vars('menu', array(
		'CLASS'		=> ($menu_id == $i) ? ( (count($mod_keys[$i]) > 1) ? 'row3' : 'row1' ) : 'row2',
		'U_MENU'	=> append_sid("./admin_board_extend.$phpEx?menu=$i"),
		'L_MENU'	=> sprintf( ( ($menu_id == $i) ? '<b>%s</b>' : '%s' ), mods_settings_get_lang($l_menu) ),
		)
	);
	if ( $menu_id == $i )
	{
		if (count($mod_keys[$i]) > 1 )
		{
			$template->assign_block_vars('menu.title_open', array());
		}
	}
	else
	{
		$template->assign_block_vars('menu.title_close', array() );
	}
	if ($menu_id == $i)
	{
		for ($j = 0; $j < count($mod_keys[$i]); $j++ )
		{
			$l_mod = $mod_keys[$i][$j];
			if ( count($sub_keys[$i][$j]) == 1 )
			{
				$l_mod = $sub_keys[$i][$j][0];
			}
			$template->assign_block_vars('menu.mod', array(
				'CLASS'	=> ( ($menu_id == $i) && ($mod_id == $j) ) ? 'row1' : 'row2',
				'ALIGN'	=> ( ($menu_id == $i) && ($mod_id == $j) && (count($sub_keys[$i][$j]) > 1) ) ? 'left' : 'center',
				'U_MOD'	=> append_sid("./admin_board_extend.$phpEx?menu=$i&mod=$j"),
				'L_MOD'	=> sprintf( ( ( ($menu_id == $i) && ($mod_id == $j) ) ? '<b>%s</b>' : '%s' ), mods_settings_get_lang($l_mod) ),
				)
			);
			if ( ($menu_id == $i) && ($mod_id == $j) )
			{
				if ( count($sub_keys[$i][$j]) > 1 )
				{
					$template->assign_block_vars('menu.mod.sub', array());
					for ($k = 0; $k < count($sub_keys[$i][$j]); $k++)
					{
						$template->assign_block_vars('menu.mod.sub.row', array(
							'CLASS'	=> ( ($menu_id == $i) && ($mod_id == $j) && ($sub_id == $k) ) ? 'row1' : 'row1',
							'U_MOD' => append_sid("./admin_board_extend.$phpEx?menu=$i&mod=$j&msub=$k"),
							'L_MOD'	=> sprintf( (($sub_id == $k) ? '<b>%s</b>' : '%s'), mods_settings_get_lang($sub_keys[$i][$j][$k]) ),
							)
						);
					}
				}
			}
		}
	}
}

// send items
foreach ($mods[$menu_name]['data'][$mod_name]['data'][$sub_name]['data'] as $field_name => $field)
{
	$config_value = isset($config[$field_name]) ? (string) $config[$field_name] : '';
	$config_value_html = phpbb_admin_html($config_value);
	$field_name_html = phpbb_admin_html($field_name);

	// get the field input statement
	$input = '';
	switch ($field['type'])
	{
		case 'LIST_RADIO':
			foreach ($field['values'] as $key => $val)
			{
				$selected = ($config_value == $val) ? ' checked="checked"' : '';
				$l_key = mods_settings_get_lang($key);
				$input .= '<input type="radio" name="' . $field_name_html . '" value="' . phpbb_admin_html($val) . '"' . $selected . ' />' . phpbb_admin_html($l_key) . '&nbsp;&nbsp;';
			}
			break;
		case 'LIST_DROP':
			foreach ($field['values'] as $key => $val)
			{
				$selected = ($config_value == $val) ? ' selected="selected"' : '';
				$l_key = mods_settings_get_lang($key);
				$input .= '<option value="' . phpbb_admin_html($val) . '"' . $selected . '>' . phpbb_admin_html($l_key) . '</option>';
			}
			$input = '<select name="' . $field_name_html . '">' . $input . '</select>';
			break;
		case 'TINYINT':
			$input = '<input type="text" name="' . $field_name_html . '" maxlength="3" size="2" class="post" value="' . $config_value_html . '" />';
			break;
		case 'SMALLINT':
			$input = '<input type="text" name="' . $field_name_html . '" maxlength="5" size="5" class="post" value="' . $config_value_html . '" />';
			break;
		case 'MEDIUMINT':
			$input = '<input type="text" name="' . $field_name_html . '" maxlength="8" size="8" class="post" value="' . $config_value_html . '" />';
			break;
		case 'INT':
			$input = '<input type="text" name="' . $field_name_html . '" maxlength="13" size="11" class="post" value="' . $config_value_html . '" />';
			break;
		case 'VARCHAR':
		case 'HTMLVARCHAR':
			$input = '<input type="text" name="' . $field_name_html . '" maxlength="255" size="45" class="post" value="' . $config_value_html . '" />';
			break;
		case 'TEXT':
		case 'HTMLTEXT':
			$input = '<textarea rows="5" cols="45" wrap="virtual" name="' . $field_name_html . '" class="post">' . $config_value_html . '</textarea>';
			break;
		default:
			$input = '';
			if ( !empty($field['get_func']) && function_exists($field['get_func']) )
			{
				$input = $field['get_func']($field_name, $config_value);
			}
			break;
	}

	// overwrite user choice
	$override = '';
	if ( !empty($input) && !empty($field['user']) && isset($userdata[ $field['user'] ]) )
	{
		$override = '';
		foreach ($list_yes_no as $key => $val)
		{
			$override_value = isset($config[$field_name . '_over']) ? $config[$field_name . '_over'] : '';
			$selected = ($override_value == $val) ? ' checked="checked"' : '';
			$l_key = mods_settings_get_lang($key);
			$override .= '<input type="radio" name="' . $field_name_html . '_over' . '" value="' . phpbb_admin_html($val) . '"' . $selected . ' />' . phpbb_admin_html($l_key) . '&nbsp;&nbsp;';
		}
		$override = '<hr />' . $lang['Override_user_choices'] . ':&nbsp;'. $override;
	}

	// dump to template
	$template->assign_block_vars('field', array(
		'L_NAME'	=> mods_settings_get_lang($field['lang_key']),
		'L_EXPLAIN'	=> !empty($field['explain']) ? '<br />' . mods_settings_get_lang($field['explain']) : '',
		'INPUT'		=> $input,
		'OVERRIDE'	=> $override,
		)
	);
}

// system
$s_hidden_fields = '';
$s_hidden_fields .= '<input type="hidden" name="menu_id" value="' . $menu_id . '" />';
$s_hidden_fields .= '<input type="hidden" name="mod_id" value="' . $mod_id . '" />';
$s_hidden_fields .= '<input type="hidden" name="sub_id" value="' . $sub_id . '" />';
$s_hidden_fields .= phpbb_admin_session_field();
$template->assign_vars(array(
	'S_ACTION'			=> append_sid("./admin_board_extend.$phpEx"),
	'S_HIDDEN_FIELDS'	=> $s_hidden_fields,
	)
);

// footer
$template->pparse("body");
include('./page_footer_admin.'.$phpEx);

?>
