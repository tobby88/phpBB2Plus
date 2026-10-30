<?php
/***************************************************************************
 *                                functions_pafiledb.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: index.php,v 1.99.2.1 2002/12/19 17:17:40 psotfx Exp $
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

//===================================================
// public pafiledb class
//=================================================== 

class pafiledb_public extends pafiledb
{
	var $modules = array();
	var $module_name = '';

	//===================================================
	// load module
	// $module name : send module name to load it
	//=================================================== 
	
	function module($module_name)
	{
		$class_name = 'pafiledb_' . $module_name;
		if (!class_exists($class_name))
		{
			global $phpbb_root_path, $phpEx;
			
			$this->module_name = $module_name;
			
			require_once($phpbb_root_path . 'pafiledb/modules/pa_' . $module_name . '.'.$phpEx);
		}

		if (!isset($this->modules[$module_name]))
		{
			$this->module_name = $module_name;
			$this->modules[$module_name] = new $class_name();

			if (method_exists($this->modules[$module_name], 'init'))
			{
				$this->modules[$module_name]->init();
			}
		}
	}
	
	//===================================================
	// this will be replaced by the loaded module
	//=================================================== 

	function main($module_id = false)
	{
		return false;
	}
	
	//===================================================
	// go ahead and output the page
	// $page title : send page title
	// $tpl_name : template file name
	//=================================================== 

	function display($page_title, $tpl_name)
	{
		global $pafiledb_template;
		
		pafiledb_page_header($page_title);
		
		$pafiledb_template->set_filenames(array(
			'body' => $tpl_name)
		);

		pafiledb_page_footer();
	}
}

//===================================================
// pafiledb class
//=================================================== 

class pafiledb
{
	var $cat_rowset = array();
	var $subcat_rowset = array();

	var $modified = false;

	var $auth = array();
	var $auth_global = array();
	
	var $total_cat = 0;
//	var $depth_info = array();
	
	var $error = array();
	private $category_file_summaries = array();
	
	//===================================================
	// Prepare data
	//=================================================== 
	
		
	function init()
	{
		global $db, $userdata, $debug;
		$this->category_file_summaries = array();
		
		$this->cat_rowset = array();
		$this->subcat_rowset = array();
		$this->total_cat = 0;

		$sql = 'SELECT * 
			FROM ' . PA_CATEGORY_TABLE . '
			ORDER BY cat_order ASC';
	
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Couldnt Query categories info', '', __LINE__, __FILE__, $sql);
		}
		$cat_rowset = $db->sql_fetchrowset($result);

		$db->sql_freeresult($result);
		
		// Both historical NULL and zero identify a root. Keep their database
		// values intact while using one root key for navigation and grouping.
		foreach ($cat_rowset as &$category)
		{
			if ($category['cat_parent'] === null) { $category['cat_parent'] = 0; }
		}
		unset($category);
		$this->auth($cat_rowset);

		for( $i = 0; $i < count($cat_rowset); $i++ )
		{
			if($this->auth[$cat_rowset[$i]['cat_id']]['auth_view'])
			{
				$this->cat_rowset[$cat_rowset[$i]['cat_id']] = $cat_rowset[$i];
				$this->subcat_rowset[$cat_rowset[$i]['cat_parent']][$cat_rowset[$i]['cat_id']] = $cat_rowset[$i];
				$this->total_cat++;
			}
		}
	}
	
	//===================================================
	// Jump menu function
	// $cat_id : to handle parent cat_id
	// $depth : related to function to generate tree
	// $default : the cat you wanted to be selected
	// $for_file: TRUE high category ids will be -1
	// $check_upload: if true permission for upload will be checked
	//=================================================== 
	
	function jumpmenu_option($cat_id = 0, $depth = 0, $default = '', $for_file = false, $check_upload = false)
	{
		static $cat_rowset = false;

		if(!is_array($cat_rowset))
		{
			if($check_upload)
			{
				if(!empty($this->cat_rowset))
				{
					foreach($this->cat_rowset as $row)
					{
						if($this->auth[$row['cat_id']]['auth_upload'])
						{
							$cat_rowset[$row['cat_id']] = $row;
						}				
					}
				}
			}
			else
			{
				$cat_rowset = $this->cat_rowset;
			}
		}

		$cat_list = '';

		$pre = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;', $depth);

		$temp_cat_rowset = $cat_rowset;

		if(!empty($temp_cat_rowset))
		{
			foreach ($temp_cat_rowset as $temp_cat_id => $cat)
			{
				if ($cat['cat_parent'] == $cat_id)
				{
					$sel = '';
					if (is_array($default))
					{
						if (isset($default[$cat['cat_id']]))
						{
							$sel = ' selected="selected"';
						}
						else
						{
							$sel = '';
						}
					}

					$cat_pre = (!$cat['cat_allow_file']) ? '+ ' : '- ';
					$sub_cat_id = ($for_file) ? ( (!$cat['cat_allow_file']) ? -1 : $cat['cat_id'] ) : $cat['cat_id'];
					$cat_class = (!$cat['cat_allow_file']) ? 'class="greyed"' : '';
					$cat_list .= '<option value="' . $sub_cat_id . '"' . $sel . ' ' . $cat_class . ' />' . $pre . $cat_pre . $cat['cat_name'] . '</option>';
                    $cat_list .= $this->jumpmenu_option($cat['cat_id'], $depth + 1, $default, $for_file, $check_upload);
				}
			}
			return $cat_list;
		}
		else
		{
			return;
		}
	}

	//===================================================
	// if there is no cat
	//===================================================
	
	function cat_empty()
	{
		return ($this->total_cat == 0) ? TRUE : FALSE;
	}
	
	
	function modified($true_false = false)
	{
		$this->modified = $true_false;
		if ($true_false) { $this->category_file_summaries = array(); }
	}
	
	//===================================================
	// get all sub category in side certain category
	// $cat_id : category id
	//=================================================== 

	function get_sub_cat($cat_id)
	{
		$cat_sub = '';
		if(!empty($this->subcat_rowset[$cat_id]))
		{
			foreach($this->subcat_rowset[$cat_id] as $cat_id => $cat_row)
			{
				if($cat_row['cat_allow_file'])
				{
					$cat_sub .= '<a href="' . append_sid('dload.php?action=category&cat_id=' . $cat_row['cat_id']) . '">' . $cat_row['cat_name'] . '</a>, ';
				}
				else
				{
					if(!empty($this->subcat_rowset[$cat_row['cat_id']]))
					{
						foreach($this->subcat_rowset[$cat_row['cat_id']] as $sub_cat_id => $sub_cat_row)
						{
							if($sub_cat_row['cat_allow_file'])
							{
								$cat_sub .= '<a href="' . append_sid('dload.php?action=category&cat_id=' . $sub_cat_row['cat_id']) . '">' . $sub_cat_row['cat_name'] . '</a>, ';
							}
						}
					}
				}			
			}
		}
		return $cat_sub;
	}
	
	private function category_file_summary($cat_id)
	{
		global $db,$pafiledb_config;
		require_once dirname(__FILE__) . '/functions_category_read.php';
		$scope=pafiledb_category_file_scope($this,$cat_id);$empty=array('total'=>0,'new'=>0,'last'=>array());
		if(!$scope){return $empty;}
		$days=isset($pafiledb_config['settings_newdays'])?$pafiledb_config['settings_newdays']:'0';
		if(!(is_int($days)||is_string($days))||!preg_match('/^[0-9]{1,10}$/D',(string)$days)||(float)$days>2147483647){$days='0';}
		$key=(int)$cat_id.':'.(string)$days;
		if(isset($this->category_file_summaries[$key])){return $this->category_file_summaries[$key];}
		$cutoff=sprintf('%.0f',time()-(float)$days*86400);$sql=pafiledb_category_file_summary_sql($scope,$cutoff);
		if(!($result=$db->sql_query($sql))){message_die(GENERAL_ERROR,'Could not read download category files','',__LINE__,__FILE__,$sql);}
		try{$row=$db->sql_fetchrow($result);}finally{$db->sql_freeresult($result);}
		if(!$row){message_die(GENERAL_ERROR,'Could not read download category files');}
		$summary=array('total'=>(int)$row['total_files'],'new'=>(int)$row['new_files'],'last'=>array());
		if($row['file_id']!==null){foreach(array('file_id','file_name','file_time','file_catid')as$field){$summary['last'][$field]=$row[$field];}}
		$this->category_file_summaries[$key]=$summary;return $summary;
	}

	function last_file_in_cat($cat_id, &$file_info)
	{
		$summary=$this->category_file_summary($cat_id);$file_info=$summary['last'];
	}
	
	function generate_category_nav($cat_id)
	{
		global $pafiledb_template;
		$cat_id = is_scalar($cat_id) ? (int) $cat_id : 0;
		if ($cat_id <= 0 || !isset($this->cat_rowset[$cat_id]) || empty($this->auth[$cat_id]['auth_view']))
		{
			return;
		}
		// Cached paths may retain renamed or no-longer-visible ancestors. Build
		// this viewer's path from current categories without writing on a GET.
		$cat_nav = array();
		$this->category_nav($this->cat_rowset[$cat_id]['cat_parent'], $cat_nav);
		
		if(!empty($cat_nav))
		{
			foreach ($cat_nav as $parent_cat_id => $parent_name)
			{
				$pafiledb_template->assign_block_vars('navlinks', array(
					'CAT_NAME'	=>	pafiledb_html($parent_name),
					'U_VIEW_CAT'	=>	append_sid('dload.php?action=category&cat_id=' . $parent_cat_id))
				);
			}
		}

		$pafiledb_template->assign_block_vars('navlinks', array(
			'CAT_NAME'	=>	pafiledb_html($this->cat_rowset[$cat_id]['cat_name']),
			'U_VIEW_CAT'	=>	append_sid('dload.php?action=category&cat_id=' . $this->cat_rowset[$cat_id]['cat_id']))
		);
		
		return;
	}
	
	function category_nav($parent_id, &$cat_nav)
	{
		$path = $seen = array();
		$parent_id = is_scalar($parent_id) ? (int) $parent_id : 0;
		while ($parent_id > 0)
		{
			if (isset($seen[$parent_id]))
			{
				// An inconsistent hierarchy must not recurse or invent a path.
				return;
			}
			if (!isset($this->cat_rowset[$parent_id]) || empty($this->auth[$parent_id]['auth_view']))
			{
				break;
			}
			$seen[$parent_id] = true;
			$path[$parent_id] = $this->cat_rowset[$parent_id]['cat_name'];
			$parent_id = (int) $this->cat_rowset[$parent_id]['cat_parent'];
		}
		foreach (array_reverse($path, true) as $id => $name)
		{
			$cat_nav[$id] = $name;
		}
	}
	
/*	function init_depth($cat_id = 0, $depth = 0)
	{
		$temp = $depth;
		if(isset($this->subcat_rowset[$cat_id]))
		{
			foreach($this->subcat_rowset[$cat_id] as $temp_cat_id => $void)
			{
				$this->init_depth($temp_cat_id, $depth + 1);
				$this->depth_info[$void['cat_parent']][$temp_cat_id] = $temp;
				$depth = 0;
			}
		}
	}
	
	function get_max_depth($cat_parent, &$max_depth)
	{
		if(empty($this->depth_info))
		{
			$this->init_depth();
		}
		
		if(isset($this->depth_info[$cat_parent]))
		{
			foreach($this->depth_info[$cat_parent] as $cat_id => $depth)
			{
				if($max_depth < $depth)
				{
					$max_depth = $depth;
				}
				$this->get_max_depth($cat_id, $max_depth);
			}
			
		}
	}*/
	
	function file_in_cat($cat_id)
	{
		$summary=$this->category_file_summary($cat_id);return $summary['total'];
	}

	function new_file_in_cat($cat_id)
	{
		$summary=$this->category_file_summary($cat_id);return $summary['new'];
	}

	function gen_cat_ids($cat_id, $cat_ids = '')
	{
		require_once dirname(__FILE__) . '/functions_category_read.php';
		// Keep the historical string API, but never include inaccessible descendants.
		$scope=pafiledb_category_file_scope($this,$cat_id);
		if ($cat_ids!=='') {
			if (!is_string($cat_ids)||!preg_match('/^[1-9][0-9]*(?:,\s*[1-9][0-9]*)*$/D',$cat_ids)) { return ''; }
			foreach (explode(',',$cat_ids) as $id) { $id=trim($id);if((float)$id>2147483647||!pafiledb_file_category_accessible($this,$id)||(string)$this->cat_rowset[(int)$id]['cat_allow_file']!==(string)PA_CAT_ALLOW_FILE){return '';}$scope[]=(int)$id; }
		}
		$scope=array_unique($scope);sort($scope,SORT_NUMERIC);return implode(', ',$scope);
	}
	
	function auth($c_access)
	{
		global $db, $db, $lang, $userdata, $pafiledb_config;
		$this->auth = array();
		$this->auth_global = array();
// Mx Addon
		$a_sql = 'a.auth_view, a.auth_read, a.auth_view_file, a.auth_edit_file, a.auth_delete_file, a.auth_upload, a.auth_download, a.auth_rate, a.auth_email, a.auth_view_comment, a.auth_post_comment, a.auth_edit_comment, a.auth_delete_comment, a.auth_mod, a.auth_search, a.auth_stats, a.auth_toplist, a.auth_viewall';
		$auth_fields = array('auth_view', 'auth_read', 'auth_view_file', 'auth_edit_file', 'auth_delete_file', 'auth_upload', 'auth_download', 'auth_rate', 'auth_email', 'auth_view_comment', 'auth_post_comment', 'auth_edit_comment', 'auth_delete_comment');
		$auth_fields_global = array('auth_search', 'auth_stats', 'auth_toplist', 'auth_viewall');

		//
		// If the user isn't logged on then all we need do is check if the forum
		// has the type set to ALL, if yes they are good to go, if not then they
		// are denied access
		//
		$u_access = array();
		$global_u_access = array();
		if ( $userdata['session_logged_in'] )
		{
			$sql = "SELECT a.cat_id, a.group_id, $a_sql 
				FROM " . PA_AUTH_ACCESS_TABLE . " a
				INNER JOIN " . USER_GROUP_TABLE . " ug ON ug.group_id = a.group_id
				INNER JOIN " . GROUPS_TABLE . " g ON g.group_id = ug.group_id
				WHERE ug.user_id = {$userdata['user_id']}
					AND ug.user_pending = 0";
			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Failed obtaining category access control lists', '', __LINE__, __FILE__, $sql);
			}

			if ( $row = $db->sql_fetchrow($result) )
			{
				do
				{
					if($row['cat_id'])
					{
						$u_access[$row['cat_id']][] = $row;
					}
					else
					{
						$global_u_access[] = $row;
					}
				}
				while( $row = $db->sql_fetchrow($result) );
			}
		}

		$is_admin = ( $userdata['user_level'] == ADMIN && $userdata['session_logged_in'] ) ? TRUE : 0;

		for($i = 0; $i < count($auth_fields); $i++)
		{
			$key = $auth_fields[$i];

			//
			// If the user is logged on and the forum type is either ALL or REG then the user has access
			//
			// If the type if ACL, MOD or ADMIN then we need to see if the user has specific permissions
			// to do whatever it is they want to do ... to do this we pull relevant information for the
			// user (and any groups they belong to)
			//
			// Now we compare the users access level against the forums. We assume here that a moderator
			// and admin automatically have access to an ACL forum, similarly we assume admins meet an
			// auth requirement of MOD
			//
			for($k = 0; $k < count($c_access); $k++)
			{
				$value = $c_access[$k][$key];
				$c_cat_id = $c_access[$k]['cat_id'];
				global $lang;

				switch( $value )
				{
					case AUTH_ALL:
						$this->auth[$c_cat_id][$key] = TRUE;
						$this->auth[$c_cat_id][$key . '_type'] = $lang['Auth_Anonymous_Users'];
						break;

					case AUTH_REG:
						$this->auth[$c_cat_id][$key] = ( $userdata['session_logged_in'] ) ? TRUE : 0;
						$this->auth[$c_cat_id][$key . '_type'] = $lang['Auth_Registered_Users'];
						break;

					case AUTH_ACL:
					$this->auth[$c_cat_id][$key] = ( $userdata['session_logged_in'] ) ? $this->auth_check_user(AUTH_ACL, $key, isset($u_access[$c_cat_id]) ? $u_access[$c_cat_id] : array(), $is_admin) : 0;
						$this->auth[$c_cat_id][$key . '_type'] = $lang['Auth_Users_granted_access'];
						break;

					case AUTH_MOD:
					$this->auth[$c_cat_id][$key] = ( $userdata['session_logged_in'] ) ? $this->auth_check_user(AUTH_MOD, 'auth_mod', isset($u_access[$c_cat_id]) ? $u_access[$c_cat_id] : array(), $is_admin) : 0;
						$this->auth[$c_cat_id][$key . '_type'] = $lang['Auth_Moderators'];
						break;

					case AUTH_ADMIN:
						$this->auth[$c_cat_id][$key] = $is_admin;
						$this->auth[$c_cat_id][$key . '_type'] = $lang['Auth_Administrators'];
						break;

					default:
						$this->auth[$c_cat_id][$key] = 0;
						break;
				}
			}
		}
		for($k = 0; $k < count($c_access); $k++)
		{
			$c_cat_id = $c_access[$k]['cat_id'];		
			$this->auth[$c_cat_id]['auth_mod'] = ( $userdata['session_logged_in'] ) ? $this->auth_check_user(AUTH_MOD, 'auth_mod', isset($u_access[$c_cat_id]) ? $u_access[$c_cat_id] : array(), $is_admin) : 0;
		}

		for($i = 0; $i < count($auth_fields_global); $i++)
		{
			$key = $auth_fields_global[$i];
			$value = $pafiledb_config[$auth_fields_global[$i]];
			global $lang;
			
			switch($value)
			{
				case AUTH_ALL:
					$this->auth_global[$key] = TRUE;
					$this->auth_global[$key . '_type'] = $lang['Auth_Anonymous_Users'];
					break;

				case AUTH_REG:
					$this->auth_global[$key] = ( $userdata['session_logged_in'] ) ? TRUE : 0;
					$this->auth_global[$key . '_type'] = $lang['Auth_Registered_Users'];
					break;

				case AUTH_ACL:
					$this->auth_global[$key] = ( $userdata['session_logged_in'] ) ? $this->global_auth_check_user(AUTH_ACL, $key, $global_u_access, $is_admin) : 0;
					$this->auth_global[$key . '_type'] = $lang['Auth_Users_granted_access'];
					break;

				case AUTH_MOD:
					$this->auth_global[$key] = ( $userdata['session_logged_in'] ) ? $this->global_auth_check_user(AUTH_MOD, 'auth_mod', $global_u_access, $is_admin) : 0;
					$this->auth_global[$key . '_type'] = $lang['Auth_Moderators'];
					break;

				case AUTH_ADMIN:
					$this->auth_global[$key] = $is_admin;
					$this->auth_global[$key . '_type'] = $lang['Auth_Administrators'];
					break;

				default:
					$this->auth_global[$key] = 0;
					break;
			}
		}
	}
	
	function auth_check_user($type, $key, $u_access, $is_admin)
	{
		$auth_user = 0;

		$u_access = is_array($u_access) ? $u_access : array();
		if ( count($u_access) )
		{
			for($j = 0; $j < count($u_access); $j++)
			{
				$result = 0;
				switch($type)
				{
					case AUTH_ACL:
						$result = $u_access[$j][$key];

					case AUTH_MOD:
						$result = $result || $u_access[$j]['auth_mod'];

					case AUTH_ADMIN:
						$result = $result || $is_admin;
						break;
				}

				$auth_user = $auth_user || $result;
			}
		}
		else
		{
			$auth_user = $is_admin;
		}

		return $auth_user;
	}

	function global_auth_check_user($type, $key, $global_u_access, $is_admin)
	{
		if (!in_array((int)$type, array(AUTH_ACL, AUTH_MOD, AUTH_ADMIN), true)) { return false; }
		if ($is_admin) { return true; }
		if ((int)$type === AUTH_ADMIN) { return false; }
		if ($this->is_moderator()) { return true; }
		if ((int)$type === AUTH_MOD) { return false; }
		// Retain compatibility for callers supplying one old flat grant row.
		$grants = is_array($global_u_access) ? $global_u_access : array();
		if (array_key_exists($key, $grants)) { $grants = array($grants); }
		foreach ($grants as $grant)
		{
			if (is_array($grant) && !empty($grant[$key])) { return true; }
		}
		return false;
	}
	
	function is_moderator()
	{
		if(!empty($this->auth))
		{
			foreach($this->auth as $cat_id => $auth_fields)
			{
				if(!empty($auth_fields['auth_mod']))
				{
					return true;
				}
			}
			return false;
		}
		return false;
	}

	function category_display($cat_id = PA_ROOT_CAT)
	{
		global $db, $pafiledb_template, $lang, $userdata, $phpEx, $images;
		global $pafiledb_config, $board_config, $debug, $phpbb_root_path;
		
		if($this->cat_empty())
		{
			if ( !$userdata['session_logged_in'] )
			{
				$redirect = ($cat_id != PA_ROOT_CAT) ? "dload.$phpEx?action=category&cat_id=$cat_id" : "dload.$phpEx";
				redirect(append_sid("login.$phpEx?redirect=$redirect", true));
			}
			message_die(GENERAL_ERROR, 'Either you are not allowed to view any category, or there is no category in the database');
		}

		$pafiledb_template->assign_vars(array(
			'CAT_PARENT' => TRUE,
			'L_SUB_CAT' => $lang['Sub_category'],
			'L_CATEGORY' => $lang['Category'],
			'L_LAST_FILE' => $lang['Last_file'],
			'L_FILES' => $lang['Files'])
		);

		//output the root level category that allow file
		if(isset($this->subcat_rowset[$cat_id]))
		{
			foreach($this->subcat_rowset[$cat_id] as $subcat_id => $subcat_row)
			{
				if( ($subcat_row['cat_allow_file'] == PA_CAT_ALLOW_FILE) )
				{
					$last_file_info = array();
					$this->last_file_in_cat($subcat_id, $last_file_info);

					if(!empty($last_file_info['file_id']) && $this->auth[$subcat_id]['auth_read'])
					{
						$last_file_time = create_date($board_config['default_dateformat'], $last_file_info['file_time'], $board_config['board_timezone']);
						$last_file = $last_file_time . '<br />';
						$file_label = pafiledb_category_file_label($last_file_info['file_name']);
						$last_file_name = $file_label['short'];
						$last_file .= '<a href="' . append_sid('dload.php?action=file&file_id=' . $last_file_info['file_id']) . '" title="' . $file_label['title'] . '">' . $last_file_name . '</a> ';
						$last_file .= '<a href="' . append_sid('dload.php?action=file&file_id=' . $last_file_info['file_id']) . '"><img src="' . $images['icon_latest_reply'] . '" border="0" alt="' . $lang['View_latest_file'] . '" title="' . $lang['View_latest_file'] . '" /></a>';
					}
					else
					{
						$last_file = $lang['No_file'];
					}
					$is_new = FALSE;
					
					if($this->new_file_in_cat($subcat_id))
					{
						$is_new = TRUE;
					}

					$sub_cat = $this->get_sub_cat($subcat_id);

					$pafiledb_template->assign_block_vars('no_cat_parent', array(
						'IS_HIGHER_CAT' => FALSE,
						'U_CAT' => append_sid('dload.php?action=category&cat_id=' . $subcat_id),
						'SUB_CAT' => ( !empty($sub_cat) ) ? $sub_cat : $lang['None'],
						'CAT_IMAGE' => ($is_new) ? $images['folder_new'] : $images['folder'],
						'CAT_NEW_FILE' => ($is_new) ? $lang['New_file'] : $lang['No_new_file'],
						'CAT_NAME' => pafiledb_html($subcat_row['cat_name']),
						'FILECAT' => $this->file_in_cat($subcat_id),
						'LAST_FILE' => $last_file,
						'CAT_DESC' => pafiledb_html($subcat_row['cat_desc']))
					);
				}
			}
		}
		if(isset($this->subcat_rowset[$cat_id]))
		{
			foreach($this->subcat_rowset[$cat_id] as $subcat_id => $subcat_row)
			{
				$total_sub_cat = 0;
				if(isset($this->subcat_rowset[$subcat_id]))
				{
					foreach($this->subcat_rowset[$subcat_id] as $sub_no_cat_id => $sub_no_cat_row)
					{
						if($sub_no_cat_row['cat_allow_file'] == PA_CAT_ALLOW_FILE)
						{				
							$sub_cat_rowset[$total_sub_cat] = $sub_no_cat_row;
							$total_sub_cat++; 
						}
					}
				}
		
				if(($subcat_row['cat_allow_file'] != PA_CAT_ALLOW_FILE))
				{	
					if($total_sub_cat)
					{
						$pafiledb_template->assign_block_vars('no_cat_parent', array(
							'IS_HIGHER_CAT' => TRUE,
							'U_CAT' => append_sid('dload.php?action=category&cat_id=' . $subcat_id),
							'CAT_NAME' => pafiledb_html($subcat_row['cat_name']))
						);
					}
					for($k = 0; $k < $total_sub_cat; $k++)
					{
						$last_file_info = array();
						$this->last_file_in_cat($sub_cat_rowset[$k]['cat_id'], $last_file_info);

						if ($sub_cat_rowset[$k]['cat_parent'] == $subcat_id)
						{
							if(!empty($last_file_info['file_id']) && $this->auth[$sub_cat_rowset[$k]['cat_id']]['auth_read'])
							{
								$last_file_time = create_date($board_config['default_dateformat'], $last_file_info['file_time'], $board_config['board_timezone']);
								$last_file = $last_file_time . '<br />';
								$file_label = pafiledb_category_file_label($last_file_info['file_name']);
								$last_file_name = $file_label['short'];
								$last_file .= '<a href="' . append_sid('dload.php?action=file&file_id=' . $last_file_info['file_id']) . '">' . $last_file_name . '</a> ';
								$last_file .= '<a href="' . append_sid('dload.php?action=file&file_id=' . $last_file_info['file_id']) . '"><img src="' . $images['icon_latest_reply'] . '" border="0" alt="' . $lang['View_latest_file'] . '" title="' . $lang['View_latest_file'] . '" /></a>';
							}
							else
							{
								$last_file = $lang['No_file'];
							}

							$is_new = FALSE;

							if($this->new_file_in_cat($sub_cat_rowset[$k]['cat_id']))
							{
								$is_new = TRUE;
							}

							$sub_cat = $this->get_sub_cat($sub_cat_rowset[$k]['cat_id']);

							$pafiledb_template->assign_block_vars('no_cat_parent', array(
								'IS_HIGER_CAT' => FALSE,
								'U_CAT' => append_sid('dload.php?action=category&cat_id=' . $sub_cat_rowset[$k]['cat_id']),
								'SUB_CAT' => ( !empty($sub_cat) ) ? $sub_cat : $lang['None'],
								'CAT_IMAGE' => ($is_new) ? $images['folder_new'] : $images['folder'],
								'CAT_NEW_FILE' => ($is_new) ? $lang['New_file'] : $lang['No_new_file'],
								'CAT_NAME' => pafiledb_html($sub_cat_rowset[$k]['cat_name']),
								'FILECAT' => $this->file_in_cat($sub_cat_rowset[$k]['cat_id']),
								'LAST_FILE' => $last_file,
								'CAT_DESC' => pafiledb_html($sub_cat_rowset[$k]['cat_desc']))
							);
						} // Have a permission to view the category
					} // It is not parent category
				}
			}
		} //higher Category
	}
	
	function display_files($sort_method, $sort_order, $start, $show_file_message, $cat_id = false)
	{
		global $db, $pafiledb_config, $pafiledb_template, $board_config;
		global $images, $lang, $phpEx, $phpbb_root_path;

		$filelist = false;		

		require_once dirname(__FILE__) . '/functions_listing.php';
		try
		{
			$listing = pafiledb_listing_read($db, pafiledb_toplist_categories($this), $cat_id, $sort_method, $sort_order, $start, $pafiledb_config['settings_file_page']);
		}
		catch (Exception $error)
		{
			message_die(GENERAL_ERROR, $lang['PA_list_unavailable']);
			return;
		}
		$sort_method = $listing['options']['sort'];
		$sort_order = $listing['options']['direction'];
		$start = $listing['options']['start'];
		$per_page = $listing['options']['per_page'];
		$cat_id = $listing['category'];
		$total_file = $listing['total'];
		$file_rowset = array_merge($listing['pinned'], $listing['files']);

		for ($i = 0; $i < count($file_rowset); $i++) 
		{
			//===================================================
			// Format the date for the given file
			//===================================================

			$date = create_date($board_config['default_dateformat'], $file_rowset[$i]['file_time'], $board_config['board_timezone']);
		
			//===================================================
			// Get rating for the file and format it
			//===================================================

			$rating = ($file_rowset[$i]['rating'] != 0) ? round($file_rowset[$i]['rating'], 2) . ' / 10' : $lang['Not_rated'];

			//===================================================
			// If the file is new then put a new image in front of it
			//===================================================
		
			$is_new = FALSE;
			if (time() - ($pafiledb_config['settings_newdays'] * 24 * 60 * 60) < $file_rowset[$i]['file_time'])
			{
				$is_new = TRUE;
			}
			
			$cat_name = (empty($cat_id)) ? $this->cat_rowset[$file_rowset[$i]['file_catid']]['cat_name'] : '';
			$cat_url = append_sid('dload.'.$phpEx.'?action=category&cat_id=' . $file_rowset[$i]['file_catid']);
		
			//===================================================
			// Get the post icon fot this file
			//===================================================
			if($file_rowset[$i]['file_pin'] != FILE_PINNED)
			{
				if ($file_rowset[$i]['file_posticon'] == 'none' || $file_rowset[$i]['file_posticon'] == 'none.gif') 
				{
					$posticon = $phpbb_root_path . 'pafiledb/images/spacer.gif';
				} 
				else 
				{
					$posticon = $phpbb_root_path . ICONS_DIR . $file_rowset[$i]['file_posticon'];
				}
			}
			else
			{
				$posticon = $phpbb_root_path . $images['folder_sticky'];
			}

			//===================================================
			// Assign Vars
			//===================================================

			$safe_screenshot = pafiledb_normalize_remote_url($file_rowset[$i]['file_ssurl'], true);
			$pafiledb_template->assign_block_vars("file_rows", array(
				'L_NEW_FILE' => $lang['New_file'],

				'PIN_IMAGE' => htmlspecialchars($posticon, ENT_QUOTES, 'UTF-8'),
				'FILE_NEW_IMAGE' => $phpbb_root_path . $images['pa_file_new'],
				'HAS_SCREENSHOTS' => ($safe_screenshot !== false),
				'SS_AS_LINK' => ($file_rowset[$i]['file_sshot_link']) ? TRUE : FALSE,
				'FILE_SCREENSHOT' => ($safe_screenshot !== false) ? htmlspecialchars($safe_screenshot, ENT_QUOTES, 'UTF-8') : '',
				'FILE_SCREENSHOT_URL' => $phpbb_root_path . 'pafiledb/images/lwin.gif',
				'FILE_NAME' => pafiledb_html($file_rowset[$i]['file_name']),
				'FILE_DESC' => pafiledb_html($file_rowset[$i]['file_desc']),
				'DATE' => $date,
				'RATING' => $rating,
				'FILE_DLS' => $file_rowset[$i]['file_dls'],
				'CAT_NAME' => pafiledb_html($cat_name),
				'IS_NEW_FILE' => $is_new,

				'U_CAT' => $cat_url,
				'U_FILE' => append_sid('dload.'.$phpEx.'?action=file&file_id=' . $file_rowset[$i]['file_id']))
			);
			$filelist = TRUE;
		}
		
		if ($filelist) 
		{
			$action =  (empty($cat_id)) ? 'viewall' : 'category&amp;cat_id='.$cat_id;
			$pafiledb_template->assign_vars(array(
				'L_CATEGORY' => $lang['Category'],
				'L_RATING' => $lang['DlRating'],
				'L_DOWNLOADS' => $lang['Dls'],
				'L_DATE' => $lang['Date'],
				'L_NAME' => $lang['Name'],
				'L_FILE' => $lang['File'],
				'L_UPDATE_TIME' => $lang['Update_time'],
				'L_SCREENSHOTS' => $lang['Scrsht'],

				'L_SELECT_SORT_METHOD' => $lang['Select_sort_method'],
				'L_ORDER' => $lang['Order'],
				'L_SORT' => $lang['Sort'],

				'L_ASC' => $lang['Sort_Ascending'],
				'L_DESC' => $lang['Sort_Descending'],
		
				'SORT_NAME' => ($sort_method == 'file_name') ? 'selected="selected"' : '',
				'SORT_TIME' => ($sort_method == 'file_time') ? 'selected="selected"' : '',
				'SORT_RATING' => ($sort_method == 'rating') ? 'selected="selected"' : '',
				'SORT_DOWNLOADS' => ($sort_method == 'file_dls') ? 'selected="selected"' : '',
				'SORT_UPDATE_TIME' => ($sort_method == 'file_update_time') ? 'selected="selected"' : '',

				'SORT_ASC' => ($sort_order == 'ASC') ? 'selected="selected"' : '',
				'SORT_DESC' => ($sort_order == 'DESC') ? 'selected="selected"' : '',
				'PAGINATION' => generate_pagination(append_sid("dload.$phpEx?action=$action&amp;sort_method=$sort_method&amp;sort_order=$sort_order"), $total_file, $per_page, $start),
				'PAGE_NUMBER' => sprintf($lang['Page_of'], floor($start / $per_page) + 1, max(1, ceil($total_file / $per_page))),
				'FILELIST' => $filelist,
				'ID' => $cat_id,
				'START' => $start,

				'S_ACTION_SORT' => append_sid("dload.$phpEx?action=$action")) 
			);
		}
		else 
		{
			$pafiledb_template->assign_vars(array(
				'NO_FILE' => $show_file_message,
				'L_NO_FILES' => $lang['No_files'],
				'L_NO_FILES_CAT' => $lang['No_files_cat']) 
			);
		}
	}
	
	//=============================================
	// Admin and mod functions
	//=============================================
	
	function update_add_cat($cat_id = false)
	{
		global $db, $_POST, $lang;
		require_once dirname(__FILE__) . '/functions_category_storage.php';
		try { $saved_id = pafiledb_category_save($db, $cat_id, $_POST); }
		catch (PaFileDbCategoryFailure $error)
		{
			$key = $error->getMessage();
			$this->error[] = isset($lang[$key]) ? $lang[$key] : (isset($lang['PA_category_failed']) ? $lang['PA_category_failed'] : 'The category could not be saved. Please reload the category list.');
			return;
		}
		// The owned save already invalidated file metadata before COMMIT.
		$this->modified(false);
		return $saved_id;
	}
	
	function delete_cat($cat_id = false)
	{
		global $db, $_POST, $lang;
		$cat_id = (int) $cat_id;
		
		$file_to_cat_id = ( isset($_POST['file_to_cat_id']) ) ? intval($_POST['file_to_cat_id']) : '';
		$subcat_to_cat_id = ( isset($_POST['subcat_to_cat_id']) ) ? intval($_POST['subcat_to_cat_id']) : '';
		$file_mode = (isset($_POST['file_mode']) && is_scalar($_POST['file_mode']) && in_array((string) $_POST['file_mode'], array('move', 'delete'), true)) ? (string) $_POST['file_mode'] : 'move';
		$subcat_mode = (isset($_POST['subcat_mode']) && is_scalar($_POST['subcat_mode']) && in_array((string) $_POST['subcat_mode'], array('move', 'delete'), true)) ? (string) $_POST['subcat_mode'] : 'move';
		
		
		
		if (empty($cat_id))
		{
			$this->error[] = $lang['Cdelerror'];
		}
		else
		{
			if ( ($file_to_cat_id == -1 || empty($file_to_cat_id)) && $file_mode == 'move')
			{
				$this->error[] = $lang['Cdelerror'];
			}

			if($subcat_mode == 'move' && empty($subcat_to_cat_id))
			{
				$this->error[] = $lang['Cdelerror'];
			}
			
			if(sizeof($this->error))
			{
				return;
			}

			$sql = 'DELETE FROM ' . PA_CATEGORY_TABLE . " 
				WHERE cat_id = $cat_id";

			if ( !($db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Couldnt Query Info', '', __LINE__, __FILE__, $sql);
			}

			$this->reorder_cat($this->cat_rowset[$cat_id]['cat_parent']);

			if ($file_mode == 'delete')
			{
				$this->delete_files($cat_id, 'category');
			}
			else
			{
				$this->move_files($cat_id, $file_to_cat_id);
			}
					
			if($subcat_mode == 'delete')
			{
				$this->delete_subcat($cat_id, $file_mode, $file_to_cat_id);
			}
			else
			{
				$this->move_subcat($cat_id, $subcat_to_cat_id);
			}
			$this->modified(TRUE);
		}
	}

	function delete_files($id, $mode = 'file')
	{
		if ($mode === 'file')
		{
			require_once dirname(__FILE__) . '/functions_file_delete.php';
			return pafiledb_file_delete($this, $id);
		}
		global $db, $phpbb_root_path, $pafiledb_functions;
		$id = (int) $id;
		$mode = ($mode === 'category') ? 'category' : 'file';
		if ($id <= 0)
		{
			return;
		}

		if($mode == 'category')
		{
			$file_ids = array();
			$files_data = array();
			$sql = 'SELECT file_id, unique_name, file_dir 
				FROM ' . PA_FILES_TABLE . "
				WHERE file_catid = $id";

			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Couldnt select files', '', __LINE__, __FILE__, $sql);
			}

			while($row = $db->sql_fetchrow($result))
			{
				$file_ids[] = $row['file_id'];
				$files_data[] = $row;
			}
			$db->sql_freeresult($result);
			// An empty category has no file identities. Never substitute its
			// category ID for a file ID when deleting related metadata.
			if (empty($file_ids))
			{
				return;
			}

			$where_sql = "WHERE file_catid = $id";
		}
		else
		{
			$sql = 'SELECT file_id, unique_name, file_dir 
				FROM ' . PA_FILES_TABLE . "
				WHERE file_id = $id";

			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Couldnt select files', '', __LINE__, __FILE__, $sql);
			}
			
			$file_data = $db->sql_fetchrow($result);
			
			$where_sql = "WHERE file_id = $id";
		}
	
		$sql = 'DELETE FROM ' . PA_FILES_TABLE . "
			$where_sql";
	
		unset($where_sql);

		if ( !($db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Couldnt delete files', '', __LINE__, __FILE__, $sql);
		}

		$where_sql = ($mode != 'file' && !empty($file_ids)) ? ' IN (' . implode(', ', $file_ids) . ') ' : " = $id";
	
		$sql = 'DELETE FROM ' . PA_CUSTOM_DATA_TABLE . "
			WHERE customdata_file$where_sql";

		if ( !($db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Couldnt delete custom data', '', __LINE__, __FILE__, $sql);
		}
		
		$sql = 'DELETE FROM ' . PA_MIRRORS_TABLE . "
			WHERE file_id$where_sql";
			
		if ( !($db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Couldnt delete mirror for this file', '', __LINE__, __FILE__, $sql);
		}
		
		if($mode == 'category')
		{
			foreach($files_data as $file_data)
			{
				if(!empty($file_data['unique_name']))
				{
					$pafiledb_functions->pafiledb_unlink($phpbb_root_path . $file_data['file_dir'] . $file_data['unique_name']);
					
				}
			}
		}
		else
		{
			if(!empty($file_data['unique_name']))
			{
				$pafiledb_functions->pafiledb_unlink($phpbb_root_path . $file_data['file_dir'] . $file_data['unique_name']);					
			}
		}
		
		
		if($mode == 'file')
		{
			$this->modified(TRUE);
		}

		return;
	}

	function move_files($from_cat, $to_cat)
	{
		global $db;

		$sql = 'UPDATE ' . PA_FILES_TABLE . "
			SET file_catid = $to_cat
			WHERE file_catid = $from_cat";
		
		if ( !($db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Couldnt move files', '', __LINE__, __FILE__, $sql);
		}
		$this->modified(TRUE);
		return;	
	}
	
	function delete_subcat($cat_id, $file_mode = 'delete', $to_cat = false)
	{
		global $db;
	
		if (!empty($this->subcat_rowset[$cat_id]))
		{
		foreach($this->subcat_rowset[$cat_id] as $sub_cat_id => $subcat_data)
		{
			$this->delete_subcat($sub_cat_id, $file_mode, $to_cat);

			$sql = 'DELETE FROM ' . PA_CATEGORY_TABLE . " 
				WHERE cat_id = $sub_cat_id";

			if ( !($db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Couldnt Query Info', '', __LINE__, __FILE__, $sql);
			}
		
			if($file_mode == 'delete')
			{
				$this->delete_files($sub_cat_id, 'category');
			}
			else
			{
				$this->move_files($sub_cat_id, $to_cat);
			}
		}
		}
		$this->modified(TRUE);
		return;
	}
	
	function move_subcat($from_cat, $to_cat)
	{
		global $db;

		$sql = 'UPDATE ' . PA_CATEGORY_TABLE . "
			SET cat_parent = $to_cat
			WHERE cat_parent = $from_cat";

		if ( !($db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Couldnt move Sub Category', '', __LINE__, __FILE__, $sql);
		}
		$this->modified(TRUE);
		return;	
	}
	
	function reorder_cat($cat_parent)
	{
		global $db;
		$cat_parent = (int) $cat_parent;
		$parent_where = $cat_parent === 0 ? '(cat_parent = 0 OR cat_parent IS NULL)' : 'cat_parent = ' . $cat_parent;

		$sql = 'SELECT cat_id, cat_order
			FROM '. PA_CATEGORY_TABLE ."
			WHERE $parent_where
			ORDER BY cat_order ASC, cat_id ASC";

		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, 'Could not get list of Categories', '', __LINE__, __FILE__, $sql);
		}

		$i = 10;
		while($row = $db->sql_fetchrow($result))
		{
			$cat_id = $row['cat_id'];

			$sql = 'UPDATE ' . PA_CATEGORY_TABLE . "
					SET cat_order = $i
					WHERE cat_id = $cat_id";
			if( !$db->sql_query($sql) )
			{
				message_die(GENERAL_ERROR, 'Could not update order fields', '', __LINE__, __FILE__, $sql);
			}
			$i += 10;
		}
	}
	
	function order_cat($cat_id, $move)
	{
		global $db;

		$cat_id = (int) $cat_id;
		$move = (int) $move;
		if (!isset($this->cat_rowset[$cat_id]) || !in_array($move, array(-15, 15), true))
		{
			return;
		}
		$cat_parent = $this->cat_rowset[$cat_id]['cat_parent'];

		$sql = 'UPDATE ' . PA_CATEGORY_TABLE . "
				SET cat_order = cat_order + $move
				WHERE cat_id = $cat_id";

		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, 'Could not change category order', '', __LINE__, __FILE__, $sql);
		}

		$this->reorder_cat($cat_parent);
		$this->init();
	}
	
	function sync($cat_id, $init = true)
	{
		global $db;
		$cat_id = (int) $cat_id;
		if (!isset($this->cat_rowset[$cat_id]))
		{
			return;
		}

		$sql = 'UPDATE ' . PA_CATEGORY_TABLE . "
				SET cat_files = '-1',
				cat_last_file_id = '0', 
				cat_last_file_name = '', 
				cat_last_file_time = '0'
				WHERE cat_id = '" . $cat_id . "'";

		if ( !($db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Couldnt Query Files info', '', __LINE__, __FILE__, $sql);
		}
		if($init)
		{
			$this->init();
		}
		return;
	}
	
	function sync_all()
	{
		foreach($this->cat_rowset as $cat_id => $void)
		{
			$this->sync($cat_id, false);
		}
		$this->init();
	}
	
	function update_add_file($file_id = false, $custom_field = null)
	{
		require_once dirname(__FILE__) . '/functions_file_actions.php';
		$body = function()use($file_id){return $this->update_add_file_owned_body($file_id);};
		return pafiledb_file_save($this, $file_id, $custom_field, $body);
	}

	protected function update_add_file_owned_body($file_id = false)
	{
		global $db, $phpbb_root_path, $_POST, $userdata, $pafiledb_config, $_FILES, $_REQUEST, $pafiledb_functions, $user_ip, $lang;
		$file_id = (int) $file_id;

		$posted = function ($key, $default = '')
		{
			return (isset($_POST[$key]) && is_scalar($_POST[$key])) ? (string) phpbb_request_raw_value($_POST[$key]) : $default;
		};
		$uploaded = function ($field, $key, $default = '')
		{
			return (isset($_FILES[$field]) && is_array($_FILES[$field]) && isset($_FILES[$field][$key]) && is_scalar($_FILES[$field][$key])) ? (string) $_FILES[$field][$key] : $default;
		};

		$ss_remote_url = $posted('screen_shot_url');
		$ss_upload = ($ss_remote_url === '');
		$ss_local = $uploaded('screen_shot', 'tmp_name');
		$ss_local = ($ss_local !== 'none') ? $ss_local : '';
		$ss_name = $uploaded('screen_shot', 'name');
		$ss_name = ($ss_name !== 'none') ? basename(str_replace('\\', '/', $ss_name)) : '';
		$ss_size = max(0, intval($uploaded('screen_shot', 'size', 0)));

		$file_remote_url = $posted('download_url');
		$file_upload = ($file_remote_url === '');
		$file_local = $uploaded('userfile', 'tmp_name');
		$file_local = ($file_local !== 'none') ? $file_local : '';
		$file_realname = $uploaded('userfile', 'name');
		$file_realname = ($file_realname !== 'none') ? basename(str_replace('\\', '/', $file_realname)) : '';
		$file_size = max(0, intval($uploaded('userfile', 'size', 0)));
		$file_type = $uploaded('userfile', 'type');

		$cat_id = intval($posted('cat_id', 0));

		$file_name = trim($posted('name'));
		$file_long_desc = $posted('long_desc');
		$file_short_desc = $posted('short_desc', ($file_long_desc !== '') ? pafiledb_file_prefix($file_long_desc, 50) . '...' : '');
		$file_author = trim($posted('author', ($userdata['user_id'] != ANONYMOUS) ? $userdata['username'] : ''));
		$file_version = trim($posted('version'));
		$file_website = trim($posted('website'));
		if(!empty($file_website))
		{
			$file_website = (!preg_match('#^https?://#i', $file_website)) ? 'https://' . $file_website : $file_website;
			$normalized_website = pafiledb_normalize_remote_url($file_website);
			if ($normalized_website === false)
			{
				$this->error[] = 'The documentation URL must use HTTP or HTTPS.';
				$file_website = '';
			}
			else
			{
				$file_website = $normalized_website;
			}
		}
		
		$file_posticon = basename(str_replace('\\', '/', $posted('posticon')));
		if ($file_posticon !== 'none' && !preg_match('/^[a-z0-9_.-]{1,100}$/Di', $file_posticon))
		{
			$file_posticon = 'none';
		}

		$file_license = max(0, intval($posted('license', 0)));
		$file_pin = (intval($posted('pin', 0)) === 1) ? 1 : 0;
		$file_ss_link = (intval($posted('sshot_link', 0)) === 1) ? 1 : 0;
// MX		$file_approved = ( isset($_POST['approved']) ) ? intval($_POST['approved']) : 0;
		$file_dls = max(0, intval($posted('file_download', 0)));
		
		$file_time = time();
		
		if($cat_id <= 0 || !isset($this->cat_rowset[$cat_id]))
		{
			$this->error[] = $lang['Missing_field'];
		}
			
		if($file_name === '')
		{
			$this->error[] = $lang['Missing_field'];
		}
			
		if($file_long_desc === '')
		{
			$this->error[] = $lang['Missing_field'];
		}
			
		if(empty($file_remote_url) && empty($file_local) && !$file_id)
		{
			$this->error[] = $lang['Missing_field'];
		}
		if (!empty($file_remote_url))
		{
			$normalized_url = pafiledb_normalize_remote_url($file_remote_url);
			if ($normalized_url === false)
			{
				$this->error[] = 'The remote download URL must use HTTP or HTTPS.';
			}
			else
			{
				$file_remote_url = $normalized_url;
			}
		}
		if (!empty($ss_remote_url))
		{
			$normalized_url = pafiledb_normalize_remote_url($ss_remote_url, true);
			if ($normalized_url === false)
			{
				$this->error[] = 'The remote screenshot URL must point to a GIF, JPEG or PNG image over HTTP or HTTPS.';
			}
			else
			{
				$ss_remote_url = $normalized_url;
			}
		}
		//
		// Extension-Screenshoots
		if ( !empty($ss_name) )
		{
            // this Array contains the allowed File-Extensions.
            $check_screen_extension = array('jpg', 'jpeg', 'gif', 'png');
            
            //
            // For explanation in Error-Message
            $count_screen_extension = count($check_screen_extension);
            $explain_extension = '';
            for ( $ci=0 ; $ci < $count_screen_extension ; $ci++ )
            {
                $explain_extension .= ( empty($explain_extension) ) ? strtoupper($check_screen_extension[$ci]) : ', ' . strtoupper($check_screen_extension[$ci]);
            }
          
            $chk_extension_array = @explode(".", $ss_name);
            if ( $chk_extension_array !== false )
            {
                $check_anzahl = count($chk_extension_array);
                if ( !in_array(strtolower($chk_extension_array[$check_anzahl-1]), $check_screen_extension) )
                {
                    message_die(GENERAL_ERROR, '<b>You are not allowed to upload this type of files for Screenshots! Allowed only  ' . $explain_extension . '</b>');
                }
            }
			if (!is_uploaded_file($ss_local) || @getimagesize($ss_local) === false)
			{
				message_die(GENERAL_ERROR, '<b>The uploaded screenshot is not a valid image.</b>');
			}
			$ss_name = $pafiledb_functions->gen_unique_name('.' . strtolower($chk_extension_array[$check_anzahl-1]));
        }
		// END Extension-Screenshots
		//
		$forbidden_extensions = array_map('strtolower', array_map('trim', @explode(',', $pafiledb_config['forbidden_extensions'])));

		$file_extension = $pafiledb_functions->get_extension($file_realname);
			
		if($file_upload && $file_local !== '' && (phpbb_forbidden_upload_extension($file_extension) || in_array($file_extension, $forbidden_extensions, true)))
		{
			$this->error[] = 'You are not allowed to upload this type of files';
		}
		
		if(sizeof($this->error))
		{
			return;
		}

		$physical_file_name = '';
		$screen_shot_url = '';
		$old_physical_path = '';

		if($file_id)
		{
			$sql = 'SELECT file_dlurl, file_size, unique_name, file_dir, real_name, file_ssurl, file_approved
				FROM ' . PA_FILES_TABLE . " 
				WHERE file_id = '$file_id'";
	
			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Couldnt query Download URL', '', __LINE__, __FILE__, $sql);
			}

			$file_data = $db->sql_fetchrow($result);

			$db->sql_freeresult($result);
			if (!$file_data)
			{
				$this->error[] = $lang['File_not_exist'];
				return;
			}
			
			if(!empty($file_remote_url) || !empty($file_local))
			{
				if(!empty($file_data['unique_name']))
				{
					$old_physical_path = $phpbb_root_path . $file_data['file_dir'] . $file_data['unique_name'];
				}
			}
			else
			{
				$file_remote_url = $file_data['file_dlurl'];
				$physical_file_name = $file_data['unique_name'];
				$file_realname = $file_data['real_name'];
				$file_size = (int) $file_data['file_size'];

				if(empty($file_local))
				{
					$file_upload = false;
				}
			}

			if (empty($ss_remote_url) && empty($ss_local))
			{
				$screen_shot_url = $file_data['file_ssurl'];
			}
		}
		
			
		if($file_upload)
		{
			$physical_file_name = $pafiledb_functions->gen_unique_name('.' . $file_extension);

			$file_info = $pafiledb_functions->upload_file($file_local, $physical_file_name, $file_size, $pafiledb_config['upload_dir']);
				
			if($file_info['error'])
			{
				message_die(GENERAL_ERROR, $file_info['message']);
			}
			$file_size = (int) $file_info['size'];
		}

			
		if(!empty($ss_remote_url) || !empty($ss_local))
		{
			if($ss_upload)
			{
				$screen_shot_info = $pafiledb_functions->upload_file($ss_local, $ss_name, $ss_size, $pafiledb_config['screenshots_dir']);
	
				if($screen_shot_info['error'])
				{
					message_die(GENERAL_ERROR, $screen_shot_info['message']);
				}
				$ss_size = (int) $screen_shot_info['size'];
				$screen_shot_url = $screen_shot_info['url'];
			}
			else
			{
				$screen_shot_url = $ss_remote_url;
			}
		}
		
		// MX addon
		if ( $pafiledb_config['need_validation'] == 0 )
		{
			if ( !$file_id  )
			{
				$file_approved = 1;
			}
			else
			{
				$file_approved = isset($file_data['file_approved'])  ? $file_data['file_approved'] : 0;
			}
		}
		elseif ( $pafiledb_config['need_validation'] == 1 )
		{
			if ( !$file_id  )
			{
				$file_approved = ( ((!empty($this->auth[$cat_id]['auth_mod'])) || $userdata['user_level'] == ADMIN) && $userdata['session_logged_in'] ) ? 1 : 0;
			}
			else
			{
				$file_approved = isset($file_data['file_approved'])  ? $file_data['file_approved'] : 0;
			}
		}
		
		if (defined('IN_ADMIN') && IN_ADMIN) { $file_approved = (int)$posted('approved', 0); }
		$user_id_sql = intval($userdata['user_id']);
		$user_ip_sql = $db->sql_escape((string) $user_ip);
		$file_name_sql = $db->sql_escape($file_name);
		$physical_file_name_sql = $db->sql_escape($physical_file_name);
		$file_realname_sql = $db->sql_escape($file_realname);
		$file_dir_sql = $db->sql_escape($file_id && !$file_upload && $old_physical_path === '' ? $file_data['file_dir'] : $pafiledb_config['upload_dir']);
		$file_short_desc_sql = $db->sql_escape($file_short_desc);
		$file_author_sql = $db->sql_escape($file_author);
		$file_version_sql = $db->sql_escape($file_version);
		$file_long_desc_sql = $db->sql_escape($file_long_desc);
		$screen_shot_url_sql = $db->sql_escape($screen_shot_url);
		$file_remote_url_sql = $db->sql_escape($file_remote_url);
		$file_posticon_sql = $db->sql_escape($file_posticon);
		$file_website_sql = $db->sql_escape($file_website);

		if(!$file_id)
		{
			$sql = 'INSERT INTO ' . PA_FILES_TABLE . " (user_id, poster_ip, file_name, file_size, unique_name, real_name, file_dir, file_desc, file_creator, file_version, file_longdesc, file_ssurl, file_sshot_link, file_dlurl, file_time, file_update_time, file_catid, file_posticon, file_license, file_dls, file_last, file_pin, file_docsurl, file_approved)
					VALUES($user_id_sql, '$user_ip_sql', '$file_name_sql', $file_size, '$physical_file_name_sql', '$file_realname_sql', '$file_dir_sql', '$file_short_desc_sql', '$file_author_sql', '$file_version_sql', '$file_long_desc_sql', '$screen_shot_url_sql', $file_ss_link, '$file_remote_url_sql', $file_time, $file_time, $cat_id, '$file_posticon_sql', $file_license, $file_dls, 0, $file_pin, '$file_website_sql', $file_approved)";
		}
		else
		{
			$sql = "UPDATE " . PA_FILES_TABLE . " 
				SET file_name = '$file_name_sql',
				file_size = $file_size,
				unique_name = '$physical_file_name_sql',
				real_name = '$file_realname_sql',
				file_dir = '$file_dir_sql',
				file_desc = '$file_short_desc_sql',
				file_longdesc = '$file_long_desc_sql',
				file_creator = '$file_author_sql',
				file_version = '$file_version_sql',
				file_ssurl = '$screen_shot_url_sql',
				file_sshot_link = $file_ss_link,
				file_dlurl = '$file_remote_url_sql',
				file_update_time = $file_time,
				file_catid = $cat_id,
				file_posticon = '$file_posticon_sql',
				file_license = $file_license,
				file_pin = $file_pin,
				file_docsurl = '$file_website_sql',
				file_dls = $file_dls,
				file_approved = $file_approved
				WHERE file_id = $file_id";
		}

		if ( !($db->sql_query($sql)) )
		{
			if ($file_upload && $physical_file_name !== '')
			{
				$pafiledb_functions->pafiledb_unlink($phpbb_root_path . $pafiledb_config['upload_dir'] . $physical_file_name);
			}
			message_die(GENERAL_ERROR, 'Couldnt Add the file information to the database', '', __LINE__, __FILE__, $sql);
		}

		// Only remove the previous upload after its replacement is stored and
		// the database points at the new location.
		if ($old_physical_path !== '')
		{
			$pafiledb_functions->pafiledb_unlink($old_physical_path);
		}
		
		$this->modified(TRUE);
		
		if($file_id)
		{
			return $file_id;
		}
		else
		{
			return $db->sql_nextid();
		}
	}
	
	function mirror_request($file_id)
	{
		global $lang;
		require_once dirname(__FILE__) . '/functions_mirror_storage.php';
		try { list($action,$payload)=pafiledb_mirror_request_data(); }
		catch (PaFileDbFileActionFailure $error) { $key=$error->getMessage();$this->error[]=isset($lang[$key])?$lang[$key]:$lang['PA_file_failed'];return null; }
		return pafiledb_mirror_change($this,$file_id,$action,$payload);
	}

	function mirror_batch($file_id, $action, $payload)
	{
		require_once dirname(__FILE__) . '/functions_mirror_storage.php';
		return pafiledb_mirror_change($this,$file_id,$action,$payload);
	}

	function mirror_add_update($file_id, $file_upload, $file_remote_url, $file_local, $file_realname, $file_size, $file_type, $mirror_location, $mirror_id = false)
	{
		require_once dirname(__FILE__) . '/functions_mirror_storage.php';
		$upload=pafiledb_mirror_legacy_upload($file_local);
		$create=$mirror_id===false||$mirror_id===0||$mirror_id==='0';
		return $this->mirror_batch($file_id,$create?'create':'modify',array($create?0:$mirror_id=>array('url'=>$file_remote_url,'location'=>$mirror_location,'upload'=>$upload)));
	}

	function delete_mirror($mirror_id, $file_id = 0)
	{
		return $this->mirror_batch($file_id,'delete',is_array($mirror_id)?$mirror_id:array($mirror_id));
	}
	
	function file_mainenance()
	{
		return false;
	}
	
	function file_approve($mode = 'do_approve', $file_id = 0)
	{
		require_once dirname(__FILE__) . '/functions_file_actions.php';
		return pafiledb_file_set_approval($this, $mode, $file_id);
	}
	
	function _pafiledb()
	{
		if($this->modified)
		{
			$this->sync_all();
		}
	}
}
?>
