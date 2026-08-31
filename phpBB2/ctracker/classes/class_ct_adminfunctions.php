<?php
/**
* <b>CrackerTracker File: class_ct_adminfunctions.php</b><br><br>
*
* This class includes some things wich are only available into the ACP.
*
*
* @author Christian Knerr (cback)
* @package ctracker
* @version 5.0.0
* @since 20.07.2006 - 21:08:18
* @copyright (c) 2006 www.cback.de
*
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*/


// Constant check
if ( !defined('IN_PHPBB') || !defined('CTRACKER_ACP') )
{
	die('Hacking attempt!');
}


class ct_adminfunctions
{
	var $filechk_root = '';
	var $filechk_count = 0;
	var $filescan_root = '';
	var $filescan_count = 0;

	/**
	 * <b>ct_adminfunctions</b>
	 * Constructor
	 */
	function __construct()
	{
		$this->ct_adminfunctions();
	}

	function ct_adminfunctions()
	{
		// Currently nothing to do
	}


	/**
	 * <b>ct_keyword_b_block</b>
	 * Generates the Block Modes Switchfields
	 *
	 * @param $current (Integer) -> Current Setting
	 * @return $switch (String)
	 */
	function ct_keyword_b_block($current)
	{
		global $lang;

		$switch = '';
		$ch_sel = array_fill(0, 3, '');
		$ch_sel[$current] = ' selected="selected"';

		$switch .= '<option value="0"' . $ch_sel[0] . '>' . $lang['ctracker_settings_off'] . '</option>';
		$switch .= '<option value="1"' . $ch_sel[1] . '>' . $lang['Profile'] . '</option>';
		$switch .= '<option value="2"' . $ch_sel[2] . '>' . $lang['Profile'] . '&' . $lang['Post'] . '</option>';

		return $switch;
	}


	/**
	 * <b>ct_complex_mode</b>
	 * Generates the Password Complex Mode Switches
	 *
	 * @param $current (Integer) -> Current Setting
	 * @return $switch (String)
	 */
	function ct_complex_mode($current)
	{
		global $lang;

		$switch = '';
		$ch_sel = array_fill(0, 10, '');
		$ch_sel[$current] = ' selected="selected"';

		$switch .= '<option value="1"' . $ch_sel[1] . '>' . $lang['ctracker_complex_1'] . '</option>';
		$switch .= '<option value="2"' . $ch_sel[2] . '>' . $lang['ctracker_complex_2'] . '</option>';
		$switch .= '<option value="3"' . $ch_sel[3] . '>' . $lang['ctracker_complex_3'] . '</option>';
		$switch .= '<option value="4"' . $ch_sel[4] . '>' . $lang['ctracker_complex_4'] . '</option>';
		$switch .= '<option value="5"' . $ch_sel[5] . '>' . $lang['ctracker_complex_5'] . '</option>';
		$switch .= '<option value="6"' . $ch_sel[6] . '>' . $lang['ctracker_complex_6'] . '</option>';
		$switch .= '<option value="7"' . $ch_sel[7] . '>' . $lang['ctracker_complex_7'] . '</option>';
		$switch .= '<option value="8"' . $ch_sel[8] . '>' . $lang['ctracker_complex_8'] . '</option>';
		$switch .= '<option value="9"' . $ch_sel[9] . '>' . $lang['ctracker_complex_9'] . '</option>';

		return $switch;
	}


	/**
	 * <b>ct_generate_number_field</b>
	 * Generates Number Switchboxes
	 *
	 * @param $begin (Integer)   -> Start Number
	 * @param $end   (Integer)   -> End Number
	 * @param $current (Integer) -> Selected Number
	 * @return $switch (String)  -> Switch HTML Code
	 */
	function ct_generate_number_field($begin, $end, $current)
	{
		$switch = '';

		for($i = $begin; $i <= $end; $i++)
		{
			if($current == $i)
			{
				$switch .= '<option value="' . $i . '" selected="selected">' . $i . '</option>';
			}
			else
			{
				$switch .= '<option value="' . $i . '">' . $i . '</option>';
			}
		}

		return $switch;
	}


