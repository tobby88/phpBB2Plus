<?php
/***************************************************************************
 *                           functions_dbmtnc.php
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

// List of tables used
$tables = array('auth_access', 'banlist', 'categories', 'config', 'disallow', 'forums', 'forum_prune', 'groups', 'posts', 'posts_text', 'privmsgs', 'privmsgs_text', 'ranks', 'search_results', 'search_wordlist', 'search_wordmatch', 'sessions', 'smilies', 'themes', 'themes_name', 'topics', 'topics_watch', 'user_group', 'users', 'vote_desc', 'vote_results', 'vote_voters', 'words');
// List of configuration data required
$config_data = array('dbmtnc_disallow_postcounter', 'dbmtnc_disallow_rebuild', 'dbmtnc_rebuild_end', 'dbmtnc_rebuild_pos');
// Default configuration records - from installation file
$default_config = array(
	'config_id' => '1',
	'board_disable' => '0',
	'sitename' => 'yourdomain.com',
	'site_desc' => 'A _little_ text to describe your forum',
	'cookie_name' => 'phpbb2mysql',
	'cookie_path' => '/',
	'cookie_domain' => '',
	'cookie_secure' => '0',
	'session_length' => '3600',
	'allow_html' => '0',
	'allow_html_tags' => 'b,i,u,pre',
	'allow_bbcode' => '1',
	'allow_smilies' => '1',
	'allow_sig' => '1',
	'allow_namechange' => '0',
	'allow_theme_create' => '0',
	'allow_avatar_local' => '0',
	'allow_avatar_remote' => '0',
	'allow_avatar_upload' => '0',
	'enable_confirm' => '0',
	'override_user_style' => '0',
	'posts_per_page' => '15',
	'topics_per_page' => '50',
	'hot_threshold' => '25',
	'max_poll_options' => '10',
	'max_sig_chars' => '255',
	'max_inbox_privmsgs' => '50',
	'max_sentbox_privmsgs' => '25',
	'max_savebox_privmsgs' => '50',
	'board_email_sig' => 'Thanks, The Management',
	'board_email' => 'youraddress@yourdomain.com',
	'smtp_delivery' => '0',
	'smtp_host' => '',
	'smtp_username' => '',
	'smtp_password' => '',
	'sendmail_fix' => '0',
	'require_activation' => '0',
	'flood_interval' => '15',
	'board_email_form' => '0',
	'avatar_filesize' => '6144',
	'avatar_max_width' => '80',
	'avatar_max_height' => '80',
	'avatar_path' => 'images/avatars',
	'avatar_gallery_path' => 'images/avatars/gallery',
	'smilies_path' => 'images/smiles',
	'default_style' => '1',
	'default_dateformat' => 'D M d, Y g:i a',
	'board_timezone' => '0',
	'prune_enable' => '1',
	'privmsg_disable' => '0',
	'gzip_compress' => '0',
	'coppa_fax' => '',
	'coppa_mail' => '',
	'record_online_users' => '0',
	'record_online_date' => '0',
	'server_name' => 'www.myserver.tld',
	'server_port' => '80',
	'script_path' => '/phpBB2/',
	'default_lang' => 'english',
	'board_startdate' => '0',
	// DB Maintenance specific entries
	'dbmtnc_rebuild_end' => '0',
	'dbmtnc_rebuild_pos' => '-1',
	'dbmtnc_rebuild_job' => '',
	'dbmtnc_rebuildcfg_maxmemory' => '500',
	'dbmtnc_rebuildcfg_minposts' => '3',
	'dbmtnc_rebuildcfg_php3only' => '0',
	'dbmtnc_rebuildcfg_php3pps' => '1',
	'dbmtnc_rebuildcfg_php4pps' => '8',
	'dbmtnc_rebuildcfg_timelimit' => '240',
	'dbmtnc_rebuildcfg_timeoverwrite' => '0',
	'dbmtnc_disallow_postcounter' => '0',
	'dbmtnc_disallow_rebuild' => '0'
);
// append data added in later versions
if (isset($board_config['version']) && is_string($board_config['version']) && preg_match('/^\.0\.([0-9]{1,2})$/D', $board_config['version'], $version_match) && (int) $version_match[1] > 0)
{
	$phpbb_version = array(0, (int) $version_match[1]);
}
else
{
	// Runtime inventory fallback only, never a database version assignment.
	$phpbb_version = array(0, 23);
}
if ( $phpbb_version[0] == 0 && $phpbb_version[1] >= 5 )
{
	$tables[] = 'confirm';
}
if ( $phpbb_version[0] == 0 && $phpbb_version[1] >= 18 )
{
	$tables[] = 'sessions_keys';
	$default_config['allow_autologin'] = '1';
	$default_config['max_autologin_time'] = '0';
}
if ( $phpbb_version[0] == 0 && $phpbb_version[1] >= 19 )
{
	$default_config['max_login_attempts'] = '5';
	$default_config['login_reset_time'] = '30';
}
if ( $phpbb_version[0] == 0 && $phpbb_version[1] >= 20 )
{
	$default_config['search_flood_interval'] = '15';
	$default_config['rand_seed'] = '0';
}
if ( $phpbb_version[0] == 0 && $phpbb_version[1] >= 21 )
{
	$default_config['search_min_chars'] = '3';
}
sort($tables);




//
// This is the equivalent function for message_die. Since we do not use the template system when doing database work, message_die() will not work.
//
function throw_error($msg_text = '', $err_line = '', $err_file = '', $sql = '')
{
	global $db, $template, $lang, $phpEx, $phpbb_root_path, $theme;
	global $list_open;

	$debug_text = '';
	if (phpbb_debug_details_allowed())
	{
		$error = is_object($db) && method_exists($db, 'sql_error') ? $db->sql_error() : array();
		$debug_text = phpbb_safe_sql_diagnostics($error, $sql, $err_line, $err_file);
	}

	//
	// Close the list if one is still open
	//
	if ( $list_open )
	{
		echo("</ul></span>\n");
	}

	if ( $msg_text == '' )
	{
		$msg_text = $lang['An_error_occured'];
	}

	echo('<p class="gen"><b><span style="color:#' . $theme['fontcolor3'] . '">' . $lang['Error'] . ":</span></b> $msg_text$debug_text</p>\n");

	//
	// Include Tail and exit
	//
	echo("<p class=\"gen\"><a href=\"" . append_sid("admin_db_maintenance.$phpEx") . "\">" . $lang['Back_to_DB_Maintenance'] . "</a></p>\n");
	include('./page_footer_admin.'.$phpEx);
	exit;
}


//
// Checks several conditions for the menu
//
function check_condition($check)
{
	global $db, $board_config;

	switch ($check)
	{
		case 0: // No check
			return TRUE;
			break;
		case 1: // MySQL >= 3.23.17
			return check_mysql_version();
			break;
		case 2: // Session storage can be upgraded without discarding rows
			require_once dirname(__FILE__) . '/functions_maintenance_session_storage.php';
			try { return in_array(dbmtnc_session_storage_engine($db), array('HEAP', 'MEMORY', 'MYISAM'), true); }
			catch (Exception $error) { return FALSE; }
			catch (Throwable $error) { return FALSE; }
		case 3: // DB locked
			if ( $board_config['board_disable'] == 1 )
			{
				// DB is locked
				return TRUE;
			}
			else
			{
				return FALSE;
			}
			break;
		case 4: // Search index in recreation
			if (!empty($board_config['dbmtnc_rebuild_job']))
			{
				require_once dirname(__FILE__) . '/functions_maintenance_rebuild.php';
				$job = dbmtnc_rebuild_decode($board_config['dbmtnc_rebuild_job']);
				return $job !== null && $job['s'] !== 'done';
			}
			if( $board_config['dbmtnc_rebuild_pos'] <> -1 )
			{
				// Rebuilding was interrupted - check for end position
				if ( $board_config['dbmtnc_rebuild_end'] >= $board_config['dbmtnc_rebuild_pos'] )
				{
					return TRUE;
				}
				else
				{
					return FALSE;
				}
			}
			else
			{
				// Rebuilding was not interrupted
				return FALSE;
			}
			break;
		case 5: // Configuration disabled
			return (CONFIG_LEVEL != 0) ? TRUE : FALSE;
			break;
		case 6: // User post counter disabled
			return ($board_config['dbmtnc_disallow_postcounter'] != 1) ? TRUE : FALSE;
			break;
		case 7: // Rebuilding disabled
			return ($board_config['dbmtnc_disallow_rebuild'] != 1) ? TRUE : FALSE;
			break;
		case 8: // Seperator for rebuilding
			return (check_condition(4) || check_condition(7)) ? TRUE : FALSE;
			break;
		default:
			return FALSE;
	}
}

//
// Checks whether MySQL supports HEAP-Tables, ANSI compatible INNER JOINs and other commands
//
function check_mysql_version()
{
	global $db;

	$sql = 'SELECT VERSION() AS mysql_version';
	$result = $db->sql_query($sql);
	if( !$result )
	{
		throw_error("Couldn't obtain MySQL Version", __LINE__, __FILE__, $sql);
	}
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	$version = preg_replace('/[^0-9.].*$/', '', $row['mysql_version']);

	return ($version !== '' && version_compare($version, '3.23.17', '>='));
}

//
// Gets the current time in microseconds
//
function getmicrotime()
{
	list($usec, $sec) = explode(" ", microtime());
	return ((float)$usec + (float)$sec);
}


//
// Gets table statistics
//
function get_table_statistic()
{
	global $db, $table_prefix;
	global $tables;

	$stat['all']['count'] = 0;
	$stat['all']['records'] = 0;
	$stat['all']['size'] = 0;
	$stat['advanced']['count'] = 0;
	$stat['advanced']['records'] = 0;
	$stat['advanced']['size'] = 0;
	$stat['core']['count'] = 0;
	$stat['core']['records'] = 0;
	$stat['core']['size'] = 0;

	$sql = 'SHOW TABLE STATUS';
	$result = $db->sql_query($sql);
	if( !$result )
	{
		throw_error("Couldn't obtain table data", __LINE__, __FILE__, $sql);
	}
	while( $row = $db->sql_fetchrow($result) )
	{
		$stat['all']['count']++;
		$stat['all']['records'] += intval($row['Rows']);
		$stat['all']['size'] += intval($row['Data_length']) + intval($row['Index_length']);
		if ( $table_prefix == substr($row['Name'], 0, strlen($table_prefix)) )
		{
			$stat['advanced']['count']++;
			$stat['advanced']['records'] += intval($row['Rows']);
			$stat['advanced']['size'] += intval($row['Data_length']) + intval($row['Index_length']);
		}
		for ($i = 0; $i < count($tables); $i++)
		{
			if ($table_prefix . $tables[$i] == $row['Name'])
			{
				$stat['core']['count']++;
				$stat['core']['records'] += intval($row['Rows']);
				$stat['core']['size'] += intval($row['Data_length']) + intval($row['Index_length']);
			}
		}
	}
	$db->sql_freeresult($result);
	return $stat;
}

//
// Converts Bytes to a apropriate Value
//
function convert_bytes($bytes)
{
	if( abs($bytes) >= 1048576 )
	{
		return sprintf("%.2f MB", ( $bytes / 1048576 ));
	}
	else if( abs($bytes) >= 1024 )
	{
		return sprintf("%.2f KB", ( $bytes / 1024 ));
	}
	else
	{
		return sprintf("%.2f Bytes", $bytes);
	}
}

function dbmtnc_optimize_table($tablename)
{
	return dbmtnc_table_maintenance($tablename, 'OPTIMIZE');
}

// CHECK, REPAIR and OPTIMIZE may return several diagnostic rows. Neither a
// result set nor an arbitrary status row proves that the operation succeeded.
function dbmtnc_table_maintenance($tablename, $operation, $erc = false)
{
	global $db, $lang;
	if (!is_string($tablename) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $tablename) || !in_array($operation, array('CHECK', 'REPAIR', 'OPTIMIZE'), true))
	{
		if ($erc) { erc_throw_error($lang['Maintenance_invalid_target']); }
		else { throw_error($lang['Maintenance_invalid_target']); }
		return false;
	}
	if ($operation === 'REPAIR')
	{
		$metadata = $db->sql_query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $tablename . "'");
		$engine = $metadata ? $db->sql_fetchrow($metadata) : false;
		if ($metadata) { $db->sql_freeresult($metadata); }
		if (!$engine || !isset($engine['ENGINE']))
		{
			if ($erc) { erc_throw_error($lang['Maintenance_query_failed']); }
			else { throw_error($lang['Maintenance_query_failed']); }
			return false;
		}
		if (strcasecmp($engine['ENGINE'], 'InnoDB') === 0)
		{
			echo '<li>' . $tablename . ': ' . $lang['Maintenance_innodb_check'] . "</li>\n";
			$operation = 'CHECK';
		}
	}
	$sql = $operation . ' TABLE `' . $tablename . '`';
	$result = $db->sql_query($sql);
	if (!$result)
	{
		if ($erc) { erc_throw_error($lang['Maintenance_query_failed'], __LINE__, __FILE__, $sql); }
		else { throw_error($lang['Maintenance_query_failed'], __LINE__, __FILE__, $sql); }
		return false;
	}
	$success = false;
	$problem = false;
	while ($row = $db->sql_fetchrow($result))
	{
		$type = isset($row['Msg_type']) && is_string($row['Msg_type']) ? strtolower(trim($row['Msg_type'])) : '';
		$text = isset($row['Msg_text']) && is_string($row['Msg_text']) ? $row['Msg_text'] : '';
		$status_ok = $type === 'status' && in_array(strtolower(trim($text)), array('ok', 'table is already up to date'), true);
		// The final result row must confirm completion, not an earlier status.
		$success = $status_ok;
		if (!$status_ok && !in_array($type, array('note', 'info'), true))
		{
			$problem = true;
		}
		$display = $type === 'status' && strtolower(trim($text)) === 'ok' ? $lang['Table_OK'] : '[' . $type . '] ' . $text;
		echo('<li>' . $tablename . ': ' . htmlspecialchars($display, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</li>\n");
	}
	$db->sql_freeresult($result);
	if (!$success || $problem)
	{
		$unconfirmed = $operation === 'OPTIMIZE' ? $lang['Optimization_unconfirmed'] : $lang['Maintenance_unconfirmed'];
		echo('<li><b>' . $tablename . ': ' . $unconfirmed . "</b></li>\n");
		return false;
	}
	return true;
}



//
// Resets the auto increment for a table
//
function dbmtnc_auto_rows($database, $sql)
{
	$result = $database->sql_query($sql);
	if (!$result) { throw new RuntimeException('Auto-increment metadata unavailable'); }
	$rows = array();
	while ($row = $database->sql_fetchrow($result)) { $rows[] = $row; }
	$database->sql_freeresult($result);
	return $rows;
}

// Legacy length/signedness parameters remain accepted, but never override the
// real schema. This restores an attribute, not a column type or an ID counter.
function set_autoincrement($table, $column, $length, $unsigned = TRUE)
{
	global $db, $lang;
	if (!is_string($table) || !is_string($column) ||
		!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $column))
	{
		throw new RuntimeException($lang['Ai_repair_failed']);
	}
	$changed_mode = false; $failure = false; $repaired = false; $review = false; $original_mode = ''; $failure_reason = null;
	try
	{
		$columns = dbmtnc_auto_rows($db, 'SHOW FULL COLUMNS FROM `' . $table . '`');
		$target = false; $other_auto = false;
		foreach ($columns as $field)
		{
			if (!isset($field['Field'], $field['Extra'])) { throw new RuntimeException('Incomplete metadata'); }
			if ($field['Field'] === $column) { $target = $field; }
			elseif (stripos($field['Extra'], 'auto_increment') !== false) { $other_auto = true; }
		}
		if (!$target) { throw new RuntimeException('Missing column'); }
		if (stripos($target['Extra'], 'auto_increment') !== false)
		{
			// In particular, do not lower a healthy counter after rows were deleted.
			echo("<li>$table: " . $lang['Ai_message_no_update'] . "</li>\n");
			return;
		}
		$review = $other_auto || !isset($target['Type'], $target['Null'], $target['Comment']) ||
			!array_key_exists('Default', $target) || $target['Default'] !== null ||
			$target['Null'] !== 'NO' || $target['Extra'] !== '' ||
			!preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?:\\([0-9]{1,3}\\))?(?: unsigned)?(?: zerofill)?$/iD', $target['Type']);
		$primary = array();
		if (!$review)
		{
			foreach (dbmtnc_auto_rows($db, 'SHOW INDEX FROM `' . $table . '`') as $index)
			{
				if (isset($index['Key_name']) && $index['Key_name'] === 'PRIMARY') { $primary[] = $index; }
			}
			// A missing/compound key is a different repair; don't guess or create it.
			$review = count($primary) !== 1 || !isset($primary[0]['Column_name'], $primary[0]['Seq_in_index']) ||
				$primary[0]['Column_name'] !== $column || (int) $primary[0]['Seq_in_index'] !== 1;
		}
		if (!$review)
		{
			$modes = dbmtnc_auto_rows($db, 'SELECT @@SESSION.sql_mode AS sql_mode');
			if (count($modes) !== 1 || !isset($modes[0]['sql_mode'])) { throw new RuntimeException('Missing SQL mode'); }
			$original_mode = $modes[0]['sql_mode'];
			$mode_list = $original_mode === '' ? array() : explode(',', $original_mode);
			$mode_list[] = 'STRICT_ALL_TABLES'; $mode_list[] = 'NO_AUTO_VALUE_ON_ZERO';
			$repair_mode = implode(',', array_unique($mode_list));
			if (!$db->sql_query("SET SESSION sql_mode = '" . $db->sql_escape($repair_mode) . "'")) { throw new RuntimeException('Cannot protect IDs'); }
			$changed_mode = true;
			// MODIFY requires attributes to be restated. Keep exact integer type,
			// signedness, display width/zerofill and comment; indexes stay untouched.
			// NO_AUTO_VALUE_ON_ZERO prevents renumbering an existing zero/sentinel.
			$sql = 'ALTER TABLE `' . $table . '` MODIFY COLUMN `' . $column . '` ' .
				$target['Type'] . " NOT NULL AUTO_INCREMENT COMMENT '" . $db->sql_escape($target['Comment']) . "'";
			if (!$db->sql_query($sql)) { throw new RuntimeException('Strict repair failed'); }
			$actual = false;
			foreach (dbmtnc_auto_rows($db, 'SHOW FULL COLUMNS FROM `' . $table . '`') as $field)
			{
				if ($field['Field'] === $column) { $actual = $field; }
			}
			if (!$actual || stripos($actual['Extra'], 'auto_increment') === false ||
				$actual['Type'] !== $target['Type'] || $actual['Null'] !== $target['Null'] ||
				$actual['Comment'] !== $target['Comment'] || $actual['Default'] !== null)
			{
				throw new RuntimeException('Repair metadata could not be verified');
			}
			$repaired = true;
		}
	}
	catch (Exception $error) { $failure = true; $failure_reason = $error; }
	catch (Throwable $error) { $failure = true; $failure_reason = $error; }
	finally
	{
		if ($changed_mode)
		{
			try { if (!$db->sql_query("SET SESSION sql_mode = '" . $db->sql_escape($original_mode) . "'")) { $failure = true; } }
			catch (Exception $error) { $failure = true; }
			catch (Throwable $error) { $failure = true; }
		}
	}
	// DDL isn't transactional. An uncertain result is reported, never rolled back
	// by guessing or retried with IGNORE/foreign-key checks disabled.
	if ($failure)
	{
		// Let the owning controller release its scope BEFORE the error renderer
		// exits. Preserve authorization failures without exposing driver messages.
		if ($failure_reason instanceof PhpbbAclException) { throw $failure_reason; }
		throw new RuntimeException($lang['Ai_repair_failed']);
	}
	echo("<li>$table: <b>" . $lang[$repaired ? 'Ai_message_update_table' : 'Ai_review_column'] . "</b></li>\n");
}

//
// Functions for Emergency Recovery Console
//
function erc_throw_error($msg_text = '', $err_line = '', $err_file = '', $sql = '')
{
	global $db, $lang;

	$debug_text = '';
	if (defined('DEBUG') && DEBUG)
	{
		$error = is_object($db) && method_exists($db, 'sql_error') ? $db->sql_error() : array();
		$debug_text = phpbb_safe_sql_diagnostics($error, $sql, $err_line, $err_file);
	}

	if ( $msg_text == '' )
	{
		$msg_text = $lang['An_error_occured'];
	}

	echo('<p class="gen"><b>' . $lang['Error'] . ":</b> $msg_text$debug_text</p>\n");

	exit;
}

function language_select($default, $select_name = "language", $file_to_check = "main", $dirname="language")
{
	global $phpEx, $phpbb_root_path, $lang;

	$dir = @opendir($phpbb_root_path . $dirname);

	$lg = array();
	while ( $dir !== false && ($file = readdir($dir)) !== false )
	{
		if (preg_match('#^lang_#i', $file) && !is_file(@phpbb_realpath($phpbb_root_path . $dirname . '/' . $file)) && !is_link(@phpbb_realpath($phpbb_root_path . $dirname . '/' . $file)) && is_file(@phpbb_realpath($phpbb_root_path . $dirname . '/' . $file . '/lang_' . $file_to_check . '.' . $phpEx)) )
		{
			$filename = trim(str_replace("lang_", "", $file));
			$displayname = preg_replace("/^(.*?)_(.*)$/", "\\1 [ \\2 ]", $filename);
			$displayname = preg_replace("/\[(.*?)_(.*)\]/", "[ \\1 - \\2 ]", $displayname);
			$lg[$displayname] = $filename;
		}
	}

	if ($dir !== false)
	{
		closedir($dir);
	}

	@asort($lg);

	if ( count($lg) )
	{
		$lang_select = '<select name="' . $select_name . '">';
		foreach ($lg as $displayname => $filename)
		{
			$selected = ( strtolower($default) == strtolower($filename) ) ? ' selected="selected"' : '';
			$lang_select .= '<option value="' . $filename . '"' . $selected . '>' . ucwords($displayname) . '</option>';
		}
		$lang_select .= '</select>';
	}
	else
	{
		$lang_select = $lang['No_selectable_language'];
	}

	return $lang_select;
}

function style_select($default_style, $select_name = "style", $dirname = "templates")
{
	global $db;

	$sql = "SELECT themes_id, style_name
		FROM " . THEMES_TABLE . "
		WHERE BINARY template_name = 'fisubsilversh' AND theme_public = 1
		ORDER BY template_name, themes_id";
	if ( !($result = $db->sql_query($sql)) )
	{
		erc_throw_error('Couldn\'t query themes table', __LINE__, __FILE__, $sql);
	}

	$style_select = '<select name="' . $select_name . '">';
	while ( $row = $db->sql_fetchrow($result) )
	{
		$selected = ( $row['themes_id'] == $default_style ) ? ' selected="selected"' : '';
		$style_select .= '<option value="' . $row['themes_id'] . '"' . $selected . '>' . htmlspecialchars($row['style_name']) . '</option>';
	}
	$db->sql_freeresult($result);
	$style_select .= "</select>";

	return $style_select;
}

function check_authorisation($die = TRUE, &$write_guard = null, &$actor_id = null)
{
	global $db, $lang, $dbuser, $dbpasswd, $option, $HTTP_POST_VARS;
	static $verified_board_credential = null;
	$write_guard = '0 = 1';
	$actor_id = null;

	$auth_method = (isset($HTTP_POST_VARS['auth_method']) && is_string($HTTP_POST_VARS['auth_method'])) ? $HTTP_POST_VARS['auth_method'] : '';
	$board_user = (isset($HTTP_POST_VARS['board_user']) && is_string($HTTP_POST_VARS['board_user'])) ? trim(htmlspecialchars($HTTP_POST_VARS['board_user'])) : '';
	$board_user = substr(str_replace("\\'", "'", $board_user), 0, 25);
	$board_user = str_replace("'", "\\'", $board_user);
	// The ERC adapter already applied exactly the same legacy escaping as
	// common.php. Board credentials must reach the verifier just as on login;
	// only literal database-owner credentials below are decoded for comparison.
	$board_password = (isset($HTTP_POST_VARS['board_password']) && is_string($HTTP_POST_VARS['board_password'])) ? $HTTP_POST_VARS['board_password'] : null;
	$db_user = (isset($HTTP_POST_VARS['db_user']) && is_string($HTTP_POST_VARS['db_user'])) ? stripslashes($HTTP_POST_VARS['db_user']) : null;
	$db_password = (isset($HTTP_POST_VARS['db_password']) && is_string($HTTP_POST_VARS['db_password'])) ? stripslashes($HTTP_POST_VARS['db_password']) : null;
	// Change authentication mode if selected option does not allow database authentication
	if ( $option == 'rld' || $option == 'rtd' )
	{
		$auth_method = 'board';
	}

	switch ($auth_method)
	{
		case 'board':
			if ($board_user === '' || !is_string($board_password)) { $allow_access = false; break; }
			$sql = "SELECT user_id, username, user_password, user_active, user_level
				FROM " . USERS_TABLE . "
				WHERE username = '" . str_replace("\\'", "''", $board_user) . "'";
			if ( !($result = $db->sql_query($sql)) )
			{
				if (!$die) { return false; }
				erc_throw_error('Error in obtaining userdata', __LINE__, __FILE__, $sql);
			}
			if( $row = $db->sql_fetchrow($result) )
			{
				// Cache only a successful password proof inside this PHP request, not
				// an authorization decision. The SELECT above and role checks below
				// remain fresh for every operation; another ID/hash/password requires
				// a new expensive verification. Failed proofs are never cached.
				$proof_key = is_string($row['user_password']) ? hash('sha256', (int)$row['user_id'] . ':' . strlen($board_password) . ':' . $board_password . $row['user_password']) : null;
				$password_valid = ($proof_key !== null && $verified_board_credential !== null && hash_equals($verified_board_credential, $proof_key))
					|| phpbb_password_verify($board_password, $row['user_password']);
				if( $password_valid && $row['user_active'] && $row['user_level'] == ADMIN )
				{
					$verified_board_credential = $proof_key;
					$allow_access = TRUE;
					$actor_id = (int) $row['user_id'];
					// Requalify a credential-authorized write at dispatch, including
					// demotion, deactivation and password replacement since this read.
					$write_guard = 'EXISTS (SELECT 1 FROM (SELECT DISTINCT user_id,username,user_password,user_active,user_level FROM ' . USERS_TABLE
						. ' WHERE user_id = ' . (int)$row['user_id'] . ') erc_actor WHERE erc_actor.user_active <> 0 AND erc_actor.user_level = ' . ADMIN
						. " AND erc_actor.username = '" . $db->sql_escape($row['username']) . "'"
						. " AND HEX(erc_actor.user_password) = HEX('" . $db->sql_escape($row['user_password']) . "'))";
				}
				else
				{
					$allow_access = FALSE;
				}
			}
			else
			{
				$allow_access = FALSE;
			}
			$db->sql_freeresult($result);
			break;
		case 'db':
			if (is_string($db_user) && is_string($db_password) && hash_equals((string) $dbuser, $db_user) && hash_equals((string) $dbpasswd, $db_password))
			{
				$allow_access = TRUE;
				$actor_id = 0; // Database-owner recovery has no board account.
				$write_guard = '1 = 1'; // Explicit database-owner credentials.
			}
			else
			{
				$allow_access = FALSE;
			}
			break;
		default:
			$allow_access = FALSE;
	}
	if ( !$allow_access && $die )
	{
?>
	<p><span style="color:red"><?php echo $lang['Auth_failed']; ?></span></p>
</body>
</html>
<?php
		exit;
	}
	return $allow_access;
}

/**
 * Requalify each emergency deletion, not just the initial form authorization.
 * A credential read alone leaves a window for a concurrent demotion, account
 * deactivation or password reset. The predicate also checks at SQL dispatch.
 * Each table is independent: after an uncertain result, never report success
 * or automatically repeat a deletion that may already have been applied.
 */
