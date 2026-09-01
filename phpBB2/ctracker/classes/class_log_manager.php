<?php
/**
* <b>CrackerTracker File: class_log_manager.php</b><br><br>
*
* This class is responsible for all Logfile operations of the CrackerTracker
* Security System.
*
* <br><br><br><br>
*
* <h1>Used File Identification IDs</h1><br>
*
* We use some File Identification Numbers to identify wich logfile should
* be written:<br><br>
*
* 1:	logfile_attempt_counter.txt
* 2:	logfile_worms.txt <br>
* 3:	logfile_blocklist.txt <br>
* 4:	logfile_malformed_logins.txt <br>
* 5:	logfile_spammer.txt <br>
*
* @author Christian Knerr (cback)
* @package ctracker
* @version 5.0.0
* @since 16.07.2006 - 02:02:47
* @copyright (c) 2006 www.cback.de
*
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*/

class log_manager
{

	/**
	 * Some vars wich are used to save the Data from the Attacker for the
	 * Logfile, etc.
	 *
	 * @var $ct_type_msg <i>(Integer)</i> Shows the CrackerTracker if its a
	 * Attack warning or a System Information
	 *
	 * @var $ct_timestamp <i>(Integer)</i> Current Timestamp
	 *
	 * @var $ct_request <i>(String)</i> How was the attack?
	 *
	 * @var $ct_referer <i>(String)</i> Where was the Attacker coming from?
	 * (Mostly empty)
	 *
	 * @var $ct_user_agent <i>(String)</i> UserAgent of the Attacker
	 *
	 * @var $ct_remote_addr <i>(String)</i> IP Adress of the Attacker
	 *
	 * @var $ct_remote_host <i>(String)</i> Remote Host of the Attacker
	 *
	 * @var $ct_counter_value <i>(Integer)</i> Counter Value of the
	 * CrackerTracker Attack Counter
	 */
	var $ct_type_msg      = 0;
	var $ct_timestamp     = 0;
	var $ct_request       = '';
	var $ct_referer       = '';
	var $ct_user_agent    = '';
	var $ct_remote_addr   = '';
	var $ct_remote_host   = '';
	var $ct_counter_value = 0;
	var $last_deleted_entries = 0;


	/**
	 * <b>Constructor</b><br>
	 * Write User Information to Vars we need these informations later into the
	 * Log File
	 */
	function __construct()
	{
		$this->log_manager();
	}

	function log_manager()
	{

		global $HTTP_SERVER_VARS, $HTTP_ENV_VARS;

		$this->ct_type_msg      = 0;
		$this->ct_timestamp     = time();
		$php_self = isset($HTTP_SERVER_VARS['PHP_SELF']) && is_scalar($HTTP_SERVER_VARS['PHP_SELF']) ? (string) $HTTP_SERVER_VARS['PHP_SELF'] : '';
		$query_string = isset($HTTP_SERVER_VARS['QUERY_STRING']) && is_scalar($HTTP_SERVER_VARS['QUERY_STRING']) ? (string) $HTTP_SERVER_VARS['QUERY_STRING'] : '';
		$query_string = $this->redact_query_string($query_string);
		$this->ct_request       = $php_self . ($query_string !== '' ? '?' . $query_string : '');
		$referer = isset($HTTP_SERVER_VARS['HTTP_REFERER']) && is_scalar($HTTP_SERVER_VARS['HTTP_REFERER']) ? (string) $HTTP_SERVER_VARS['HTTP_REFERER'] : '';
		$this->ct_referer       = $this->redact_url_query($referer);
		$this->ct_user_agent    = isset($HTTP_SERVER_VARS['HTTP_USER_AGENT']) ? $HTTP_SERVER_VARS['HTTP_USER_AGENT'] : '';
		$this->ct_remote_addr   = ( !empty($HTTP_SERVER_VARS['REMOTE_ADDR']) ) ? $HTTP_SERVER_VARS['REMOTE_ADDR'] : ( ( !empty($HTTP_ENV_VARS['REMOTE_ADDR']) ) ? $HTTP_ENV_VARS['REMOTE_ADDR'] : getenv('REMOTE_ADDR') );
		$this->ct_remote_host   = isset($HTTP_SERVER_VARS['REMOTE_HOST']) ? $HTTP_SERVER_VARS['REMOTE_HOST'] : '';
		$this->ct_counter_value = 0;

	}

	function sensitive_query_key($key)
	{
		$key = strtolower(rawurldecode(str_replace('+', ' ', (string) $key)));
		// Treat brackets, dots, dashes and other form-name separators alike so
		// nested names such as account[token] cannot bypass redaction.
		$key = trim(preg_replace('/[^a-z0-9]+/', '_', $key), '_');
		return preg_match('/(?:^|_)(?:password|passwd|pass|sid|session(?:id)?|token|csrf|confirm(?:ation)?_?code|act(?:ivation)?_?key|reset_?key|api_?key|access_?key|autologinid|credential|secret|user_actkey)(?:$|_)/', $key) === 1;
	}

