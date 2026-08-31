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
*	users phpBB database.  The resulting file should be able to be imported by
*	the db_restore.php function, or by using the mysql command_line
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
include($phpbb_root_path . 'includes/sql_parse.'.$phpEx);

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
// This function is used for grabbing the sequences for postgres...
//
function pg_get_sequences($crlf, $backup_type)
{
	global $db;

	$get_seq_sql = "SELECT relname FROM pg_class WHERE NOT relname ~ 'pg_.*'
		AND relkind = 'S' ORDER BY relname";

	$seq = $db->sql_query($get_seq_sql);

	if( !$num_seq = $db->sql_numrows($seq) )
	{

		$return_val = "# No Sequences Found $crlf";

	}
	else
	{
		$return_val = "# Sequences $crlf";
		$i_seq = 0;

		while($i_seq < $num_seq)
		{
			$row = $db->sql_fetchrow($seq);
			$sequence = $row['relname'];

			$get_props_sql = "SELECT * FROM $sequence";
			$seq_props = $db->sql_query($get_props_sql);

			if($db->sql_numrows($seq_props) > 0)
			{
				$row1 = $db->sql_fetchrow($seq_props);

				if($backup_type == 'structure')
				{
					$row['last_value'] = 1;
				}

				$return_val .= "CREATE SEQUENCE $sequence start " . $row['last_value'] . ' increment ' . $row['increment_by'] . ' maxvalue ' . $row['max_value'] . ' minvalue ' . $row['min_value'] . ' cache ' . $row['cache_value'] . "; $crlf";

			}  // End if numrows > 0

			if(($row['last_value'] > 1) && ($backup_type != 'structure'))
			{
				$return_val .= "SELECT NEXTVALE('$sequence'); $crlf";
				unset($row['last_value']);
			}

			$i_seq++;

		} // End while..

	} // End else...

	return $returnval;

} // End function...

