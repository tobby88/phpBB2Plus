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
		foreach ($runtime_defaults as $setting_name => $setting_value)
		{
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
			'request_limit_upload' => '30'
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
		$value = is_scalar($value) ? trim((string) $value) : '';
		$known_settings = $this->default_settings();
		if (!preg_match('/^[a-z0-9_]{1,64}$/D', $setting) || !array_key_exists($setting, $known_settings))
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_updating_config']);
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
			$this->blocklist[] = stripslashes($row['ct_blocker_value']);

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
	 * <b>save_to_blocklist</b><br>
	 * This function writes a new entry into the Blocklist
	 *
	 * @param $blocklist_value (String) Value to write into the List
	 */
	function save_to_blocklist($blocklist_value)
	{
		global $db, $lang;

		$blocklist_value = is_scalar($blocklist_value) ? trim((string) $blocklist_value) : '';
		$blocklist_value = str_replace(array("\r", "\n", "\0"), '', $blocklist_value);
		if ($blocklist_value === '' || strlen($blocklist_value) > 200 || substr_count($blocklist_value, '*') > 8 || preg_match('/[\x00-\x1f\x7f]/', $blocklist_value))
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_insert_blocklist']);
		}

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

		// Clean up the input
		$blocklist_id = intval($blocklist_id);

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

		$blocklist_id = intval($blocklist_id);
		$blocklist_val = is_scalar($blocklist_val) ? trim((string) $blocklist_val) : '';
		$blocklist_val = str_replace(array("\r", "\n", "\0"), '', $blocklist_val);
		if ($blocklist_id < 1 || $blocklist_val === '' || strlen($blocklist_val) > 200 || substr_count($blocklist_val, '*') > 8 || preg_match('/[\x00-\x1f\x7f]/', $blocklist_val))
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_database_op']);
		}

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
