<?php
/**
* <b>CrackerTracker File: class_ct_database.php</b><br><br>
*
* This class is responsible for all Database operations performed by
* CrackerTracker.
*
*
* @author Christian Knerr (cback)
* @package ctracker
* @version 5.0.0
* @since 16.07.2006 - 02:03:30
* @copyright (c) 2006 www.cback.de
*
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*/

class ct_database
{

	/**
	 * @var $settings (String Array)   The Array to save the CrackerTracker
	 * 								   Settings from the Database
	 *
	 * @var $blocklist (String Array)  Array wich saves the CrackerTracker
	 * 								   Blocklist Information from the Database
	 *
	 * @var $blocklist_id (Int Array)  Sepearate Array for Blocklist IDs
	 *
	 * @var $blocklist_count (Integer) Field-Counter for the Data in Blocklist
	 * 								   Array
	 *
	 * @var $verbose (Boolean) 		   Enables the ID Array for the Blocklist
	 */
	var $settings        = array();
	var $fieldnames_set  = array();
	var $blocklist       = array();
	var $blocklist_id    = array();
	var $blocklist_count = 0;
	var $verbose         = false;
	var $user_ip_value   = '';
	var $invalid_settings = array();

	/**
	 * <b>Constructor</b><br>
	 * Loads all Configuration Data from Database
	 */
	function __construct()
	{
		$this->ct_database();
	}

	function ct_database()
	{
		global $db, $lang, $HTTP_SERVER_VARS, $HTTP_ENV_VARS;

		// Set Up UserIP
		$remote_ip = ( !empty($HTTP_SERVER_VARS['REMOTE_ADDR']) ) ? $HTTP_SERVER_VARS['REMOTE_ADDR'] : ( ( !empty($HTTP_ENV_VARS['REMOTE_ADDR']) ) ? $HTTP_ENV_VARS['REMOTE_ADDR'] : getenv('REMOTE_ADDR') );
		$remote_ip = is_scalar($remote_ip) ? trim((string) $remote_ip) : '';
		$this->user_ip_value = (filter_var($remote_ip, FILTER_VALIDATE_IP) !== false) ? $remote_ip : '0.0.0.0';

		// Load CrackerTracker configuration from database
		$sql = 'SELECT * FROM ' . CTRACKER_CONFIG;

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_loading_config'], '', __LINE__, __FILE__, $sql);
		}

		while ( $row = $db->sql_fetchrow($result) )
		{
			$this->fieldnames_set[] = $row['ct_config_name'];
			$this->settings[$row['ct_config_name']] = $row['ct_config_value'];
		}

