<?php

/***************************************************************************
 *                              admin_icons.php
 *                            -------------------
 *   begin                : 07/09/2003
 *   copyright            : Ptirhiik
 *   email                : ptirhiik@wanadoo.fr
 *   version              : 1.0.2 - 28/10/2003
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
	$module['General']['Icons_settings'] = $file;
	return;
}

//
// Let's set the root dir for phpBB
//
$phpbb_root_path = "./../";
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
require_once dirname(__DIR__) . '/includes/functions_icon_storage.php';

//
// constants
//
$auths = array(
	AUTH_ALL	=> $lang['Forum_ALL'],
	AUTH_REG	=> $lang['Forum_REG'],
	AUTH_MOD	=> $lang['Forum_MOD'],
	AUTH_ADMIN	=> $lang['Forum_ADMIN'],
);

if (!isset($nav_separator)) $nav_separator = '&nbsp;->&nbsp;';

//
// functions
//
// read parameters
//
// read the icons
try { phpbb_admin_icons_read(); } catch (PhpbbAclException $error) { message_die(GENERAL_MESSAGE, $error->getMessage()); }

// mode
$mode = (isset($_POST['mode']) && is_scalar($_POST['mode'])) ? (string) $_POST['mode'] :
	((isset($_GET['mode']) && is_scalar($_GET['mode'])) ? (string) $_GET['mode'] : '');

// icon
foreach(array($_POST,$_GET)as$source){if(array_key_exists('icon',$source)&&!is_int($source['icon'])&&!is_string($source['icon'])){message_die(GENERAL_MESSAGE,$lang['Icons_selection_changed']);}}
$icon = -1;
if ( isset($_POST['icon']) && is_scalar($_POST['icon']) )
{
	$icon = ($_POST['icon']==='-1'&&$mode==='edit'?-1:phpbb_icon_config_id($_POST['icon']));if($icon===false){message_die(GENERAL_MESSAGE,$lang['Icons_selection_changed']);}
}
else if ( isset($_GET['icon']) && is_scalar($_GET['icon']) )
{
	$icon = ($_GET['icon']==='-1'&&$mode==='edit'?-1:phpbb_icon_config_id($_GET['icon']));if($icon===false){message_die(GENERAL_MESSAGE,$lang['Icons_selection_changed']);}
}
if (isset($_POST['icon_action']) && is_scalar($_POST['icon_action']) && preg_match('/^(up|dw):([0-9]+)$/D', (string) $_POST['icon_action'], $icon_action))
{
	$mode = $icon_action[1];
	$icon = phpbb_icon_config_id($icon_action[2]);if($icon===false){message_die(GENERAL_MESSAGE,$lang['Icons_selection_changed']);}
}
if (!in_array($mode, array('edit', 'up', 'dw', 'del'), true)) $mode = '';

// buttons
$create = isset($_POST['create']);
$submit = isset($_POST['submit']);
$confirm = isset($_POST['confirm']);
$cancel = isset($_POST['cancel']);
$refresh = isset($_POST['refresh']);

if(!$create&&$icon>=0&&!isset($map_icon[$icon])&&in_array($mode,array('edit','del','up','dw'),true)){message_die(GENERAL_MESSAGE,$lang['Icons_selection_changed']);}
$mutating_request = (($mode == 'up') || ($mode == 'dw') || ($mode == 'del' && $confirm) || ($mode == 'edit' && $submit));
if ($mutating_request && !$cancel)
{
    phpbb_admin_require_post_session();
    $request=$_POST;$request['icon']=(string)$icon;
    $operation=$mode==='edit'?'save':($mode==='del'?'delete':($mode==='up'?'up':'down'));
    try { phpbb_admin_icons_read(phpbb_icon_admin_change($db,$operation,$request)); }
    catch (PhpbbAclException $error) { message_die(GENERAL_MESSAGE,$error->getMessage()); }
    $mode='';$submit=$confirm=false;
}
if ((($mode == 'up') || ($mode == 'dw')) && (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST'))
{
	$mode = '';
}

// creation
if ($create)
{
	$icon = -1;
	$mode = 'edit';
}

// handle the mode
if ( !isset($map_icon[$icon]) && ($mode != 'edit') )
{
	$mode = '';
}
if ( !isset($map_icon[$icon]) )
{
	$icon = -1;
}

// adjust mode with buttons
if ($create)
{
	$mode = 'edit';
	$create = false;
}

//
// delete an icon
//
if ($mode == 'del')
{
	if ($cancel)
	{
		// back to the main list
		$mode = '';
		$cancel = false;
	}

	if ($mode == 'del')
	{
		if (isset($map_icon[$icon]))
		{
			$used = ($icon == 0);
            foreach($icon_defined_special as$special){if((int)$special['icon']===$icon){$used=true;}}

			// check if posts are using this icon
			if (!$used)
			{
				$sql = "SELECT * FROM " . POSTS_TABLE . " WHERE post_icon=$icon LIMIT 0, 1";
				if (!$result = $db->sql_query($sql)) message_die(GENERAL_ERROR, 'unable to access posts', '', __LINE__, __FILE__, $sql);
				$used = ($row = $db->sql_fetchrow($result));
				$db->sql_freeresult($result);
			}

			// check if topics are using this icon
			if (!$used)
			{
				$sql = "SELECT * FROM " . TOPICS_TABLE . " WHERE topic_icon=$icon LIMIT 0, 1";
				if (!$result = $db->sql_query($sql)) message_die(GENERAL_ERROR, 'unable to access topics', '', __LINE__, __FILE__, $sql);
				$used = ($row = $db->sql_fetchrow($result));
				$db->sql_freeresult($result);
			}

			// some prevent check
			$error = false;
			$error_msg = '';

			// can't remove icon 0
			if ($icon == 0)
			{
				$error = true;
				$error_msg = (empty($error_msg) ? '' : '<br />') . $lang['Icons_error_del_0'];
			}

			// send error messages
			if ($error)
			{
				message_die(GENERAL_MESSAGE, '<br /><br />' . $error_msg . '<br /><br /><br />');
			}

			// send the confirm or replace template
			$template->set_filenames(array(
				'body' => 'admin/admin_icons_delete_body.tpl')
			);

			// header
			$template->assign_vars(array(
				'L_TITLE'					=> $lang['Icons_settings'],
				'L_TITLE_EXPLAIN'			=> $lang['Icons_settings_explain'],
				'L_TITLE_DELETE'			=> $lang['Icons_delete'],

				'L_CONFIRM'					=> $lang['Confirm'],
				'L_CANCEL'					=> $lang['Cancel'],
				)
			);

			// vars
			$template->assign_vars(array(
				'MESSAGE'	=> ($used ? $lang['Icons_delete_explain'] : $lang['Icons_confirm_delete']),
				'ICON'		=> get_icon_title($icon, 2, -1, true),
				)
			);

			// icon not used : validate the delete and send message
			if ($used)
			{
				// get the number of icon per row from config
				$icon_per_row = isset($board_config['icon_per_row']) ? intval($board_config['icon_per_row']) : 10;
				if ($icon_per_row <= 1)
				{
						$icon_per_row = 10;
				}

				// builded a new icones array without the one to replace
				$tmp = array();
				for ($i=0; $i < count($icones); $i++)
				{
					if ($icones[$i]['ind'] != $icon)
					{
						$tmp[] = $icones[$i];
					}
				}

				// display the icons
				$template->assign_block_vars('replace',array());
				$nb_row = intval( (count($tmp)-1) / $icon_per_row )+1;
				$offset = 0;
				for ($i=0; $i < $nb_row; $i++)
				{
					$template->assign_block_vars('replace.row',array());
					for ($j=0; ( ($j < $icon_per_row) && ($offset < count($tmp)) ); $j++)
					{
						// send to cell or cell_none
						$template->assign_block_vars('replace.row.cell', array(
							'ICON_ID'		=> $tmp[$offset]['ind'],
							'ICON_CHECKED'	=> ($tmp[$offset]['ind'] == 0) ? ' checked="checked"' : '',
							'ICON_IMG'		=> get_icon_title($tmp[$offset]['ind'], 2, -1, true),
							)
						);
						$offset++;
					}
				}
			}

			// system
			$s_hidden_fields = '<input type="hidden" name="mode" value="' . $mode . '" />';
			$s_hidden_fields .= '<input type="hidden" name="icon" value="' . $icon . '" />';
			$s_hidden_fields .= '<input type="hidden" name="sid" value="' . htmlspecialchars((string) $userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />';
			$template->assign_vars(array(
				'NAV_SEPARATOR'		=> $nav_separator,
				'S_ACTION'			=> append_sid("./admin_icons.$phpEx"),
				'S_HIDDEN_FIELDS'	=> $s_hidden_fields,
				)
			);

			// footer
			$template->pparse('body');
			include('./page_footer_admin.'.$phpEx);
		}
		else
		{
			// back to the main list
			$mode = '';
			$cancel = false;
		}
	}
}

//
// edit an icon
//
if ($mode == 'edit')
{
	// get the values from the existing state
	$icon_title='';$icon_url='';$icon_auth=AUTH_ALL;
	$icon_ids = array();
	if ($icon >= 0)
	{
		$icon_title		= $icones[ $map_icon[$icon] ]['alt'];
		$icon_url		= $icones[ $map_icon[$icon] ]['img'];
		$icon_auth		= $icones[ $map_icon[$icon] ]['auth'];
		@reset($icon_defined_special);
		foreach ($icon_defined_special as $key => $data)
		{
			if (isset($lang[ $data['lang_key'] ]) && is_scalar($lang[ $data['lang_key'] ]))
			{
				if ($icon_defined_special[$key]['icon'] == $icon)
				{
					$icon_ids[] = $key;
				}
			}
		}
	}

	// read the formular
	if (isset($_POST['icon_title']) && is_scalar($_POST['icon_title'])) $icon_title = trim(phpbb_request_raw_value((string) $_POST['icon_title']));
	if (isset($_POST['icon_url']) && is_scalar($_POST['icon_url'])) $icon_url = trim(phpbb_request_raw_value((string) $_POST['icon_url']));
	if (isset($_POST['icon_auth']) && is_scalar($_POST['icon_auth'])) $icon_auth = (int) $_POST['icon_auth'];

	if ($refresh || $submit)
	{
		$icon_ids = (isset($_POST['ids']) && is_array($_POST['ids'])) ? array_values(array_intersect(array_keys($icon_defined_special), array_filter($_POST['ids'], 'is_scalar'))) : array();
	}

	// process the buttons
	if ($cancel)
	{
		// back to the main list
		$mode = '';
		$cancel = false;
	}

	// detail
	if ($mode == 'edit')
	{
		// template
		$template->set_filenames(array(
			'body' => 'admin/admin_icons_edit_body.tpl')
		);

		// header
		$template->assign_vars(array(
			'L_TITLE'			=> $lang['Icons_settings'],
			'L_TITLE_EXPLAIN'	=> $lang['Icons_settings_explain'],

			'L_LANG'			=> $lang['Icons_lang_key'],
			'L_LANG_EXPLAIN'	=> $lang['Icons_lang_key_explain'],
			'L_ICON'			=> $lang['Icons_icon_key'],
			'L_ICON_EXPLAIN'	=> $lang['Icons_icon_key_explain'],
			'L_AUTH'			=> $lang['Icons_auth'],
			'L_AUTH_EXPLAIN'	=> $lang['Icons_auth_explain'],
			'L_DEFAULT'			=> $lang['Icons_defaults'],
			'L_DEFAULT_EXPLAIN'	=> $lang['Icons_defaults_explain'],

			'L_SUBMIT'			=> $lang['Submit'],
			'L_REFRESH'			=> $lang['Refresh'],
			'L_CANCEL'			=> $lang['Cancel'],
			)
		);

		// get the icon url
		$url = (preg_match('#^(?:|[A-Za-z0-9_][A-Za-z0-9_./-]{0,254})$#D', $icon_url) && strpos($icon_url, '..') === false) ? $icon_url : '';
		if (isset($images[$icon_url]) && is_string($images[$icon_url]))
		{
			$url = $images[$icon_url];
		}
		$pic = '';
		if (!empty($url))
		{
			$pic = '<img src="../' . phpbb_admin_html($url) . '" align="middle" alt="' . phpbb_icon_label($icon_title) . '" border="0" />&nbsp;';
		}

		// prepare auth level list
		$s_auths = '';
		@reset($auths);
		foreach ($auths as $key => $data)
		{
			$selected = ($icon_auth == $key) ? ' selected="selected"' : '';
			$s_auths .= sprintf('<option value="%s"%s>%s</option>', $key, $selected, $data);
		}
		$s_auths = sprintf('<select name="icon_auth">%s</select>', $s_auths);

		// images list
		$s_icons = '<option value="" selected="selected">' . $lang['Image_key_pick_up'] . '</option>';
		@ksort($images);
		@reset($images);
		foreach ($images as $image_key => $image_url)
		{
			if ( !is_array($image_url) )
			{
				$s_icons .= '<option value="' . phpbb_admin_html((string)$image_key) . '">' . phpbb_admin_html((string)$image_key) . '</option>';
			}
		}
		$s_icons = '<select name="icon_url_pickup_list" onChange="javascript:icon_url.value=this.options[this.selectedIndex].value; this.selectedIndex=0;">' . $s_icons . '</select>';

		// lang keys list
		$s_langs = '<option value="" selected="selected">' . $lang['Lang_key_pick_up'] . '</option>';
		@ksort($lang);
		@reset($lang);
		foreach ($lang as $lang_key => $lang_data)
		{
			if ( !is_array($lang_data) )
			{
				$s_langs .= '<option value="' . phpbb_admin_html((string)$lang_key) . '">' . phpbb_admin_html((string)$lang_key) . '</option>';
			}
		}
		$s_langs = '<select name="lang_key_pickup_list" onChange="javascript:icon_title.value=this.options[this.selectedIndex].value; this.selectedIndex=0;">' . $s_langs . '</select>';

		// vars
		$template->assign_vars(array(
			'ICON_TITLE_KEY'	=> phpbb_admin_html($icon_title),
			'ICON_TITLE'		=> isset($lang[$icon_title]) && is_scalar($lang[$icon_title]) ? '<br />' . phpbb_icon_label($icon_title) : '',
			'ICON'				=> $pic,
			'ICON_URL'			=> phpbb_admin_html($icon_url),
			'S_AUTHS'			=> $s_auths,
			'S_ICONS'			=> $s_icons,
			'S_LANGS'			=> $s_langs,
			)
		);

		// defaults assignments
		@reset($icon_defined_special);
		foreach ($icon_defined_special as $key => $data)
		{
			if (isset($lang[ $data['lang_key'] ]) && is_scalar($lang[ $data['lang_key'] ]))
			{
				$template->assign_block_vars('defaults', array(
					'NAME'		=> phpbb_icon_label($data['lang_key']),
					'ID'		=> $key,
					'CHECKED'	=> @in_array($key, $icon_ids) ? ' checked="checked"' : '',
					)
				);
			}
		}

		// system
		$s_hidden_fields = '<input type="hidden" name="mode" value="' . $mode . '" />';
		if ($icon >= 0)
		{
			$s_hidden_fields .= '<input type="hidden" name="icon" value="' . $icon . '" />';
		}
		$s_hidden_fields .= '<input type="hidden" name="sid" value="' . htmlspecialchars((string) $userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />';
		$template->assign_vars(array(
			'NAV_SEPARATOR'		=> $nav_separator,
			'S_ACTION'			=> append_sid("./admin_icons.$phpEx"),
			'S_HIDDEN_FIELDS'	=> $s_hidden_fields,
			)
		);

		// footer
		$template->pparse('body');
		include('./page_footer_admin.'.$phpEx);
	}
}

if ($mode == '')
{
	// get the icons usage
	$sql = "SELECT post_icon, count(*) as count FROM " . POSTS_TABLE . " 
			GROUP BY post_icon 
			ORDER BY post_icon";
	$result = $db->sql_query($sql);$icon_usage_available=(bool)$result;
	$total_posts = 0;
	while ($result && ($row = $db->sql_fetchrow($result)))
	{
		$total_posts = $total_posts + $row['count'];
		$row['post_icon'] = intval($row['post_icon']);
		if (isset($map_icon[ $row['post_icon'] ]))
		{
			$icon_index = $map_icon[$row['post_icon']];
			$icones[$icon_index]['usage'] = (isset($icones[$icon_index]['usage']) ? $icones[$icon_index]['usage'] : 0) + $row['count'];
		}
	}
	if ($total_posts <= 0) $total_posts = 1;

	// template
	$template->set_filenames(array(
		'body' => 'admin/admin_icons_body.tpl')
	);

	// header
	$template->assign_vars(array(
		'L_TITLE'			=> $lang['Icons_settings'],
		'L_TITLE_EXPLAIN'	=> $lang['Icons_settings_explain'],

		'L_PERMISSIONS'		=> $lang['Icons_auth'],
		'L_DEFAULT'			=> $lang['Icons_defaults'],
		'L_USAGE'			=> $lang['Usage'],
		'L_ACTION'			=> $lang['Action'],

		'L_EDIT'			=> $lang['Edit'],
		'L_DELETE'			=> $lang['Delete'],
		'L_MOVEUP'			=> $lang['Move_up'],
		'L_MOVEDW'			=> $lang['Move_down'],

		'L_CREATE'			=> $lang['Create_new'],
		)
	);

	// display icons
	for ($i=0; $i < count($icones); $i++)
	{
		$template->assign_block_vars('row', array(
			'ICON'		=> get_icon_title($icones[$i]['ind'], 1, -1, true),
			'ICON_KEY'	=> $icones[$i]['img'],
			'L_LANG'	=> phpbb_icon_label($icones[$i]['alt']),
			'LANG_KEY'	=> isset($lang[ $icones[$i]['alt'] ]) ? '<br />(' . $icones[$i]['alt'] . ')' : '',
			'L_AUTH'	=> $auths[ $icones[$i]['auth'] ],
			'USAGE' => !$icon_usage_available ? phpbb_admin_html($lang['Icons_usage_unavailable']) : ((intval(isset($icones[$i]['usage'])?$icones[$i]['usage']:0) > 0) ? $icones[$i]['usage'] . '&nbsp;(' . round(($icones[$i]['usage'] * 100)/$total_posts) . '%)' : ''),
			'U_EDIT'	=> append_sid("./admin_icons.$phpEx?mode=edit&icon=" . $icones[$i]['ind']),
			'U_DELETE'	=> append_sid("./admin_icons.$phpEx?mode=del&icon=" . $icones[$i]['ind']),
			'ICON_ID'	=> (int) $icones[$i]['ind'],
			)
		);

		// list of default assignement
		@reset($icon_defined_special);
		foreach ($icon_defined_special as $key => $data)
		{
			if ( ($data['icon'] == $icones[$i]['ind']) && isset($lang[ $data['lang_key'] ]) )
			{
				$template->assign_block_vars('row.default', array(
					'L_DEFAULT' => phpbb_icon_label($data['lang_key']),
					)
				);
			}
		}
	}

	// system
	$s_hidden_fields = '<input type="hidden" name="sid" value="' . htmlspecialchars((string) $userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />';
	$template->assign_vars(array(
		'NAV_SEPARATOR'		=> $nav_separator,
		'S_ACTION'			=> append_sid("./admin_icons.$phpEx"),
		'S_HIDDEN_FIELDS'	=> $s_hidden_fields,
		)
	);

	// footer
	$template->pparse('body');
	include('./page_footer_admin.'.$phpEx);
}

?>
