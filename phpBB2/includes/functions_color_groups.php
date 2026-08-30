<?php
/***************************************************************************
*                    $RCSfile: functions_color_groups.php,v $
*                            -------------------
*   copyright            : (C) 2002-2003 Nivisec.com
*   email                : support@nivisec.com
*
*   $Id: functions_color_groups.php,v 1.3 2003/09/03 02:52:46 nivisec Exp $
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
if (!defined('IN_PHPBB') || !IN_PHPBB) die('Invalid Function Include, Hacking Attempt?');

define('RGB_COLOR_LIST', 'aqua,black,blue,fuchsia,gray,green,lime,maroon,navy,olive,purple,red,silver,teal,white,yellow');
if (!defined('COPYRIGHT_NIVISEC_FORMAT')) define('COPYRIGHT_NIVISEC_FORMAT',
'<br /><span class="copyright"><center>
	%s 
	&copy; %s 
	<a href="http://www.nivisec.com" class="copyright">Nivisec.com</a>.
	</center></span>'
);


if (!function_exists('copyright_nivisec'))
{
	/**
	* @return void
	* @desc Prints a sytlized line of copyright for module
	*/
	function copyright_nivisec($name, $year)
	{
		printf(COPYRIGHT_NIVISEC_FORMAT, $name, $year);
	}
}

if (!function_exists('check_font_color_nivisec'))
{
	/**
	* @return boolean
	* @param item string
	* @desc Checks for a valid color entry in the form of one of default words or #rrggbb.  Assumes $colors is defined already.
	*/
	function check_font_color_nivisec($item)
	{
		$item = trim((string) $item);
		//Find out if it's a valid hex or valid word
		if (!preg_match('/^#[0-9A-Fa-f]{6}$/D', $item) && !in_array(strtolower($item), explode(',', RGB_COLOR_LIST), true))
		{
			return false;
		}
		//If we get this far, it exists and/or is valid
		return true;
	}
}

if (!function_exists('find_lang_file_nivisec'))
{
	/**
	* @return boolean
	* @param filename string
	* @desc Tries to locate and include the specified language file.  Do not include the .php extension!
	*/
	function find_lang_file_nivisec($filename)
	{
		global $lang, $phpbb_root_path, $board_config, $phpEx;
		
		if (file_exists($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . "/$filename.$phpEx"))
		{
			include_once($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . "/$filename.$phpEx");
		}
		elseif (file_exists($phpbb_root_path . "language/lang_english/$filename.$phpEx"))
		{
			include_once($phpbb_root_path . "language/lang_english/$filename.$phpEx");
		}
		else
		{
			message_die(GENERAL_ERROR, "Unable to find a suitable language file for $filename!", '');
		}
		return true;
	}
}
if (!function_exists('set_filename_nivisec'))
{
	/**
	* @return boolean
	* @param filename string
	* @param handle string
	* @desc Sets the filename to handle in the $template class.  Saves typing for me :)
	*/
	function set_filename_nivisec($handle, $filename)
	{
		global $template;
		
		$template->set_filenames(array(
		$handle => $filename
		));
		
		return true;
	}
}
if (!function_exists('do_query_nivisec'))
{
	/**
	* @return void
	* @param sql string
	* @param $result_list array
	* @param error string
	* @desc Does $sql query.  If error, prints $error and modifies reference $result_list to be a row set
	*/
	function do_query_nivisec($sql, &$result_list, $error)
	{
		global $db;
		
		if (!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $error, '', __LINE__, __FILE__, $sql);
		}
		$result_list = $db->sql_fetchrowset($result);
	}
}