function dbmtnc_erc_clear_table($table)
{
	global $db;
	if (!in_array($table, array(SESSIONS_TABLE, SEARCH_TABLE, BANLIST_TABLE, DISALLOW_TABLE), true))
	{
		return false;
	}
	if (!check_authorisation(false, $write_guard)) { return false; }
	$result = $db->sql_query('DELETE FROM ' . $table . ' WHERE (' . $write_guard . ')');
	if (!$result) { return false; }
	// Zero affected rows can mean either an already empty table or revoked
	// credentials. Recheck rather than treating both outcomes as a success.
	return check_authorisation(false);
}

function dbmtnc_erc_remove_administrator($target_id, $expected_actor_id)
{
	global $db;
	if (!is_int($target_id) || $target_id <= 0 || !is_int($expected_actor_id) || $expected_actor_id < 0) { return false; }
	if (!check_authorisation(false, $write_guard, $actor_id) || $actor_id !== $expected_actor_id) { return false; }
	if ($actor_id === $target_id) { return 0; }
	require_once dirname(__FILE__) . '/functions_acl_storage.php';
	// Use the same current moderator policy as the normal ACP. Do not preserve
	// a stale pending/orphaned membership or use a role computed by an older read.
	$desired = 'CASE WHEN ' . phpbb_acl_mod_guard($target_id) . ' THEN ' . MOD . ' ELSE ' . USER . ' END';
	$sql = 'UPDATE ' . USERS_TABLE . ' SET user_level = ' . $desired
		. ' WHERE user_id = ' . $target_id . ' AND user_level = ' . ADMIN . ' AND (' . $write_guard . ')';
	if (!$db->sql_query($sql)) { return false; }
	$changed = (int) $db->sql_affectedrows();
	if (!check_authorisation(false, $after_guard, $after_actor_id) || $after_actor_id !== $expected_actor_id) { return false; }
	return $changed;
}

