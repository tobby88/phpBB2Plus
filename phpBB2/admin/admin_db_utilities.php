<?php
/***************************************************************************
*                             admin_db_utilities.php
*                              -------------------
*     begin                : Thu May 31, 2001
*     copyright            : (C) 2001 The phpBB Group
*     email                : support@phpbb.com
*
*     $Id: admin_db_utilities.php,v 1.42.2.10 2003/03/04 21:02:19 acydburn Exp $
*
****************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

/***************************************************************************
*	We will attempt to create a file based backup of all of the data in the
*	users phpBB database. Restore the resulting file offline with a database
*	client, then run the required storage migrations before reopening writers.
*
*	Some functions are adapted from the upgrade_20.php script and others
*	adapted from the unoficial phpMyAdmin 2.2.0.
***************************************************************************/

if (!defined('IN_PHPBB'))
{
    define( 'IN_PHPBB', 1);
}

if( !empty($setmodules) )
{
	$filename = basename(__FILE__);
	$module['General']['Backup_DB'] = $filename . "?perform=backup";

	$file_uploads = (@phpversion() >= '4.0.0') ? @ini_get('file_uploads') : @get_cfg_var('file_uploads');

	if( (empty($file_uploads) || $file_uploads != 0) && (strtolower($file_uploads) != 'off') && (@phpversion() != '4.0.4pl1') )
	{
		$module['General']['Restore_DB'] = $filename . "?perform=restore";
	}

	return;
}

//
// Load default header
//
$no_page_header = TRUE;
$phpbb_root_path = "./../";
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);

//
// Set VERBOSE to 1  for debugging info..
//
define("VERBOSE", 0);

//
// Increase maximum execution time, but don't complain about it if it isn't
// allowed.
//
@set_time_limit(1200);


//
// This function returns the "CREATE TABLE" syntax for mysql dbms...
//
function get_table_def_mysql($table, $crlf)
{
	global $drop, $db;
	$quoted_table = '`' . str_replace('`', '``', $table) . '`';
	$query = 'SHOW CREATE TABLE ' . $quoted_table;
	$result = $db->sql_query($query);
	if (!$result || !($row = $db->sql_fetchrow($result)))
	{
		phpbb_database_backup_failed();
	}
	$create_sql = isset($row['Create Table']) ? $row['Create Table'] : end($row);
	$db->sql_freeresult($result);
	$schema_create = ($drop == 1) ? 'DROP TABLE IF EXISTS ' . $quoted_table . ';' . $crlf : '';
	$schema_create .= $create_sql . ';';
	return $schema_create;

} // End get_table_def_mysql


//
// This fuction will return a tables create definition to be used as an sql
// statement.
//
//
// Export MySQL/MariaDB rows as INSERT statements.
// After every row a custom callback function $handler gets called.
// $handler must accept one parameter ($sql_insert);
//

//
// This function is for getting the data from a mysql table.
//

function get_table_content_mysql($table, $handler)
{
	global $db;
	$quoted_table = '`' . str_replace('`', '``', $table) . '`';

	// Grab the data from the table.
	if (!($result = $db->sql_query("SELECT * FROM $quoted_table")))
	{
		phpbb_database_backup_failed();
	}

	// Loop through the resulting rows and build the sql statement.
	if ($row = $db->sql_fetchrow($result))
	{
		$handler("\n#\n# Table Data for " . str_replace(array("\r", "\n"), ' ', $table) . "\n#\n");
		$field_names = array();

		// Grab the list of field names.
		$num_fields = $db->sql_numfields($result);
		$table_list = '(';
		for ($j = 0; $j < $num_fields; $j++)
		{
			$field_names[$j] = $db->sql_fieldname($j, $result);
			$table_list .= (($j > 0) ? ', ' : '') . '`' . str_replace('`', '``', $field_names[$j]) . '`';
			
		}
		$table_list .= ')';

		do
		{
			// Start building the SQL statement.
			$schema_insert = "INSERT INTO $quoted_table $table_list VALUES(";

			// Loop through the rows and fill in data for each column
			for ($j = 0; $j < $num_fields; $j++)
			{
				$schema_insert .= ($j > 0) ? ', ' : '';

				if(!isset($row[$field_names[$j]]))
				{
					//
					// If there is no data for the column set it to null.
					// There was a problem here with an extra space causing the
					// sql file not to reimport if the last column was null in
					// any table.  Should be fixed now :) JLH
					//
					$schema_insert .= 'NULL';
				}
				elseif ($row[$field_names[$j]] != '')
				{
					$schema_insert .= '\'' . $db->sql_escape($row[$field_names[$j]]) . '\'';
				}
				else
				{
					$schema_insert .= '\'\'';
				}
			}

			$schema_insert .= ');';

			// Go ahead and send the insert statement to the handler function.
			$handler(trim($schema_insert));

		}
		while ($row = $db->sql_fetchrow($result));
	}

	$db->sql_freeresult($result);
	return(true);
}