	function redact_query_string($query_string)
	{
		$query_string = is_scalar($query_string) ? substr((string) $query_string, 0, 8192) : '';
		if ($query_string === '')
		{
			return '';
		}

		$parts = preg_split('/([&;])/', $query_string, -1, PREG_SPLIT_DELIM_CAPTURE);
		for ($i = 0; $i < count($parts); $i += 2)
		{
			$separator = strpos($parts[$i], '=');
			$key = ($separator === false) ? $parts[$i] : substr($parts[$i], 0, $separator);
			if ($this->sensitive_query_key($key))
			{
				$parts[$i] = $key . '=REDACTED';
			}
		}
		return implode('', $parts);
	}

	function redact_url_query($url)
	{
		$url = is_scalar($url) ? substr((string) $url, 0, 8192) : '';
		$query_offset = strpos($url, '?');
		if ($query_offset === false)
		{
			return $url;
		}

		return substr($url, 0, $query_offset + 1) . $this->redact_query_string(substr($url, $query_offset + 1));
	}


	/**
	 * This is responsible to create a String for the Logfile
	 *
	 * @return $log_entry - Logfile formatted String
	 */
	function to_string()
	{
		$log_entry = '';				// Logfile String
		$splitter = '|||';			// File Token

		// Write information into Logfile String
		$log_entry .= $this->ct_type_msg;
		$log_entry .= $splitter;
		$log_entry .= $this->ct_timestamp;
		$log_entry .= $splitter;
		$log_entry .= $this->clean_log_value($this->ct_request, 2048);
		$log_entry .= $splitter;
		$log_entry .= $this->clean_log_value($this->ct_referer, 1024);
		$log_entry .= $splitter;
		$log_entry .= $this->clean_log_value($this->ct_user_agent, 1024);
		$log_entry .= $splitter;
		$log_entry .= $this->clean_log_value($this->ct_remote_addr, 64);
		$log_entry .= $splitter;
		$log_entry .= $this->clean_log_value($this->ct_remote_host, 255);

		// Return String to write into the Logfile
		return $log_entry;
	}

	function clean_log_value($value, $max_length)
	{
		$value = is_scalar($value) ? (string) $value : '';
		$value = str_replace(array('|||', "\r", "\n", "\0"), '', $value);
		return substr($value, 0, max(0, intval($max_length)));
	}


	/**
	 * This little function translates the file Identification numbers into the
	 * correct path to the selected file.
	 *
	 * @param $file_id File Identification Number
	 * @return $ct_filepath Path to the Logfile
	 */
	function create_ct_path($file_id)
	{
		global $phpbb_root_path;

		$ct_filepath = '';
		$file_id = intval($file_id);

		switch($file_id)
		{
			case 1: $ct_filepath = $phpbb_root_path . 'ctracker/logfiles/logfile_attempt_counter.txt';
					break;

			case 2: $ct_filepath = $phpbb_root_path . 'ctracker/logfiles/logfile_worms.txt';
					break;

			case 3: $ct_filepath = $phpbb_root_path . 'ctracker/logfiles/logfile_blocklist.txt';
					break;

			case 4: $ct_filepath = $phpbb_root_path . 'ctracker/logfiles/logfile_malformed_logins.txt';
					break;

			case 5: $ct_filepath = $phpbb_root_path . 'ctracker/logfiles/logfile_spammer.txt';
					break;

			case 6: $ct_filepath = $phpbb_root_path . 'ctracker/logfiles/logfile_debug_mode.txt';
					break;
		}

		return $ct_filepath;
	}

	/**
	 * Validate the fixed log destination without following a planted symlink.
	 * The file may not exist yet, therefore its real parent is checked too.
	 */
	function safe_log_path($file_id)
	{
		global $phpbb_root_path;

		$path = $this->create_ct_path($file_id);
		if ($path === '' || @is_link($path))
		{
			return false;
		}
		$expected_parent = @realpath($phpbb_root_path . 'ctracker/logfiles');
		$actual_parent = @realpath(dirname($path));
		if ($expected_parent === false || $actual_parent === false ||
			str_replace('\\', '/', $expected_parent) !== str_replace('\\', '/', $actual_parent))
		{
			return false;
		}

		return $path;
	}

	function invalidate_counter_cache()
	{
		global $phpbb_root_path;
		$cache_file = $phpbb_root_path . 'cache/ctracker_counter.cache';
		if (@is_file($cache_file) && !@is_link($cache_file))
		{
			@unlink($cache_file);
		}
	}