//
// The following functions will return the "CREATE TABLE syntax for the
// varying DBMS's
//
// This function returns, will return the table def's for postgres...
//
function get_table_def_postgresql($table, $crlf)
{
	global $drop, $db;

	$schema_create = "";
	//
	// Get a listing of the fields, with their associated types, etc.
	//

	$field_query = "SELECT a.attnum, a.attname AS field, t.typname as type, a.attlen AS length, a.atttypmod as lengthvar, a.attnotnull as notnull
		FROM pg_class c, pg_attribute a, pg_type t
		WHERE c.relname = '$table'
			AND a.attnum > 0
			AND a.attrelid = c.oid
			AND a.atttypid = t.oid
		ORDER BY a.attnum";
	$result = $db->sql_query($field_query);

	if(!$result)
	{
		message_die(GENERAL_ERROR, "Failed in get_table_def (show fields)", "", __LINE__, __FILE__, $field_query);
	} // end if..

	if ($drop == 1)
	{
		$schema_create .= "DROP TABLE $table;$crlf";
	} // end if

	//
	// Ok now we actually start building the SQL statements to restore the tables
	//

	$schema_create .= "CREATE TABLE $table($crlf";

	while ($row = $db->sql_fetchrow($result))
	{
		//
		// Get the data from the table
		//
		$sql_get_default = "SELECT d.adsrc AS rowdefault
			FROM pg_attrdef d, pg_class c
			WHERE (c.relname = '$table')
				AND (c.oid = d.adrelid)
				AND d.adnum = " . $row['attnum'];
		$def_res = $db->sql_query($sql_get_default);

		if (!$def_res)
		{
			unset($row['rowdefault']);
		}
		else
		{
			$row['rowdefault'] = @pg_result($def_res, 0, 'rowdefault');
		}

		if ($row['type'] == 'bpchar')
		{
			// Internally stored as bpchar, but isn't accepted in a CREATE TABLE statement.
			$row['type'] = 'char';
		}

		$schema_create .= '	' . $row['field'] . ' ' . $row['type'];

		if (preg_match('/char/i', $row['type']))
		{
			if ($row['lengthvar'] > 0)
			{
				$schema_create .= '(' . ($row['lengthvar'] -4) . ')';
			}
		}

		if (preg_match('/numeric/i', $row['type']))
		{
			$schema_create .= '(';
			$schema_create .= sprintf("%s,%s", (($row['lengthvar'] >> 16) & 0xffff), (($row['lengthvar'] - 4) & 0xffff));
			$schema_create .= ')';
		}

		if (!empty($row['rowdefault']))
		{
			$schema_create .= ' DEFAULT ' . $row['rowdefault'];
		}

		if ($row['notnull'] == 't')
		{
			$schema_create .= ' NOT NULL';
		}

		$schema_create .= ",$crlf";

	}
	//
	// Get the listing of primary keys.
	//

	$sql_pri_keys = "SELECT ic.relname AS index_name, bc.relname AS tab_name, ta.attname AS column_name, i.indisunique AS unique_key, i.indisprimary AS primary_key
		FROM pg_class bc, pg_class ic, pg_index i, pg_attribute ta, pg_attribute ia
		WHERE (bc.oid = i.indrelid)
			AND (ic.oid = i.indexrelid)
			AND (ia.attrelid = i.indexrelid)
			AND	(ta.attrelid = bc.oid)
			AND (bc.relname = '$table')
			AND (ta.attrelid = i.indrelid)
			AND (ta.attnum = i.indkey[ia.attnum-1])
		ORDER BY index_name, tab_name, column_name ";
	$result = $db->sql_query($sql_pri_keys);

	if(!$result)
	{
		message_die(GENERAL_ERROR, "Failed in get_table_def (show fields)", "", __LINE__, __FILE__, $sql_pri_keys);
	}

	while ( $row = $db->sql_fetchrow($result))
	{
		if ($row['primary_key'] == 't')
		{
			if (!empty($primary_key))
			{
				$primary_key .= ', ';
			}

			$primary_key .= $row['column_name'];
			$primary_key_name = $row['index_name'];

		}
		else
		{
			//
			// We have to store this all this info because it is possible to have a multi-column key...
			// we can loop through it again and build the statement
			//
			$index_rows[$row['index_name']]['table'] = $table;
			$index_rows[$row['index_name']]['unique'] = ($row['unique_key'] == 't') ? ' UNIQUE ' : '';
			$index_rows[$row['index_name']]['column_names'] .= $row['column_name'] . ', ';
		}
	}

	if (!empty($index_rows))
	{
		foreach ($index_rows as $idx_name => $props)
		{
			$props['column_names'] = preg_replace("/, $/", "" , $props['column_names']);
			$index_create .= 'CREATE ' . $props['unique'] . " INDEX $idx_name ON $table (" . $props['column_names'] . ");$crlf";
		}
	}

	if (!empty($primary_key))
	{
		$schema_create .= "	CONSTRAINT $primary_key_name PRIMARY KEY ($primary_key),$crlf";
	}

	//
	// Generate constraint clauses for CHECK constraints
	//
	$sql_checks = "SELECT rcname as index_name, rcsrc
		FROM pg_relcheck, pg_class bc
		WHERE rcrelid = bc.oid
			AND bc.relname = '$table'
			AND NOT EXISTS (
				SELECT *
					FROM pg_relcheck as c, pg_inherits as i
					WHERE i.inhrelid = pg_relcheck.rcrelid
						AND c.rcname = pg_relcheck.rcname
						AND c.rcsrc = pg_relcheck.rcsrc
						AND c.rcrelid = i.inhparent
			)";
	$result = $db->sql_query($sql_checks);

	if (!$result)
	{
		message_die(GENERAL_ERROR, "Failed in get_table_def (show fields)", "", __LINE__, __FILE__, $sql_checks);
	}

	//
	// Add the constraints to the sql file.
	//
	while ($row = $db->sql_fetchrow($result))
	{
		$schema_create .= '	CONSTRAINT ' . $row['index_name'] . ' CHECK ' . $row['rcsrc'] . ",$crlf";
	}

	$schema_create = preg_replace('/,/' . $crlf . '$', '', $schema_create);
	$index_create = preg_replace('/,/' . $crlf . '$', '', $index_create);

	$schema_create .= "$crlf);$crlf";

	if (!empty($index_create))
	{
		$schema_create .= $index_create;
	}

	//
	// Ok now we've built all the sql return it to the calling function.
	//
	return (stripslashes($schema_create));

}

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
		message_die(GENERAL_ERROR, 'Failed in get_table_def (show create table)', '', __LINE__, __FILE__, $query);
	}
	$create_sql = isset($row['Create Table']) ? $row['Create Table'] : end($row);
	$schema_create = ($drop == 1) ? 'DROP TABLE IF EXISTS ' . $quoted_table . ';' . $crlf : '';
	$schema_create .= $create_sql . ';';
	return $schema_create;

} // End get_table_def_mysql


