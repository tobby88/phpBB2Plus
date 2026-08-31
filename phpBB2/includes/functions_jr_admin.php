<?php

define('EXPLODE_SEPERATOR_CHAR', '|');
define('JR_ADMIN_DIR', 'admin/');
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
		if (!is_scalar($filename) || !preg_match('/^lang_[a-z0-9_]+$/iD', (string) $filename))
		{
			message_die(GENERAL_ERROR, 'Invalid language file request.', '');
		}
		$filename = (string) $filename;
		
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

if (!function_exists('sql_query_nivisec'))
{
	/**
	* @return array
	* @param sql string
	* @param error string
	* @param fast boolean
	* @param return_items int
	* @desc Does $sql query and returns a list if $fast = false.  $error displayed on error.  if $return_items = 1, then only the first row data is returned.  Usefull when querying unique entries.
	*/
	function sql_query_nivisec($sql, $error, $fast = true, $return_items = 0)
	{
		global $db;
		
		switch($fast)
		{
			case true:
			{
				
				if (!$db->sql_query($sql))
				{
					message_die(GENERAL_ERROR, $error, '', __LINE__, __FILE__, $sql);
				}
				return false;
			}
			case false:
			{
				if (!$result = $db->sql_query($sql))
				{
					message_die(GENERAL_ERROR, $error, '', __LINE__, __FILE__, $sql);
				}
				if ($return_items != 1)
				{
					return ($db->sql_fetchrowset($result));
				}
				else
				{
					return ($db->sql_fetchrow($result));
				}
			}
			
		}
	}
}

function jr_admin_check_file_hashes($file)
{
	global $phpbb_root_path, $phpEx, $userdata;
	$file = is_scalar($file) ? basename((string) $file) : '';
	if (!preg_match('/^admin_[a-z0-9_]+\.' . preg_quote($phpEx, '/') . '$/iD', $file))
	{
		return false;
	}
	$module = array();
	
	//Include the file to get the module list
	$setmodules = 1;
	include($phpbb_root_path.JR_ADMIN_DIR.$file);
	unset($setmodules);
	
	$jr_admin_userdata = jr_admin_get_user_info($userdata['user_id']);
	
	$user_modules = explode(EXPLODE_SEPERATOR_CHAR, $jr_admin_userdata['user_jr_admin']);
	
	foreach($module as $cat => $module_data)
	{
		foreach($module_data as $module_name => $module_file)
		{
			//Remove sid if we find one
			$module_file = preg_replace("/(\?|&|&amp;)sid=[A-Z,a-z,0-9]{32}/", '', $module_file);
			//Make our unique ID
			$file_hash = md5($cat.$module_name.$module_file);
			//See if it is in the array
			if (in_array($file_hash, $user_modules, true))
			{
				return true;
			}
		}
	}
	
	//If we get this far, the user has no business with the module filename
	return false;
}

function jr_admin_get_module_list($user_module_list = false)
{
	global $db, $phpbb_root_path, $lang, $phpEx, $board_config, $userdata;
	$module = array();
	$module_list = array();
	
	/* Debugging for this function. Debugging in this function causes changes to the way ADMIN users
	are interpreted.  You are warned */
	$debug = false;
	/* Even more debug info! */
	$verbose = false;
	
	//Read all the modules
	$setmodules = 1;
	$dir = @opendir($phpbb_root_path.JR_ADMIN_DIR);
	if ($dir === false)
	{
		return $module_list;
	}
	$pattern = "/^admin_.+\.$phpEx$/";
	while (($file = @readdir($dir)) !== false)
	{
		if (preg_match($pattern, $file))
		{
			//include($phpbb_root_path.JR_ADMIN_DIR.$file);
			include_once($phpbb_root_path.JR_ADMIN_DIR.$file);
		}
	}
	@closedir($dir);
	unset($setmodules);
	
	@ksort($module);
	if ($debug && $verbose)
	{
		print "<pre><font color=\"green\"><span class=\"gensmall\">DEBUG - Module List Non Cache - <br>";
		print_r($module);
		print "</span></font><br></pre>";
	}
	
	//Get the cache list we have and find non-existing and new items
	foreach ($module as $cat => $item_array)
	{
		foreach ($item_array as $module_name => $filename)
		{
			//Remove sid in case some retarted person appended it early *(cough admin_disallow.php cough)*
			$filename = preg_replace("/(\?|&|&amp;)sid=[A-Z,a-z,0-9]{32}/", '', $filename);
			if ($debug && $verbose) print "<span class=\"gensmall\"><font color=\"red\">DEBUG - filename = $filename</font></span><br>";
			//Note the md5 function compilation here to make a unique id
			$file_hash = md5($cat.$module_name.$filename);
			
			//Wee a 3-D array of our info!
			if ($user_module_list && ($userdata['user_level'] != ADMIN || $debug))
			{
				//If we were passed a list of valid modules, make sure we are sending the correct list back
				$user_modules = explode(EXPLODE_SEPERATOR_CHAR, $user_module_list);
				if (in_array($file_hash, $user_modules))
				{
					$module_list[$cat][$module_name]['filename'] = $filename;
					$module_list[$cat][$module_name]['file_hash'] = $file_hash;
				}
			}
			else
			{
				//No list sent?  Send back all of them because we should be an ADMIN!
				$module_list[$cat][$module_name]['filename'] = $filename;
				$module_list[$cat][$module_name]['file_hash'] = $file_hash;
			}
		}
	}
	
	jr_admin_include_all_lang_files();
	return jr_admin_prepare_navigation_modules($module_list);
}