	/**
	 * Just delete a File in a way wich works without delete it and
	 * recreate it later
	 *
	 * @param $file_id File Identification Number
	 */
	function delete_logfile($file_id)
	{
		// Set Vars
		$this->last_deleted_entries = 0;
		$path        = $this->safe_log_path($file_id);
		if ($path === false)
		{
			return $this->ct_file_error();
		}
		$resetstring = ($file_id != 6) ? '1|||' . time() . "|||null|||null|||null|||null|||null\n" : '';

		// Delete now
		$logentry = @fopen($path, 'c+b');
		if ($logentry === false)
		{
			return $this->ct_file_error();
		}
		if (!@flock($logentry, LOCK_EX))
		{
			@fclose($logentry);
			return $this->ct_file_error();
		}
		// Count while holding the same lock that protects truncation. Otherwise a
		// concurrent security event could be deleted without being transferred to
		// the persistent counter.
		if (intval($file_id) !== 6)
		{
			@rewind($logentry);
			if (@fgets($logentry, 16385) !== false)
			{
				while (@fgets($logentry, 16385) !== false)
				{
					$this->last_deleted_entries++;
				}
			}
		}
		$success = @ftruncate($logentry, 0) && @rewind($logentry) &&
			@fwrite($logentry, $resetstring) === strlen($resetstring) && @fflush($logentry);
		@flock($logentry, LOCK_UN);
		@fclose($logentry);
		if (!$success)
		{
			return $this->ct_file_error();
		}
		$this->invalidate_counter_cache();
		return true;
	}


	/**
	 * This function writes the log entry into a logfile
	 *
	 * @param $file_id File Identification Number
	 * @param $str_log String to write into the Logfile
	 */
	function write_to_log($file_id, $str_log)
	{
		// Set Vars
		$path = $this->safe_log_path($file_id);
		if ($path === false)
		{
			return $this->ct_file_error();
		}
		$str_log = is_scalar($str_log) ? substr(str_replace(array("\r", "\n", "\0"), '', (string) $str_log), 0, 8192) : '';

		// Write down new log entry
		$logentry = @fopen($path, 'ab');
		if ($logentry === false)
		{
			return $this->ct_file_error();
		}
		if (!@flock($logentry, LOCK_EX))
		{
			@fclose($logentry);
			return $this->ct_file_error();
		}
		$line = $str_log . "\n";
		$success = @fwrite($logentry, $line) === strlen($line) && @fflush($logentry);
		@flock($logentry, LOCK_UN);
		@fclose($logentry);
		if (!$success)
		{
			return $this->ct_file_error();
		}
		$this->invalidate_counter_cache();
		return true;
	}


	/**
	 * This function sets new values into the attack Counter
	 *
	 * @param $value Increment Step
	 */
	function increment_counter($value)
	{
		// Variable Reset
		$path                   = '';
		$this->ct_counter_value = 0;

		// Create Path to Counter file and load the current Status
		$path                   = $this->safe_log_path(1);
		if ($path === false)
		{
			return $this->ct_file_error();
		}
		$counterfile = @fopen($path, 'c+b');
		if ($counterfile === false)
		{
			return $this->ct_file_error();
		}
		if (!@flock($counterfile, LOCK_EX))
		{
			@fclose($counterfile);
			return $this->ct_file_error();
		}
		@rewind($counterfile);
		$this->ct_counter_value = max(0, intval(@fgets($counterfile, 64))) + max(0, intval($value));
		$counter_value = (string) $this->ct_counter_value;
		$success = @ftruncate($counterfile, 0) && @rewind($counterfile) &&
			@fwrite($counterfile, $counter_value) === strlen($counter_value) && @fflush($counterfile);
		@flock($counterfile, LOCK_UN);
		@fclose($counterfile);
		if (!$success)
		{
			return $this->ct_file_error();
		}
		$this->invalidate_counter_cache();
		return true;

	}


	/**
	 * check_log_size is responsible to check how much entrys are in a Log file
	 *
	 * @param $file_id Identification of the Log File
	 * @return $logsize Count how many entrys are in the Log
	 */
	function check_log_size($file_id)
	{
		$logsize  = 0;
		$path     = '';

		$path     = $this->safe_log_path($file_id);
		if ($path === false)
		{
			return 0;
		}
		$handle = @fopen($path, 'rb');
		if ($handle === false)
		{
			return 0;
		}
		$first_line = @fgets($handle);
		if ($first_line !== false)
		{
			if ($file_id != 6)
			{
				while (@fgets($handle) !== false)
				{
					$logsize++;
				}
			}
			else
			{
				$logsize = 1;
				while (($line = @fgets($handle)) !== false)
				{
					if ($line === $first_line)
					{
						$logsize++;
					}
				}
			}
		}
		@fclose($handle);

		return $logsize;
	}