//
// This fuction will return a tables create definition to be used as an sql
// statement.
//
//
// The following functions Get the data from the tables and format it as a
// series of INSERT statements, for each different DBMS...
// After every row a custom callback function $handler gets called.
// $handler must accept one parameter ($sql_insert);
//
//
// Here is the function for postgres...
//
function get_table_content_postgresql($table, $handler)
{
	global $db;

	//
	// Grab all of the data from current table.
	//

	$result = $db->sql_query("SELECT * FROM $table");

	if (!$result)
	{
		message_die(GENERAL_ERROR, "Failed in get_table_content (select *)", "", __LINE__, __FILE__, "SELECT * FROM $table");
	}

	$i_num_fields = $db->sql_numfields($result);

	for ($i = 0; $i < $i_num_fields; $i++)
	{
		$aryType[] = $db->sql_fieldtype($i, $result);
		$aryName[] = $db->sql_fieldname($i, $result);
	}

	$iRec = 0;

	while($row = $db->sql_fetchrow($result))
	{
		$schema_vals = '';
		$schema_fields = '';
		$schema_insert = '';
		//
		// Build the SQL statement to recreate the data.
		//
		for($i = 0; $i < $i_num_fields; $i++)
		{
			$strVal = $row[$aryName[$i]];
			if (preg_match("/char|text|bool/i", $aryType[$i]))
			{
				$strQuote = "'";
				$strEmpty = "";
				$strVal = addslashes($strVal);
			}
			elseif (preg_match("/date|timestamp/i", $aryType[$i]))
			{
				if (empty($strVal))
				{
					$strQuote = "";
				}
				else
				{
					$strQuote = "'";
				}
			}
			else
			{
				$strQuote = "";
				$strEmpty = "NULL";
			}

			if (empty($strVal) && $strVal != "0")
			{
				$strVal = $strEmpty;
			}

			$schema_vals .= " $strQuote$strVal$strQuote,";
			$schema_fields .= " $aryName[$i],";

		}

		$schema_vals = preg_replace("/,$/", "", $schema_vals);
		$schema_vals = preg_replace("/^ /", "", $schema_vals);
		$schema_fields = preg_replace("/,$/", "", $schema_fields);
		$schema_fields = preg_replace("/^ /", "", $schema_fields);

		//
		// Take the ordered fields and their associated data and build it
		// into a valid sql statement to recreate that field in the data.
		//
		$schema_insert = "INSERT INTO $table ($schema_fields) VALUES($schema_vals);";

		$handler(trim($schema_insert));
	}

	return(true);

}// end function get_table_content_postgres...

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
		message_die(GENERAL_ERROR, "Failed in get_table_content (select *)", "", __LINE__, __FILE__, "SELECT * FROM $quoted_table");
	}

	// Loop through the resulting rows and build the sql statement.
	if ($row = $db->sql_fetchrow($result))
	{
		$handler("\n#\n# Table Data for $table\n#\n");
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

	return(true);
}

