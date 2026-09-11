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


/**
 * Serialize publication on the database server, including installations with
 * several web workers/hosts. A dedicated non-persistent connection owns the
 * advisory lock: normal return, exceptions, message_die/exit and termination
 * cannot leave it on a pooled forum connection. All scan/recovery SQL uses that same
 * connection, so losing the lock session also prevents further publication.
 */
class ct_scan_lock
{
	var $connection = null;
	var $acquired = null;

	function __construct($database, $table)
	{
		// Strip an explicit persistent prefix as well as passing false.
		$server = preg_replace('/^p:/', '', $database->server);
		$this->connection = new sql_db($server, $database->user, $database->password, $database->dbname, false);
		if (!$this->connection->db_connect_id)
		{
			$this->connection = null;
			return;
		}
		register_shutdown_function(array($this, 'release'));
		// GET_LOCK names are server-global and limited to 64 bytes on MySQL.
		$name = 'ctscan:' . md5($database->dbname . "\0" . $table);
		$result = $this->connection->sql_query("SELECT GET_LOCK('" . $name . "', 0) AS acquired");
		if ($result)
		{
			$row = $this->connection->sql_fetchrow($result);
			$this->connection->sql_freeresult($result);
			if (isset($row['acquired']) && ($row['acquired'] === 1 || $row['acquired'] === '1'))
			{
				$this->acquired = 1;
			}
			else if (isset($row['acquired']) && ($row['acquired'] === 0 || $row['acquired'] === '0'))
			{
				$this->acquired = 0;
			}
		}
		if ($this->acquired !== 1)
		{
			$this->release();
		}
	}

	function release()
	{
		if ($this->connection !== null)
		{
			$connection = $this->connection;
			$this->connection = null;
			// Connection termination releases its GET_LOCK, even when the
			// ordinary forum connection was already closed by error rendering.
			$connection->sql_close();
		}
	}
}

class ct_adminfunctions
{
	var $filechk_root = '';
	var $filechk_count = 0;
	var $filescan_root = '';
	var $filescan_count = 0;
	var $scan_database = null;

	function scan_database()
	{
		return $this->scan_database !== null ? $this->scan_database : $GLOBALS['db'];
	}

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

		$current = intval($current);
		if ($current < 0 || $current > 2)
		{
			$current = 0;
		}
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