	/**
	 * Read a bounded tail of a logfile for the ACP. This avoids loading a
	 * corrupted or manually enlarged file completely into PHP memory.
	 */
	function read_log_lines($file_id, $max_lines = 1000)
	{
		$path = $this->safe_log_path($file_id);
		$max_lines = max(1, min(5000, intval($max_lines)));
		if ($path === false || !is_file($path) || !is_readable($path))
		{
			return array();
		}

		$handle = @fopen($path, 'rb');
		if ($handle === false)
		{
			return array();
		}
		$lines = array();
		while (($line = @fgets($handle, 16385)) !== false)
		{
			$lines[] = $line;
			if (count($lines) > $max_lines)
			{
				array_shift($lines);
			}
		}
		@fclose($handle);

		return $lines;
	}


	/**
	 * Stops the script on file operation errors
	 * We use this because we're unsure where we use this file.
	 *
	 * For example a Problem occurs in the logfile of the Exploit protection we
	 * don't have the message_die() function from phpBB available!
	 */
	function ct_file_error()
	{
		// Logging must never turn a blocked request or ordinary forum page into
		// a site-wide outage. Keep the protection decision and report the local
		// operational problem through PHP's error log instead.
		error_log('CrackerTracker could not write its local log files.');
		return false;
	}


	/**
	 * This function writes an entry into the Worm Logfile
	 */
	function write_worm()
	{
		/*
		 * Because we don't want to contact the database on exploit attacks we
		 * have to use a fixed logfile size value here for this logfile. The
		 * default value is set to 100 lines. Feel free to change it!
		 */
		$max_log_size = 100;
		$current_size = $this->check_log_size(2);
		if ($current_size >= $max_log_size)
		{
			if ($this->delete_logfile(2))
			{
				$this->increment_counter($this->last_deleted_entries);
			}
		}

		$this->write_to_log(2, $this->to_string());
	}


	/**
	 * This function writes an entry into the IP Blocker Logfile
	 *
	 * @param $logsize Allowed size of the Logfile
	 */
	function write_general_logfile($logsize, $file_id)
	{
		$logsize = max(1, min(10000, intval($logsize)));
		$current_size = $this->check_log_size($file_id);
		if ($current_size >= $logsize)
		{
			if ($this->delete_logfile($file_id))
			{
				$this->increment_counter($this->last_deleted_entries);
			}
		}

		$this->write_to_log($file_id, $this->to_string());
	}


	/**
	 * This function changes the $ct_request var in our Object
	 * to the Username someone has in your forum. We do this step
	 * because the Logfiles 4 and 5 have to display wich Board user
	 * tried to Login or tried to Spam.
	 *
	 * @param $username (String) - Username
	 */
	function prepare_log($username)
	{
		$this->ct_request = $username;
	}


	/**
	 * This function creates a correct value for the counter
	 * (Because of performance reasons we don't increment the counter each time
	 * an attack occurs, we just write it into the logfile and increment the
	 * counter value when we have to delete a full logfile. But when you want to
	 * display the attack counter inside the footer we need to build our correct
	 * counter value with this function)
	 *
	 * @return $this->ct_counter_value New counter Value
	 */
	function get_counter_value()
	{
		global $phpbb_root_path;

		// Variable Reset
		$path                   = '';
		$this->ct_counter_value = 0;
		$cache_file = $phpbb_root_path . 'cache/ctracker_counter.cache';
		if (function_exists('phpbb_data_cache_read') && @is_file($cache_file) && !@is_link($cache_file) && @filemtime($cache_file) >= time() - 60)
		{
			$cached = phpbb_data_cache_read($cache_file);
			if (is_array($cached) && isset($cached['value']))
			{
				$this->ct_counter_value = max(0, intval($cached['value']));
				return $this->ct_counter_value;
			}
		}

		// Create Path to Counter file and load the current value
		$path                   = $this->safe_log_path(1);
		if ($path !== false && ($counter_handle = @fopen($path, 'rb')) !== false)
		{
			$this->ct_counter_value = max(0, intval(@fgets($counter_handle, 64)));
			@fclose($counter_handle);
		}

		// Current entries in the logfiles have to be added
		for($i = 2; $i <= 5; $i++)
		{
			// Ignore the wrong logins
      if ($i == 4) continue;
      $this->ct_counter_value += $this->check_log_size($i);
		}
		if (function_exists('phpbb_data_cache_write'))
		{
			phpbb_data_cache_write($cache_file, array('value' => $this->ct_counter_value));
		}

		// Return Counter Value
		return max(0, intval($this->ct_counter_value));
	}
}

?>
