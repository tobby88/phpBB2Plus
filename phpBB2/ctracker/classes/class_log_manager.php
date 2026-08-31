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
	 * Just delete a File in a way wich works without delete it and
	 * recreate it later
	 *
	 * @param $file_id File Identification Number
	 */
	function delete_logfile($file_id)
	{
		// Set Vars
		$path        = $this->create_ct_path($file_id);
		$resetstring = ($file_id != 6) ? '1|||' . time() . "|||null|||null|||null|||null|||null\n" : '';

		// Delete now
		$logentry = @fopen($path, 'c+b');
		if ($logentry === false)
		{
			return $this->ct_file_error();
		}
		if (@flock($logentry, LOCK_EX))
		{
			@ftruncate($logentry, 0);
			@rewind($logentry);
			@fwrite($logentry, $resetstring);
			@fflush($logentry);
			@flock($logentry, LOCK_UN);
		}
		@fclose($logentry);
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
		$path = $this->create_ct_path($file_id);

		// Write down new log entry
		$logentry = @fopen($path, 'ab');
		if ($logentry === false)
		{
			return $this->ct_file_error();
		}
		if (@flock($logentry, LOCK_EX))
		{
			@fwrite($logentry, $str_log . "\n");
			@fflush($logentry);
			@flock($logentry, LOCK_UN);
		}
		@fclose($logentry);
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
		$path                   = $this->create_ct_path(1);
		$counterfile = @fopen($path, 'c+b');
		if ($counterfile === false)
		{
			return $this->ct_file_error();
		}
		if (@flock($counterfile, LOCK_EX))
		{
			@rewind($counterfile);
			$this->ct_counter_value = max(0, intval(stream_get_contents($counterfile))) + max(0, intval($value));
			@ftruncate($counterfile, 0);
			@rewind($counterfile);
			@fwrite($counterfile, (string) $this->ct_counter_value);
			@fflush($counterfile);
			@flock($counterfile, LOCK_UN);
		}
		@fclose($counterfile);
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

		$path     = $this->create_ct_path($file_id);
		if ($file_id != 6)
    {
		  $log_lines = @file($path);
		  $logsize = is_array($log_lines) ? max(0, count($log_lines) - 1) : 0;
		}
		else
		{
      $debug_array = @file($path);
	  if (is_array($debug_array) && isset($debug_array[0]))
	  {
        $debug_delimiter = $debug_array[0];
        $logsize  = count($debug_array) - count(array_diff($debug_array, (array) $debug_delimiter));
	  }
    }

		return $logsize;
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
			$this->delete_logfile(2);
			$this->increment_counter($current_size);
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
			$this->delete_logfile($file_id);
			$this->increment_counter($current_size);
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
		// Variable Reset
		$path                   = '';
		$this->ct_counter_value = 0;

		// Create Path to Counter file and load the current value
		$path                   = $this->create_ct_path(1);
		$this->ct_counter_value = max(0, intval(@file_get_contents($path)));

		// Current entries in the logfiles have to be added
		for($i = 2; $i <= 5; $i++)
		{
			// Ignore the wrong logins
      if ($i == 4) continue;
      $this->ct_counter_value += $this->check_log_size($i);
		}

		// Return Counter Value
		return max(0, intval($this->ct_counter_value));
	}
}

?>