function output_table_content($content)
{
	global $phpbb_backup_output;
	phpbb_database_backup_write($phpbb_backup_output, $content . "\n");
	return;
}
//
// End Functions
// -------------


function phpbb_database_backup_build($reader, $stream, $tables, $backup_type, $dbname)
{
	global $db, $phpbb_backup_output;
	$original = $db; $previous_output = isset($phpbb_backup_output) ? $phpbb_backup_output : null;
	$db = $reader; $phpbb_backup_output = $stream;
	$dbname = str_replace(array("\r", "\n"), ' ', (string)$dbname);
	try
	{
		//
		// Build the sql script file...
		//
		phpbb_database_backup_write($phpbb_backup_output, "#\n");
		phpbb_database_backup_write($phpbb_backup_output, "# phpBB Backup Script\n");
		phpbb_database_backup_write($phpbb_backup_output, "# Dump of tables for $dbname\n");
		phpbb_database_backup_write($phpbb_backup_output, "#\n# DATE : " .  gmdate("d-m-Y H:i:s", time()) . " GMT\n");
		phpbb_database_backup_write($phpbb_backup_output, "#\n\nSET @phpbb_backup_old_sql_mode=@@SESSION.sql_mode;\n");
		phpbb_database_backup_write($phpbb_backup_output, "SET @phpbb_backup_old_foreign_keys=@@SESSION.foreign_key_checks;\n");
		phpbb_database_backup_write($phpbb_backup_output, "SET @phpbb_backup_old_client=@@SESSION.character_set_client;\n");
		phpbb_database_backup_write($phpbb_backup_output, "SET @phpbb_backup_old_results=@@SESSION.character_set_results;\n");
		phpbb_database_backup_write($phpbb_backup_output, "SET @phpbb_backup_old_connection=@@SESSION.character_set_connection;\n");
		phpbb_database_backup_write($phpbb_backup_output, "SET @phpbb_backup_old_collation=@@SESSION.collation_connection;\n");
		phpbb_database_backup_write($phpbb_backup_output, "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\n");
		phpbb_database_backup_write($phpbb_backup_output, "SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION';\n");
		phpbb_database_backup_write($phpbb_backup_output, "SET FOREIGN_KEY_CHECKS=0;\n");

		for($i = 0; $i < count($tables); $i++)
		{
			$table_name = $tables[$i];
			$table_comment = str_replace(array("\r", "\n"), ' ', $table_name);


			if($backup_type != 'data')
			{
				phpbb_database_backup_write($phpbb_backup_output, "#\n# TABLE: $table_comment \n#\n");
				phpbb_database_backup_write($phpbb_backup_output, get_table_def_mysql($table_name, "\n") . "\n");
			}

			if($backup_type != 'structure')
			{
				get_table_content_mysql($table_name, "output_table_content");
			}
		}
		phpbb_database_backup_write($phpbb_backup_output, "\nSET FOREIGN_KEY_CHECKS=@phpbb_backup_old_foreign_keys;\n");
		phpbb_database_backup_write($phpbb_backup_output, "SET SESSION sql_mode=@phpbb_backup_old_sql_mode;\n");
		phpbb_database_backup_write($phpbb_backup_output, "SET SESSION character_set_client=@phpbb_backup_old_client;\n");
		phpbb_database_backup_write($phpbb_backup_output, "SET SESSION character_set_results=@phpbb_backup_old_results;\n");
		phpbb_database_backup_write($phpbb_backup_output, "SET SESSION character_set_connection=@phpbb_backup_old_connection;\n");
		phpbb_database_backup_write($phpbb_backup_output, "SET SESSION collation_connection=@phpbb_backup_old_collation;\n");
	}
	finally { $db = $original; $phpbb_backup_output = $previous_output; }
}