/** Explicit emergency promotion, bound to both resolved account identities. */
function dbmtnc_erc_grant_administrator($username, $expected_actor_id)
{
	global $db;
	if (!is_string($username) || $username === '' || strlen($username) > 255 || strpos($username, "\0") !== false
		|| preg_match('//u', $username) !== 1 || !is_int($expected_actor_id) || $expected_actor_id < 0) { return false; }
	if (!check_authorisation(false, $write_guard, $actor_id) || $actor_id !== $expected_actor_id) { return false; }
	$result = $db->sql_query('SELECT user_id, username FROM ' . USERS_TABLE
		. " WHERE username = '" . $db->sql_escape($username) . "' AND user_id > 0 LIMIT 2");
	if (!$result) { return false; }
	$target = $db->sql_fetchrow($result); $duplicate = $db->sql_fetchrow($result); $db->sql_freeresult($result);
	if ($duplicate) { return false; } // Never promote several ambiguous legacy rows.
	if (!$target) { return 0; }
	$target_id = (int) $target['user_id'];
	if ($target_id <= 0) { return false; }
	$target_guard = 'user_id = ' . $target_id . " AND username = '" . $db->sql_escape($target['username']) . "'";

	// Old recovery databases may lack these two counters. Discover available
	// fields before writing, instead of ignoring a failed second UPDATE. Include
	// all supported resets in the same credential-guarded promotion statement.
	$result = $db->sql_query('SHOW COLUMNS FROM ' . USERS_TABLE);
	if (!$result) { return false; }
	$fields = array();
	while ($field = $db->sql_fetchrow($result)) { $fields[$field['Field']] = true; }
	$db->sql_freeresult($result);
	$assignments = 'user_active = 1, user_level = ' . ADMIN;
	foreach (array('user_login_tries', 'user_last_login_try') as $counter)
	{
		if (isset($fields[$counter])) { $assignments .= ', ' . $counter . ' = 0'; }
	}
	if (!check_authorisation(false, $write_guard, $actor_id) || $actor_id !== $expected_actor_id) { return false; }
	if (!$db->sql_query('UPDATE ' . USERS_TABLE . ' SET ' . $assignments . ' WHERE ' . $target_guard . ' AND (' . $write_guard . ')')) { return false; }
	// An uncertain acknowledgement is never retried above. Also distinguish a
	// harmless already-active administrator from a deleted/renamed target.
	if (!check_authorisation(false, $after_guard, $after_actor_id) || $after_actor_id !== $expected_actor_id) { return false; }
	$result = $db->sql_query('SELECT user_id FROM ' . USERS_TABLE . ' WHERE ' . $target_guard . ' AND user_active = 1 AND user_level = ' . ADMIN);
	if (!$result) { return false; }
	$confirmed = (bool) $db->sql_fetchrow($result); $db->sql_freeresult($result);
	return $confirmed ? 1 : false;
}

