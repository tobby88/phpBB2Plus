<?php
/***************************************************************************
*                           functions_stats.php
*                            -------------------
*   begin                : Wed, Sep 04, 2002
*   copyright            : (C) 2002 Meik Sievertsen
*   email                : acyd.burn@gmx.de
*
*   $Id: functions_stats.php,v 1.13 2002/11/28 18:37:55 acydburn Exp $
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

// For backward compatibility
function module_language_parse($lang_key, $lang_var)
{
	global $lang;

	$lang[$lang_key] = $lang_var;
}

function sql_quote($data)
{
	$data = str_replace("'", "\'", $data);
	return ($data);
}

function stats_modules_root()
{
	global $phpbb_root_path, $__stats_config;

	$relative = isset($__stats_config['modules_dir']) ? trim(str_replace('\\', '/', $__stats_config['modules_dir']), '/') : '';
	if ($relative === '' || strpos($relative, '..') !== false || !preg_match('#^[a-zA-Z0-9_./-]+$#D', $relative))
	{
		return false;
	}
	$board_root = @realpath($phpbb_root_path);
	$modules_root = @realpath($phpbb_root_path . $relative);
	if (!$board_root || !$modules_root)
	{
		return false;
	}
	$board_root = rtrim(str_replace('\\', '/', $board_root), '/');
	$modules_root_normalized = str_replace('\\', '/', $modules_root);
	if (strpos($modules_root_normalized, $board_root . '/') !== 0)
	{
		return false;
	}
	return $modules_root;
}

function stats_module_path($module_name, $relative_file = '')
{
	if (!is_string($module_name) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/D', $module_name))
	{
		return false;
	}
	$modules_root = stats_modules_root();
	if (!$modules_root)
	{
		return false;
	}
	$module_root = @realpath($modules_root . '/' . $module_name);
	$modules_root_normalized = rtrim(str_replace('\\', '/', $modules_root), '/');
	$module_root_normalized = $module_root ? str_replace('\\', '/', $module_root) : '';
	if (!$module_root || strpos($module_root_normalized, $modules_root_normalized . '/') !== 0 || !@is_dir($module_root))
	{
		return false;
	}
	if ($relative_file === '')
	{
		return $module_root;
	}
	$relative_file = str_replace('\\', '/', (string) $relative_file);
	if ($relative_file === '' || strpos($relative_file, '..') !== false || substr($relative_file, 0, 1) === '/')
	{
		return false;
	}
	$file = @realpath($module_root . '/' . $relative_file);
	$file_normalized = $file ? str_replace('\\', '/', $file) : '';
	if (!$file || strpos($file_normalized, rtrim($module_root_normalized, '/') . '/') !== 0 || !@is_file($file))
	{
		return false;
	}
	return $file;
}

function generate_module_info($module_data, $install = FALSE)
{
	global $db, $phpbb_root_path, $__stats_config;

	$module_dir = trim($module_data['name']);
	$info_file = stats_module_path($module_dir, 'info.txt');
	if (!$info_file)
	{
		return array(
			'name' => $module_dir,
			'dname' => '',
			'condition_result' => false,
			'update_time' => 0,
			'auth_value' => 0,
			'active' => 0
		);
	}

	//
	// Get Info from Cache or not...
	//
	$condition_mode = FALSE;
	$ret_array['condition_result'] = TRUE;
	$condition = '';

	$cache_valid = $module_data['module_info_time'] == filemtime($info_file);
	if ($cache_valid)
	{
		$cached_info = phpbb_safe_unserialize(stripslashes($module_data['module_info_cache']));
		if (is_array($cached_info))
		{
			$ret_array = $cached_info;
		}
		else
		{
			$cache_valid = false;
		}
	}
	if (!$cache_valid)
	{
		$extra_info_mode = FALSE;
		$ret_array['default_update_time'] = 0;
		$data_file = @file($info_file);
		
		foreach ((array) $data_file as $key => $data)
		{
			if ((!$extra_info_mode) && (!$condition_mode))
			{
				if (preg_match("/\[name\]/", $data))
				{
					$ret_array['name'] = trim(str_replace("[name]", '', $data));
				}
				elseif (preg_match("/\[author\]/", $data))
				{
					$ret_array['author'] = trim(str_replace("[author]", '', $data));
				}
				elseif (preg_match("/\[email\]/", $data))
				{
					$ret_array['email'] = trim(str_replace("[email]", '', $data));
				}
				elseif (preg_match("/\[url\]/", $data))
				{
					$ret_array['url'] = trim(str_replace("[url]", '', $data));
				}
				elseif (preg_match("/\[version\]/", $data))
				{
					$ret_array['version'] = trim(str_replace("[version]", '', $data));
				}
				elseif (preg_match("/\[update_time\]/", $data))
				{
					$ret_array['default_update_time'] = trim(str_replace("[update_time]", '', $data));
				}
				elseif (preg_match("/\[stats_mod_version\]/", $data))
				{
					$ret_array['stats_mod_version'] = trim(str_replace("[stats_mod_version]", '', $data));
				}
				elseif (preg_match("/\[extra_info\]/", $data))
				{
					$extra_info_mode = TRUE;
					$ret_array['extra_info'] =  trim(str_replace("[extra_info]", '', $data));
				}
			}
			else
			{
				if ($extra_info_mode)
				{
					if (preg_match("/\[\/extra_info\]/", $data))
					{
						$extra_info_mode = FALSE;
					}
					else
					{
						$ret_array['extra_info'] .= $data;
					}
				}
			}
		}

		$sql = "UPDATE " . MODULES_TABLE . "
		SET module_info_cache = '" . addslashes(serialize($ret_array)) . "',
		module_info_time = " . filemtime($info_file) . "
		WHERE module_id = " . intval($module_data['module_id']);

		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Could not update Info Cache', '', __LINE__, __FILE__, $sql);
		}
		
	}
	
	$ret_array['dname'] = $module_dir;
	$ret_array['update_time'] = $module_data['update_time'];
	$ret_array['auth_value'] = $module_data['auth_value'];
	$ret_array['active'] = $module_data['active'];

	if ($install)
	{
		$data_file = @file($info_file);

		foreach ((array) $data_file as $key => $data)
		{
			if (!$condition_mode)
			{
				if (preg_match("/\[condition\]/", $data))
				{
					$condition_mode = TRUE;
					$condition =  trim(str_replace("[condition]", '', $data));
				}
			}
			else
			{
				if (preg_match("/\[\/condition\]/", $data))
				{
					$condition_mode = FALSE;
				}
				else
				{
					$condition .= $data;
				}
			}
		}

		// Parse the condition
		if ($condition != '')
		{
			$condition_name = trim($condition);
			$return_val = (bool) preg_match('/^[A-Z][A-Z0-9_]*$/', $condition_name)
				&& defined($condition_name)
				&& constant($condition_name) !== '';
			$ret_array['condition_result'] = $return_val;
		}
	}

	return $ret_array;
}

//
// Get and update Module List
//
function update_module_list()
{
	global $phpbb_root_path, $db, $__stats_config;

	//
	// Returns a list of modules found by directory and updates the database as needed
	//
	$ret_list = array();
	
	$modules_root = stats_modules_root();
	$handle = $modules_root ? @opendir($modules_root) : false;

	if (!$handle)
	{
		message_die(GENERAL_ERROR, 'Unable to open statistics modules directory');
	}

	$dir_list = '';
	
	while ($file = readdir($handle))
	{
		if ($file != '.' && $file != '..' && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/D', $file) && is_dir($modules_root . '/' . $file) && ($file != '_vti_cnf') && ($file != 'CVS') )
		{
			$dir_list .= ($dir_list == '') ? "'$file'" : ", '$file'";
			
			$sql = "SELECT MAX(display_order) as max
			FROM " . MODULES_TABLE;

			if (!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Unable to select display order', '', __LINE__, __FILE__, $sql);
			}

			$row = $db->sql_fetchrow($result);
			
			$curr_max = $row['max'];
			
			$sql = "SELECT module_id, name, display_order, active
			FROM " . MODULES_TABLE . "
			WHERE (name = '" . trim($file) . "')";

			if (!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not query Modules Table', '', __LINE__, __FILE__, $sql);
			}

			if ($db->sql_numrows($result) == 0)
			{
				$sql = "SELECT MAX(module_id) as next_id FROM " . MODULES_TABLE;

				if (!($result = $db->sql_query($sql)) )
				{
					message_die(GENERAL_ERROR, 'Unable to get next Module ID', '', __LINE__, __FILE__, $sql);
				}
				
				$row = $db->sql_fetchrow($result);
				$next_id = $row['next_id'] + 1;
			    
				$sql = "INSERT INTO  " . MODULES_TABLE . "
				(module_id, name, display_order, module_info_cache, module_db_cache, module_result_cache)
				VALUES (" . $next_id . ", '" . trim($file) . "', " . ($curr_max + 10) . ", '', '', '')";

				if (!$db->sql_query($sql))
				{
					message_die(GENERAL_ERROR, 'Could not insert data into Modules Table', '', __LINE__, __FILE__, $sql);
				}
				
				$sql = "SELECT module_id, display_order, active
				FROM " . MODULES_TABLE . "
				WHERE module_id = " . $next_id;
				
				if (!$result = $db->sql_query($sql))
				{
					message_die(GENERAL_ERROR, 'Unable to select created Module Entry', '', __LINE__, __FILE__, $sql);
				}

				$row = $db->sql_fetchrow($result);
			}
			else
			{
				$row = $db->sql_fetchrow($result);
			}

		}
	}

	//
	// Kill old module folders that were deleted
	//
	if ($dir_list === '')
	{
		message_die(GENERAL_ERROR, 'No valid statistics modules were found.');
	}
	$sql = "DELETE FROM " . MODULES_TABLE . " WHERE name NOT IN ($dir_list)";

	if (!$db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not delete obsolete Modules', '', __LINE__, __FILE__, $sql);
	}
}

//
// Get complete Module List from Database
//
function get_module_list_from_db()
{
	global $phpbb_root_path, $db, $__stats_config;

	//
	// Returns a list of modules stored in the database
	//
	$ret_list = array();
	
	$sql = "SELECT module_id, name, display_order
	FROM " . MODULES_TABLE . "
	WHERE (active = 1) AND (installed = 1)
	ORDER BY display_order ASC";

	if (!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not get Module List', '', __LINE__, __FILE__, $sql);
	}

	if ($db->sql_numrows($result) != 0)
	{
		$rows = $db->sql_fetchrowset($result);

		for ($i = 0; $i < count($rows); $i++)
		{
			$ret_list[$rows[$i]['module_id']] = $rows[$i]['name'];
		}
	}

	return ($ret_list);
}

//
// Get complete Module Data from Database
//
function get_module_data_from_db()
{
	global $phpbb_root_path, $db, $__stats_config;

	//
	// Returns a list of modules stored in the database
	//
	$ret_list = array();
	
	$sql = "SELECT *
	FROM " . MODULES_TABLE . "
	WHERE (active = 1) AND (installed = 1)
	ORDER BY display_order ASC";

	if (!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not get Module List', '', __LINE__, __FILE__, $sql);
	}

	if (($num_rows = $db->sql_numrows($result)) != 0)
	{
		$rows = $db->sql_fetchrowset($result);

		for ($i = 0; $i < $num_rows; $i++)
		{
			$ret_list[$rows[$i]['module_id']] = $rows[$i];
		}
	}

	return ($ret_list);
}


//
// Check Module Authentication
// Only ALL, REG and ADMIN is supported
//
function module_auth_check($module_data, $userdata)
{
	// FALSE = Not Authorized
	// TRUE = Authorized
	global $db;

	$auth_value = intval($module_data['auth_value']);

	switch ($auth_value)
	{
		case AUTH_ALL:
			return (true);
			break;

		case AUTH_REG:
			if ( ($userdata['session_logged_in']) && ($userdata['user_id'] != ANONYMOUS) )
			{
				return (true);
			}
			else
			{
				return (false);
			}
			break;

		case AUTH_ADMIN:
			if ( ( $userdata['user_level'] == ADMIN && $userdata['session_logged_in'] ) )
			{
				return (true);
			}
			else
			{
				return (false);
			}
			break;
	}

	return (false);
}

?>