function jr_admin_navigation_label($name)
{
	global $lang;
	return isset($lang[$name]) && is_scalar($lang[$name])
		? (string) $lang[$name]
		: preg_replace('/_/', ' ', (string) $name);
}

function jr_admin_navigation_name_compare($left, $right)
{
	$comparison = strnatcasecmp(jr_admin_navigation_label($left), jr_admin_navigation_label($right));
	return ($comparison !== 0) ? $comparison : strcmp((string) $left, (string) $right);
}

function jr_admin_navigation_module_compare($left, $right)
{
	$left_name = isset($left['navigation_name']) ? $left['navigation_name'] : '';
	$right_name = isset($right['navigation_name']) ? $right['navigation_name'] : '';
	$comparison = jr_admin_navigation_name_compare($left_name, $right_name);
	if ($comparison !== 0)
	{
		return $comparison;
	}
	$left_file = isset($left['filename']) ? $left['filename'] : '';
	$right_file = isset($right['filename']) ? $right['filename'] : '';
	return strcmp((string) $left_file, (string) $right_file);
}

function jr_admin_prepare_navigation_modules($module_list)
{
	if (!is_array($module_list))
	{
		return array();
	}
	foreach ($module_list as $category => $modules)
	{
		if (!is_array($modules))
		{
			continue;
		}
		foreach ($modules as $module_name => $module_data)
		{
			if (!isset($module_data['navigation_name']))
			{
				$module_data['navigation_name'] = $module_name;
			}
			$module_list[$category][$module_name] = $module_data;
		}
	}

	// These are separate categories only because the original MOD packages
	// registered them independently. Present related controls together while
	// keeping every original module hash intact for Junior Admin permissions.
	$category_aliases = array(
		'Extreme_Styles' => 'Styles',
		'Extensions' => 'Attachments',
		'Custom_Profile' => 'Users',
		'Systeminfo' => 'General',
		'Plus' => 'General'
	);
	$module_name_aliases = array(
		'Custom_Profile' => array(
			'Add_new' => 'Profile_fields_add',
			'Edit' => 'Profile_fields_edit'
		),
		'Plus' => array(
			'Configuration' => 'General_Plusconfig'
		)
	);
	foreach ($category_aliases as $source => $destination)
	{
		if (!isset($module_list[$source]) || !is_array($module_list[$source]))
		{
			continue;
		}
		if (!isset($module_list[$destination]) || !is_array($module_list[$destination]))
		{
			$module_list[$destination] = array();
		}
		foreach ($module_list[$source] as $module_name => $module_data)
		{
			if (isset($module_name_aliases[$source][$module_name]))
			{
				$module_data['navigation_name'] = $module_name_aliases[$source][$module_name];
			}
			$navigation_key = $module_name;
			if (isset($module_list[$destination][$navigation_key]))
			{
				$navigation_key = $source . '__' . $module_name;
			}
			$module_list[$destination][$navigation_key] = $module_data;
		}
		unset($module_list[$source]);
	}

	foreach ($module_list as $category => $modules)
	{
		if (is_array($modules))
		{
			uasort($modules, 'jr_admin_navigation_module_compare');
			$module_list[$category] = $modules;
		}
	}

	// Core board administration comes first. Integrated feature areas follow
	// in a stable, task-oriented order; any future category is appended by its
	// translated display name instead of unexpectedly appearing at the top.
	$category_order = array(
		'General', 'Forums', 'Users', 'Groups', 'Styles', 'Attachments',
		'Logs', 'Plus', 'Portal', 'Photo_Album', 'Arcade',
		'Download', 'Links', 'News Admin', 'KB_title', 'Statistics',
		'ctracker_module_category'
	);
	$ordered = array();
	foreach ($category_order as $category)
	{
		if (isset($module_list[$category]))
		{
			$ordered[$category] = $module_list[$category];
			unset($module_list[$category]);
		}
	}
	uksort($module_list, 'jr_admin_navigation_name_compare');
	foreach ($module_list as $category => $modules)
	{
		$ordered[$category] = $modules;
	}

	return $ordered;
}