function output_table_content($content)
{
	global $tempfile;

	//fwrite($tempfile, $content . "\n");
	//$backup_sql .= $content . "\n";
	echo $content ."\n";
	return;
}
//
// End Functions
// -------------


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
			header("Pragma: no-cache");
			header('Cache-Control: no-store, no-cache, must-revalidate');
			header('X-Content-Type-Options: nosniff');
			$do_gzip_compress = $gzipcompress && extension_loaded('zlib');
			if($do_gzip_compress)
			{
				@ob_start();
				@ob_implicit_flush(0);
				header("Content-Type: application/x-gzip; name=\"phpbb_db_backup.sql.gz\"");
				header('Content-Disposition: attachment; filename="phpbb_db_backup.sql.gz"');
			}
			else
			{
				header("Content-Type: text/x-delimtext; name=\"phpbb_db_backup.sql\"");
				header('Content-Disposition: attachment; filename="phpbb_db_backup.sql"');
			}

			//
			// Build the sql script file...
			//
			echo "#\n";
			echo "# phpBB Backup Script\n";
			echo "# Dump of tables for $dbname\n";
			echo "#\n# DATE : " .  gmdate("d-m-Y H:i:s", time()) . " GMT\n";
			echo "#\n\nSET FOREIGN_KEY_CHECKS=0;\n";

			if(SQL_LAYER == 'postgresql')
			{
				 echo "\n" . pg_get_sequences("\n", $backup_type);
			}
			for($i = 0; $i < count($tables); $i++)
			{
				$table_name = $tables[$i];

				switch (SQL_LAYER)
				{
					case 'postgresql':
						$table_def_function = "get_table_def_postgresql";
						$table_content_function = "get_table_content_postgresql";
						break;

					case 'mysql':
					case 'mysql4':
					case 'mysqli':
						$table_def_function = "get_table_def_mysql";
						$table_content_function = "get_table_content_mysql";
						break;
				}

				if($backup_type != 'data')
				{
					echo "#\n# TABLE: $table_name \n#\n";
					echo $table_def_function($table_name, "\n") . "\n";
				}

				if($backup_type != 'structure')
				{
					$table_content_function($table_name, "output_table_content");
				}
			}
			echo "\nSET FOREIGN_KEY_CHECKS=1;\n";
			
			if($do_gzip_compress)
			{
				$contents = gzencode(ob_get_contents(), 9);
				ob_end_clean();
				echo $contents;
			}
			exit;

			break;

		case 'restore':
			if(!isset($_POST['restore_start']))
			{
				//
				// Define Template files...
				//
				include('./page_header_admin.'.$phpEx);

				$template->set_filenames(array(
					"body" => "admin/db_utils_restore_body.tpl")
				);

				$s_hidden_fields = "<input type=\"hidden\" name=\"perform\" value=\"restore\" />" . phpbb_admin_session_field();

				$template->assign_vars(array(
					"L_DATABASE_RESTORE" => $lang['Database_Utilities'] . " : " . $lang['Restore'],
					"L_RESTORE_EXPLAIN" => $lang['Restore_explain'],
					"L_SELECT_FILE" => $lang['Select_file'],
					"L_START_RESTORE" => $lang['Start_Restore'],

					"S_DBUTILS_ACTION" => append_sid("admin_db_utilities.$phpEx"),
					"S_HIDDEN_FIELDS" => $s_hidden_fields)
				);
				$template->pparse("body");

				break;

			}
			else
			{
				phpbb_admin_require_post_session();
				//
				// Handle the file upload ....
				// If no file was uploaded report an error...
				//
				$backup_file = isset($_FILES['backup_file']) && is_array($_FILES['backup_file']) ? $_FILES['backup_file'] : array();
				$backup_file_name = isset($backup_file['name']) && is_scalar($backup_file['name']) ? basename((string) $backup_file['name']) : '';
				$backup_file_tmpname = isset($backup_file['tmp_name']) && is_scalar($backup_file['tmp_name']) ? (string) $backup_file['tmp_name'] : '';
				$backup_file_error = isset($backup_file['error']) ? (int) $backup_file['error'] : UPLOAD_ERR_NO_FILE;

				if($backup_file_error !== UPLOAD_ERR_OK || $backup_file_tmpname === '' || $backup_file_name === '')
				{
					message_die(GENERAL_MESSAGE, $lang['Restore_Error_no_file']);
				}
				//
				// If I file was actually uploaded, check to make sure that we
				// are actually passed the name of an uploaded file, and not
				// a hackers attempt at getting us to process a local system
				// file.
				//
				if( is_uploaded_file($backup_file_tmpname) )
				{
					if( preg_match('/\.sql(?:\.gz)?$/iD', $backup_file_name) )
					{
						if( preg_match('/\.gz$/iD', $backup_file_name) )
						{
							$do_gzip_compress = extension_loaded('zlib');

							if($do_gzip_compress)
							{
								$gz_ptr = gzopen($backup_file_tmpname, 'rb');
								if (!$gz_ptr)
								{
									message_die(GENERAL_ERROR, $lang['Restore_Error_decompress']);
								}
								$sql_query = "";
								while( !gzeof($gz_ptr) )
								{
									$sql_query .= gzgets($gz_ptr, 100000);
								}
								gzclose($gz_ptr);
							}
							else
							{
								message_die(GENERAL_ERROR, $lang['Restore_Error_decompress']);
							}
						}
						else
						{
							$sql_query = file_get_contents($backup_file_tmpname);
							if ($sql_query === false)
							{
								message_die(GENERAL_ERROR, $lang['Restore_Error_uploading']);
							}
						}
						//
						// Comment this line out to see if this fixes the stuff...
						//
						//$sql_query = stripslashes($sql_query);
					}
					else
					{
						message_die(GENERAL_ERROR, $lang['Restore_Error_filename']);
					}
				}
				else
				{
					message_die(GENERAL_ERROR, $lang['Restore_Error_uploading']);
				}

				if($sql_query != "")
				{
					// Strip out sql comments...
					$sql_query = remove_remarks($sql_query);
					$pieces = split_sql_file($sql_query, ";");

					$sql_count = count($pieces);
					for($i = 0; $i < $sql_count; $i++)
					{
						$sql = trim($pieces[$i]);

						if(!empty($sql) and $sql[0] != "#")
						{
							if(VERBOSE == 1)
							{
								echo "Executing: $sql\n<br>";
								flush();
							}

							$result = $db->sql_query($sql);

							if(!$result && ( !(SQL_LAYER == 'postgresql' && preg_match("/drop table/i", $sql) ) ) )
							{
								message_die(GENERAL_ERROR, "Error importing backup file", "", __LINE__, __FILE__, $sql);
							}
						}
					}
				}

				include('./page_header_admin.'.$phpEx);

				$template->set_filenames(array(
					"body" => "admin/admin_message_body.tpl")
				);

				$message = $lang['Restore_success'];

				$template->assign_vars(array(
					"MESSAGE_TITLE" => $lang['Database_Utilities'] . " : " . $lang['Restore'],
					"MESSAGE_TEXT" => $message)
				);

				$template->pparse("body");
				break;
			}
			break;
	}
}

include('./page_footer_admin.'.$phpEx);

?>