/** One explicit emergency settings form; never a general config import API. */
function dbmtnc_erc_update_config($values, $expected_actor_id)
{
	global $db, $phpbb_root_path, $board_config;
	$allowed = array('cookie_secure', 'server_name', 'server_port', 'script_path', 'cookie_domain', 'cookie_name', 'cookie_path', 'gzip_compress');
	if (!is_array($values) || !is_int($expected_actor_id) || $expected_actor_id < 0) { return false; }
	$keys = $cases = array();
	foreach ($values as $key => $value)
	{
		if (!in_array($key, $allowed, true) || !is_string($value) || strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/', $value)) { return false; }
		if (in_array($key, array('cookie_secure', 'gzip_compress'), true) && !in_array($value, array('0','1'), true)) { return false; }
		if ($key === 'cookie_name' && !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) { return false; }
		if ($key === 'server_port' && (!preg_match('/^[0-9]{1,5}$/D', $value) || (int)$value < 1 || (int)$value > 65535)) { return false; }
		if ($key === 'server_name' && ($value === '' || phpbb_normalize_host($value, '') !== $value)) { return false; }
		if (in_array($key, array('script_path','cookie_path'), true))
		{
			$normalized_path = phpbb_normalize_script_path($value, '/');
			if ($value !== $normalized_path && $value . '/' !== $normalized_path) { return false; }
		}
		if ($key === 'cookie_domain' && $value !== '')
		{
			$domain = $value[0] === '.' ? substr($value, 1) : $value;
			if (strlen($domain) > 253 || $domain === '') { return false; }
			foreach (explode('.', $domain) as $label)
			{
				if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD', $label)) { return false; }
			}
		}
		$keys[] = "'" . $key . "'";
		$cases[] = "WHEN '" . $key . "' THEN '" . $db->sql_escape($value) . "'";
	}
	if (!check_authorisation(false, $guard, $actor_id) || $actor_id !== $expected_actor_id) { return false; }
	if (!$values) { return true; }
	$where = 'config_name IN (' . implode(',', $keys) . ')';
	$read = 'SELECT config_name, config_value FROM ' . CONFIG_TABLE . ' WHERE ' . $where;
	$result = $db->sql_query($read);
	if (!$result) { return false; }
	$found = array(); $valid = true;
	while ($row = $db->sql_fetchrow($result))
	{
		if (!array_key_exists($row['config_name'], $values) || isset($found[$row['config_name']])) { $valid = false; }
		$found[$row['config_name']] = true;
	}
	$db->sql_freeresult($result);
	if (!$valid || count($found) !== count($values)) { return false; }
	if (!check_authorisation(false, $guard, $actor_id) || $actor_id !== $expected_actor_id) { return false; }
	// All selected keys must still exist exactly once at dispatch. A concurrent
	// missing/renamed/duplicated key must not leave only the other fields changed.
	$config_guard = 'EXISTS (SELECT 1 FROM (SELECT COUNT(*) AS row_count, COUNT(DISTINCT BINARY config_name) AS key_count,'
		. ' SUM(BINARY config_name IN (' . implode(',', $keys) . ')) AS exact_count FROM ' . CONFIG_TABLE . ' WHERE ' . $where
		. ') erc_config_current WHERE row_count = ' . count($values) . ' AND key_count = ' . count($values) . ' AND exact_count = ' . count($values) . ')';
	$success = false;
	try
	{
		// One guarded statement for the complete selection, not sequential partial
		// form updates. Do not retry an uncertain acknowledgement or claim rollback.
		if (!$db->sql_query('UPDATE ' . CONFIG_TABLE . ' SET config_value = CASE config_name ' . implode(' ', $cases)
			. ' ELSE config_value END WHERE ' . $where . ' AND (' . $guard . ') AND ' . $config_guard)) { return false; }
		if (!check_authorisation(false, $after_guard, $after_actor_id) || $after_actor_id !== $expected_actor_id) { return false; }
		$result = $db->sql_query($read);
		if (!$result) { return false; }
		$found = array(); $valid = true;
		while ($row = $db->sql_fetchrow($result))
		{
			if (!array_key_exists($row['config_name'], $values) || isset($found[$row['config_name']])
				|| (string)$row['config_value'] !== $values[$row['config_name']]) { $valid = false; }
			$found[$row['config_name']] = true;
		}
		$db->sql_freeresult($result);
		$success = $valid && count($found) === count($values);
	}
	finally
	{
		// Also invalidate after failed/uncertain writes: the database may already
		// contain the new values. Never unlink other cache or uploaded files.
		$cache = $phpbb_root_path . 'cache/config_data.cache';
		clearstatcache(true, $cache);
		if ((file_exists($cache) || is_link($cache)) && !@unlink($cache)) { $success = false; }
	}
	if (!$success) { return false; }
	foreach ($values as $key => $value) { $board_config[$key] = $value; }
	return true;
}

/** Recover the authenticated account and board language together, never by a
 * second interpretation of the submitted username or by owner credentials. */
function dbmtnc_erc_reset_language($language, $expected_actor_id)
{
	global $db, $phpbb_root_path, $board_config;
	if (!is_int($expected_actor_id) || $expected_actor_id <= 0 || !is_string($language)
		|| !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $language)) { return false; }
	foreach (array('main', 'admin') as $part)
	{
		if (!is_file($phpbb_root_path . 'language/lang_' . $language . '/lang_' . $part . '.php')) { return false; }
	}
	if (!check_authorisation(false, $guard, $actor_id) || $actor_id !== $expected_actor_id) { return false; }
	$read = "SELECT config_name, config_value FROM " . CONFIG_TABLE . " WHERE config_name = 'default_lang'";
	$result = $db->sql_query($read);
	if (!$result) { return false; }
	$row = $db->sql_fetchrow($result); $extra = $db->sql_fetchrow($result); $db->sql_freeresult($result);
	if (!$row || $extra || $row['config_name'] !== 'default_lang') { return false; }
	if (!check_authorisation(false, $guard, $actor_id) || $actor_id !== $expected_actor_id) { return false; }
	// Materialize the current key count so a concurrent deletion, case alias or
	// duplicate cannot update the account without its matching board setting.
	$config_guard = 'EXISTS (SELECT 1 FROM (SELECT COUNT(*) AS row_count,'
		. " SUM(BINARY config_name = 'default_lang') AS exact_count FROM " . CONFIG_TABLE
		. " WHERE config_name = 'default_lang') erc_language_current WHERE row_count = 1 AND exact_count = 1)";
	$value = $db->sql_escape($language); $success = false;
	try
	{
		if (!$db->sql_query('UPDATE ' . USERS_TABLE . ' erc_language_user CROSS JOIN ' . CONFIG_TABLE . ' erc_language_config'
			. " SET erc_language_user.user_lang = '" . $value . "', erc_language_config.config_value = '" . $value . "'"
			. ' WHERE erc_language_user.user_id = ' . $expected_actor_id . " AND erc_language_config.config_name = 'default_lang'"
			. ' AND (' . $guard . ') AND ' . $config_guard)) { return false; }
		if (!check_authorisation(false, $after_guard, $after_actor_id) || $after_actor_id !== $expected_actor_id) { return false; }
		$result = $db->sql_query($read);
		if (!$result) { return false; }
		$row = $db->sql_fetchrow($result); $extra = $db->sql_fetchrow($result); $db->sql_freeresult($result);
		if (!$row || $extra || $row['config_name'] !== 'default_lang' || $row['config_value'] !== $language) { return false; }
		$result = $db->sql_query('SELECT user_lang FROM ' . USERS_TABLE . ' WHERE user_id = ' . $expected_actor_id);
		if (!$result) { return false; }
		$row = $db->sql_fetchrow($result); $db->sql_freeresult($result);
		$success = $row && $row['user_lang'] === $language;
		if (!check_authorisation(false, $after_guard, $after_actor_id) || $after_actor_id !== $expected_actor_id) { $success = false; }
	}
	finally
	{
		// A lost ACK may follow completed changes even on legacy recovery tables.
		// Never retry automatically, claim rollback or leave the old cache behind.
		$cache = $phpbb_root_path . 'cache/config_data.cache'; clearstatcache(true, $cache);
		if ((file_exists($cache) || is_link($cache)) && !@unlink($cache)) { $success = false; }
	}
	if ($success) { $board_config['default_lang'] = $language; }
	return $success;
}

function get_config_data($option)
{
	global $db;

	$sql = "SELECT config_value
		FROM " . CONFIG_TABLE . "
		WHERE config_name = '$option'";
	$result = $db->sql_query($sql);
	if ( !$result )
	{
		erc_throw_error("Couldn't get config data!", __LINE__, __FILE__, $sql);
	}
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if ( !$row )
	{
		erc_throw_error("Config data does not exist!", __LINE__, __FILE__, $sql);
	}

	return $row['config_value'];
}

function success_message($text)
{
	global $lang, $lg;

?>
	<p><?php echo $text; ?></p>
	<p style="text-align:center"><a href="<?php echo 'erc.php?lg=' . rawurlencode($lg); ?>"><?php echo $lang['Return_ERC']; ?></a></p>
<?php
}
?>