		$runtime_defaults = $this->default_settings();
		$numeric_ranges = $this->setting_ranges();
		foreach ($runtime_defaults as $setting_name => $setting_value)
		{
			if (array_key_exists($setting_name, $this->settings) && isset($numeric_ranges[$setting_name]) &&
				!$this->valid_numeric_setting($setting_name, $this->settings[$setting_name]))
			{
				$this->invalid_settings[$setting_name] = true;
				$this->settings[$setting_name] = $setting_value;
			}
			if (!isset($this->settings[$setting_name]))
			{
				$this->settings[$setting_name] = $setting_value;
			}
			if (!in_array($setting_name, $this->fieldnames_set, true))
			{
				$this->fieldnames_set[] = $setting_name;
			}
		}
	}

	/** Shared bounds for runtime loading, write APIs and ACP forms. */
	function setting_ranges()
	{
		static $ranges = array(
			'ipblock_enabled' => array(0, 1), 'ipblock_logsize' => array(1, 400),
			'search_feature_enabled' => array(0, 1), 'search_time_user' => array(1, 90),
			'search_count_user' => array(1, 6), 'search_time_guest' => array(1, 90),
			'search_count_guest' => array(1, 6), 'loginfeature' => array(0, 1),
			'logsize_logins' => array(1, 400), 'logincount' => array(5, 20),
			'login_history' => array(0, 1), 'login_history_count' => array(1, 60),
			'login_ip_check' => array(0, 1), 'spammer_blockmode' => array(0, 1),
			'spammer_postcount' => array(1, 12), 'spammer_time' => array(1, 90),
			'reg_protection' => array(0, 1), 'reg_blocktime' => array(1, 200),
			'pw_control' => array(0, 1), 'pw_validity' => array(6, 365),
			'pw_complex' => array(0, 1), 'pw_complex_mode' => array(1, 9),
			'pw_complex_min' => array(1, 20), 'pw_reset_feature' => array(0, 1),
			'pwreset_time' => array(1, 180), 'massmail_protection' => array(0, 1),
			'massmail_time' => array(1, 180), 'auto_recovery' => array(0, 1),
			'vconfirm_guest' => array(0, 1), 'autoban_mails' => array(0, 1),
			'detect_misconfiguration' => array(0, 1), 'spam_attack_boost' => array(0, 1),
			'spam_keyword_det' => array(0, 2), 'request_limit_enabled' => array(0, 1),
			'request_limit_login' => array(5, 100), 'request_limit_register' => array(1, 50),
			'request_limit_account' => array(1, 100), 'request_limit_write' => array(20, 500),
			'request_limit_upload' => array(1, 100), 'request_limit_content' => array(10, 200),
			'global_message_type' => array(0, 1), 'footer_layout' => array(1, 8),
			'password_timestamps_split' => array(0, 1),
			'last_file_scan' => array(0, PHP_INT_MAX), 'last_checksum_scan' => array(0, PHP_INT_MAX)
		);
		return $ranges;
	}

	function valid_numeric_setting($setting, $value)
	{
		$ranges = $this->setting_ranges();
		if (!is_string($setting) || !isset($ranges[$setting]) || (!is_string($value) && !is_int($value)))
		{
			return false;
		}
		$value = (string) $value;
		$max = (string) PHP_INT_MAX;
		if ($value === '' || strlen($value) > strlen($max) ||
			strspn($value, '0123456789') !== strlen($value) ||
			(strlen($value) > 1 && $value[0] === '0') ||
			(strlen($value) === strlen($max) && strcmp($value, $max) > 0))
		{
			return false;
		}
		return (int) $value >= $ranges[$setting][0] && (int) $value <= $ranges[$setting][1];
	}


	/**
	 * Canonical CrackerTracker defaults. These keep a partially upgraded or
	 * damaged configuration usable until the idempotent updater (or the ACP
	 * upsert path) restores the missing database rows.
	 */
	function default_settings()
	{
		return array(
			'ipblock_enabled' => '1',
			'ipblock_logsize' => '100',
			'auto_recovery' => '1',
			'vconfirm_guest' => '1',
			'autoban_mails' => '1',
			'detect_misconfiguration' => '1',
			'search_time_guest' => '30',
			'search_time_user' => '20',
			'search_count_guest' => '1',
			'search_count_user' => '4',
			'massmail_protection' => '1',
			'reg_protection' => '1',
			'reg_blocktime' => '30',
			'pwreset_time' => '20',
			'massmail_time' => '20',
			'spammer_time' => '30',
			'spammer_postcount' => '4',
			'spammer_blockmode' => '1',
			'loginfeature' => '1',
			'pw_reset_feature' => '1',
			'login_history' => '1',
			'login_history_count' => '10',
			'login_ip_check' => '1',
			'pw_validity' => '30',
			'password_timestamps_split' => '1',
			'pw_complex_min' => '4',
			'pw_complex_mode' => '1',
			'pw_control' => '0',
			'pw_complex' => '0',
			'last_file_scan' => '0',
			'last_checksum_scan' => '0',
			'logsize_logins' => '100',
			'global_message' => '',
			'global_message_type' => '1',
			'logincount' => '5',
			'search_feature_enabled' => '1',
			'spam_attack_boost' => '1',
			'spam_keyword_det' => '1',
			'footer_layout' => '3',
			'request_limit_enabled' => '1',
			'request_limit_login' => '30',
			'request_limit_register' => '10',
			'request_limit_account' => '20',
			'request_limit_write' => '120',
			'request_limit_upload' => '30',
			'request_limit_content' => '60'
		);
	}


	/**
	 * <b>change_configuration</b><br>
	 * This function is responsible to update a configuration value into the
	 * CrackerTracker Config Table. You can use this function for one or more
	 * values as you like.
	 *
	 * @param $setting (String) - Config Name
	 * @param $value (String)   - New Config Value
	 */
	function change_configuration($setting, $value)
	{
		global $db, $lang;

		$setting = is_scalar($setting) ? trim((string) $setting) : '';
		$known_settings = $this->default_settings();
		if (!preg_match('/^[a-z0-9_]{1,64}$/D', $setting) || !array_key_exists($setting, $known_settings))
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_updating_config']);
		}
		if (!is_string($value) && !is_int($value))
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_updating_config']);
		}
		if ($setting === 'global_message')
		{
			$value = $this->normalize_global_message($value);
		}
		else
		{
			if (!$this->valid_numeric_setting($setting, $value))
			{
				message_die(GENERAL_ERROR, $lang['ctracker_error_updating_config']);
			}
			$value = (string) $value;
		}

		// INSERT ... ON DUPLICATE KEY UPDATE also repairs a missing row. A plain
		// UPDATE silently affected zero rows in partially upgraded databases.
		$setting_sql = $db->sql_escape($setting);
		$value_sql = $db->sql_escape($value);
		$sql = "INSERT INTO " . CTRACKER_CONFIG . " (ct_config_name, ct_config_value)
			VALUES ('$setting_sql', '$value_sql')
			ON DUPLICATE KEY UPDATE ct_config_value = VALUES(ct_config_value)";

		// Execute SQL Command in database
		if ( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_updating_config'], '', __LINE__, __FILE__, $sql);
		}
		$this->settings[$setting] = $value;
		unset($this->invalid_settings[$setting]);
	}

	/** Preserve complete UTF-8 announcements within the VARCHAR(255) limit. */
	function normalize_global_message($message)
	{
		if (!is_string($message) || strlen($message) > 1020 ||
			preg_match('/\A.{0,255}\z/us', $message) !== 1 ||
			preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $message) !== 0)
		{
			global $lang;
			message_die(GENERAL_MESSAGE, $lang['ctracker_error_global_message']);
		}
		return $message;
	}

	/** Validate both announcement fields before issuing a single upsert. */
	function change_global_message($message, $type)
	{
		global $db, $lang;
		$message = $this->normalize_global_message($message);
		if ($type !== '0' && $type !== '1')
		{
			message_die(GENERAL_MESSAGE, $lang['ctracker_glob_msg_invalid_type']);
		}
		$message_sql = $db->sql_escape($message);
		$sql = "INSERT INTO " . CTRACKER_CONFIG . " (ct_config_name, ct_config_value)
			VALUES ('global_message_type', '$type'), ('global_message', '$message_sql')
			ON DUPLICATE KEY UPDATE ct_config_value = VALUES(ct_config_value)";
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_updating_config'], '', __LINE__, __FILE__, $sql);
		}
		$this->settings['global_message_type'] = $type;
		$this->settings['global_message'] = $message;
		unset($this->invalid_settings['global_message_type']);
	}


	/**
	 * <b>load_blocklist</b><br>
	 * If requested this function loads the Blocklist Data from the Database
	 */
	function load_blocklist()
	{
		global $db, $lang;

		// Initializing
		$this->blocklist_count = 0;
		$this->blocklist       = array();

		/*
		 * Verbose  Mode active? This also saves ID Values from Database in
		 * a sepearate array wich we can parse faster than all in one.
		 */
		if ( $this->verbose == true )
		{
			$this->blocklist_id = array();
		}


		// Load CrackerTracker blocklist from database
		$sql = 'SELECT * FROM ' . CTRACKER_IPBLOCKER . ' ORDER BY ct_blocker_value ASC';

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_loading_blocklist'], '', __LINE__, __FILE__, $sql);
		}

		while ( $row = $db->sql_fetchrow($result) )
		{
			// Database result values are already raw. stripslashes() corrupted
			// legitimate backslashes in stored User-Agent or host patterns.
			$this->blocklist[] = (string) $row['ct_blocker_value'];

			/*
		 	* Verbose  Mode active? This also saves ID Values from Database in a
		 	* sepearate array wich we can parse faster than all in one.
		 	*/
			if ( $this->verbose == true )
			{
				$this->blocklist_id[] = $row['id'];
			}
		}

		// How much entrys do we have?
		$this->blocklist_count = count($this->blocklist);
	}


	/**
	 * Validate rules without silently joining pasted lines or dropping NULs.
	 * Such rewriting can create a different (even catch-all) blocking rule.
	 */
	function normalize_blocklist_value($value)
	{
		$value = is_string($value) ? trim($value, ' ') : '';
		if ($value === '' || strlen($value) > 200 || substr_count($value, '*') > 8 ||
			preg_match('//u', $value) !== 1 ||
			preg_match('/[\x00-\x1f\x7f]/', $value) !== 0)
		{
			global $lang;
			message_die(GENERAL_MESSAGE, $lang['ctracker_error_blocklist_value']);
		}
		return $value;
	}

	function normalize_blocklist_id($id)
	{
		$value = (is_string($id) || is_int($id)) ? (string) $id : '';
		$blocklist_id = (int) $value;
		if ($value === '' || strlen($value) > 8 || !ctype_digit($value) ||
			$blocklist_id < 1 || $blocklist_id > 16777215)
		{
			global $lang;
			message_die(GENERAL_MESSAGE, $lang['ctracker_error_blocklist_id']);
		}
		return $blocklist_id;
	}

	/**
	 * <b>save_to_blocklist</b><br>
	 * This function writes a new entry into the Blocklist
	 *
	 * @param $blocklist_value (String) Value to write into the List
	 */
	function save_to_blocklist($blocklist_value)
	{
		global $db, $lang;

		$blocklist_value = $this->normalize_blocklist_value($blocklist_value);

		// The primary key is AUTO_INCREMENT. Let the database allocate it so
		// concurrent administrators cannot race on MAX(id) + 1.
		$sql = "INSERT INTO " . CTRACKER_IPBLOCKER . " (`ct_blocker_value`)
			VALUES ('" . $db->sql_escape($blocklist_value) . "')";

		// And lets write it into the database
		if ( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_insert_blocklist'], '', __LINE__, __FILE__, $sql);
		}
	}


	/**
	 * <b>delete_from_blocklist</b><br>
	 * This function deletes a record from the CrackerTracker Blocklist
	 *
	 * @param $blocklist_id (Integer) - ID Field of the entry
	 */
	function delete_from_blocklist($blocklist_id)
	{
		global $db, $lang;

		// Reject malformed IDs instead of coercing them to another record.
		$blocklist_id = $this->normalize_blocklist_id($blocklist_id);

		// Build an SQL Query
		$sql = 'DELETE FROM ' . CTRACKER_IPBLOCKER . ' WHERE id = ' . $blocklist_id;

		// And lets execute the command into database
		if ( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_delete_blocklist'], '', __LINE__, __FILE__, $sql);
		}
	}


	/**
	 * This function enables the ID saving array for the Blocklist information.
	 * We can run faster to two arrays where we need it than to use a 2D Array.
	 * Has some code-layout improvements in my opinion. I don't like foreach in
	 * 2D Arrays with huge constructs. No need for it. ;)
	 */
	function set_blocklist_verbose()
	{
		$this->verbose = true;
	}


	/**
	 * This function disables the ID saving array for the Blocklist information.
	 */
	function unset_blocklist_verbose()
	{
		$this->verbose = false;
	}


	/**
	 * <b>update_blocklist</b><br>
	 * This updates a record in the CrackerTracker Blocklist
	 *
	 * @param $blocklist_id (Integer)  - ID of the value wich should be replaced
	 * @param $blocklist_val (String)  - New entry for the record
	 */
	function update_blocklist($blocklist_id, $blocklist_val)
	{
		global $db, $lang;

		$blocklist_id = $this->normalize_blocklist_id($blocklist_id);
		$blocklist_val = $this->normalize_blocklist_value($blocklist_val);

		$sql = "UPDATE " . CTRACKER_IPBLOCKER . "
			SET ct_blocker_value = '" . $db->sql_escape($blocklist_val) . "'
			WHERE id = " . $blocklist_id;
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
		}
	}


	/**
	 * <b>update_login_history</b><br>
	 * This function manages the login history table if activated
	 *
	 * @param $user_id (Integer) User ID
	 */
	function update_login_history($user_id)
	{
		global $db, $lang;

		// Initialize
		$login_ip   = '';
		$login_time = 0;
		$temp_time  = 0;
		$temp_id    = 0;

		// Set values
		$login_ip   = $db->sql_escape((string) $this->user_ip_value);
		$login_time = time();

		// Ensure that $user_id is integer
		$user_id = intval($user_id);

		// Create SQL Command to insert new login
		$sql = 'INSERT INTO ' . CTRACKER_LOGINHISTORY . ' (ct_user_id, ct_login_ip, ct_login_time) VALUES ' . "($user_id, '$login_ip', $login_time)";

		// Execute SQL Command
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_login_history'], '', __LINE__, __FILE__, $sql);
		}

		// Delete old values from the Database
		$history_offset = max(0, intval($this->settings['login_history_count']) - 1);
		$sql = 'SELECT ct_login_id, ct_login_time FROM ' . CTRACKER_LOGINHISTORY .
			' WHERE ct_user_id = ' . $user_id .
			' ORDER BY ct_login_time DESC, ct_login_id DESC LIMIT ' . $history_offset . ',1';

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_login_history'], '', __LINE__, __FILE__, $sql);
		}

		$row       = $db->sql_fetchrow($result);
		$temp_time = !empty($row['ct_login_time']) ? intval($row['ct_login_time']) : 0;
		$temp_id   = !empty($row['ct_login_id']) ? intval($row['ct_login_id']) : 0;

		$sql = 'DELETE FROM ' . CTRACKER_LOGINHISTORY . ' WHERE ct_user_id = ' . $user_id .
			' AND (ct_login_time < ' . $temp_time .
			' OR (ct_login_time = ' . $temp_time . ' AND ct_login_id < ' . $temp_id . '))';

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_login_history'], '', __LINE__, __FILE__, $sql);
		}
	}


	/**
	 * <b>clean_up_login_history</b><br>
	 * Cleans the complete login_history Table
	 */
	function clean_up_login_history()
	{
		global $db, $lang;

		$sql = 'TRUNCATE ' . CTRACKER_LOGINHISTORY;

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_del_login_history'], '', __LINE__, __FILE__, $sql);
		}
	}


	/**
	 * <b>set_user_ip</b><br>
	 * Saves last Logged in IP Adress for the IP Scanner
	 *
	 * @param $user_id (Integer) User ID
	 */
	function set_user_ip($user_id)
	{
		global $db, $lang, $userdata;

		// Ensure that $user_id is integer
		$user_id = intval($user_id);

		$sql = 'UPDATE ' . USERS_TABLE . ' SET ct_last_ip = ct_last_used_ip WHERE user_id = ' . $user_id;

		// Execute SQL Command in database
		if ( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_updating_userdata'], '', __LINE__, __FILE__, $sql);
		}

		// Update Userdata Array (wich is already available here!)
		$userdata['ct_last_ip'] = $userdata['ct_last_used_ip'];

		$sql = 'UPDATE ' . USERS_TABLE . " SET ct_last_used_ip = '" . $db->sql_escape((string) $this->user_ip_value) . "' WHERE user_id = " . $user_id;

		// Execute SQL Command in database
		if ( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_updating_userdata'], '', __LINE__, __FILE__, $sql);
		}

		// Update Userdata Array (wich is already available here!)
		$userdata['ct_last_used_ip'] = $this->user_ip_value;
	}


	/**
	 * Return the lowest current administrator ID instead of assuming that the
	 * original phpBB user ID 2 still belongs to the board founder.
	 */
	function first_admin_user_id()
	{
		global $db, $lang;

		$sql = 'SELECT MIN(user_id) AS user_id FROM ' . USERS_TABLE . ' WHERE user_level = ' . ADMIN;
		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_loading_config'], '', __LINE__, __FILE__, $sql);
		}
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		return ($row && intval($row['user_id']) > 0) ? intval($row['user_id']) : 0;
	}

	/**
	 * <b>first_admin_protection</b>
	 * Checks if submitted user id is the user id of the first admin. If so stop
	 * the script.
	 *
	 * @param $user_id
	 */
	function first_admin_protection($user_id)
	{
		global $lang, $userdata;

		$user_id = intval($user_id);
		$current_user_id = isset($userdata['user_id']) ? intval($userdata['user_id']) : ANONYMOUS;
		if ($user_id > 0 && $user_id !== $current_user_id && $user_id === $this->first_admin_user_id())
		{
			message_die(GENERAL_MESSAGE, $lang['ctracker_gmb_1stadmin']);
		}
	}

}

?>