if (!function_exists('do_fast_query_nivisec'))
{
	/**
	* @return void
	* @param sql string
	* @param error string
	* @desc Does $sql query and doesn't bother with results.  If error, prints $error
	*/
	function do_fast_query_nivisec($sql, $error)
	{
		global $db;
		
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $error, '', __LINE__, __FILE__, $sql);
		}
	}
}
function get_color_group_order_max()
{
	global $db, $lang;
	
	$sql = 'SELECT max(order_num) as max FROM ' . COLOR_GROUPS_TABLE;
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	
	return $row['max'];
}
function get_color_group_order_min()
{
	global $db, $lang;
	
	$sql = 'SELECT MIN(order_num) as min FROM ' . COLOR_GROUPS_TABLE;
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	
	return $row['min'];
}
/**
* @return void
* @param new string
* @param orig string
* @param type int
* @desc Updates user levels of type based on the difference between new and orig string lists
*/
function color_groups_update_group_id($group_list, $user_list, $group_id)
{
	global $lang, $db, $status_message;
	$group_id = (int) $group_id;
	if ($group_id <= 0)
	{
		return false;
	}
	$sql = 'SELECT group_id FROM ' . COLOR_GROUPS_TABLE . "
		WHERE group_id = $group_id";
	if (!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['Error_Group_Table'], '', __LINE__, __FILE__, $sql);
	}
	if (!$db->sql_fetchrow($result))
	{
		return false;
	}
	$user_ids = array();
	foreach (preg_split('/\s*,\s*/', is_scalar($user_list) ? (string) $user_list : '', -1, PREG_SPLIT_NO_EMPTY) as $id)
	{
		$id = (int) $id;
		if ($id > 0)
		{
			$user_ids[$id] = $id;
		}
	}
	$group_ids = array();
	foreach (preg_split('/\s*,\s*/', is_scalar($group_list) ? (string) $group_list : '', -1, PREG_SPLIT_NO_EMPTY) as $id)
	{
		$id = (int) $id;
		if ($id > 0)
		{
			$group_ids[$id] = $id;
		}
	}
	$sql = array();
	
	// Set all old user's and groups to "NO COLOR GROUP" to take care of any deletions //
	$sql[] = 'UPDATE ' . USERS_TABLE . "
		SET user_color_group = 0
		WHERE user_color_group = $group_id";
	$sql[] = 'UPDATE ' . GROUPS_TABLE . "
		SET group_color_group = 0
		WHERE group_color_group = $group_id";
	// Set all new list items to have the color group, if we were given a list //
	if (!empty($user_ids))
	{
		$sql[] = 'UPDATE ' . USERS_TABLE . "
		SET user_color_group = $group_id
		WHERE user_id IN (" . implode(',', $user_ids) . ')';
	}
	if (!empty($group_ids))
	{
		$sql[] = 'UPDATE ' . GROUPS_TABLE . "
		SET group_color_group = $group_id
		WHERE group_id IN (" . implode(',', $group_ids) . ')';
	}
	
	// DO the actual SQL commands now //
	foreach($sql as $command)
	{
		if (!$db->sql_query($command))
		{
			message_die(GENERAL_ERROR, $lang['Error_Group_Table'], '', __LINE__, __FILE__, $sql);
		}
	}
	
	$status_message .= $lang['Updated_Group'];
	return true;
}

function color_groups_setup_list()
{
	global $lang, $template, $db, $plus_config;
	
	$sql = 'SELECT * FROM ' . COLOR_GROUPS_TABLE . '
		WHERE hidden = 0
		ORDER BY order_num ASC';
	if (!$result = $db->sql_query($sql)) message_die(GENERAL_ERROR, $lang['Error_Group_Table'], '', __LINE__, __FILE__, $sql);
	$list = '';
	
	if ($plus_config['index_layout'] == 'index_body_plus.tpl')
		{ 
			while ($row = $db->sql_fetchrow($result))
			{
				$color = check_font_color_nivisec($row['group_color']) ? htmlspecialchars($row['group_color'], ENT_QUOTES, 'UTF-8') : '';
				$style = ($color !== '') ? ' style="font-weight:bold;color:' . $color . '"' : '';
				$list .= '&nbsp;[ <span' . $style . '>' . htmlspecialchars($row['group_name'], ENT_QUOTES, 'UTF-8') . '</span> ]&nbsp;<br />';
			}
		}
	else
		{
			while ($row = $db->sql_fetchrow($result))
			{
				$color = check_font_color_nivisec($row['group_color']) ? htmlspecialchars($row['group_color'], ENT_QUOTES, 'UTF-8') : '';
				$style = ($color !== '') ? ' style="font-weight:bold;color:' . $color . '"' : '';
				$list .= '&nbsp;[ <span' . $style . '>' . htmlspecialchars($row['group_name'], ENT_QUOTES, 'UTF-8') . '</span> ]&nbsp;';
			}
		}
				$template->assign_var('COLOR_GROUPS_LIST', $list);
}

function cg_get_data($user_id, $all = false)
{
	global $db, $phpbb_root_path;
	$user_id = (int) $user_id;

	// Version cache mod start 
	// Change following two variables if you need to: 
	$cache_update = 86400; // 1 day cache timeout. change it to whatever you want 
	$cache_dir = $phpbb_root_path . 'cache';
	$cache_file = $cache_dir . '/cg_users.cache';

	$use_cache = defined('CCache');
	$can_write_cache = ($use_cache && is_writable($cache_dir));

	$do_update = true; 

	$user_style = array();
	if (@file_exists($cache_file) && $use_cache)
	{ 
		$cached_colors = phpbb_data_cache_read($cache_file);
		if (is_array($cached_colors) && isset($cached_colors['updated'], $cached_colors['users']) &&
			is_numeric($cached_colors['updated']) && is_array($cached_colors['users']))
		{ 
			$last_update = (int) $cached_colors['updated'];
			$user_style = $cached_colors['users'];
			if ($last_update > (time() - $cache_update))
			{
				$do_update = false;
			}
		} 
	} 

	if($do_update || !isset($user_style[$user_id]['name']) || !$user_style[$user_id]['name'])
	{ 
		if (!$can_write_cache && !$all)
		{
			// Get the user info and see if they are assigned a color_group //
			$sql = 'SELECT u.user_color_group, u.username, c.* FROM (' . USERS_TABLE . ' u LEFT JOIN ' . COLOR_GROUPS_TABLE . " c ON u.user_color_group = c.group_id)
				WHERE u.user_id = $user_id";
			$result = $db->sql_query($sql);
			$row = $db->sql_fetchrow($result);
			if (!isset($row['username']))
			{
				//If there was a problem before, we don't want a blank username!
				$sql = 'SELECT username FROM ' . USERS_TABLE . "
					WHERE user_id = ".$user_id;
				$result = $db->sql_query($sql);
				$row = $db->sql_fetchrow($result);
			}
			$user_style[$user_id]['name'] = $row['username'];
			$user_style[$user_id]['color'] = '';

			if (isset($row['group_color']))
			{
				// WE found the highest level color, head out now //
				$user_style[$user_id]['color'] = stripslashes($row['group_color']);
			}
			else
			{
				// Now start looking for user group memberships //
				$sql = 'SELECT c.* FROM ' . USER_GROUP_TABLE . ' ug, ' . USERS_TABLE . ' u, ' . COLOR_GROUPS_TABLE . ' c, ' . GROUPS_TABLE . ' g
				WHERE ug.user_id = ' . $user_id . '
				AND u.user_id = ug.user_id
				AND ug.group_id = g.group_id
				AND g.group_color_group = c.group_id
				AND g.group_single_user = 0
				ORDER BY c.order_num ASC LIMIT 1';
				//print $sql;
				$result = $db->sql_query($sql);
				$curr = 10000000000000;
				$style_color = '';
				while ($row = $db->sql_fetchrow($result))
				{
					// If our new group in the list is a higher order number, it's color takes precedence //
					if ($row['order_num'] < $curr)
					{
						$curr = $row['order_num'];
						$user_style[$user_id]['color'] = stripslashes($row['group_color']);
					}
				}
			}

		} else 
		{
			$user_color_id = '';
			$group_style_color = array();
			$user_style = array();
	
			// Start looking for user group memberships //
			$sql = 'SELECT c.group_color, u.user_id FROM ' . USER_GROUP_TABLE . ' ug, ' . USERS_TABLE . ' u, ' . COLOR_GROUPS_TABLE . ' c, ' . GROUPS_TABLE . ' g
				WHERE u.user_id = ug.user_id
					AND ug.group_id = g.group_id
					AND g.group_color_group = c.group_id
					AND g.group_single_user = 0
				GROUP BY u.user_id
				ORDER BY c.order_num';
			$result = $db->sql_query($sql);
			while ($row = $db->sql_fetchrow($result))
			{
				$group_style_color[$row['user_id']] = $row['group_color'];
			}
			$db->sql_freeresult($result);
	
			// Get the user info and see if they are assigned a color_group //
			$sql = "SELECT u.user_color_group, u.username, u.user_id, cg.group_color FROM (" . USERS_TABLE . " u LEFT JOIN " . COLOR_GROUPS_TABLE . " cg ON (cg.group_id = u.user_color_group))
				WHERE u.user_id <> " . ANONYMOUS . "
				ORDER BY u.user_id";
			$result = $db->sql_query($sql);
	
			while($row = $db->sql_fetchrow($result))
			{
				$style_color = '';
				$user_color_id = $row['user_id'];
				$username = $row['username'];
	
				if ($row['user_color_group'] > 0)
				{
						$style_color = $row['group_color'];
				}
				else
				{
					$style_color = isset($group_style_color[$user_color_id]) ? $group_style_color[$user_color_id] : '';
				}
	
				$user_style[$user_color_id]['name'] = $username;
				$user_style[$user_color_id]['color'] = $style_color;
			}
			$db->sql_freeresult($result);
			
			if ($can_write_cache)
			{
				phpbb_data_cache_write($cache_file, array(
					'updated' => time(),
					'users' => $user_style,
				));
			}
		}
	}

	$cacheUsers = array();
	// filecache only
	if (!$all)
		$cacheUsers[$user_id] = isset($user_style[$user_id]) ? $user_style[$user_id] : array('name' => '', 'color' => '');
	else 
		$cacheUsers = $user_style;

	return $cacheUsers;
}

function color_group_colorize_name($user_id, $no_profile = false)
{
	global $db, $lang, $phpEx;
	global $phpbb_root_path;
	static $cacheUsers;
	$user_id = (int) $user_id;
	
	if ($user_id == ANONYMOUS)
	{
		return $lang['Guest'];
	}

	if ( !isset($cacheUsers[$user_id]['name']) || !$cacheUsers[$user_id]['name'] )
	{
		// $cacheUsers = cg_get_data($user_id); 
		$cacheUsers = cg_get_data($user_id, true);
	}	
	if (!isset($cacheUsers[$user_id]) || !is_array($cacheUsers[$user_id]))
	{
		$cacheUsers[$user_id] = array('name' => '', 'color' => '');
	}

	$color = check_font_color_nivisec($cacheUsers[$user_id]['color']) ? htmlspecialchars($cacheUsers[$user_id]['color'], ENT_QUOTES, 'UTF-8') : '';
	$style_color = ($color !== '') ? 'style="font-weight:bold;color:' . $color . '"' : '';

	$username = stripslashes($cacheUsers[$user_id]['name']);

	if ($username == '')
	{
		$sql = "SELECT username FROM " . USERS_TABLE . "
			WHERE user_id = $user_id";
		if (!$result = $db->sql_query($sql))
		{
			$username = 'John Doe_'.$user_id;
		} else {
			$row = $db->sql_fetchrow($result);
			$username = ($row && isset($row['username'])) ? $row['username'] : $lang['Guest'];
			$db->sql_freeresult($result);
		}
	}
	$username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');

	// Make the profile link or no and return it //
	if ($no_profile)
	{
		$user_link = "<span $style_color>$username</span>";
	}
	else
	{
		$user_link = '<a href="' . append_sid($phpbb_root_path."profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=$user_id") . '" '.$style_color.'>'.$username.'</a>';
	}

	return $user_link;
}

?>