		$current = intval($current);
		if ($current < 1 || $current > 9)
		{
			$current = 1;
		}
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
		$lock = $this->acquire_scan_lock(CTRACKER_FILECHK);
		$this->scan_database = $lock->connection;
		try
		{
			$this->build_filechk();
		}
		finally
		{
			$this->scan_database = null;
			$lock->release();
		}
	}

	function acquire_scan_lock($table, $busy_message = 'ctracker_scan_busy')
	{
		global $db, $lang;
		$lock = new ct_scan_lock($db, $table);
		if ($lock->acquired === 0)
		{
			message_die(GENERAL_MESSAGE, $lang[$busy_message]);
		}
		if ($lock->acquired !== 1)
		{
			message_die(CRITICAL_ERROR, $lang['ctracker_error_database_op']);
		}
		return $lock;
	}

	/** Reject old/restored source tables before CREATE LIKE can propagate them. */
	private function require_modern_storage($db, $table)
	{
		global $lang;
		$name = $db->sql_escape($table);
		$sql = "SELECT COUNT(*) AS modern_storage FROM information_schema.TABLES t " .
			"WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = '" . $name . "' " .
			"AND t.TABLE_TYPE = 'BASE TABLE' AND t.ENGINE = 'InnoDB' AND t.ROW_FORMAT = 'Dynamic' " .
			"AND t.TABLE_COLLATION = 'utf8mb4_unicode_ci' AND NOT EXISTS (" .
			"SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA " .
			"AND c.TABLE_NAME = t.TABLE_NAME AND c.CHARACTER_SET_NAME IS NOT NULL " .
			"AND (c.CHARACTER_SET_NAME <> 'utf8mb4' OR c.COLLATION_NAME <> 'utf8mb4_unicode_ci'))";
		$result = $db->sql_query($sql);
		$row = $result ? $db->sql_fetchrow($result) : false;
		if ($result) { $db->sql_freeresult($result); }
		if (!$row || !isset($row['modern_storage']) || (string) $row['modern_storage'] !== '1')
		{
			message_die(CRITICAL_ERROR, $lang['ctracker_error_storage_migration']);
		}
	}

	private function build_filechk()
	{
		global $lang, $phpbb_root_path, $phpEx;
		$db = $this->scan_database();

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
		$this->require_modern_storage($db, CTRACKER_FILECHK);
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

		$scan_complete = $this->recursive_filechk($phpbb_root_path, '', $phpEx, $temporary_table);
		$this->require_modern_storage($db, $temporary_table);
		if (!$scan_complete || $this->filechk_count < 1)
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
	 * Resolve a readable regular file and prove that it belongs to the forum
	 * tree. Scanner paths are persisted in the database and therefore must not
	 * be trusted when a later request reads them again.
	 */
	function resolve_file_within_root($path, $required_root)
	{
		if (!$this->is_local_file_path($path) || !$this->is_local_file_path($required_root))
		{
			return false;
		}
		if (@is_link($path))
		{
			return false;
		}

		$resolved_root = @realpath($required_root);
		$resolved_path = @realpath($path);
		if ($resolved_root === false || $resolved_path === false || !is_file($resolved_path) || !is_readable($resolved_path))
		{
			return false;
		}

		$resolved_root = str_replace('\\', '/', rtrim($resolved_root, '/\\'));
		$resolved_path = str_replace('\\', '/', $resolved_path);
		if ($resolved_path !== $resolved_root && strpos($resolved_path, $resolved_root . '/') !== 0)
		{
			return false;
		}

		return $resolved_path;
	}

	/**
	 * Reject stream wrappers before ANY stat/read operation on persisted paths.
	 * Native Windows drive paths are local; URL schemes and NUL bytes are not.
	 */
	function is_local_file_path($path)
	{
		if (!is_string($path) || $path === '' || strpos($path, "\0") !== false)
		{
			return false;
		}
		$colon = strpos($path, ':');
		if ($colon !== false && $colon < strcspn($path, '/\\'))
		{
			// Do not depend on PCRE for this security boundary. Also reject
			// unusual registered schemes, including ones beginning with digits.
			return DIRECTORY_SEPARATOR === '\\' && $colon === 1 && strlen($path) > 2 &&
				strspn($path[0], 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ') === 1 &&
				($path[2] === '/' || $path[2] === '\\');
		}
		return true;
	}

	/**
	 * Only report "not found" beneath a readable/searchable, in-tree parent.
	 * Unresolvable parents, links and out-of-tree paths remain "not checkable".
	 */
	function missing_file_within_root($path, $required_root)
	{
		if (!$this->is_local_file_path($path) || !$this->is_local_file_path($required_root))
		{
			return false;
		}
		$root = @realpath($required_root);
		$parent = @realpath(dirname($path));
		if ($root === false || $parent === false)
		{
			return false;
		}
		$root = str_replace('\\', '/', rtrim($root, '/\\'));
		$parent = str_replace('\\', '/', $parent);
		if (($parent !== $root && strpos($parent, $root . '/') !== 0) ||
			!is_dir($parent) || !is_readable($parent) ||
			(DIRECTORY_SEPARATOR !== '\\' && !is_executable($parent)))
		{
			return false;
		}
		return @lstat($path) === false;
	}


	/**
	 * Return a content checksum suitable for detecting same-size changes.
	 */
	function file_checksum($path, $required_root = '')
	{
		if (!$this->is_local_file_path($path))
		{
			return false;
		}
		if ($required_root !== '')
		{
			$path = $this->resolve_file_within_root($path, $required_root);
			if ($path === false)
			{
				return false;
			}
		}
		else if (!is_file($path) || !is_readable($path))
		{
			return false;
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
		global $lang;
		$db = $this->scan_database();

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
				@closedir($directory);
				return false;
			}
			$resolved_path = str_replace('\\', '/', $resolved_path);
			if ($resolved_path !== $this->filechk_root && strpos($resolved_path, $this->filechk_root . '/') !== 0)
			{
				continue;
			}

			if (@is_dir($path))
			{
				// Cached PHP is deliberately outside the baseline; do not enter
				// excluded directories and mistake their permissions for failure.
				if (strcasecmp($file, 'cache') === 0)
				{
					continue;
				}
				if (!$this->recursive_filechk($path, '', $extension, $target_table))
				{
					@closedir($directory);
					return false;
				}
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
				@closedir($directory);
				return false;
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
		global $phpbb_root_path, $lang;
		$db = $this->scan_database();
		if ($source_table === '')
		{
			$source_table = CTRACKER_FILESCANNER;
		}
		if ($this->filescan_root === '')
		{
			$scan_root = @realpath($phpbb_root_path);
			if ($scan_root === false || !is_dir($scan_root) || !is_readable($scan_root))
			{
				message_die(CRITICAL_ERROR, $lang['ctracker_error_fileop']);
			}
			$this->filescan_root = str_replace('\\', '/', rtrim($scan_root, '/\\'));
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

			$file_db_id      = intval($row['id']);
			$resolved_file   = $this->resolve_file_within_root($row['filepath'], $this->filescan_root);
			$filename        = ($resolved_file !== false) ? @file($resolved_file) : false;
			if (!is_array($filename))
			{
				$write_back = 'UPDATE ' . $source_table . ' SET safety = 10 WHERE id = ' . $file_db_id;
				if (!$db->sql_query($write_back))
				{
					message_die(CRITICAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $write_back);
				}
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
		$lock = $this->acquire_scan_lock(CTRACKER_FILESCANNER);
		$this->scan_database = $lock->connection;
		try
		{
			$this->build_file_scan($dir, $extension);
		}
		finally
		{
			$this->scan_database = null;
			$lock->release();
		}
	}

	private function build_file_scan($dir, $extension = '')
	{
		global $lang;
		$db = $this->scan_database();

		$scan_root = @realpath($dir);
		if ($scan_root === false || !is_dir($scan_root) || !is_readable($scan_root))
		{
			message_die(CRITICAL_ERROR, $lang['ctracker_error_fileop']);
		}
		$this->filescan_root = str_replace('\\', '/', rtrim($scan_root, '/\\'));
		$this->filescan_count = 0;

		$temporary_table = CTRACKER_FILESCANNER . '_new';
		$backup_table = CTRACKER_FILESCANNER . '_old';
		$this->require_modern_storage($db, CTRACKER_FILESCANNER);
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

		$scan_complete = $this->CreateFileList($dir, '', $extension, $temporary_table);
		$this->require_modern_storage($db, $temporary_table);
		if (!$scan_complete || $this->filescan_count < 1)
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
		global $lang;
		$db = $this->scan_database();
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
				@closedir($directory);
				return false;
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
				// These trees are already excluded from this heuristic report.
				if (in_array(strtolower($file), array('language', 'db', 'cache'), true))
				{
					continue;
				}
				if (!$this->CreateFileList($path, '', $extension, $target_table))
				{
					@closedir($directory);
					return false;
				}
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
		$lock = $this->acquire_scan_lock(CTRACKER_BACKUP, 'ctracker_recovery_busy');
		try
		{
			$this->build_configuration_backup($lock->connection);
		}
		finally
		{
			$lock->release();
		}
	}

	private function build_configuration_backup($db)
	{
		global $lang;

		// Keep the backup table stable so a failed refresh cannot drop the last
		// usable snapshot before a replacement exists.
		$sql = 'CREATE TABLE IF NOT EXISTS ' . CTRACKER_BACKUP . ' (
					`config_name` varchar( 191 ) NOT NULL ,
					`config_value` varchar( 255 ) NOT NULL ,
					PRIMARY KEY ( `config_name` )
					) ENGINE=InnoDB ROW_FORMAT=DYNAMIC CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		if ( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}

		$temporary_table = CTRACKER_BACKUP . '_new';
		$backup_table = CTRACKER_BACKUP . '_old';
		$this->require_modern_storage($db, CTRACKER_BACKUP);
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
		$this->require_modern_storage($db, $temporary_table);
		$sql = 'SELECT * FROM ' . CONFIG_TABLE;

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_loading_config'], '', __LINE__, __FILE__, $sql);
		}

		$backup_values = 0;
		while ( $row = $db->sql_fetchrow($result) )
		{
			// Older restores could copy this metadata key into CONFIG_TABLE.
			// It must not collide with the completion marker written below.
			if (strcasecmp((string) $row['config_name'], 'ct_last_backup') === 0)
			{
				continue;
			}
			$config_name = $db->sql_escape((string) $row['config_name']);
			$config_value = $db->sql_escape((string) $row['config_value']);
			$sql2 = "INSERT INTO " . $temporary_table . " (`config_name`, `config_value`) VALUES ('" . $config_name . "', '" . $config_value . "')";
			if ( !$result2 = $db->sql_query($sql2) )
			{
				message_die(GENERAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql2);
			}
			$backup_values++;
		}
		$db->sql_freeresult($result);
		if ($backup_values < 1)
		{
			$db->sql_query('DROP TABLE IF EXISTS ' . $temporary_table);
			message_die(GENERAL_ERROR, $lang['ctracker_rec_empty_source']);
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
		global $lang, $phpbb_root_path;
		// Share the backup's lock so its marker and rows cannot come from
		// different snapshots and another restore cannot interleave its writes.
		$lock = $this->acquire_scan_lock(CTRACKER_BACKUP, 'ctracker_recovery_busy');
		try
		{
			$db = $lock->connection;
			// Never commit silently truncated values from a legacy/custom backup.
			if (!$db->sql_query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')"))
			{
				message_die(GENERAL_ERROR, $lang['ctracker_error_database_op']);
			}
			if (!$db->sql_query('START TRANSACTION'))
			{
				message_die(GENERAL_ERROR, $lang['ctracker_error_database_op']);
			}
			// Hold a metadata lock before checking the engine, so a concurrent
			// ALTER cannot silently remove transactional guarantees mid-restore.
			$result = $db->sql_query('SELECT config_name FROM ' . CONFIG_TABLE . ' LIMIT 0');
			if (!$result)
			{
				message_die(GENERAL_ERROR, $lang['ctracker_error_loading_config']);
			}
			$db->sql_freeresult($result);
			$sql = "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" .
				$db->sql_escape(CONFIG_TABLE) . "'";
			$result = $db->sql_query($sql);
			$engine = $result ? $db->sql_fetchrow($result) : false;
			if ($result) { $db->sql_freeresult($result); }
			if (!$engine || !isset($engine['ENGINE']) || strcasecmp($engine['ENGINE'], 'InnoDB') !== 0)
			{
				message_die(GENERAL_ERROR, $lang['ctracker_rec_transaction_required']);
			}
			$this->restore_configuration_backup($lock->connection);
			if (!$db->sql_query('COMMIT'))
			{
				message_die(GENERAL_ERROR, $lang['ctracker_error_database_op']);
			}
			// Legacy caches are no longer read by common.php. Remove one if it
			// remains from an earlier installation, only after a committed restore.
			@unlink($phpbb_root_path . 'cache/config_data.cache');
		}
		finally
		{
			// The owned non-persistent connection also rolls back uncommitted
			// writes on exception, message_die/exit or worker termination.
			$lock->release();
		}
	}

	private function restore_configuration_backup($db)
	{
		global $lang;

		// The timestamp is written last into the staging snapshot. Its presence
		// proves that enumeration completed before the tables were swapped.
		$marker_sql = 'SELECT config_value FROM ' . CTRACKER_BACKUP .
			" WHERE config_name = 'ct_last_backup' LIMIT 1";
		if (!($marker_result = $db->sql_query($marker_sql)))
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_loading_config'], '', __LINE__, __FILE__, $marker_sql);
		}
		$marker_row = $db->sql_fetchrow($marker_result);
		$db->sql_freeresult($marker_result);
		if (!$marker_row || !self::valid_backup_timestamp($marker_row['config_value']))
		{
			message_die(GENERAL_ERROR, $lang['ctracker_rec_never_saved']);
		}

		// Restore values in place. Dropping and recreating the live configuration
		// table could lose its charset, indexes or newer settings on interruption.
		$sql = 'SELECT config_name, config_value FROM ' . CTRACKER_BACKUP .
			" WHERE config_name <> 'ct_last_backup'";

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_loading_config'], '', __LINE__, __FILE__, $sql);
		}

		$restored_values = 0;
		while ( $row = $db->sql_fetchrow($result) )
		{
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

	}

	static function valid_backup_timestamp($value)
	{
		if (!is_string($value) && !is_int($value))
		{
			return false;
		}
		$value = (string) $value;
		$max = (string) PHP_INT_MAX;
		return $value !== '' && $value[0] !== '0' &&
			strlen($value) <= strlen($max) && strspn($value, '0123456789') === strlen($value) &&
			(strlen($value) < strlen($max) || strcmp($value, $max) <= 0);
	}
}

?>
