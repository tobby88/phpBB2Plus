<?php
/***************************************************************************
 *                         admin_db_maintenance.php
 *                            -------------------
 *   begin                : Fri Feb 07, 2003
 *   copyright            : (C) 2004 Philipp Kordowich
 *                          Parts: (C) 2002 The phpBB Group
 *
 *   part of DB Maintenance Mod 1.3.8
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

if (!defined('IN_PHPBB'))
{
	define('IN_PHPBB', true);
}
define('DBMTNC_VERSION', '1.3.8');
// CONFIG_LEVEL = 0: configuration is disabled
// CONFIG_LEVEL = 1: only general configuration available
// Rebuild batches and checkpoints are managed by the durable rebuild service.
define('CONFIG_LEVEL', 1); // Only general options remain configurable.

if ( !empty($setmodules) )
{
	$filename = basename(__FILE__);
	$module['General']['DB_Maintenance'] = $filename;
	return;
}

//
// Load default header
//
$phpbb_root_path = "./../";
$no_page_header = TRUE; // We do not send the page header right here to prevent problems with GZIP-compression
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
require($phpbb_root_path . 'includes/functions_dbmtnc.'.$phpEx);
require_once($phpbb_root_path . 'includes/functions_privmsgs.'.$phpEx);

//
// Set up timer
//
$timer = getmicrotime();

//
// Get language file for this mod
//
if ( !file_exists(@phpbb_realpath($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_dbmtnc.'.$phpEx)) )
{
	$board_config['default_lang'] = 'english';
}
include($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_dbmtnc.' . $phpEx);

function dbmtnc_continuation_token($function, $db_state)
{
	global $userdata;

	return hash_hmac('sha256', (string) $function . '|' . (int) $db_state, (string) $userdata['session_id']);
}

function dbmtnc_post_int($key, $default)
{
	return (isset($_POST[$key]) && is_scalar($_POST[$key]) && is_numeric($_POST[$key])) ? intval($_POST[$key]) : (int) $default;
}

//
// Set up variables and constants
//
$function = (isset($_GET['function']) && is_scalar($_GET['function'])) ? trim((string) $_GET['function']) : '';
$mode_id = (isset($_GET['mode']) && is_scalar($_GET['mode'])) ? trim((string) $_GET['mode']) : '';
if (!in_array($mode_id, array('', 'start', 'perform'), true))
{
	message_die(GENERAL_ERROR, $lang['Invalid_dbmtnc_request']);
}
// Check for parameters
foreach ($config_data as $value)
{
	if ( !isset($board_config[$value]) )
	{
		message_die(GENERAL_MESSAGE, sprintf($lang['Incomplete_configuration'], $value));
	}
}

//
// Get form-data if specified and override old settings
//
if (isset($_POST['mode']) && is_scalar($_POST['mode']) && $_POST['mode'] == 'perform')
{
	if (isset($_POST['confirm']))
	{
		$mode_id = 'perform';
		$function = (isset($_POST['function']) && is_scalar($_POST['function'])) ? trim((string) $_POST['function']) : '';
	}
}

$dbmtnc_allowed_functions = array('perform_rebuild', 'synchronize_post_direct');
foreach ($mtnc as $dbmtnc_function)
{
	if (!empty($dbmtnc_function[0]) && $dbmtnc_function[0] != '--')
	{
		$dbmtnc_allowed_functions[] = $dbmtnc_function[0];
	}
}
if ($function !== '' && !in_array($function, $dbmtnc_allowed_functions, true))
{
	message_die(GENERAL_ERROR, $lang['function_unknown']);
}

if ($mode_id == 'perform')
{
	if ($function === 'perform_rebuild')
	{
		require_once($phpbb_root_path . 'includes/functions_maintenance_rebuild.' . $phpEx);
		try { dbmtnc_rebuild_request('step', $_GET); }
		catch (PhpbbAclException $error) { message_die(GENERAL_ERROR, $error->getMessage()); }
	}
	elseif ($function === 'synchronize_post_direct')
	{
		$dbmtnc_state = (isset($_GET['db_state']) && is_scalar($_GET['db_state'])) ? intval($_GET['db_state']) : 0;
		$dbmtnc_token = (isset($_GET['dbmtnc_token']) && is_scalar($_GET['dbmtnc_token'])) ? (string) $_GET['dbmtnc_token'] : '';
		if (!hash_equals(dbmtnc_continuation_token($function, $dbmtnc_state), $dbmtnc_token))
		{
			message_die(GENERAL_ERROR, $lang['Invalid_dbmtnc_request']);
		}
	}
	else
	{
		phpbb_admin_require_post_session();
	}
}

if ($mode_id == 'start' && $function == 'config' && isset($_POST['submit']))
{
	phpbb_admin_require_post_session();
}

//
// Switch of GZIP-compression when necessary and send the page header
//
if ($mode_id == 'start' || $mode_id == 'perform')
{
	$board_config['gzip_compress'] = FALSE;
}
if ($function != 'perform_rebuild') // Don't send header when rebuilding the search index
{
	include('./page_header_admin.'.$phpEx);
}

//
// Check the db-type
//
if (SQL_LAYER != 'mysql' && SQL_LAYER != 'mysql4' && SQL_LAYER != 'mysqli')
{
	message_die(GENERAL_MESSAGE, $lang['dbtype_not_supported']);
}

switch($mode_id)
{
	case 'start': // Show warning message if specified
		if ($function == '')
		{
			message_die(GENERAL_ERROR, $lang['no_function_specified']);
		}
		$warning_message_defined = FALSE;

		for($i = 0; $i < count($mtnc); $i++)
		{
			if ( count($mtnc[$i]) && $mtnc[$i][0] == $function )
			{
				$warning_message = $mtnc[$i];
				$warning_message_defined = TRUE;
			};
		}

		if ( !$warning_message_defined )
		{
			message_die(GENERAL_ERROR, $lang['function_unknown']);
		}
		elseif ($warning_message[3] == '' && !in_array($function, array('statistic', 'config'), true))
		{
			$warning_message[3] = $lang['Confirm_dbmtnc_action'];
		}

		if ($warning_message[3] != '')
		{
			$s_hidden_fields = '<input type="hidden" name="mode" value="perform" />';
			$s_hidden_fields .= '<input type="hidden" name="function" value="' . phpbb_admin_html($function) . '" />';
			$s_hidden_fields .= phpbb_admin_session_field();
			if (in_array($function, array('rebuild_search_index','proceed_rebuilding'), true))
			{
				require_once($phpbb_root_path . 'includes/functions_maintenance_rebuild.' . $phpEx);
				try { $rebuild_snapshot = dbmtnc_rebuild_read(new PhpbbAclDatabase($db,'Maintenance_rebuild_failed')); }
				catch (PhpbbAclException $error) { message_die(GENERAL_ERROR, $error->getMessage()); }
				$rebuild_generation = $rebuild_snapshot['state'] && $rebuild_snapshot['state']['s'] !== 'done' ? $rebuild_snapshot['state']['g'] : '';
				$s_hidden_fields .= '<input type="hidden" name="job" value="' . phpbb_admin_html($rebuild_generation) . '" />';
			}

			$template->set_filenames(array(
				'body' => 'admin/dbmtnc_confirm_body.tpl')
			);

			$template->assign_vars(array(
				'MESSAGE_TITLE' => $warning_message[1],
				'MESSAGE_TEXT' => $warning_message[3],

				'L_YES' => $lang['Yes'],
				'L_NO' => $lang['No'],

				'S_CONFIRM_ACTION' => append_sid("admin_db_maintenance.$phpEx"),
				'S_HIDDEN_FIELDS' => $s_hidden_fields)
			);

			$template->pparse("body");
			break;
		}
		//
		// We do not exit if no warning message is specified. In this case we will start directly with performing...
		//
	case 'perform': // Execute the commands
		//
		// phpBB-Template System not used here to allow output information directly to the screen
		// Using the font tag will allow to get the gen-class applied :-)
		//
		$list_open = FALSE;

		//
		// Increase maximum execution time, but don't complain about it if it isn't
		// allowed.
		@set_time_limit(120);
		// Switch of buffering - not when rebuilding search index since we still need to add some headers 
		if ($function != 'perform_rebuild')
		{
			ob_end_flush();
		}
		switch($function)
		{
			case 'statistic': // Statistics
				$template->set_filenames(array(
					'body' => 'admin/dbmtnc_statistic_body.tpl')
				);

				// Get board statistics
				$total_topics = get_db_stat('topiccount');
				$total_posts = get_db_stat('postcount');
				$total_users = get_db_stat('usercount');
				$sql = "SELECT COUNT(user_id) AS total
					FROM " . USERS_TABLE . "
					WHERE user_active = 0
						AND user_id <> " . ANONYMOUS;
				if ( !($result = $db->sql_query($sql)) )
				{
					throw_error("Couldn't get statistic data!", __LINE__, __FILE__, $sql);
				}
				if ( $row = $db->sql_fetchrow($result) )
				{
					$total_deactivated_users = $row['total'];
				}
				else
				{
					throw_error("Couldn't update pending information!", __LINE__, __FILE__, $sql);
				}
				$db->sql_freeresult($result);
				$sql = "SELECT COUNT(user_id) AS total
					FROM " . USERS_TABLE . "
					WHERE user_level = " . MOD . "
						AND user_id <> " . ANONYMOUS;
				if ( !($result = $db->sql_query($sql)) )
				{
					throw_error("Couldn't get statistic data!", __LINE__, __FILE__, $sql);
				}
				if ( $row = $db->sql_fetchrow($result) )
				{
					$total_moderators = $row['total'];
				}
				else
				{
					throw_error("Couldn't update pending information!", __LINE__, __FILE__, $sql);
				}
				$db->sql_freeresult($result);
				$sql = "SELECT COUNT(user_id) AS total
					FROM " . USERS_TABLE . "
					WHERE user_level = " . ADMIN . "
						AND user_id <> " . ANONYMOUS;
				if ( !($result = $db->sql_query($sql)) )
				{
					throw_error("Couldn't get statistic data!", __LINE__, __FILE__, $sql);
				}
				if ( $row = $db->sql_fetchrow($result) )
				{
					$total_administrators = $row['total'];
				}
				else
				{
					throw_error("Couldn't update pending information!", __LINE__, __FILE__, $sql);
				}
				$db->sql_freeresult($result);
				$administrator_names = '';
				$sql = "SELECT username
					FROM " . USERS_TABLE . "
					WHERE user_level = " . ADMIN . "
						AND user_id <> " . ANONYMOUS . "
					ORDER BY username";
				if ( !($result = $db->sql_query($sql)) )
				{
					throw_error("Couldn't get statistic data!", __LINE__, __FILE__, $sql);
				}
				while ( $row = $db->sql_fetchrow($result) )
				{
					$administrator_names .= (($administrator_names == '') ? '' : ', ') . $row['username'];
				}
				$db->sql_freeresult($result);
				$template->assign_vars(array(
					'NUMBER_OF_TOPICS' => $total_topics,
					'NUMBER_OF_POSTS' => $total_posts,
					'NUMBER_OF_USERS' => $total_users,
					'NUMBER_OF_DEACTIVATED_USERS' => $total_deactivated_users,
					'NUMBER_OF_MODERATORS' => $total_moderators,
					'NUMBER_OF_ADMINISTRATORS' => $total_administrators,
					'NAMES_OF_ADMINISTRATORS' => htmlspecialchars($administrator_names))
				);
				
				// Database statistic
				if (check_mysql_version())
				{
					$stat = get_table_statistic();
					$template->assign_block_vars('db_statistics', array());
					$template->assign_vars(array(
						'NUMBER_OF_DB_TABLES' => $stat['all']['count'],
						'NUMBER_OF_CORE_DB_TABLES' => $stat['core']['count'],
						'NUMBER_OF_ADVANCED_DB_TABLES' => $stat['advanced']['count'],
						'NUMBER_OF_DB_RECORDS' => $stat['all']['records'],
						'NUMBER_OF_CORE_DB_RECORDS' => $stat['core']['records'],
						'NUMBER_OF_ADVANCED_DB_RECORDS' => $stat['advanced']['records'],
						'SIZE_OF_DB' => convert_bytes($stat['all']['size']),
						'SIZE_OF_CORE_DB' => convert_bytes($stat['core']['size']),
						'SIZE_OF_ADVANCED_DB' => convert_bytes($stat['advanced']['size']))
					);
				}				

				// Version information
				$sql = "SELECT VERSION() AS mysql_version";
				$result = $db->sql_query($sql);
				if ( !$result )
				{
					throw_error("Couldn't obtain MySQL Version", __LINE__, __FILE__, $sql);
				}
				$row = $db->sql_fetchrow($result);
				$mysql_version = $row['mysql_version'];
				$db->sql_freeresult($result);

				$template->assign_vars(array(
					'PHPBB_VERSION' => '2' . $board_config['version'],
					'MOD_VERSION' => DBMTNC_VERSION,
					'PHP_VERSION' => phpversion(),
					'MYSQL_VERSION' => $mysql_version,

					'L_DBMTNC_TITLE' => $lang['DB_Maintenance'],
					'L_DBMTNC_SUB_TITLE' => $lang['Statistic_title'],
					'L_DB_INFO' => $lang['Database_table_info'],
					'L_BOARD_STATISTIC' => $lang['Board_statistic'],
					'L_DB_STATISTIC' => $lang['Database_statistic'],
					'L_VERSION_INFO' => $lang['Version_info'],
					'L_NUMBER_POSTS' => $lang['Number_posts'], // from lang_admin.php
					'L_NUMBER_TOPICS' => $lang['Number_topics'], // from lang_admin.php
					'L_NUMBER_USERS' => $lang['Number_users'], // from lang_admin.php
					'L_NUMBER_DEACTIVATED_USERS' => $lang['Thereof_deactivated_users'],
					'L_NUMBER_MODERATORS' => $lang['Thereof_Moderators'],
					'L_NUMBER_ADMINISTRATORS' => $lang['Thereof_Administrators'],
					'L_NAME_ADMINISTRATORS' => $lang['Users_with_Admin_Privileges'],
					'L_NUMBER_DB_TABLES' => $lang['Number_tables'],
					'L_NUMBER_DB_RECORDS' => $lang['Number_records'],
					'L_DB_SIZE' => $lang['DB_size'],
					'L_THEREOF_PHPBB_CORE' => $lang['Thereof_phpbb_core'],
					'L_THEREOF_PHPBB_ADVANCED' => $lang['Thereof_phpbb_advanced'],
					'L_BOARD_VERSION' => $lang['Version_of_board'],
					'L_MOD_VERSION' => $lang['Version_of_mod'],
					'L_PHP_VERSION' => $lang['Version_of_PHP'],
					'L_MYSQL_VERSION' => $lang['Version_of_MySQL'])
				);

				$template->pparse("body");
				break;
			case 'config': // General maintenance configuration; rebuild state is not editable.
				if (CONFIG_LEVEL < 1) { message_die(GENERAL_ERROR, $lang['Invalid_dbmtnc_request']); }
				if (isset($_POST['submit']))
				{
					$disallow_postcounter = dbmtnc_post_int('disallow_postcounter', 0);
					$disallow_rebuild = dbmtnc_post_int('disallow_rebuild', 0);
					if ($disallow_rebuild >= 0 && $disallow_rebuild <= 1) { update_config('dbmtnc_disallow_rebuild', $disallow_rebuild); }
					if ($disallow_postcounter >= 0 && $disallow_postcounter <= 1) { update_config('dbmtnc_disallow_postcounter', $disallow_postcounter); }
					$message = $lang['Dbmtnc_config_updated'] . '<br /><br />' . sprintf($lang['Click_return_dbmtnc_config'], '<a href="' . append_sid("admin_db_maintenance.$phpEx?mode=start&function=config") . '">', '</a>');
					message_die(GENERAL_MESSAGE, $message);
				}
				$template->set_filenames(array('body' => 'admin/dbmtnc_config_body.tpl'));
				$template->assign_vars(array(
					'S_CONFIG_ACTION' => append_sid("admin_db_maintenance.$phpEx?mode=start&function=config"),
					'S_HIDDEN_FIELDS' => phpbb_admin_session_field(),
					'L_DBMTNC_TITLE' => $lang['DB_Maintenance'],
					'L_DBMTNC_SUB_TITLE' => $lang['Config_title'],
					'L_CONFIG_INFO' => $lang['Config_info'],
					'L_GENERAL_CONFIG' => $lang['General_Config'],
					'L_DISALLOW_POSTCOUNTER' => $lang['Disallow_postcounter'],
					'L_DISALLOW_POSTCOUNTER_EXPLAIN' => $lang['Disallow_postcounter_Explain'],
					'L_DISALLOW_REBUILD' => $lang['Disallow_rebuild'],
					'L_DISALLOW_REBUILD_EXPLAIN' => $lang['Disallow_rebuild_Explain'],
					'L_YES' => $lang['Yes'], 'L_NO' => $lang['No'], 'L_SUBMIT' => $lang['Submit'], 'L_RESET' => $lang['Reset'],
					'DISALLOW_POSTCOUNTER_YES' => $board_config['dbmtnc_disallow_postcounter'] ? 'checked="checked"' : '',
					'DISALLOW_POSTCOUNTER_NO' => !$board_config['dbmtnc_disallow_postcounter'] ? 'checked="checked"' : '',
					'DISALLOW_REBUILD_YES' => $board_config['dbmtnc_disallow_rebuild'] ? 'checked="checked"' : '',
					'DISALLOW_REBUILD_NO' => !$board_config['dbmtnc_disallow_rebuild'] ? 'checked="checked"' : ''));
				$template->pparse('body');
				break;
			case 'check_user': // Check user tables
				echo("<h1>" . $lang['Checking_user_tables'] . "</h1>\n");
				require_once($phpbb_root_path . 'includes/functions_maintenance_users.' . $phpEx);
				$user_repair_scope = null;
				$user_repair_error = '';
				try
				{
				$user_repair_scope = dbmtnc_user_begin($db, $_POST);

				// Check for missing anonymous user
				echo("<p class=\"gen\"><b>" . $lang['Checking_missing_anonymous'] . "</b></p>\n");
				$sql = "SELECT user_id FROM " . USERS_TABLE . "
					WHERE user_id = " . ANONYMOUS;
				$result = $db->sql_query($sql);
				if ( !$result )
				{
					dbmtnc_user_error("Couldn't get user information!", __LINE__, __FILE__, $sql);
				}
				if ( $row = $db->sql_fetchrow($result) ) // anonymous user exists
				{
					echo($lang['Nothing_to_do']);
				}
				else // anonymous user does not exist
				{
					// Recreate entry
					$sql = "INSERT INTO " . USERS_TABLE . " (user_id, username, user_level, user_regdate, user_password, user_email, user_icq, user_website, user_occ, user_from, user_interests, user_sig, user_viewemail, user_style, user_aim, user_yim, user_msnm, user_posts, user_attachsig, user_allowsmile, user_allowhtml, user_allowbbcode, user_allow_pm, user_notify_pm, user_allow_viewonline, user_rank, user_avatar, user_lang, user_timezone, user_dateformat, user_actkey, user_newpasswd, user_notify, user_active)
						SELECT " . ANONYMOUS . ", 'Anonymous', 0, 0, '', '', '', '', '', '', '', '', 0, NULL, '', '', '', 0, 0, 1, 1, 1, 0, 1, 1, 0, '', '', 0, '', '', '', 0, 0 WHERE NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT user_id FROM " . USERS_TABLE . ") guest_current WHERE user_id = " . ANONYMOUS . ")";
					$result = $db->sql_write($sql);
					if ( !$result )
					{
						dbmtnc_user_error("Couldn't add user data!", __LINE__, __FILE__, $sql);
					}
					echo("<p class=\"gen\">" . sprintf($lang['Anonymous_recreated'], $db->sql_affectedrows()) . "</p>\n");
				}

				// Update incorrect pending information: either a single user group with pending state or a group with pending state NULL
				echo("<p class=\"gen\"><b>" . $lang['Checking_incorrect_pending_information'] . "</b></p>\n");
				$db_updated = FALSE;
				// Update the cases where user_pending is null (there were some cases reported, so we just do it)
				$sql = "UPDATE " . USER_GROUP_TABLE . "
					SET user_pending = 1
					WHERE user_pending IS NULL";
				$result = $db->sql_write($sql);
				if ( !$result )
				{
					dbmtnc_user_error("Couldn't update pending information!", __LINE__, __FILE__, $sql);
				}
				$affected_rows = $db->sql_affectedrows();
				if ( $affected_rows == 1 )
				{
					$db_updated = TRUE;
					echo("<p class=\"gen\">" . sprintf($lang['Updating_invalid_pendig_user'], $affected_rows) . "</p>\n");
				}
				elseif ( $affected_rows > 1 )
				{
					$db_updated = TRUE;
					echo("<p class=\"gen\">" . sprintf($lang['Updating_invalid_pendig_users'], $affected_rows) . "</p>\n");
				}
				// Check for pending single user groups
				// A corrupted personal group can have more than one owner. Never
				// approve one owner's request using another owner's private rights.
				$sql = "SELECT DISTINCT g.group_id
					FROM " . USER_GROUP_TABLE . " ug
						INNER JOIN " . GROUPS_TABLE . " g ON ug.group_id = g.group_id
					WHERE ug.user_pending = 1 AND g.group_single_user = 1
						AND NOT EXISTS (SELECT 1 FROM " . USER_GROUP_TABLE . " other_member
							WHERE other_member.group_id = ug.group_id AND other_member.user_id <> ug.user_id)";
				$result_array = array();
				$result = $db->sql_query($sql);
				if ( !$result )
				{
					dbmtnc_user_error("Couldn't get user and group data!", __LINE__, __FILE__, $sql);
				}
				while ( $row = $db->sql_fetchrow($result) )
				{
					$result_array[] = (int) $row['group_id'];
				}
				$db->sql_freeresult($result);
				if ( count($result_array) )
				{
					$db_updated = TRUE;
					$record_list = implode(',', $result_array);
					echo("<p class=\"gen\">" . $lang['Updating_pending_information'] . ": $record_list</p>\n");
					$sql = "UPDATE " . USER_GROUP_TABLE . "
						SET user_pending = 0
						WHERE group_id IN ($record_list)"
						. " AND user_pending = 1 AND EXISTS (SELECT 1 FROM " . GROUPS_TABLE . " pending_group"
						. " WHERE pending_group.group_id = " . USER_GROUP_TABLE . ".group_id AND pending_group.group_single_user = 1)"
						. " AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT group_id, user_id FROM " . USER_GROUP_TABLE . ") pending_other"
						. " WHERE pending_other.group_id = " . USER_GROUP_TABLE . ".group_id AND pending_other.user_id <> " . USER_GROUP_TABLE . ".user_id)";
					$result = $db->sql_write($sql);
					if ( !$result )
					{
						dbmtnc_user_error("Couldn't update pending information!", __LINE__, __FILE__, $sql);
					}
				}
				if (!$db_updated)
				{
					echo($lang['Nothing_to_do']);
				}

				// Checking for users without a single user group
				echo("<p class=\"gen\"><b>" . $lang['Checking_missing_user_groups'] . "</b></p>\n");
				$db_updated = FALSE;
				$sql = "SELECT u.user_id, COUNT(DISTINCT CASE WHEN g.group_single_user = 1 THEN g.group_id ELSE NULL END) AS group_count
					FROM " . USERS_TABLE . " u
						LEFT JOIN " . USER_GROUP_TABLE . " ug ON u.user_id = ug.user_id
						LEFT JOIN " . GROUPS_TABLE . " g ON ug.group_id = g.group_id
					GROUP BY u.user_id
					HAVING group_count <> 1";
				$missing_groups = array();
				$multiple_groups = array();
				$result = $db->sql_query($sql);
				if (!$result) { dbmtnc_user_error("Couldn't get user and group data!", __LINE__, __FILE__, $sql); }
				while ($row = $db->sql_fetchrow($result))
				{
					if ((int) $row['group_count'] === 0) { $missing_groups[] = (int) $row['user_id']; }
					else { $multiple_groups[] = (int) $row['user_id']; }
				}
				$db->sql_freeresult($result);
				// A single personal group shared by different users is ambiguous too.
				$sql = "SELECT DISTINCT ug.user_id FROM " . USER_GROUP_TABLE . " ug
					INNER JOIN " . GROUPS_TABLE . " g ON g.group_id = ug.group_id
					WHERE g.group_single_user = 1
						AND EXISTS (SELECT 1 FROM " . USER_GROUP_TABLE . " other_member
							WHERE other_member.group_id = ug.group_id AND other_member.user_id <> ug.user_id)";
				$result = $db->sql_query($sql);
				if (!$result) { dbmtnc_user_error("Couldn't check personal group ownership!", __LINE__, __FILE__, $sql); }
				while ($row = $db->sql_fetchrow($result)) { $multiple_groups[] = (int) $row['user_id']; }
				$db->sql_freeresult($result);
				$multiple_groups = array_values(array_unique($multiple_groups));
				sort($multiple_groups, SORT_NUMERIC);
				if (count($multiple_groups))
				{
					// Do not destroy/recreate groups: IDs can own ACLs, quotas and MOD
					// policies, and may have another legitimate member. Preserve them.
					echo("<p class=\"gen\"><b>" . $lang['Review_personal_groups'] . "</b><br />" . implode(', ', $multiple_groups) . "</p>\n");
				}
				// Create single user groups
				if ( count($missing_groups) )
				{
					$db_updated = TRUE;
					$record_list = implode(',', $missing_groups);
					echo("<p class=\"gen\">" . $lang['Recreating_SUG'] . ": $record_list</p>\n");
					for($i = 0; $i < count($missing_groups); $i++)
					{
						$group_name = ($missing_groups[$i] == ANONYMOUS) ? 'Anonymous' : '';
						$sql = "INSERT INTO " . GROUPS_TABLE . " (group_type, group_name, group_description, group_moderator, group_single_user)
							SELECT 1, '$group_name', 'Personal User', 0, 1 WHERE 1 = 1"
							. " AND EXISTS (SELECT 1 FROM " . USERS_TABLE . " missing_owner WHERE missing_owner.user_id = " . $missing_groups[$i] . ")"
							. " AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT ug.user_id FROM " . USER_GROUP_TABLE . " ug"
							. " INNER JOIN " . GROUPS_TABLE . " g ON g.group_id = ug.group_id WHERE g.group_single_user = 1) existing_personal"
							. " WHERE existing_personal.user_id = " . $missing_groups[$i] . ")";
						$result = $db->sql_write($sql);
						if ( !$result )
						{
							dbmtnc_user_error("Couldn't add group data!", __LINE__, __FILE__, $sql);
						}
						if ($db->sql_affectedrows() !== 1) { continue; }
						$group_id = (int) $db->sql_nextid();
						if ($group_id <= 0) { dbmtnc_user_error($lang['Maintenance_user_failed']); }
						$sql = "INSERT INTO " . USER_GROUP_TABLE . " (group_id, user_id, user_pending)
							SELECT $group_id, " . $missing_groups[$i] . ", 0 WHERE 1 = 1"
							. " AND EXISTS (SELECT 1 FROM " . USERS_TABLE . " missing_owner WHERE missing_owner.user_id = " . $missing_groups[$i] . ")"
							. " AND EXISTS (SELECT 1 FROM " . GROUPS_TABLE . " new_personal WHERE new_personal.group_id = $group_id AND new_personal.group_single_user = 1)"
							. " AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT group_id FROM " . USER_GROUP_TABLE . ") existing_member WHERE existing_member.group_id = $group_id)"
							. " AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT ug.user_id FROM " . USER_GROUP_TABLE . " ug"
							. " INNER JOIN " . GROUPS_TABLE . " g ON g.group_id = ug.group_id WHERE g.group_single_user = 1) existing_personal"
							. " WHERE existing_personal.user_id = " . $missing_groups[$i] . ")";
						$result = $db->sql_write($sql);
						if ( !$result )
						{
							dbmtnc_user_error("Couldn't add user - group connection!", __LINE__, __FILE__, $sql);
						}
					}
				}
				if (!$db_updated && !count($multiple_groups))
				{
					echo($lang['Nothing_to_do']);
				}

				// Check for group moderators who do not exist
				echo("<p class=\"gen\"><b>" . $lang['Checking_for_invalid_moderators'] . "</b></p>\n");
				$sql = "SELECT g.group_id, g.group_name
					FROM " . GROUPS_TABLE . " g
						LEFT JOIN " . USERS_TABLE . " u ON g.group_moderator = u.user_id
					WHERE g.group_single_user = 0
						AND (u.user_id IS NULL OR u.user_id = " . ANONYMOUS . ")";
				$result = $db->sql_query($sql);
				if ( !$result )
				{
					dbmtnc_user_error("Couldn't get group data!", __LINE__, __FILE__, $sql);
				}
				while ( $row = $db->sql_fetchrow($result) )
				{
					if (!$list_open)
					{
						echo("<p class=\"gen\"><b>" . $lang['Updating_Moderator'] . ":</b></p>\n");
						echo("<font class=\"gen\"><ul>\n");
						$list_open = TRUE;
					}
					echo("<li>" . htmlspecialchars($row['group_name']) . " (" . $row['group_id'] . ")</li>\n");
					$sql2 = "UPDATE " . GROUPS_TABLE . "
						SET group_moderator = " . (int) $userdata['user_id'] . "
						WHERE group_id = " . (int) $row['group_id']
						. " AND group_single_user = 0 AND (group_moderator = " . ANONYMOUS
						. " OR NOT EXISTS (SELECT 1 FROM " . USERS_TABLE . " current_moderator WHERE current_moderator.user_id = " . GROUPS_TABLE . ".group_moderator))";
					$result2 = $db->sql_write($sql2);
					if ( !$result2 )
					{
						dbmtnc_user_error("Couldn't update group data!", __LINE__, __FILE__, $sql2);
					}
				}
				$db->sql_freeresult($result);
				if ($list_open)
				{
					echo("</ul></font>\n");
					$list_open = FALSE;
				}
				else
				{
					echo($lang['Nothing_to_do']);
				}

				// Check for group moderators who are not member of the group they moderate
				echo("<p class=\"gen\"><b>" . $lang['Checking_moderator_membership'] . "</b></p>\n");
				$sql = "SELECT group_id, group_name, group_moderator
					FROM " . GROUPS_TABLE . " g
					WHERE g.group_single_user = 0";
				$result = $db->sql_query($sql);
				if ( !$result )
				{
					dbmtnc_user_error("Couldn't get group data!", __LINE__, __FILE__, $sql);
				}
				while ( $row = $db->sql_fetchrow($result) )
				{
					$sql2 = "SELECT user_pending
						FROM " . USER_GROUP_TABLE . "
						WHERE group_id = " . $row['group_id'] . "
							AND user_id = " . $row['group_moderator'];
					$result2 = $db->sql_query($sql2);
					if ( !$result2 )
					{
						dbmtnc_user_error("Couldn't get group data!", __LINE__, __FILE__, $sql2);
					}
					if ( !($row2 = $db->sql_fetchrow($result2)) ) // No record found
					{
						if (!$list_open)
						{
							echo("<p class=\"gen\"><b>" . $lang['Updating_mod_membership'] . ":</b></p>\n");
							echo("<font class=\"gen\"><ul>\n");
							$list_open = TRUE;
						}
						echo("<li>" . htmlspecialchars($row['group_name']) . " (" . $row['group_id'] . ") - " . $lang['Moderator_added'] . "</li>\n");
						$sql3 = "INSERT INTO " . USER_GROUP_TABLE . " (group_id, user_id, user_pending)
							SELECT " . (int) $row['group_id'] . ", " . (int) $row['group_moderator'] . ", 0 WHERE 1 = 1"
							. " AND EXISTS (SELECT 1 FROM " . GROUPS_TABLE . " current_group INNER JOIN " . USERS_TABLE . " current_mod"
							. " ON current_mod.user_id = current_group.group_moderator WHERE current_group.group_id = " . (int) $row['group_id']
							. " AND current_group.group_single_user = 0 AND current_group.group_moderator = " . (int) $row['group_moderator']
							. " AND current_mod.user_id <> " . ANONYMOUS . ")"
							. " AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT group_id,user_id FROM " . USER_GROUP_TABLE . ") current_member"
							. " WHERE current_member.group_id = " . (int) $row['group_id'] . " AND current_member.user_id = " . (int) $row['group_moderator'] . ")";
						$result3 = $db->sql_write($sql3);
						if ( !$result3 )
						{
							dbmtnc_user_error("Couldn't insert data in user-group-table!", __LINE__, __FILE__, $sql3);
						}
					}
					elseif ( $row2['user_pending'] == 1 ) // Record found but moderator is pending
					{
						if (!$list_open)
						{
							echo("<p class=\"gen\"><b>" . $lang['Updating_mod_membership'] . ":</b></p>\n");
							echo("<font class=\"gen\"><ul>\n");
							$list_open = TRUE;
						}
						echo("<li>" . htmlspecialchars($row['group_name']) . " (" . $row['group_id'] . ") - " . $lang['Moderator_changed_pending'] . "</li>\n");
						$sql3 = "UPDATE " . USER_GROUP_TABLE . "
							SET user_pending = 0
							WHERE group_id = " . (int) $row['group_id'] . "
								AND user_id = " . (int) $row['group_moderator']
							. " AND user_pending = 1 AND EXISTS (SELECT 1 FROM " . GROUPS_TABLE . " current_group INNER JOIN " . USERS_TABLE . " current_mod"
							. " ON current_mod.user_id = current_group.group_moderator WHERE current_group.group_id = " . (int) $row['group_id']
							. " AND current_group.group_single_user = 0 AND current_group.group_moderator = " . (int) $row['group_moderator']
							. " AND current_mod.user_id <> " . ANONYMOUS . ")";
						$result3 = $db->sql_write($sql3);
						if ( !$result3 )
						{
							dbmtnc_user_error("Couldn't update data in user-group-table!", __LINE__, __FILE__, $sql3);
						}
					}
					$db->sql_freeresult($result2);
				}
				$db->sql_freeresult($result);
				if ($list_open)
				{
					echo("</ul></font>\n");
					$list_open = FALSE;
				}
				else
				{
					echo($lang['Nothing_to_do']);
				}

				// Remove user-group data without a valid user
				echo("<p class=\"gen\"><b>" . $lang['Remove_invalid_user_data'] . "</b></p>\n");
				$sql = "SELECT ug.user_id
					FROM " . USER_GROUP_TABLE . " ug
						LEFT JOIN " . USERS_TABLE . " u ON ug.user_id = u.user_id
					WHERE u.user_id IS NULL
					GROUP BY ug.user_id";
				$result_array = array();
				$result = $db->sql_query($sql);
				if ( !$result )
				{
					dbmtnc_user_error("Couldn't get user and group data!", __LINE__, __FILE__, $sql);
				}
				while ( $row = $db->sql_fetchrow($result) )
				{
					$result_array[] = (int) $row['user_id'];
				}
				$db->sql_freeresult($result);
				if ( count($result_array) )
				{
					$record_list = implode(',', $result_array);
					$sql = "DELETE FROM " . USER_GROUP_TABLE . "
						WHERE user_id IN ($record_list)"
						. " AND NOT EXISTS (SELECT 1 FROM " . USERS_TABLE . " repair_user WHERE repair_user.user_id = " . USER_GROUP_TABLE . ".user_id)";
					$result = $db->sql_write($sql);
					if ( !$result )
					{
						dbmtnc_user_error("Couldn't update user-group data!", __LINE__, __FILE__, $sql);
					}
					$affected_rows = $db->sql_affectedrows();
					if ( $affected_rows == 1 )
					{
						echo("<p class=\"gen\">" . sprintf($lang['Affected_row'], $affected_rows) . "</p>\n");
					}
					elseif ( $affected_rows > 1 )
					{
						echo("<p class=\"gen\">" . sprintf($lang['Affected_rows'], $affected_rows) . "</p>\n");
					}
				}
				else
				{
					echo($lang['Nothing_to_do']);
				}

				// Remove groups without any members: historical deletion is replaced
				// by review. Empty groups may still own permissions or plugin rules.
				$sql = "SELECT g.group_id FROM " . GROUPS_TABLE . " g
					WHERE NOT EXISTS (SELECT 1 FROM " . USER_GROUP_TABLE . " ug WHERE ug.group_id = g.group_id)
					ORDER BY g.group_id";
				$result = $db->sql_query($sql);
				if (!$result) { dbmtnc_user_error("Couldn't check empty groups!", __LINE__, __FILE__, $sql); }
				$empty_groups = array();
				while ($row = $db->sql_fetchrow($result)) { $empty_groups[] = (int) $row['group_id']; }
				$db->sql_freeresult($result);
				if (count($empty_groups))
				{
					echo("<p class=\"gen\"><b>" . $lang['Review_empty_groups'] . "</b><br />" . implode(', ', $empty_groups) . "</p>\n");
				}
				else { echo($lang['Nothing_to_do']); }

				// Remove user-group data without a valid group
				echo("<p class=\"gen\"><b>" . $lang['Remove_invalid_group_data'] . "</b></p>\n");
				$sql = "SELECT ug.group_id
					FROM " . USER_GROUP_TABLE . " ug
						LEFT JOIN " . GROUPS_TABLE . " g ON ug.group_id = g.group_id
					WHERE g.group_id IS NULL
					GROUP BY ug.group_id";
				$result_array = array();
				$result = $db->sql_query($sql);
				if ( !$result )
				{
					dbmtnc_user_error("Couldn't get user and group data!", __LINE__, __FILE__, $sql);
				}
				while ( $row = $db->sql_fetchrow($result) )
				{
					$result_array[] = (int) $row['group_id'];
				}
				$db->sql_freeresult($result);
				if ( count($result_array) )
				{
					$record_list = implode(',', $result_array);
					$sql = "DELETE FROM " . USER_GROUP_TABLE . "
						WHERE group_id IN ($record_list)"
						. " AND NOT EXISTS (SELECT 1 FROM " . GROUPS_TABLE . " current_group WHERE current_group.group_id = " . USER_GROUP_TABLE . ".group_id)";
					$result = $db->sql_write($sql);
					if ( !$result )
					{
						dbmtnc_user_error("Couldn't update user-group data!", __LINE__, __FILE__, $sql);
					}
					$affected_rows = $db->sql_affectedrows();
					if ( $affected_rows == 1 )
					{
						echo("<p class=\"gen\">" . sprintf($lang['Affected_row'], $affected_rows) . "</p>\n");
					}
					elseif ( $affected_rows > 1 )
					{
						echo("<p class=\"gen\">" . sprintf($lang['Affected_rows'], $affected_rows) . "</p>\n");
					}
				}
				else
				{
					echo($lang['Nothing_to_do']);
				}

				// Checking for invalid ranks
				echo("<p class=\"gen\"><b>" . $lang['Checking_ranks'] . "</b></p>\n");
				$sql = "SELECT u.user_id, u.username
					FROM " . USERS_TABLE . " u
						LEFT JOIN " . RANKS_TABLE . " r ON u.user_rank = r.rank_id
					WHERE r.rank_id IS NULL AND u.user_rank <> 0";
				$result_array = array();
				$result = $db->sql_query($sql);
				if ( !$result )
				{
					dbmtnc_user_error("Couldn't get user and rank data!", __LINE__, __FILE__, $sql);
				}
				while ( $row = $db->sql_fetchrow($result) )
				{
					if (!$list_open)
					{
						echo("<p class=\"gen\">" . $lang['Invalid_ranks_found'] . ":</p>\n");
						echo("<font class=\"gen\"><ul>\n");
						$list_open = TRUE;
					}
					echo("<li>" . htmlspecialchars($row['username']) . " (" . $row['user_id'] . ")</li>\n");
					$result_array[] = (int) $row['user_id'];
				}
				$db->sql_freeresult($result);
				if ($list_open)
				{
					echo("</ul></font>\n");
					$list_open = FALSE;
				}
				if ( count($result_array) )
				{
					echo("<p class=\"gen\">" . $lang['Removing_invalid_ranks'] . "</p>\n");
					$record_list = implode(',', $result_array);
					$sql = "UPDATE " . USERS_TABLE . "
						SET user_rank = 0
						WHERE user_id IN ($record_list)"
						. " AND user_rank <> 0 AND NOT EXISTS (SELECT 1 FROM " . RANKS_TABLE . " current_rank WHERE current_rank.rank_id = " . USERS_TABLE . ".user_rank)";
					$result = $db->sql_write($sql);
					if ( !$result )
					{
						dbmtnc_user_error("Couldn't update user data!", __LINE__, __FILE__, $sql);
					}
				}
				else
				{
					echo($lang['Nothing_to_do']);
				}

				// Checking for invalid themes
				echo("<p class=\"gen\"><b>" . $lang['Checking_themes'] . "</b></p>\n");
				$sql = "SELECT u.user_style
					FROM " . USERS_TABLE . " u
						LEFT JOIN " . THEMES_TABLE . " t ON u.user_style = t.themes_id
					WHERE t.themes_id IS NULL AND u.user_id <> " . ANONYMOUS . "
					GROUP BY u.user_style";
				$result_array = array();
				$result = $db->sql_query($sql);
				if ( !$result )
				{
					dbmtnc_user_error("Couldn't get user and theme data!", __LINE__, __FILE__, $sql);
				}
				while ( $row = $db->sql_fetchrow($result) )
				{
					if ( $row['user_style'] == '' )
					{
						// At least one style is NULL, so change these records
						echo("<p class=\"gen\">" . $lang['Updating_users_without_style'] . "</p>\n");
						$sql2 = "UPDATE " . USERS_TABLE . "
							SET user_style = 0
							WHERE user_style IS NULL AND user_id <> " . ANONYMOUS;
						$result2 = $db->sql_write($sql2);
						if ( !$result2 )
						{
							dbmtnc_user_error("Couldn't update themes data!", __LINE__, __FILE__, $sql2);
						}
						$result_array[] = 0;
					}
					else
					{
						$result_array[] = (int) $row['user_style'];
					}
				}
				$db->sql_freeresult($result);
				if ( count($result_array) )
				{
					$new_style = 0;
					$record_list = implode(',', $result_array);
					$sql = "SELECT themes_id
						FROM " . THEMES_TABLE . "
						WHERE themes_id = " . (int) $board_config['default_style'];
					$result = $db->sql_query($sql);
					if ( !$result )
					{
						dbmtnc_user_error("Couldn't get themes data!", __LINE__, __FILE__, $sql);
					}
					if ( $row = $db->sql_fetchrow($result) )
					{
						$new_style = (int) $row['themes_id'];
					}
					else // the default template is not available
					{
						echo("<p class=\"gen\">" . $lang['Default_theme_invalid'] . "</p>\n");
						$db->sql_freeresult($result);
						$sql = "SELECT themes_id
							FROM " . THEMES_TABLE . "
							WHERE themes_id = " . (int) $userdata['user_style'];
						$result = $db->sql_query($sql);
						if ( !$result )
						{
							dbmtnc_user_error("Couldn't get themes data!", __LINE__, __FILE__, $sql);
						}
						if ( $row = $db->sql_fetchrow($result) )
						{
							$new_style = (int) $row['themes_id'];
						}
						else // We never should get to this point. If both the board and the user style is invalid, I don't know how someone should get to this point
						{
							dbmtnc_user_error("Fatal error!");
						}
					}
					$db->sql_freeresult($result);
					echo("<p class=\"gen\">" . sprintf($lang['Updating_themes'], $new_style) . "...</p>\n");
					$sql = "UPDATE " . USERS_TABLE . "
						SET user_style = $new_style
						WHERE user_style IN ($record_list)"
						. " AND user_id <> " . ANONYMOUS
						. " AND EXISTS (SELECT 1 FROM " . THEMES_TABLE . " replacement_style WHERE replacement_style.themes_id = $new_style)"
						. " AND NOT EXISTS (SELECT 1 FROM " . THEMES_TABLE . " current_style WHERE current_style.themes_id = " . USERS_TABLE . ".user_style)";
					$result = $db->sql_write($sql);
					if ( !$result )
					{
						dbmtnc_user_error("Couldn't update themes data!", __LINE__, __FILE__, $sql);
					}
				}
				else
				{
					echo($lang['Nothing_to_do']);
				}

				// Checking for invalid theme names data
				echo("<p class=\"gen\"><b>" . $lang['Checking_theme_names'] . "</b></p>\n");
				$sql = "SELECT tn.themes_id
					FROM " . THEMES_NAME_TABLE . " tn
						LEFT JOIN " . THEMES_TABLE . " t ON tn.themes_id = t.themes_id
					WHERE t.themes_id IS NULL";
				$result_array = array();
				$result = $db->sql_query($sql);
				if ( !$result )
				{
					dbmtnc_user_error("Couldn't get themes data!", __LINE__, __FILE__, $sql);
				}
				while ( $row = $db->sql_fetchrow($result) )
				{
					$result_array[] = (int) $row['themes_id'];
				}
				$db->sql_freeresult($result);
				if ( count($result_array) )
				{
					echo("<p class=\"gen\">" . $lang['Removing_invalid_theme_names'] . "</p>\n");
					$record_list = implode(',', $result_array);
					$sql = "DELETE FROM " . THEMES_NAME_TABLE . "
						WHERE themes_id IN ($record_list)"
						. " AND NOT EXISTS (SELECT 1 FROM " . THEMES_TABLE . " current_style WHERE current_style.themes_id = " . THEMES_NAME_TABLE . ".themes_id)";
					$result = $db->sql_write($sql);
					if ( !$result )
					{
						dbmtnc_user_error("Couldn't update user data!", __LINE__, __FILE__, $sql);
					}
					$affected_rows = $db->sql_affectedrows();
					if ( $affected_rows == 1 )
					{
						echo("<p class=\"gen\">" . sprintf($lang['Affected_row'], $affected_rows) . "</p>\n");
					}
					elseif ( $affected_rows > 1 )
					{
						echo("<p class=\"gen\">" . sprintf($lang['Affected_rows'], $affected_rows) . "</p>\n");
					}
				}
				else
				{
					echo($lang['Nothing_to_do']);
				}

				// Checking for invalid languages
				echo("<p class=\"gen\"><b>" . $lang['Checking_languages'] . "</b></p>\n");
				// Keep byte-distinct values separate even under case/accent-insensitive
				// database collations. NULL also needs an explicit repair predicate.
				$sql = "SELECT DISTINCT user_lang, HEX(user_lang) AS language_bytes
					FROM " . USERS_TABLE . "
					WHERE user_id <> " . ANONYMOUS;
				$result_array = array();
				$result = $db->sql_query($sql);
				if (!$result) { dbmtnc_user_error("Couldn't get user language data!", __LINE__, __FILE__, $sql); }
				while ($row = $db->sql_fetchrow($result))
				{
					// Do not construct filesystem paths from unchecked legacy values.
					if (!is_string($row['user_lang']) || !preg_match('/^[a-z0-9_-]{1,30}$/D', $row['user_lang'])
						|| !is_file($phpbb_root_path . 'language/lang_' . $row['user_lang'] . '/lang_main.' . $phpEx))
					{
						$result_array[] = $row['user_lang'];
					}
				}
				$db->sql_freeresult($result);
				if (count($result_array))
				{
					$sql = "SELECT config_value FROM " . CONFIG_TABLE . " WHERE config_name = 'default_lang'";
					$result = $db->sql_query($sql);
					if (!$result) { dbmtnc_user_error("Couldn't get language data!", __LINE__, __FILE__, $sql); }
					$row = $db->sql_fetchrow($result);
					$db->sql_freeresult($result);
					if (!$row) { dbmtnc_user_error("Couldn't get config data! Please check your configuration table."); }
					$board_language = $row['config_value'];
					$default_lang = null;
					foreach (array($board_language, $userdata['user_lang'], 'english') as $candidate)
					{
						if (is_string($candidate) && preg_match('/^[a-z0-9_-]{1,30}$/D', $candidate)
							&& is_file($phpbb_root_path . 'language/lang_' . $candidate . '/lang_main.' . $phpEx))
						{
							$default_lang = $candidate;
							break;
						}
					}
					// Never replace preferences with another missing language pack.
					if ($default_lang === null) { dbmtnc_user_error($lang['English_language_invalid']); }
					if ($default_lang !== $board_language) { echo('<p class="gen">' . $lang['Default_language_invalid'] . '</p>'); }
					echo('<p class="gen">' . $lang['Invalid_languages_found'] . ':</p><ul class="gen">');
					$list_open = TRUE;
					foreach ($result_array as $invalid_language)
					{
						$language_match = $invalid_language === null ? 'user_lang IS NULL'
							: "HEX(user_lang) = HEX('" . $db->sql_escape($invalid_language) . "')";
						// Compare current bytes, not a collation-equivalent valid value;
						// an independently corrected preference must remain untouched.
						$sql = "UPDATE " . USERS_TABLE . " SET user_lang = '" . $db->sql_escape($default_lang) . "'"
							. " WHERE " . $language_match . " AND user_id <> " . ANONYMOUS;
						$result = $db->sql_write($sql);
						if (!$result) { dbmtnc_user_error("Couldn't update user language data!", __LINE__, __FILE__, $sql); }
						if ($db->sql_affectedrows() > 0)
						{
							echo('<li>' . sprintf($lang['Changing_language'],
								htmlspecialchars((string) $invalid_language, ENT_QUOTES, 'UTF-8'),
								htmlspecialchars($default_lang, ENT_QUOTES, 'UTF-8')) . "</li>\n");
						}
					}
					echo("</ul>\n");
					$list_open = FALSE;
				}
				else
				{
					echo($lang['Nothing_to_do']);
				}

				// Remove ban data without a valid user
				echo("<p class=\"gen\"><b>" . $lang['Remove_invalid_ban_data'] . "</b></p>\n");
				$sql = "SELECT b.ban_userid
					FROM " . BANLIST_TABLE . " b
						LEFT JOIN " . USERS_TABLE . " u ON b.ban_userid = u.user_id
					WHERE u.user_id IS NULL
						AND b.ban_userid <> 0
						AND b.ban_userid IS NOT NULL";
				$result_array = array();
				$result = $db->sql_query($sql);
				if ( !$result )
				{
					dbmtnc_user_error("Couldn't get banlist and user data!", __LINE__, __FILE__, $sql);
				}
				while ( $row = $db->sql_fetchrow($result) )
				{
					$result_array[] = (int) $row['ban_userid'];
				}
				$db->sql_freeresult($result);
				if ( count($result_array) )
				{
					$record_list = implode(',', $result_array);
					$sql = "DELETE FROM " . BANLIST_TABLE . "
						WHERE ban_userid IN ($record_list)"
						. " AND NOT EXISTS (SELECT 1 FROM " . USERS_TABLE . " repair_user WHERE repair_user.user_id = " . BANLIST_TABLE . ".ban_userid)"
						. " AND (ban_ip IS NULL OR ban_ip = '') AND (ban_email IS NULL OR ban_email = '')";
					$result = $db->sql_write($sql);
					if ( !$result )
					{
						dbmtnc_user_error("Couldn't update ban data!", __LINE__, __FILE__, $sql);
					}
					$affected_rows = $db->sql_affectedrows();
					// A combined ban can still enforce an IP/email restriction. Clear
					// only its dangling user reference, never those independent rules.
					$sql = "UPDATE " . BANLIST_TABLE . " SET ban_userid = 0 WHERE ban_userid IN ($record_list)"
						. " AND NOT EXISTS (SELECT 1 FROM " . USERS_TABLE . " repair_user WHERE repair_user.user_id = " . BANLIST_TABLE . ".ban_userid)"
						. " AND ((ban_ip IS NOT NULL AND ban_ip <> '') OR (ban_email IS NOT NULL AND ban_email <> ''))";
					$db->sql_write($sql);
					$affected_rows += $db->sql_affectedrows();
					if ( $affected_rows == 1 )
					{
						echo("<p class=\"gen\">" . sprintf($lang['Affected_row'], $affected_rows) . "</p>\n");
					}
					elseif ( $affected_rows > 1 )
					{
						echo("<p class=\"gen\">" . sprintf($lang['Affected_rows'], $affected_rows) . "</p>\n");
					}
				}
				else
				{
					echo($lang['Nothing_to_do']);
				}

				// Remove session key data without valid user
				if ($phpbb_version[0] == 0 && $phpbb_version[1] >= 18)
				{
					echo("<p class=\"gen\"><b>" . $lang['Remove_invalid_session_keys'] . "</b></p>\n");
					// key_id alone is not an identity: the primary key also contains
					// user_id. Qualify each current row directly, so another user's
					// identical key or a concurrently restored account survives.
					$sql = "DELETE FROM " . SESSIONS_KEYS_TABLE
						. " WHERE (NOT EXISTS (SELECT 1 FROM " . USERS_TABLE . " key_user"
						. " WHERE key_user.user_id = " . SESSIONS_KEYS_TABLE . ".user_id)"
						. " OR user_id = " . ANONYMOUS . " OR last_login > " . time() . ")";
					$result = $db->sql_write($sql);
					if (!$result) { dbmtnc_user_error("Couldn't update session key data!", __LINE__, __FILE__, $sql); }
					$affected_rows = $db->sql_affectedrows();
					if ($affected_rows > 0)
					{
						echo('<p class="gen">' . sprintf($lang[$affected_rows == 1 ? 'Affected_row' : 'Affected_rows'], $affected_rows) . "</p>\n");
					}
					else
					{
						echo($lang['Nothing_to_do']);
					}
				}

				$db->actor();
				}
				catch (PhpbbAclException $error) { $user_repair_error = $error->getMessage(); }
				catch (Exception $error) { $user_repair_error = $lang['Maintenance_user_failed']; }
				catch (Throwable $error) { $user_repair_error = $lang['Maintenance_user_failed']; }
				finally { if ($user_repair_scope !== null) { dbmtnc_user_end($db, $user_repair_scope); } }
				if ($user_repair_error !== '') { throw_error($user_repair_error); }
				break;
			case 'check_post': // Checks post data
				echo("<h1>" . $lang['Checking_post_tables'] . "</h1>\n");
				// Each phase owns the shared writer; never change board availability.

				// Repair missing authors against current source and ACP authority.
				echo('<p class="gen"><b>' . $lang['Checking_invalid_posters'] . ' / ' . $lang['Checking_invalid_topic_posters'] . '</b></p>');
				require_once($phpbb_root_path . 'includes/functions_maintenance_authors.' . $phpEx);
				$author_repair_error = '';
				try { $author_repair = dbmtnc_repair_authors($db, $_POST); }
				catch (PhpbbAclException $error) { $author_repair_error = $error->getMessage(); }
				catch (Exception $error) { $author_repair_error = $lang['Maintenance_author_failed']; }
				catch (Throwable $error) { $author_repair_error = $lang['Maintenance_author_failed']; }
				if ($author_repair_error !== '') { throw_error($author_repair_error); }
				echo('<p class="gen">' . sprintf($lang['Maintenance_author_summary'], $author_repair['posts'], $author_repair['topics'], $author_repair['skipped']) . '</p>');

				// Check for forums with invalid categories: handled by the topology worker below.

				// Check for posts without a text and topics without a post under one owner.
				require_once($phpbb_root_path . 'includes/functions_maintenance_cleanup.' . $phpEx);
				$cleanup_error = '';
				try { $parent_cleanup = dbmtnc_cleanup_structure($db, $_POST, 'parents'); }
				catch (PhpbbAclException $error) { $cleanup_error = $error->getMessage(); }
				catch (Exception $error) { $cleanup_error = $lang['Maintenance_cleanup_failed']; }
				catch (Throwable $error) { $cleanup_error = $lang['Maintenance_cleanup_failed']; }
				if ($cleanup_error !== '') { throw_error($cleanup_error); }
				echo('<p class="gen">' . sprintf($lang['Maintenance_cleanup_parent_summary'], $parent_cleanup['steps']['posts'], $parent_cleanup['steps']['topics'], $parent_cleanup['skipped']) . '</p>');

				// Check for topics with invalid forum, orphan posts and mismatched routing.
				echo('<p class="gen"><b>' . $lang['Maintenance_topology_heading'] . '</b></p>');
				require_once($phpbb_root_path . 'includes/functions_maintenance_topology.' . $phpEx);
				$topology_error = '';
				try { $topology = dbmtnc_repair_topology($db, $_POST); }
				catch (PhpbbAclException $error) { $topology_error = $error->getMessage(); }
				catch (Exception $error) { $topology_error = $lang['Maintenance_topology_failed']; }
				catch (Throwable $error) { $topology_error = $lang['Maintenance_topology_failed']; }
				if ($topology_error !== '') { throw_error($topology_error); }
				echo('<p class="gen">' . sprintf($lang['Maintenance_topology_summary'], $topology['forums'], $topology['topics'], $topology['posts'], $topology['routes'], $topology['skipped'], count($topology['synchronization']['review'])) . '</p>');

				// Check for texts without a post: current-source, retryable recovery.
				echo('<p class="gen"><b>' . $lang['Checking_texts_wo_post'] . '</b></p>');
				require_once($phpbb_root_path . 'includes/functions_maintenance_recovery.' . $phpEx);
				try { $orphan_recovery = dbmtnc_recover_orphan_text($db, $_POST); }
				catch (PhpbbAclException $error) { throw_error($error->getMessage(), __LINE__, __FILE__); break; }
				catch (Exception $error) { throw_error($lang['Maintenance_recovery_failed']); break; }
				catch (Throwable $error) { throw_error($lang['Maintenance_recovery_failed']); break; }
				echo('<p class="gen">' . sprintf($lang['Maintenance_recovery_summary'], $orphan_recovery['restored'], $orphan_recovery['skipped']) . '</p>');

				// Check moved topics, pruning, subscriptions and ACL references.
				$cleanup_error = '';
				try { $reference_cleanup = dbmtnc_cleanup_structure($db, $_POST, 'references'); }
				catch (PhpbbAclException $error) { $cleanup_error = $error->getMessage(); }
				catch (Exception $error) { $cleanup_error = $lang['Maintenance_cleanup_failed']; }
				catch (Throwable $error) { $cleanup_error = $lang['Maintenance_cleanup_failed']; }
				if ($cleanup_error !== '') { throw_error($cleanup_error); }
				echo('<p class="gen">' . sprintf($lang['Maintenance_cleanup_reference_summary'],
					$reference_cleanup['steps']['redirects'], $reference_cleanup['steps']['moved'],
					$reference_cleanup['steps']['prune_orphans'] + $reference_cleanup['steps']['prune_duplicates'],
					$reference_cleanup['steps']['prune_disabled'], $reference_cleanup['steps']['watch'],
					$reference_cleanup['steps']['acl'], $reference_cleanup['skipped']) . '</p>');
				if ($reference_cleanup['conflicts'])
				{
					echo('<p class="gen">' . sprintf($lang['Maintenance_cleanup_prune_conflicts'], implode(', ', $reference_cleanup['conflicts'])) . '</p>');
				}
				echo('<p class="gen">' . sprintf($lang['Maintenance_cleanup_complete'], count($reference_cleanup['synchronization']['review'])) . '</p>');
				break;
			case 'check_vote': // Guarded poll maintenance; source repair is reported.
				echo('<h1>' . $lang['Checking_vote_tables'] . '</h1>');
				require_once($phpbb_root_path . 'includes/functions_maintenance_polls.' . $phpEx);
				$poll_maintenance_error = '';
				try { $poll_maintenance = dbmtnc_maintain_polls($db, $_POST); }
				catch (PhpbbAclException $error) { $poll_maintenance_error = $error->getMessage(); }
				catch (Exception $error) { $poll_maintenance_error = $lang['Maintenance_poll_failed']; }
				catch (Throwable $error) { $poll_maintenance_error = $lang['Maintenance_poll_failed']; }
				if ($poll_maintenance_error !== '') { throw_error($poll_maintenance_error); }
				echo('<p class="gen">' . sprintf($lang['Maintenance_poll_summary'],
					$poll_maintenance['polls_removed'], $poll_maintenance['options_removed'], $poll_maintenance['voters_removed'],
					$poll_maintenance['voters_anonymized'], $poll_maintenance['topics_updated']) . '</p>');
				if ($poll_maintenance['review_count'] > 0)
				{
					echo('<p class="gen">' . sprintf($lang['Maintenance_poll_review'], $poll_maintenance['review_count']) . '</p><ul class="gen">');
					foreach ($poll_maintenance['review'] as $poll)
					{
						echo('<li>' . sprintf($lang['Maintenance_poll_review_item'], (int)$poll['vote_id'], (int)$poll['topic_id'], phpbb_admin_html($poll['vote_text'])) . '</li>');
					}
					echo('</ul>');
				}
				break;
			case 'check_pm': // Check private messages
				require_once($phpbb_root_path . 'includes/functions_maintenance_pm.' . $phpEx);
				echo('<h1>' . $lang['Checking_pm_tables'] . '</h1>');
				$pm_repair_error = '';
				try { $pm_repairs = dbmtnc_repair_pm($db, $_POST); }
				catch (PhpbbAclException $error) { $pm_repair_error = $error->getMessage(); }
				catch (Exception $error) { $pm_repair_error = $lang['Maintenance_pm_repair_failed']; }
				catch (Throwable $error) { $pm_repair_error = $lang['Maintenance_pm_repair_failed']; }
				if ($pm_repair_error !== '')
				{
					throw_error($pm_repair_error . ($pm_repair_error === $lang['Maintenance_pm_repair_failed'] ? '' : '<br />' . $lang['Maintenance_pm_repair_failed']));
				}
				foreach ($pm_repairs as $pm_mode => $pm_changed)
				{
					echo('<p class="gen">' . sprintf($lang['Maintenance_pm_repair_' . $pm_mode], $pm_changed) . '</p>');
				}

				// Synchronize both PM counters from current mailbox state.
				require_once($phpbb_root_path . 'includes/functions_maintenance_pm.' . $phpEx);
				$pm_counter_error = '';
				try { $pm_counter_changed = dbmtnc_synchronize_pm_counters($db, $_POST); }
				catch (PhpbbAclException $error) { $pm_counter_error = $error->getMessage(); }
				catch (Exception $error) { $pm_counter_error = $lang['Maintenance_pm_counter_failed']; }
				catch (Throwable $error) { $pm_counter_error = $lang['Maintenance_pm_counter_failed']; }
				if ($pm_counter_error !== '') { throw_error($pm_counter_error); }
				echo('<p class="gen">' . sprintf($lang['Maintenance_pm_counter_summary'], $pm_counter_changed) . '</p>');
				break;
			case 'check_config': // Restore missing settings without changing existing values.
				echo('<h1>' . $lang['Checking_config_table'] . '</h1>');
				require_once($phpbb_root_path . 'includes/functions_maintenance_config.' . $phpEx);
				try { $config_recovery = dbmtnc_recover_config($db, $_POST, $default_config); }
				catch (\PhpbbAclException $error) { throw_error($error->getMessage()); break; }
				catch (\Exception $error) { throw_error($lang['Maintenance_config_failed']); break; }
				catch (\Throwable $error) { throw_error($lang['Maintenance_config_failed']); break; }
				if ($config_recovery['restored'])
				{
					echo('<p class="gen">' . $lang['Restoring_config'] . ':</p><ul>');
					foreach ($config_recovery['restored'] as $config_key) { echo('<li>' . htmlspecialchars($config_key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>'); }
					echo('</ul>');
				}
				else { echo($lang['Nothing_to_do']); }
				if ($config_recovery['version_unknown']) { echo('<p class="gen"><b>' . $lang['Maintenance_config_version_unknown'] . '</b></p>'); }
				break;
			case 'check_search_wordmatch': // Check search word match data
			case 'check_search_wordlist': // Check search word list data
				echo('<h1>' . $lang[$function === 'check_search_wordlist' ? 'Checking_search_wordlist_tables' : 'Checking_search_wordmatch_tables'] . '</h1>');
				require_once($phpbb_root_path . 'includes/functions_maintenance_search.' . $phpEx);
				try { dbmtnc_search_cleanup_request($function, $_POST); }
				catch (PhpbbAclException $error) { throw_error($error->getMessage()); }
				$search_cleanup_error = '';
				try { $affected_rows = dbmtnc_cleanup_search($db, $function, $_POST); }
				catch (PhpbbAclException $error) { $search_cleanup_error = $error->getMessage(); }
				catch (Exception $error) { $search_cleanup_error = $lang['Maintenance_search_cleanup_failed']; }
				catch (Throwable $error) { $search_cleanup_error = $lang['Maintenance_search_cleanup_failed']; }
				if ($search_cleanup_error !== '') { throw_error($search_cleanup_error); }
				if ($affected_rows > 0)
				{
					echo('<p class="gen">' . sprintf($lang[$affected_rows === 1 ? 'Affected_row' : 'Affected_rows'], $affected_rows) . '</p>');
				}
				else { echo($lang['Nothing_to_do']); }
				break;
			case 'rebuild_search_index':
			case 'proceed_rebuilding':
			case 'perform_rebuild':
				require_once($phpbb_root_path . 'includes/functions_maintenance_rebuild.' . $phpEx);
				$rebuild_mode = $function === 'perform_rebuild' ? 'step' : ($function === 'proceed_rebuilding' ? 'resume' : 'start');
				$rebuild_request = $rebuild_mode === 'step' ? $_GET : $_POST;
				$rebuild_error = '';
				try
				{
					$rebuild_language = in_array($board_config['default_lang'], array('english','german'), true) ? $board_config['default_lang'] : 'english';
					$rebuild_stopwords = @file($phpbb_root_path . 'language/lang_' . $rebuild_language . '/search_stopwords.txt');
					$rebuild_synonyms = @file($phpbb_root_path . 'language/lang_' . $rebuild_language . '/search_synonyms.txt');
					$rebuild_job = dbmtnc_rebuild_batch($db, $rebuild_mode, $rebuild_request,
						is_array($rebuild_stopwords) ? $rebuild_stopwords : array(), is_array($rebuild_synonyms) ? $rebuild_synonyms : array());
				}
				catch (PhpbbAclException $error) { $rebuild_error = $error->getMessage(); }
				catch (Exception $error) { $rebuild_error = $lang['Maintenance_rebuild_failed']; }
				catch (Throwable $error) { $rebuild_error = $lang['Maintenance_rebuild_failed']; }
				$rebuild_url = '';
				if ($rebuild_error === '' && $rebuild_job['state']['s'] !== 'done')
				{
					$rebuild_url = dbmtnc_rebuild_url($rebuild_job['state']);
					if ($rebuild_mode === 'step') { $template->assign_vars(array('META' => '<meta http-equiv="refresh" content="1;url=' . $rebuild_url . '" />')); }
				}
				if ($rebuild_mode === 'step') { include('./page_header_admin.' . $phpEx); }
				echo('<h1>' . $lang['Rebuilding_search_index'] . '</h1>');
				if ($rebuild_error !== '')
				{
					echo('<p class="gen">' . phpbb_admin_html($rebuild_error) . '</p><p class="gen">' . $lang['Maintenance_rebuild_resume_help'] . '</p>');
					break;
				}
				if ($rebuild_job['state']['s'] === 'done') { echo('<p class="gen">' . $lang['Indexing_finished'] . '</p>'); }
				else
				{
					$rebuild_state = $rebuild_job['state'];
					$rebuild_message = in_array($rebuild_state['s'],array('finish','release'),true)
						? $lang['Maintenance_rebuild_finishing']
						: sprintf($lang['Maintenance_rebuild_checkpoint'], $rebuild_state['p'], $rebuild_state['e']);
					echo('<p class="gen">' . $rebuild_message . '</p><p class="gen"><a href="' . $rebuild_url . '">' . $lang['Click_or_wait_to_proceed'] . '</a></p>');
				}
				break;
			case 'synchronize_post': // Synchronize post data
			case 'synchronize_post_direct': // Session-bound continuation
				echo('<h1>' . $lang['Synchronize_posts'] . '</h1>');
				require_once($phpbb_root_path . 'includes/functions_maintenance_posts.' . $phpEx);
				$post_sync_direct = $function === 'synchronize_post_direct';
				$post_sync_request = $post_sync_direct ? $_GET : $_POST;
				// Old signed links remain valid for recounting only, never for reopening.
				try { dbmtnc_post_sync_request($function, $post_sync_request); }
				catch (PhpbbAclException $error) { throw_error($error->getMessage()); }
				$post_sync_error = '';
				try { $post_sync_result = dbmtnc_synchronize_posts($db, $function, $post_sync_request); }
				catch (PhpbbAclException $error) { $post_sync_error = $error->getMessage(); }
				catch (Exception $error) { $post_sync_error = $lang['Maintenance_post_sync_failed']; }
				catch (Throwable $error) { $post_sync_error = $lang['Maintenance_post_sync_failed']; }
				if ($post_sync_error !== '') { throw_error($post_sync_error); }
				foreach (array('topics' => 'Synchronize_topic_data', 'redirects' => 'Synchronize_moved_topic_data', 'forums' => 'Synchronizing_forums') as $post_sync_kind => $post_sync_title)
				{
					echo('<p class="gen"><b>' . $lang[$post_sync_title] . '</b></p>');
					if (!$post_sync_result[$post_sync_kind]) { echo($lang['Nothing_to_do']); continue; }
					echo('<ul class="gen">');
					foreach ($post_sync_result[$post_sync_kind] as $post_sync_row)
					{
						echo('<li>' . (int) $post_sync_row['id'] . ': ' . htmlspecialchars($post_sync_row['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>');
					}
					echo('</ul>');
				}
				if ($post_sync_result['review'])
				{
					echo('<p class="gen">' . sprintf($lang['Maintenance_post_sync_review'], implode(', ', array_map('intval', $post_sync_result['review']))) . '</p>');
				}
				break;
			case 'synchronize_user': // Synchronize personal post counters
				echo('<h1>' . $lang['Synchronize_post_counters'] . '</h1>');
				require_once($phpbb_root_path . 'includes/functions_maintenance_posts.' . $phpEx);
				try { dbmtnc_post_sync_request('synchronize_user', $_POST); }
				catch (PhpbbAclException $error) { throw_error($error->getMessage()); }
				$user_sync_error = '';
				try { $user_sync_result = dbmtnc_synchronize_user_counts($db, $_POST); }
				catch (PhpbbAclException $error) { $user_sync_error = $error->getMessage(); }
				catch (Exception $error) { $user_sync_error = $lang['Maintenance_user_sync_failed']; }
				catch (Throwable $error) { $user_sync_error = $lang['Maintenance_user_sync_failed']; }
				if ($user_sync_error !== '') { throw_error($user_sync_error); }
				if (!$user_sync_result['changed'] && !$user_sync_result['skipped']) { echo($lang['Nothing_to_do']); }
				else
				{
					echo('<ul class="gen">');
					foreach ($user_sync_result['changed'] as $user_sync_row)
					{
						echo('<li>' . sprintf($lang['Maintenance_user_counter_changed'], htmlspecialchars($user_sync_row['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), (int) $user_sync_row['user_id'], (int) $user_sync_row['user_posts']) . '</li>');
					}
					foreach ($user_sync_result['skipped'] as $user_sync_id)
					{
						echo('<li>' . sprintf($lang['Maintenance_user_counter_skipped'], $user_sync_id) . '</li>');
					}
					echo('</ul>');
				}
				break;
			case 'synchronize_mod_state': // Synchronize moderator status
				echo("<h1>" . $lang['Synchronize_moderators'] . "</h1>\n");
				require_once($phpbb_root_path . 'includes/functions_maintenance_roles.' . $phpEx);
				$role_sync_error = '';
				try
				{
					$role_sync_result = dbmtnc_synchronize_mod_state($db, $_POST);
				}
				catch (PhpbbAclException $error) { $role_sync_error = $error->getMessage(); }
				catch (Exception $error) { $role_sync_error = $lang['Maintenance_role_sync_failed']; }
				catch (Throwable $error) { $role_sync_error = $lang['Maintenance_role_sync_failed']; }
				if ($role_sync_error !== '') { throw_error($role_sync_error); }
				if (!$role_sync_result['changed'] && !$role_sync_result['skipped'])
				{
					echo($lang['Nothing_to_do']);
				}
				else
				{
					echo('<ul class="gen">');
					foreach ($role_sync_result['changed'] as $role_user)
					{
						echo('<li>' . sprintf($lang['Changing_moderator_status'], htmlspecialchars($role_user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $role_user['user_id']) . '</li>');
					}
					foreach ($role_sync_result['skipped'] as $role_user)
					{
						echo('<li>' . sprintf($lang['Maintenance_role_sync_skipped'], $role_user['user_id']) . '</li>');
					}
					echo('</ul>');
				}
				break;
			case 'reset_date': // Guarded repair without changing board availability.
				echo('<h1>' . $lang['Resetting_future_post_dates'] . '</h1>');
				require_once($phpbb_root_path . 'includes/functions_maintenance_dates.' . $phpEx);
				try { $date_reset = dbmtnc_reset_dates($db, $_POST); }
				catch (PhpbbAclException $error) { throw_error($error->getMessage(), __LINE__, __FILE__); break; }
				echo('<p class="gen">' . sprintf($lang['Maintenance_date_reset_summary'], $date_reset['posts'], $date_reset['pm'], $date_reset['email'], $date_reset['login'], $date_reset['search']) . '</p>');
				if ($date_reset['deferred']) { echo('<p class="gen">' . sprintf($lang['Maintenance_date_reset_deferred'], $date_reset['deferred']) . '</p>'); }
				break;
			case 'reset_sessions': // Preserve the current ACP session; never reconstruct it.
				echo('<h1>' . $lang['Resetting_sessions'] . '</h1>');
				require_once($phpbb_root_path . 'includes/functions_maintenance_sessions.' . $phpEx);
				try { $session_reset = dbmtnc_reset_sessions($db, $_POST); }
				catch (PhpbbAclException $error) { throw_error($error->getMessage(), __LINE__, __FILE__); break; }
				echo('<p class="gen">' . sprintf($lang['Maintenance_session_reset_summary'], $session_reset['sessions'], $session_reset['searches']) . '</p>');
				break;
			case 'check_db': // Check database
				echo("<h1>" . $lang['Checking_db'] . "</h1>\n");
				if ( !check_mysql_version() )
				{
					echo("<p class=\"gen\">" . $lang['Old_MySQL_Version'] . "</p>\n");
					break;
				}
				require_once($phpbb_root_path . 'includes/functions_maintenance_tables.' . $phpEx);
				$table_scope = null; $table_error = '';
				try
				{
					$table_scope = dbmtnc_table_begin($db, $_POST);
					echo("<p class=\"gen\"><b>" . $lang['Checking_tables'] . ":</b></p>\n");
					echo("<font class=\"gen\"><ul>\n");
					$list_open = TRUE;

					$maintenance_complete = count($tables) > 0;
					for ($i = 0; $i < count($tables); $i++)
					{
						if (!dbmtnc_table_maintenance($table_prefix . $tables[$i], 'CHECK'))
						{
							$maintenance_complete = false;
						}
					}
					echo("</ul></font>\n");
					$list_open = FALSE;
					if (!$maintenance_complete)
					{
						echo('<p class="gen"><b>' . $lang['Maintenance_incomplete'] . "</b></p>\n");
					}
				}
				catch (\PhpbbAclException $error) { $table_error = $error->getMessage(); }
				catch (\Exception $error) { $table_error = $lang['Maintenance_query_failed']; }
				catch (\Throwable $error) { $table_error = $lang['Maintenance_query_failed']; }
				finally { if ($table_scope !== null) { dbmtnc_table_end($db, $table_scope); } }
				if ($table_error !== '') { throw_error($table_error); }
				break;
			case 'repair_db': // Repair database
				echo("<h1>" . $lang['Repairing_db'] . "</h1>\n");
				if ( !check_mysql_version() )
				{
					echo("<p class=\"gen\">" . $lang['Old_MySQL_Version'] . "</p>\n");
					break;
				}
				require_once($phpbb_root_path . 'includes/functions_maintenance_tables.' . $phpEx);
				$table_scope = null; $table_error = '';
				try
				{
					$table_scope = dbmtnc_table_begin($db, $_POST);
					echo("<p class=\"gen\"><b>" . $lang['Repairing_tables'] . ":</b></p>\n");
					echo("<font class=\"gen\"><ul>\n");
					$list_open = TRUE;

					$maintenance_complete = count($tables) > 0;
					for ($i = 0; $i < count($tables); $i++)
					{
						if (!dbmtnc_table_maintenance($table_prefix . $tables[$i], 'REPAIR'))
						{
							$maintenance_complete = false;
						}
					}
					echo("</ul></font>\n");
					$list_open = FALSE;
					if (!$maintenance_complete)
					{
						echo('<p class="gen"><b>' . $lang['Maintenance_incomplete'] . "</b></p>\n");
					}
				}
				catch (\PhpbbAclException $error) { $table_error = $error->getMessage(); }
				catch (\Exception $error) { $table_error = $lang['Maintenance_query_failed']; }
				catch (\Throwable $error) { $table_error = $lang['Maintenance_query_failed']; }
				finally { if ($table_scope !== null) { dbmtnc_table_end($db, $table_scope); } }
				if ($table_error !== '') { throw_error($table_error); }
				break;
			case 'optimize_db': // Optimize database
				echo("<h1>" . $lang['Optimizing_db'] . "</h1>\n");
				if ( !check_mysql_version() )
				{
					echo("<p class=\"gen\">" . $lang['Old_MySQL_Version'] . "</p>\n");
					break;
				}
				require_once($phpbb_root_path . 'includes/functions_maintenance_tables.' . $phpEx);
				$table_scope = null; $table_error = '';
				try
				{
					$table_scope = dbmtnc_table_begin($db, $_POST);
					$old_stat = get_table_statistic();
					$optimization_complete = true;
					echo("<p class=\"gen\"><b>" . $lang['Optimizing_tables'] . ":</b></p>\n");
					echo("<font class=\"gen\"><ul>\n");
					$list_open = TRUE;

					for($i = 0; $i < count($tables); $i++)
					{
						$tablename = $table_prefix . $tables[$i];
						if (!dbmtnc_optimize_table($tablename))
						{
							$optimization_complete = false;
						}
					}
					echo("</ul></font>\n");
					$list_open = FALSE;
					$new_stat = get_table_statistic();
					$reduction_absolute = $old_stat['core']['size'] - $new_stat['core']['size'];
					$reduction_percent = $old_stat['core']['size'] > 0 ? sprintf('%01.2f%%', ($reduction_absolute / $old_stat['core']['size']) * 100) : $lang['Optimization_percent_unavailable'];
					if (!$optimization_complete)
					{
						echo('<p class="gen"><b>' . $lang['Optimization_incomplete'] . "</b></p>\n");
					}
					echo("<p class=\"gen\">" . sprintf($lang['Optimization_statistic'], convert_bytes($old_stat['core']['size']), convert_bytes($new_stat['core']['size']), convert_bytes($reduction_absolute), $reduction_percent) . "</p>\n");
				}
				catch (\PhpbbAclException $error) { $table_error = $error->getMessage(); }
				catch (\Exception $error) { $table_error = $lang['Maintenance_query_failed']; }
				catch (\Throwable $error) { $table_error = $lang['Maintenance_query_failed']; }
				finally { if ($table_scope !== null) { dbmtnc_table_end($db, $table_scope); } }
				if ($table_error !== '') { throw_error($table_error); }
				break;
			case 'reset_auto_increment': // Reset autoincrement values
				echo("<h1>" . $lang['Reset_ai'] . "</h1>\n");
				require_once($phpbb_root_path . 'includes/functions_maintenance_tables.' . $phpEx);
				$auto_repair_scope = null; $auto_repair_error = '';
				try
				{
					$auto_repair_scope = dbmtnc_table_begin($db, $_POST);
					echo("<p class=\"gen\"><b>" . $lang['Reset_ai'] . "...</b></p>\n");
					echo("<font class=\"gen\"><ul>\n");

					set_autoincrement(BANLIST_TABLE, 'ban_id', 8);
					set_autoincrement(CATEGORIES_TABLE, 'cat_id', 8);
					set_autoincrement(DISALLOW_TABLE, 'disallow_id', 8);
					set_autoincrement(PRUNE_TABLE, 'prune_id', 8);
					set_autoincrement(GROUPS_TABLE, 'group_id', 8, FALSE);
					set_autoincrement(POSTS_TABLE, 'post_id', 8);
					set_autoincrement(PRIVMSGS_TABLE, 'privmsgs_id', 8);
					set_autoincrement(RANKS_TABLE, 'rank_id', 5);
					set_autoincrement(SEARCH_WORD_TABLE, 'word_id', 8);
					set_autoincrement(SMILIES_TABLE, 'smilies_id', 5);
					set_autoincrement(THEMES_TABLE, 'themes_id', 8);
					set_autoincrement(TOPICS_TABLE, 'topic_id', 8);
					set_autoincrement(VOTE_DESC_TABLE, 'vote_id', 8);
					set_autoincrement(WORDS_TABLE, 'word_id', 8);

					echo("</ul></font>\n");
					$list_open = FALSE;

				}
				catch (\PhpbbAclException $error) { $auto_repair_error = $error->getMessage(); }
				catch (\Exception $error) { $auto_repair_error = $lang['Ai_repair_failed']; }
				catch (\Throwable $error) { $auto_repair_error = $lang['Ai_repair_failed']; }
				finally { if ($auto_repair_scope !== null) { dbmtnc_table_end($db, $auto_repair_scope); } }
				if ($auto_repair_error !== '') { throw_error($auto_repair_error); }
				break;
			case 'session_storage': // Preserve sessions in persistent InnoDB storage
				require_once($phpbb_root_path . 'includes/functions_maintenance_session_storage.' . $phpEx);
				echo('<h1>' . $lang['Session_storage_title'] . "</h1>\n");
				$storage_error = '';
				try { $storage_outcome = dbmtnc_convert_session_storage($db, $_POST); }
				catch (\PhpbbAclException $error) { $storage_error = $error->getMessage(); }
				catch (\Exception $error) { $storage_error = $lang['Session_storage_failed']; }
				catch (\Throwable $error) { $storage_error = $lang['Session_storage_failed']; }
				if ($storage_error !== '') { throw_error($storage_error); }
				echo('<p class="gen">' . $lang[$storage_outcome] . "</p>\n");
				break;
			case 'unlock_db': // Unlock the database
				echo("<h1>" . $lang['Unlocking_db'] . "</h1>\n");
				lock_db(TRUE, TRUE, TRUE);
				break;
			default:
				echo("<p class=\"gen\">" . $lang['function_unknown'] . "</p>\n");
		}
		echo("<p class=\"gen\"><a href=\"" . append_sid("admin_db_maintenance.$phpEx") . "\">" . $lang['Back_to_DB_Maintenance'] . "</a></p>\n");
		// Send Information about processing time
		echo('<p class="gensmall">' . sprintf($lang['Processing_time'], getmicrotime() - $timer) . '</p>');
		ob_start();
		break;
	default:
		$template->set_filenames(array(
			"body" => "admin/dbmtnc_list_body.tpl")
		);

		$template->assign_vars(array(
			"L_DBMTNC_TITLE" => $lang['DB_Maintenance'],
			"L_DBMTNC_TEXT" => $lang['DB_Maintenance_Description'],
			"L_FUNCTION" => $lang['Function'],
			"L_FUNCTION_DESCRIPTION" => $lang['Function_Description'])
		);

		//
		// OK, let's list the functions
		//

		for($i = 0; $i < count($mtnc); $i++)
		{
			if ( count($mtnc[$i]) && check_condition($mtnc[$i][4]))
			{
				if ($mtnc[$i][0] == '--')
				{
					$template->assign_block_vars('function.spaceRow', array());
				}
				else
				{
					$template->assign_block_vars('function', array(
						'FUNCTION_NAME' => $mtnc[$i][1],
						'FUNCTION_DESCRIPTION' => $mtnc[$i][2],

						'U_FUNCTION_URL' => append_sid("admin_db_maintenance.$phpEx?mode=start&function=" . $mtnc[$i][0]))
					);
				}
			};
		}

		$template->pparse("body");
		break;
}

include('./page_footer_admin.'.$phpEx);
?>