	/**
	 * <b>ct_generate_on_off</b>
	 * Generates Switch Fields to enable or disable functions
	 *
	 * @param $setting (Integer) 0: Off | 1: On
	 * @return $switch (String)
	 */
	function ct_generate_on_off($setting)
	{
		global $lang;

		$switch = '';

		if($setting == 1)
		{
			$switch = '<option value="1" selected="selected">' . $lang['ctracker_settings_on'] . '</option><option value="0">' . $lang['ctracker_settings_off'] . '</option>';
		}
		else
		{
			$switch = '<option value="1">' . $lang['ctracker_settings_on'] . '</option><option value="0" selected="selected">' . $lang['ctracker_settings_off'] . '</option>';
		}

		return $switch;
	}


	/**
	 * <b>do_filechk</b>
	 * This function is responsible for the CrackerTracker File Check
	 * (Hash Checker)
	 */
	function do_filechk()
	{
		global $db, $lang, $phpbb_root_path, $phpEx;

		$scan_root = @realpath($phpbb_root_path);
		if ($scan_root === false || !is_dir($scan_root) || !is_readable($scan_root))
		{
			message_die(CRITICAL_ERROR, $lang['ctracker_error_fileop']);
		}

		$this->filechk_root = str_replace('\\', '/', rtrim($scan_root, '/\\'));
		$this->filechk_count = 0;

		// Build the replacement separately. The existing baseline remains usable
		// if hashing or a database write fails halfway through the scan.
		$temporary_table = CTRACKER_FILECHK . '_new';
		$backup_table = CTRACKER_FILECHK . '_old';
		$sql = 'DROP TABLE IF EXISTS ' . $temporary_table;
		if (!$db->sql_query($sql))
		{
			message_die(CRITICAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}

		$sql = 'CREATE TABLE ' . $temporary_table . ' LIKE ' . CTRACKER_FILECHK;
		if (!$db->sql_query($sql))
		{
			message_die(CRITICAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}

		$this->recursive_filechk($phpbb_root_path, '', $phpEx, $temporary_table);
		if ($this->filechk_count < 1)
		{
			$db->sql_query('DROP TABLE IF EXISTS ' . $temporary_table);
			message_die(CRITICAL_ERROR, $lang['ctracker_error_fileop']);
		}

		$sql = 'DROP TABLE IF EXISTS ' . $backup_table;
		if (!$db->sql_query($sql))
		{
			message_die(CRITICAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}

		$sql = 'RENAME TABLE ' . CTRACKER_FILECHK . ' TO ' . $backup_table . ', ' .
			$temporary_table . ' TO ' . CTRACKER_FILECHK;
		if (!$db->sql_query($sql))
		{
			message_die(CRITICAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}

		$db->sql_query('DROP TABLE IF EXISTS ' . $backup_table);
	}


	/**
	 * Return a content checksum suitable for detecting same-size changes.
	 */
	function file_checksum($path, $required_root = '')
	{
		if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path))
		{
			return false;
		}
		if ($required_root !== '')
		{
			if (@is_link($path))
			{
				return false;
			}
			$resolved_root = @realpath($required_root);
			$resolved_path = @realpath($path);
			if ($resolved_root === false || $resolved_path === false)
			{
				return false;
			}
			$resolved_root = str_replace('\\', '/', rtrim($resolved_root, '/\\'));
			$resolved_path = str_replace('\\', '/', $resolved_path);
			if ($resolved_path !== $resolved_root && strpos($resolved_path, $resolved_root . '/') !== 0)
			{
				return false;
			}
			$path = $resolved_path;
		}

		$checksum = @hash_file('sha256', $path);
		return (is_string($checksum) && strlen($checksum) === 64) ? $checksum : false;
	}


	/**
	 * <b>recursive_filechk</b>
	 * Filewriter for the CrackerTracker Hashcode Checker
	 *
	 * @param $dir       = Recursively scanned Folder
	 * @param $prefix    = Current File Path
	 * @param $extension = File Extension to find
	 */
	function recursive_filechk($dir, $prefix = '', $extension = '', $target_table = '')
	{
		global $db, $lang;

		if ($target_table === '')
		{
			$target_table = CTRACKER_FILECHK;
		}

		$directory = @opendir($dir);
		if ($directory === false)
		{
			return false;
		}

		$extension = strtolower(ltrim((string) $extension, '.'));

		while (($file = @readdir($directory)) !== false)
		{
			if ($file === '.' || $file === '..')
			{
				continue;
			}

			$path = rtrim($dir, '/\\') . '/' . $file;
			// Never follow links: an administrator or compromised upload must not
			// make the integrity scan read files outside the forum tree.
			if (@is_link($path))
			{
				continue;
			}

			$resolved_path = @realpath($path);
			if ($resolved_path === false)
			{
				continue;
			}
			$resolved_path = str_replace('\\', '/', $resolved_path);
			if ($resolved_path !== $this->filechk_root && strpos($resolved_path, $this->filechk_root . '/') !== 0)
			{
				continue;
			}

			if (@is_dir($path))
			{
				$this->recursive_filechk($path, '', $extension, $target_table);
				continue;
			}

			$relative_path = substr($resolved_path, strlen($this->filechk_root));
			if (preg_match('~(^|/)cache(/|$)~i', $relative_path) ||
				strtolower(pathinfo($resolved_path, PATHINFO_EXTENSION)) !== $extension)
			{
				continue;
			}

			$filehash = $this->file_checksum($resolved_path);
			if ($filehash === false)
			{
				continue;
			}

			// Keep relocatable paths in the database so the installation can move.
			$stored_path = preg_replace('~/+~', '/', rtrim($dir, '/\\') . '/' . $file);
			$sql = 'INSERT INTO ' . $target_table . " (`filepath`, `hash`) VALUES ('" .
				$db->sql_escape($stored_path) . "', '" . $db->sql_escape($filehash) . "')";
			if (!$db->sql_query($sql))
			{
				message_die(CRITICAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
			}
			$this->filechk_count++;
		}

		@closedir($directory);
		return true;
	}


	/**
	* <b>ScanFile</b><br>
	* This function scans a file wich is saved into the
	* filescan database for potential security risks. Well this is a really
	* complex function because something like that is little bit complex to do
	* with PHP, but well, this algorithm works really fast and also if you have
	* a huge premodded board 30seconds execution time for a PHP Script should be
	* really enough to scan all PHP files of your board. Sorry for the little
	* spaghetti code in here but i've longtimes optimized this and this one is
	* really the shortest and fastest method to do that what this function
	* should do.
	*
	* @param $fid = File Identification Number in Database
	*/
	function ScanFile($source_table = '')
	{
		global $db, $phpbb_root_path, $lang;
		if ($source_table === '')
		{
			$source_table = CTRACKER_FILESCANNER;
		}

		$sql = 'SELECT id, filepath FROM ' . $source_table;

	  	if((!$result = $db->sql_query($sql)))
	  	{
	    	message_die(CRITICAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
	  	}

	  	while($row = $db->sql_fetchrow($result))
	  	{
			// Initialize vars
			$common_included = false;
			$constant_check  = false;
			$constant_set    = false;
			$func_or_class   = false;
			$func_class_flag = false;
			$reachable_code  = false;
			$root_path       = false;
			$extension_inc   = false;

			$action_counter  = 0;
			$security_risk   = 0;
			$bcounter        = 0;
			$file_db_id      = 0;

			$scanline        = '';
			$acp_flag        = false;

			$filename        = @file($row['filepath']);
			$file_db_id      = intval($row['id']);
			if (!is_array($filename))
			{
				$write_back = 'UPDATE ' . $source_table . ' SET safety = 10 WHERE id = ' . $file_db_id;
				$db->sql_query($write_back);
				continue;
			}

			for ($i = 0; $i <= count($filename)-1; $i++)
	    	{
				$scanline = $filename[$i];
				$scanline = strtolower($scanline);
				$scanline = str_replace(' ', '', $scanline);
				$scanline = str_replace("\t", '', $scanline);
				$scanline = str_replace(chr(13), '', $scanline);

				if(!preg_match('/\\$no_page_header|\\$confirm|\\$close/', $scanline) && !preg_match('/^[ \\t]*$\\r?\\n/m', $scanline) && !preg_match('/<\\?php|\\?>|\/\/|\/\/.|\/\\*.|\/\\*|#|#.|\*|\*./m', $scanline) && !empty($scanline))
				{
					$action_counter++;

					if(preg_match('/define\\(\'in_phpbb\'./m', $scanline) && $action_counter == 1)
					{
						$constant_set = true;
					}
					else if(preg_match('/if\\(!defined\\(\'in_phpbb\'./m', $scanline) && $action_counter == 1)
					{
						$constant_check = true;
						break;
					}
					else if($constant_set && preg_match('/\\$phpbb_root_path=./m', $scanline) && $action_counter == 2)
					{
						$root_path = true;
					}
					else if($constant_set && preg_match('/include\\(\\$phpbb_root_path\\.\'extension\\.inc|require\\(\\$phpbb_root_path\\.\'extension\\.inc/', $scanline) && $action_counter == 3)
					{
						$extension_inc = true;
					}
					else if($constant_set && preg_match('/include\\(\\$phpbb_root_path\\.\'common\\.|include\\(\'\\.\/common\\.|require\\(\'\\.\/common\\.|require\\(\\$phpbb_root_path\\.\'common\\.|require\\(\'\\.\/pagestart\\./', $scanline) && ($action_counter == 4 || $action_counter == 5))
					{
						$common_included = true;
						break;
					}
					else if($constant_set && preg_match('/if\\(!empty\\(\\$setmodules|if\\(isset|if\\(!isset/', $scanline))
					{
						$action_counter--;
						$acp_flag = true;

						if(preg_match('/{/m', $scanline))
						{
							$bcounter++;
						}

						if(preg_match('/}/m', $scanline))
						{
							$bcounter--;
						}
					}
					else if($constant_set && $acp_flag)
					{
						if(preg_match('/{/m', $scanline))
						{
							$bcounter++;
						}

						if(preg_match('/}/m', $scanline))
						{
							$bcounter--;
						}

						if($bcounter == 0)
						{
							$acp_flag = false;
						}

						$action_counter--;
					}
					else if(preg_match('/function.|class./', $scanline))
					{
						$func_or_class = true;
						$func_class_flag = true;

						if(preg_match('/{/m', $scanline))
						{
							$bcounter++;
						}

						if(preg_match('/}/m', $scanline))
						{
							$bcounter--;
						}
					}
					else if($func_or_class)
					{
						if(preg_match('/{/m', $scanline))
						{
							$bcounter++;
						}

						if(preg_match('/}/m', $scanline))
						{
							$bcounter--;
						}

						if($bcounter == 0)
						{
							$func_or_class = false;
						}
					}
					else if(!$constant_check || !$common_included || !$func_or_class)
					{
						$reachable_code = true;
						$func_class_flag = false;
						break;
					}
					else
					{
				  		$reachable_code = true;
				  		break;
					}// else
				} // if
			} // for

			// wich security scanner value will be written in database?
			if($constant_check)
			{
			  // Constant checked, so file OK
			  $security_risk   = 0;
			}
			else if($common_included && $constant_set && $root_path && $extension_inc)
			{
			  	// Every basics are there, so declare file as secure
			  	$security_risk   = 0;
			}
			else if($common_included && $constant_set && $root_path)
			{
			  	// We don't have the extension.inc included so someone can change the FileExtension
			  	$security_risk   = 1;
			}
			else if($common_included && $constant_set && $extension_inc)
			{
			  	// We don't have the root path defined
			  	$security_risk   = 2;
			}
			else if($extension_inc || $root_path)
			{
			  	// We don't have common.php included
			  	$security_risk   = 3;
			}
			else if($reachable_code)
			{
			  	// There is reachable code in the file
			  	$security_risk   = 4;
			}
			else if($common_included)
			{
			  	// We miss everything except the common.php
			  	$security_risk   = 5;
			}
			else if($func_class_flag)
			{
			  	// File is a function or class file, so no reachable code detected
			  	$security_risk   = 0;
			}
			else
			{
			  	// Something happened wich is not defined. Confusing message
			  	$security_risk   = 6;
			}

			// Write value back to database
			$write_back = 'UPDATE ' . $source_table . ' SET safety = ' . intval($security_risk) . ' WHERE id = ' . $file_db_id;
	  		if((!$backwriter = $db->sql_query($write_back)))
	  		{
	    		message_die(CRITICAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $write_back);
	  		}
	  	} // while
	}


	/**
	 * Build and analyze a new scanner report without destroying the previous
	 * complete report when traversal, parsing or database writes fail.
	 */
	function RunFileScan($dir, $extension = '')
	{
		global $db, $lang;

		$scan_root = @realpath($dir);
		if ($scan_root === false || !is_dir($scan_root) || !is_readable($scan_root))
		{
			message_die(CRITICAL_ERROR, $lang['ctracker_error_fileop']);
		}
		$this->filescan_root = str_replace('\\', '/', rtrim($scan_root, '/\\'));
		$this->filescan_count = 0;

		$temporary_table = CTRACKER_FILESCANNER . '_new';
		$backup_table = CTRACKER_FILESCANNER . '_old';
		$sql = 'DROP TABLE IF EXISTS ' . $temporary_table;
		if (!$db->sql_query($sql))
		{
			message_die(CRITICAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}
		$sql = 'CREATE TABLE ' . $temporary_table . ' LIKE ' . CTRACKER_FILESCANNER;
		if (!$db->sql_query($sql))
		{
			message_die(CRITICAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}

		$this->CreateFileList($dir, '', $extension, $temporary_table);
		if ($this->filescan_count < 1)
		{
			$db->sql_query('DROP TABLE IF EXISTS ' . $temporary_table);
			message_die(CRITICAL_ERROR, $lang['ctracker_error_fileop']);
		}
		$this->ScanFile($temporary_table);

		$sql = 'DROP TABLE IF EXISTS ' . $backup_table;
		if (!$db->sql_query($sql))
		{
			message_die(CRITICAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}
		$sql = 'RENAME TABLE ' . CTRACKER_FILESCANNER . ' TO ' . $backup_table . ', ' .
			$temporary_table . ' TO ' . CTRACKER_FILESCANNER;
		if (!$db->sql_query($sql))
		{
			message_die(CRITICAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}
		$db->sql_query('DROP TABLE IF EXISTS ' . $backup_table);
	}


	/**
	* <b>DropData</b><br>
	* This function cleans up the Database from the FileScanner before rescanning
	*/
	function DropData()
	{
		global $db, $lang;

	  	$sql = 'TRUNCATE TABLE ' . CTRACKER_FILESCANNER;

	  	if(!($result = $db->sql_query($sql)))
	  	{
	    	message_die(CRITICAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
	  	}
	}


	/**
	 * <b>CreateFileList</b><br>
	 * This is a recursive Function to display all specific files from all
	 * subdirectories for the file security scanner. We save these files into a
	 * database for better FileList handling later - and we can always read out
	 * the results without rescanning. ;)
	 *
	 * @param $dir       = recursively scanned folder
	 * @param $prefix    = current file path
	 * @param $extension = file extension to find
	 */
	function CreateFileList($dir, $prefix = '', $extension = '', $target_table = '')
	{
	  	global $db, $lang;
		if ($target_table === '')
		{
			$target_table = CTRACKER_FILESCANNER;
		}
		if ($this->filescan_root === '')
		{
			$scan_root = @realpath($dir);
			if ($scan_root === false)
			{
				return false;
			}
			$this->filescan_root = str_replace('\\', '/', rtrim($scan_root, '/\\'));
			$this->filescan_count = 0;
		}

		$directory = @opendir($dir);
		if ($directory === false)
		{
			return false;
		}
		$extension = strtolower(ltrim((string) $extension, '.'));

		while (($file = @readdir($directory)) !== false)
		{
			if ($file === '.' || $file === '..')
			{
				continue;
			}

			$path = rtrim($dir, '/\\') . '/' . $file;
			if (@is_link($path))
			{
				continue;
			}
			$resolved_path = @realpath($path);
			if ($resolved_path === false)
			{
				continue;
			}
			$resolved_path = str_replace('\\', '/', $resolved_path);
			if ($resolved_path !== $this->filescan_root && strpos($resolved_path, $this->filescan_root . '/') !== 0)
			{
				continue;
			}

			$is_dir = @is_dir($path);
			$temp_path = preg_replace('~/+~', '/', $path);
			$relative_path = substr($resolved_path, strlen($this->filescan_root));
			if (!$is_dir && strtolower(pathinfo($resolved_path, PATHINFO_EXTENSION)) === $extension &&
				!preg_match('~(^|/)(?:language|db|cache)(?:/|$)|(?:^|/)config\\.php$|(?:^|/)common\\.php$~i', $relative_path))
			{
				$newid = ++$this->filescan_count;
				$sql = 'INSERT INTO ' . $target_table . ' (`id`, `filepath`, `safety`)
					VALUES (' . $newid . ", '" . $db->sql_escape($temp_path) . "', 10)";
				if (!$db->sql_query($sql))
				{
					message_die(CRITICAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
				}
			}

			if ($is_dir)
			{
				$this->CreateFileList($path, '', $extension, $target_table);
			}
		}

		@closedir($directory);
		return true;
	} // CreateFileList


	/**
	 * <b>set_global_message</b>
	 * Sets the global message flag for every user
	 */
	function set_global_message()
	{
		global $db, $lang;

		$sql = 'UPDATE ' . USERS_TABLE . ' SET ct_global_msg_read = 1';

		if( !($result = $db->sql_query($sql)) )
		{
			message_die(CRITICAL_ERROR, $lang['ctracker_error_updating_userdata'], '', __LINE__, __FILE__, $sql);
		}
	}


	/**
	 * <b>unset_global_message</b>
	 * Unset global message flag for every user
	 */
	function unset_global_message()
	{
		global $db, $lang;

		$sql = 'UPDATE ' . USERS_TABLE . ' SET ct_global_msg_read = 0';

		if( !($result = $db->sql_query($sql)) )
		{
			message_die(CRITICAL_ERROR, $lang['ctracker_error_updating_userdata'], '', __LINE__, __FILE__, $sql);
		}
	}


	/**
	 * <b>recover_configuration</b>
	 * Quick Recover phpBB Configuration
	 */
	function recover_configuration()
	{
		global $db, $lang;

		// Keep the backup table stable so a failed refresh cannot drop the last
		// usable snapshot before a replacement exists.
		$sql = 'CREATE TABLE IF NOT EXISTS ' . CTRACKER_BACKUP . ' (
					`config_name` varchar( 255 ) NOT NULL ,
					`config_value` varchar( 255 ) NOT NULL ,
					PRIMARY KEY ( `config_name` )
					) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		if ( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}

		$temporary_table = CTRACKER_BACKUP . '_new';
		$backup_table = CTRACKER_BACKUP . '_old';
		$sql = 'DROP TABLE IF EXISTS ' . $temporary_table;
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}
		$sql = 'CREATE TABLE ' . $temporary_table . ' LIKE ' . CTRACKER_BACKUP;
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}

		// Insert config data
		$sql = 'SELECT * FROM ' . CONFIG_TABLE;

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_loading_config'], '', __LINE__, __FILE__, $sql);
		}

		while ( $row = $db->sql_fetchrow($result) )
		{
			$config_name = $db->sql_escape((string) $row['config_name']);
			$config_value = $db->sql_escape((string) $row['config_value']);
			$sql2 = "INSERT INTO " . $temporary_table . " (`config_name`, `config_value`) VALUES ('" . $config_name . "', '" . $config_value . "')";
			if ( !$result2 = $db->sql_query($sql2) )
			{
				message_die(GENERAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql2);
			}
		}

		// Insert Backup Timestamp
		$sql = 'INSERT INTO ' . $temporary_table . ' (`config_name`, `config_value`) VALUES (\'ct_last_backup\', \'' . time() . '\')';
		if ( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}

		$sql = 'DROP TABLE IF EXISTS ' . $backup_table;
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}
		$sql = 'RENAME TABLE ' . CTRACKER_BACKUP . ' TO ' . $backup_table . ', ' .
			$temporary_table . ' TO ' . CTRACKER_BACKUP;
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}
		$db->sql_query('DROP TABLE IF EXISTS ' . $backup_table);
	}


	/**
	 * <b>restore_configuration</b>
	 * Quick restore phpBB Configuration
	 */
	function restore_configuration()
	{
		global $db, $lang;

		// Restore values in place. Dropping and recreating the live configuration
		// table could lose its charset, indexes or newer settings on interruption.
		$sql = 'SELECT * FROM ' . CTRACKER_BACKUP;

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_loading_config'], '', __LINE__, __FILE__, $sql);
		}

		$restored_values = 0;
		while ( $row = $db->sql_fetchrow($result) )
		{
			if ($row['config_name'] === 'ct_last_backup')
			{
				continue;
			}
			$config_name = $db->sql_escape((string) $row['config_name']);
			$config_value = $db->sql_escape((string) $row['config_value']);
			$sql2 = "INSERT INTO " . CONFIG_TABLE . " (`config_name`, `config_value`)
				VALUES ('" . $config_name . "', '" . $config_value . "')
				ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)";
			if ( !$result2 = $db->sql_query($sql2) )
			{
				message_die(GENERAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql2);
			}
			$restored_values++;
		}
		if ($restored_values < 1)
		{
			message_die(GENERAL_ERROR, $lang['ctracker_rec_never_saved']);
		}

		global $phpbb_root_path;
		@unlink($phpbb_root_path . 'cache/config_data.cache');
	}
}

?>