function jr_admin_secure($file)
{
	global $_GET, $_POST, $db, $lang, $userdata;
	$file = is_scalar($file) ? basename((string) $file) : '';
	
	/* Debugging in this function causes changes to the way ADMIN users
	are interpreted.  You are warned */
	$debug = false;
	
	$jr_admin_userdata = jr_admin_get_user_info($userdata['user_id']);
	
	if ($debug)
	{
		if (!preg_match("/^index.$phpEx/", $file))
		{
			print '<pre><span class="gen"><font color="red">DEBUG - File Accessed - ';
			print $file;
			print '</pre></font></span><br>';
		}
	}
	if ($userdata['user_level'] == ADMIN && !$debug)
	{
		//Admin always has access
		return true;
	}
	elseif (empty($jr_admin_userdata['user_jr_admin']))
	{
		//This user has no modules and no business being here
		return false;
	}
	elseif (preg_match("/^index.$phpEx/", $file))
	{
		//We are at the index file, which is already secure pretty much
		return true;
	}
	elseif (isset($_GET['module']) && is_scalar($_GET['module']) && preg_match('/^[a-f0-9]{32}$/D', (string) $_GET['module']) && in_array((string) $_GET['module'], explode(EXPLODE_SEPERATOR_CHAR, $jr_admin_userdata['user_jr_admin']), true))
	{
		//The user has access for sure by module_id security from GET vars only
		return true;
	}
	elseif (!isset($_GET['module']) && count($_POST))
	{
		//This user likely entered a post form, so let's use some checking logic
		//to make sure they are doing it from where they should be!
		
		//Get the filename without any arguments
		$file = preg_replace("/\?.+=.*$/", '', $file);
		//Return the check to make sure the user has access to what they are submitting
		return jr_admin_check_file_hashes($file);
	}
	elseif (!isset($_GET['module']) && isset($_GET['sid']))
	{
		//This user has clicked on a url that specified items
		if (!is_scalar($_GET['sid']) || !hash_equals((string) $userdata['session_id'], (string) $_GET['sid']))
		{
			return false;
		}
		else
		{
			//Get the filename without any arguments
			$file = preg_replace("/\?.+=.*$/", '', $file);
			//Return the check to make sure the user has access to what they are submitting
			return jr_admin_check_file_hashes($file);
		}
	}
	else
	{
		//Something came up that shouldn't have!
		return false;
	}
}

function jr_admin_include_all_lang_files()
{
	global $lang, $phpbb_root_path, $board_config, $phpEx;

	$language = isset($board_config['default_lang']) && preg_match('/^[a-z0-9_-]+$/iD', (string) $board_config['default_lang'])
		? (string) $board_config['default_lang']
		: 'english';
	$language_dir = $phpbb_root_path . 'language/lang_' . $language;
	if (!is_dir($language_dir))
	{
		$language_dir = $phpbb_root_path . 'language/lang_english';
	}
	$dir = @opendir($language_dir);
	if ($dir === false)
	{
		return;
	}
	$pattern = "/^lang.+\.$phpEx$/";
	while (($file = @readdir($dir)) !== false)
	{
		if (preg_match($pattern, $file))
		{
			include_once($language_dir . '/' . $file);
		}
	}
	@closedir($dir);	
}