//
// Begin program proper
//
if( isset($_GET['perform']) || isset($_POST['perform']) )
{
	$perform_value = isset($_POST['perform']) ? $_POST['perform'] : $_GET['perform'];
	$perform = is_scalar($perform_value) ? (string) $perform_value : '';
	if (!in_array($perform, array('backup', 'restore'), true))
	{
		message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
	}
	if (!in_array(SQL_LAYER, array('mysql', 'mysql4', 'mysqli'), true))
	{
		message_die(GENERAL_MESSAGE, $lang['Backups_not_supported']);
	}

	switch($perform)
	{
		case 'backup':
			$result = $db->sql_query('SHOW TABLES');
			$available_tables = array();
			$tables = array();
			while ($table_row = $db->sql_fetchrow($result))
			{
				$table_name = (string) reset($table_row);
				$available_tables[$table_name] = true;
				if (strpos($table_name, $table_prefix) === 0)
				{
					$tables[$table_name] = $table_name;
				}
			}
			$db->sql_freeresult($result);
			$additional_tables = isset($_POST['additional_tables']) && is_scalar($_POST['additional_tables']) ? (string) $_POST['additional_tables'] : '';
			$backup_type = isset($_POST['backup_type']) && is_scalar($_POST['backup_type']) ? (string) $_POST['backup_type'] : 'full';
			if (!in_array($backup_type, array('full', 'structure', 'data'), true))
			{
				$backup_type = 'full';
			}
			$gzipcompress = !empty($_POST['gzipcompress']) ? 1 : 0;
			$drop = 1;

			if(!empty($additional_tables))
			{
				foreach (preg_split('/\s*,\s*/', $additional_tables, -1, PREG_SPLIT_NO_EMPTY) as $additional_table)
				{
					$additional_table = trim($additional_table);
					if (preg_match('/^[A-Za-z0-9_]+$/D', $additional_table) && isset($available_tables[$additional_table]))
					{
						$tables[$additional_table] = $additional_table;
					}
				}
			}
			$tables = array_values($tables);

			if( !isset($_POST['backupstart']))
			{
				include('./page_header_admin.'.$phpEx);

				$template->set_filenames(array(
					"body" => "admin/db_utils_backup_body.tpl")
				);	
				$s_hidden_fields = "<input type=\"hidden\" name=\"perform\" value=\"backup\" />" . phpbb_admin_session_field();

				$template->assign_vars(array(
					"L_DATABASE_BACKUP" => $lang['Database_Utilities'] . " : " . $lang['Backup'],
					"L_BACKUP_EXPLAIN" => $lang['Backup_explain'],
					"L_FULL_BACKUP" => $lang['Full_backup'],
					"L_STRUCTURE_BACKUP" => $lang['Structure_backup'],
					"L_DATA_BACKUP" => $lang['Data_backup'],
					"L_ADDITIONAL_TABLES" => $lang['Additional_tables'],
					"L_START_BACKUP" => $lang['Start_backup'],
					"L_BACKUP_OPTIONS" => $lang['Backup_options'],
					"L_GZIP_COMPRESS" => $lang['Gzip_compress'],
					"L_NO" => $lang['No'],
					"L_YES" => $lang['Yes'],

					"S_HIDDEN_FIELDS" => $s_hidden_fields,
					"S_DBUTILS_ACTION" => append_sid("admin_db_utilities.$phpEx"))
				);
				$template->pparse("body");

				break;

			}
			phpbb_admin_require_post_session();
			require_once dirname(__DIR__) . '/includes/functions_database_backup.php';
			$do_gzip_compress = (bool)($gzipcompress && extension_loaded('zlib'));
			$backup_stream = null;
			try
			{
				$backup_stream = phpbb_database_backup_capture($db, $tables, $do_gzip_compress,
					function($reader, $stream) use ($tables, $backup_type, $dbname) {
						phpbb_database_backup_build($reader, $stream, $tables, $backup_type, $dbname);
					}, $backup_type !== 'structure');
				$backup_stat = @fstat($backup_stream);
				if (!$backup_stat || $backup_stat['size'] <= 0) { phpbb_database_backup_failed(); }
			}
			catch (Exception $error)
			{
				if (is_resource($backup_stream)) { fclose($backup_stream); }
				$key = $error instanceof PhpbbAclException ? 'Not_Authorised' :
					($error instanceof PhpbbDatabaseBackupException && $error->getMessage() === 'storage' ? 'Database_backup_requires_innodb' : 'Database_backup_failed');
				http_response_code($key === 'Not_Authorised' ? 403 : 503);
				message_die(GENERAL_ERROR, isset($lang[$key]) ? $lang[$key] : 'Database backup refused.');
			}
			catch (Error $error)
			{
				if (is_resource($backup_stream)) { fclose($backup_stream); }
				http_response_code(503); message_die(GENERAL_ERROR, $lang['Database_backup_failed']);
			}
			// No headers or SQL bytes are sent until the snapshot, authority,
			// temporary storage and (when selected) gzip trailer are complete.
			header('Pragma: no-cache');
			header('Cache-Control: no-store, no-cache, must-revalidate');
			header('X-Content-Type-Options: nosniff');
			header('Content-Length: ' . sprintf('%.0f', $backup_stat['size']));
			if ($do_gzip_compress)
			{
				header('Content-Type: application/x-gzip; name="phpbb_db_backup.sql.gz"');
				header('Content-Disposition: attachment; filename="phpbb_db_backup.sql.gz"');
			}
			else
			{
				header('Content-Type: text/x-delimtext; name="phpbb_db_backup.sql"');
				header('Content-Disposition: attachment; filename="phpbb_db_backup.sql"');
			}
			try { fpassthru($backup_stream); }
			finally { fclose($backup_stream); }
			exit;

			break;

		case 'restore':
			// A dump can execute arbitrary DDL/session changes before a later error.
			// Never partially import it into an open forum or rewrite SQL by regex.
			http_response_code(403);
			message_die(GENERAL_MESSAGE, $lang['Restore_offline_only']);
			break;
	}
}

include('./page_footer_admin.'.$phpEx);

?>