function jr_admin_make_left_pane()
{
	global $template, $lang, $module, $phpEx, $userdata;
	
	jr_admin_include_all_lang_files();
	
	//Loop through and set up all the nice form names, etc
		//+MOD: DHTML Menu for ACP 
   $menu_cat_id = 0; 
//-MOD: DHTML Menu for ACP
	foreach ($module as $cat => $module_array)
	{
		$template->assign_block_vars("catrow", array(
		//+MOD: DHTML Menu for ACP 
         'MENU_CAT_ID' => $menu_cat_id, 
		 'MENU_CAT_ROWS' => count($module_array), 
//-MOD: DHTML Menu for ACP
		'ADMIN_CATEGORY' => (isset($lang[$cat])) ? $lang[$cat] : preg_replace("/_/", ' ', $cat)
		));
		@ksort($module_array);
		$i = 0;
		foreach ($module_array as $module_name => $data_array)
		{
			$navigation_name = isset($data_array['navigation_name']) ? $data_array['navigation_name'] : $module_name;
			//Compile our module url with lots of options
			$module_url = $data_array['filename'];
			$module_url .= (preg_match("/^.*\.$phpEx\?/", $module_url)) ? '&amp;' : '?';
			$module_url .= "sid=".$userdata['session_id']."&amp;module=".$data_array['file_hash'];
			
			$template->assign_block_vars("catrow.modulerow", array(
			'ROW_CLASS' => (++$i % 2) ? 'row1' : 'row2',
			//+MOD: DHTML Menu for ACP 
            'ROW_COUNT' => $i, 
//-MOD: DHTML Menu for ACP
			'ADMIN_MODULE' => jr_admin_navigation_label($navigation_name),
			'U_ADMIN_MODULE' => $module_url
			));
		}
		//+MOD: DHTML Menu for ACP 
      $menu_cat_id++; 
//-MOD: DHTML Menu for ACP 
	}
}

function jr_admin_make_info_box()
{
	global $template, $lang, $module, $userdata, $board_config;
	
	/* Debug?  Changes the status stnading of ADMIN!!!  You are warned */
	$debug = false;
	
	if ($userdata['user_level'] != ADMIN || $debug)
	{
		find_lang_file_nivisec('lang_jr_admin');
		
		$jr_admin_userdata = jr_admin_get_user_info($userdata['user_id']);
		
		$template->set_filenames(array('JR_ADMIN_INFO' => 'admin/jr_admin_user_info_header.tpl'));
		
		$template->assign_vars(array(
		'JR_ADMIN_START_DATE' => create_date($board_config['default_dateformat'], $jr_admin_userdata['start_date'], $board_config['board_timezone']),
		'JR_ADMIN_UPDATE_DATE' => create_date($board_config['default_dateformat'], $jr_admin_userdata['update_date'], $board_config['board_timezone']),
		'JR_ADMIN_ADMIN_NOTES' => phpbb_profile_text(isset($jr_admin_userdata['admin_notes']) ? $jr_admin_userdata['admin_notes'] : ''),
		'L_VERSION' => $lang['Version'],
		'L_JR_ADMIN_TITLE' => $lang['Junior_Admin_Info'],
		'VERSION' => MOD_VERSION,
		'L_MODULE_COUNT' => $lang['Module_Count'],
		'L_NOTES' => $lang['Notes'],
		'L_ALLOW_VIEW' => $lang['Allow_View'],
		'L_START_DATE' => $lang['Start_Date'],
		'L_UPDATE_DATE' => $lang['Update_Date'],
		'L_ADMIN_NOTES' => $lang['Admin_Notes']
		));
		
		//Switch the info area if allowed to view it
		if ($jr_admin_userdata['notes_view'])
		{
			$template->assign_block_vars('jr_admin_info_switch', array());
		}
		
		$template->assign_var_from_handle('JR_ADMIN_INFO_TABLE', 'JR_ADMIN_INFO');
	}
}

function jr_admin_get_user_info($user_id)
{
	global $lang;
	$user_id = max(0, intval($user_id));
	//Do the query and get the results, return the user row as well.
	return (
	sql_query_nivisec(
	'SELECT * FROM ' . JR_ADMIN_TABLE . "
	WHERE user_id = $user_id",
	
	sprintf(isset($lang['Error_Table']) ? $lang['Error_Table'] : 'Could not query table %s', JR_ADMIN_TABLE),
	false,
	1
	)
	);
}

function jr_admin_make_admin_link()
{
	global $lang, $userdata, $phpEx;
	
	if ($userdata['user_level'] == ADMIN) return '<a href="admin/index.' . $phpEx . '?sid=' . $userdata['session_id'] . '">' . $lang['Admin_panel'] . '</a><br /><br />';
	
	$jr_admin_userdata = jr_admin_get_user_info($userdata['user_id']);
	
	if (!empty($jr_admin_userdata['user_jr_admin']))
	{
		return '<a href="admin/index.' . $phpEx . '?sid=' . $userdata['session_id'] . '">' . $lang['Admin_panel'] . '</a><br /><br />';
	}
	else
	{
		return '';
	}
}
?>
