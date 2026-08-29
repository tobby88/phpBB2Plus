<?php
/**
* <b>CrackerTracker File: class_ct_userfunctions.php</b><br><br>
*
* This class implements all userfunctions for the CrackerTracker security
* system. These are the Database handling of the userdata field as well
* as the handling of security relevant functions for the Board internal
* engines.
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

class ct_userfunctions
{
	/**
	 * Return a bounded scalar POST value without triggering PHP 8 type errors.
	 */
	function post_value($name, $max_length = 65535)
	{
		global $HTTP_POST_VARS;

		if (!isset($HTTP_POST_VARS[$name]) || !is_scalar($HTTP_POST_VARS[$name]))
		{
			return '';
		}

		$value = (string) $HTTP_POST_VARS[$name];
		if (strlen($value) > $max_length)
		{
			$value = substr($value, 0, $max_length);
		}

		return $value;
	}

	/**
	 * <b>search_handler</b><br>
	 * This controls the CrackerTracker Search Security functions and
	 * outputs an wait-message if a user or guest has executed more searches
	 * than allowed.
	 */
	function search_handler()
	{
		global $userdata, $ctracker_config, $lang;

		if ( $ctracker_config->settings['search_feature_enabled'] == 0 )
		{
			// Search feature function was disabled
			return;
		}

		if ( $userdata['user_id'] == ANONYMOUS )
		{
			$max_searches = max(1, intval($ctracker_config->settings['search_count_guest']));
			$wait_time = max(1, intval($ctracker_config->settings['search_time_guest']));
			$identity = 'ip:' . (string) $ctracker_config->user_ip_value;
		}
		else
		{
			$max_searches = max(1, intval($ctracker_config->settings['search_count_user']));
			$wait_time = max(1, intval($ctracker_config->settings['search_time_user']));
			$identity = 'user:' . intval($userdata['user_id']);
		}

		if (!function_exists('ctracker_rate_limit_increment'))
		{
			return;
		}

		$retry_after = ctracker_rate_limit_increment('search', $identity, $wait_time, $max_searches);
		if ($retry_after !== false && $retry_after > 0)
		{
			if (!headers_sent())
			{
				http_response_code(429);
				header('Retry-After: ' . intval($retry_after));
				header('Cache-Control: no-store');
			}
			$waitmessage = sprintf($lang['ctracker_info_search_time'], $max_searches, $wait_time, intval($retry_after));
			message_die(GENERAL_MESSAGE, $waitmessage);
		}
	}


	/**
	 * <b>check_ip_range</b><br>
	 * This function checks the IP Range of an user after login.
	 * Its part of the IP Range Scanner.
	 *
	 * @return (String) (the info message itself)
	 */
	function check_ip_range()
	{
		global $lang, $userdata;

		$last_ip = isset($userdata['ct_last_ip']) && is_scalar($userdata['ct_last_ip']) ? (string) $userdata['ct_last_ip'] : '';
		$last_used_ip = isset($userdata['ct_last_used_ip']) && is_scalar($userdata['ct_last_used_ip']) ? (string) $userdata['ct_last_used_ip'] : '';
		if ($last_ip === '0.0.0.0' || $last_used_ip === '0.0.0.0' ||
			filter_var($last_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ||
			filter_var($last_used_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
		{
			return 'allclear'; // not initialized or not representable by this legacy IPv4 range check
		}

		$first_ip_range  = array();
		$second_ip_range = array();

		$first_ip_range  = explode('.', $last_used_ip);
		$second_ip_range = explode('.', $last_ip);


		if ( $first_ip_range[0] == $second_ip_range[0] && $first_ip_range[1] == $second_ip_range[1])
		{
			return 'allclear';
		}

		return sprintf($lang['ctracker_ipwarn_chng'], $first_ip_range[0] . '.' . $first_ip_range[1] . '.x.x', $second_ip_range[0] . '.' . $second_ip_range[1] . '.x.x');
	}


	/**
	 * <b>handle_postings</b>
	 * This is the spammer post detection. Every features for post scanning you
	 * can find here in one place. This function includes two features. Standard
	 * Spammer detection system and the System for Spam Detection Boost and Spam
	 * Detection Wordfilter.
	 *
	 * <br><br>
	 *
	 * I will show in a little diagram how this function works because the code
	 * is little bit tricky if you have not programmed it. ;-)
	 *
	 * <br><br>
	 *
	 * First we check if time() >= spammer_time. If so we have to start a new
	 * counting for this user. So we write ct_last_post = time() +
	 * $ctracker_config->settings['spammer_time'] into the usertable and we set
	 * the Database field ct_post_counter to 1.
	 *
	 * If time is not >= spammer_time we have to check if ct_post_counter
	 * < $ctracker_config->settings['spammer_postcount'] to see if the maximum
	 * number of posts in the timespan is exceeded. One post before banning the
	 * user we output a warning message that a user is informed.
	 *
	 * We do the warning message in a very simple way: If the new counter value
	 * == the maximum post count in the timespan we output a message_die() and
	 * we don't write the post into the database then.
	 *
	 * If the user starts his next attempt we handle it as spammer and block the
	 * user.
	 */
	function handle_postings()
	{
		global $lang, $db, $ctracker_config, $userdata, $phpbb_root_path, $phpEx;

		// MOD or ADMIN? - No Action please.
		if ( $userdata['user_level'] > 0 )
		{
			return;
		}


		// Standard Spammer detection system
		// Why String and Int Check? Well some servers have problems to cast values from an Object so we make here little compatibility tricks
		if ( $ctracker_config->settings['spammer_blockmode'] != '0' || intval($ctracker_config->settings['spammer_blockmode']) > 0 )
		{
			if ( time() >= $userdata['ct_last_post'] )
			{
				$last_post = time() + max(0, intval($ctracker_config->settings['spammer_time']));
				$sql = 'UPDATE ' . USERS_TABLE . ' SET ct_post_counter = 1, ct_last_post = ' . $last_post . ' WHERE user_id = ' . intval($userdata['user_id']);
				if ( !$result = $db->sql_query($sql) )
				{
					message_die(GENERAL_ERROR, $lang['ctracker_error_updating_userdata'], '', __LINE__, __FILE__, $sql);
				}
			}
			else if ( $userdata['ct_post_counter'] < intval($ctracker_config->settings['spammer_postcount']) )
			{
				$sql = 'UPDATE ' . USERS_TABLE . ' SET ct_post_counter = ct_post_counter + 1 WHERE user_id = ' . intval($userdata['user_id']);
				if ( !$result = $db->sql_query($sql) )
				{
					message_die(GENERAL_ERROR, $lang['ctracker_error_updating_userdata'], '', __LINE__, __FILE__, $sql);
				}

				$userdata['ct_post_counter']++;
				if ( $userdata['ct_post_counter'] == intval($ctracker_config->settings['spammer_postcount']) )
				{
					message_die(GENERAL_MESSAGE, sprintf($lang['ctracker_binf_spammer'], $ctracker_config->settings['spammer_time'], $ctracker_config->settings['spammer_time']));
				}
			}
			else
			{
				$this->block_handler();
			} // else
		} // standard spammer detection

		// Spammer Boost
		if ($ctracker_config->settings['spam_attack_boost'] == '1' || intval($ctracker_config->settings['spam_attack_boost']) == 1 )
		{
      if ( $userdata['user_posts'] >= 2 )
			{
				return;
			}

			$message = $this->post_value('message', 262144);
			$subject = $this->post_value('subject', 1024);
			$url_count = preg_match_all('/(?:\\[url=|https?:\/\/|www\\.)/i', $message . "\n" . $subject, $matches);
			$eur_count = preg_match_all('/(?:US|\\$|€)/u', $message . "\n" . $subject, $matches);

			if ( $url_count > 6 || $eur_count > 6 )
			{
				message_die(GENERAL_MESSAGE, $lang['ctracker_info_post_spammer']);
			}

			if ($ctracker_config->settings['spam_keyword_det'] == '2' || intval($ctracker_config->settings['spam_keyword_det']) == 2 )
			{
				// Did this that Eclipse does not output warning message because
				// the IDE doesn't know that we initialize this in the included
				// file!
				$ct_spammer_def = array();

				include_once($phpbb_root_path . 'ctracker/constants.' . $phpEx);

				for($i = 0; $i < count($ct_spammer_def); $i++)
				{
					$current_value = preg_quote($ct_spammer_def[$i], '/');
		 			$current_value = str_replace('\*', '.*?', $current_value);

					$clean_message = str_replace("\xAD", '', $message);
					$clean_title   = str_replace("\xAD", '', $subject);

					if ( preg_match('/^' . $current_value . '$/is', $clean_message) || preg_match('/^' . $current_value . '$/is', $clean_title) )
					{
						message_die(GENERAL_MESSAGE, $lang['ctracker_info_post_spammer']);
					} // if
				} // for
			} // if
		} // spammer boost
	}


	/**
	 * <b>block_handler</b>
	 * Blocks a user if required
	 */
	function block_handler()
	{
		global $db, $lang, $ctracker_config, $userdata, $phpbb_root_path, $phpEx;

		$user_id = isset($userdata['user_id']) ? intval($userdata['user_id']) : ANONYMOUS;
		$block_mode = intval($ctracker_config->settings['spammer_blockmode']);
		if ($user_id == ANONYMOUS || ($block_mode !== 1 && $block_mode !== 2))
		{
			return;
		}

		if ( $block_mode === 1 )
		{
			// Ban the user once; repeated detections must not grow the ban table.
			$sql = 'SELECT ban_id FROM ' . BANLIST_TABLE . ' WHERE ban_userid = ' . $user_id . ' LIMIT 1';
			if (!$result = $db->sql_query($sql))
			{
				message_die(CRITICAL_ERROR, $lang['ctracker_error_updating_userdata'], '', __LINE__, __FILE__, $sql);
			}
			$ban_exists = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);
			if (!$ban_exists)
			{
				$sql = 'INSERT INTO ' . BANLIST_TABLE . " (ban_userid, ban_ip, ban_email) VALUES (" . $user_id . ", '', NULL)";
				if (!$db->sql_query($sql))
				{
					message_die(CRITICAL_ERROR, $lang['ctracker_error_updating_userdata'], '', __LINE__, __FILE__, $sql);
				}
			}
		}
		else if ( $block_mode === 2 )
		{
			// Block user
			$sql = 'UPDATE ' . USERS_TABLE . ' SET user_active = 0 WHERE user_id = ' . $user_id;
			if ( !$result = $db->sql_query($sql) )
			{
				message_die(GENERAL_ERROR, $lang['ctracker_error_updating_userdata'], '', __LINE__, __FILE__, $sql);
			}
		}

      	// Log it
		include_once($phpbb_root_path . 'ctracker/classes/class_log_manager.' . $phpEx);
		$logfile = new log_manager();
		$logfile->prepare_log($userdata['username']);
		$logfile->write_general_logfile($ctracker_config->settings['logsize_spammer'], 5);
		unset($logfile);

		// Log out user
		if( $userdata['session_logged_in'] )
      	{
	    	session_end($userdata['session_id'], $userdata['user_id']);
      	}

		// Output Info Message
		message_die(GENERAL_MESSAGE, $lang['ctracker_binf_sban']);
	}


	/**
	 * <b>handle_profile</b>
	 * This function includes all the register protection
	 * features of CrackerTracker. So we just have to call this function from
	 * the registersite and we can manage all features at this one place. :)
	 *
	 * Includes:
	 * - Register Protection (Time)
	 * - Register IP Protection
	 * - Spammer detection (Username & Mails)
	 * - Spammer words detection in Profile
	 */
	function handle_profile()
	{
		global $ctracker_config, $phpbb_root_path, $phpEx, $mode, $lang, $HTTP_POST_VARS;

		/*
		 * Done this that Eclipse or another Code-Checker does not output
		 * warning messages here because it does not know that the Vars are
		 * initialized in the included file and so they have not to be defined
		 * as global in this function.
		 */
		$ct_spammer_def = array();
		$ct_mailscn_def = array();
		$ct_userspm_def = array();

		// We need the constants file so we include it now
		include_once($phpbb_root_path . 'ctracker/constants.' . $phpEx);
		$username = $this->post_value('username', 255);
		$email = $this->post_value('email', 320);
		$profile_values = array(
			$this->post_value('aim', 255),
			$this->post_value('msn', 255),
			$this->post_value('yim', 255),
			$this->post_value('website', 2048),
			$this->post_value('location', 255),
			$this->post_value('occupation', 255),
			$this->post_value('interests', 255),
			$this->post_value('signature', 65535)
		);

		// Register Protection (TIME)
		if ( intval($ctracker_config->settings['reg_protection']) == 1 && $mode == 'register')
		{
			if ( time() <= intval($ctracker_config->settings['reg_last_reg']) )
			{
				$waittime_new = intval($ctracker_config->settings['reg_last_reg']) - time();
				message_die(GENERAL_MESSAGE, sprintf($lang['ctracker_info_regist_time'], $ctracker_config->settings['reg_blocktime'], $waittime_new, $waittime_new));
			}
		}

		// Register IP Feature
		if ( intval($ctracker_config->settings['reg_ip_scan']) == 1 && $mode == 'register' )
		{
			if ( $ctracker_config->user_ip_value == $ctracker_config->settings['reg_lastip'] )
			{
				message_die(GENERAL_MESSAGE, $lang['ctracker_info_regip_double']);
			}
		}

		// Registration Scan blocked Mails
		if ( isset($HTTP_POST_VARS['submit']) && intval($ctracker_config->settings['autoban_mails']) == 1 && $mode == 'register' )
		{
			for($i = 0; $i < count($ct_userspm_def); $i++)
			{
				if ( strcasecmp($username, (string) $ct_userspm_def[$i]) === 0 )
				{
					message_die(GENERAL_MESSAGE, $lang['ctracker_info_profile_spammer']);
				}
			}

			for($i = 0; $i < count($ct_mailscn_def); $i++)
			{
				$current_value = preg_quote($ct_mailscn_def[$i], '/');
		 		$current_value = str_replace('\*', '.*?', $current_value);

				if ( preg_match('/^' . $current_value . '$/is', $email) )
				{
					message_die(GENERAL_MESSAGE, $lang['ctracker_info_profile_spammer']);
				}
			}
		}

		// Registration Scan blocked Words
		if ( isset($HTTP_POST_VARS['submit']) && intval($ctracker_config->settings['spam_keyword_det']) >= 1 )
		{
			for($i = 0; $i < count($ct_spammer_def); $i++)
			{
				$current_value = preg_quote($ct_spammer_def[$i], '/');
		 		$current_value = str_replace('\*', '.*?', $current_value);

				foreach ($profile_values as $profile_value)
				{
					$profile_value = str_replace("\xAD", '', $profile_value);
					if (preg_match('/^' . $current_value . '$/is', $profile_value))
					{
						$message_key = ($mode == 'register') ? 'ctracker_info_profile_spammer' : 'ctracker_info_profile_content';
						message_die(GENERAL_MESSAGE, $lang[$message_key]);
					}
				}
			} // for
		} // reg scan blocked words
	} // function


	/**
	 * <b>reg_done</b>
	 * This handles everything when a registration was done
	 */
	function reg_done()
	{
		global $ctracker_config;

		// Regtime
		$waittime_new = time() + intval($ctracker_config->settings['reg_blocktime']);
		$ctracker_config->change_configuration('reg_last_reg', $waittime_new);

		// Reg IP
		$ctracker_config->change_configuration('reg_lastip', $ctracker_config->user_ip_value);
	}

	/**
	 * <b>password_functions</b>
	 * All Password security functions of CrackerTracker in one place
	 */
	function password_functions()
	{
		global $ctracker_config, $lang;

		// Password length check
		$new_password = $this->post_value('new_password', 4096);
		$minimum_length = max(1, min(20, intval($ctracker_config->settings['pw_complex_min'])));
		$pw_length = strlen($new_password);
		if ( $pw_length < $minimum_length && $new_password !== '' )
		{
			message_die(GENERAL_MESSAGE, sprintf($lang['ctracker_info_password_minlng'], $minimum_length, $pw_length));
		}

		// Password complexity
		if ( intval($ctracker_config->settings['pw_complex']) == 1 && $new_password !== '' )
		{
			$p_patterns 	= '';
			$active_pw_prot = '';
			$p_pass     	= $new_password;

			switch ( intval($ctracker_config->settings['pw_complex_mode']) )
			{
				case 1: $p_patterns 	= '/^.*(?=.+)(?=.*\\d).*$/'; // [0-9]
						$active_pw_prot = $lang['ctracker_info_password_cmplx_1'];
						break;

				case 2: $p_patterns 	= '/^.*(?=.+)(?=.*[a-z]).*$/'; // [a-z]
						$active_pw_prot = $lang['ctracker_info_password_cmplx_2'];
						break;

				case 3: $p_patterns 	= '/^.*(?=.+)(?=.*[A-Z]).*$/'; // [A-Z]
						$active_pw_prot = $lang['ctracker_info_password_cmplx_3'];
						break;

				case 4: $p_patterns 	= '/^.*(?=.+)(?=.*\\d)(?=.*[a-z]).*$/'; // [0-9][a-z]
						$active_pw_prot = $lang['ctracker_info_password_cmplx_1'] . ', ' . $lang['ctracker_info_password_cmplx_2'];
						break;

				case 5: $p_patterns 	= '/^.*(?=.+)(?=.*\\d)(?=.*[A-Z]).*$/'; // [0-9][A-Z]
						$active_pw_prot = $lang['ctracker_info_password_cmplx_1'] . ', ' . $lang['ctracker_info_password_cmplx_3'];
						break;

				case 6: $p_patterns 	= '/^.*(?=.+)(?=.*\\d)(?=.*[a-z])(?=.*[A-Z]).*$/'; // [0-9][a-z][A-Z]
						$active_pw_prot = $lang['ctracker_info_password_cmplx_1'] . ', ' . $lang['ctracker_info_password_cmplx_2'] . ', ' . $lang['ctracker_info_password_cmplx_3'];
						break;

				case 7: $p_patterns 	= '/^.*(?=.+)(?=.*\\d)(?=.*\\W).*$/'; // [0-9][*]
						$active_pw_prot = $lang['ctracker_info_password_cmplx_1'] . ', ' . $lang['ctracker_info_password_cmplx_4'];
						break;

				case 8: $p_patterns 	= '/^.*(?=.+)(?=.*\\d)(?=.*[a-z])(?=.*\\W).*$/'; // [0-9][a-z][*]
						$active_pw_prot = $lang['ctracker_info_password_cmplx_1'] . ', ' . $lang['ctracker_info_password_cmplx_2'] . ', ' . $lang['ctracker_info_password_cmplx_4'];
						break;

				case 9: $p_patterns 	= '/^.*(?=.+)(?=.*\\d)(?=.*[a-z])(?=.*[A-Z])(?=.*\\W).*$/'; // [0-9][a-z][A-Z][*]
						$active_pw_prot = $lang['ctracker_info_password_cmplx_1'] . ', ' . $lang['ctracker_info_password_cmplx_2'] . ', ' . $lang['ctracker_info_password_cmplx_3'] . ', ' . $lang['ctracker_info_password_cmplx_4'];
						break;

				default: $p_patterns = '/^.*(?=.+)(?=.*\\d).*$/';
						 $active_pw_prot = $lang['ctracker_info_password_cmplx_1'];
						 break;
			}

			if ( !preg_match($p_patterns, $p_pass) )
			{
				message_die(GENERAL_MESSAGE, sprintf($lang['ctracker_info_password_cmplx'], $active_pw_prot));
			}
		}
	}


	/**
	 * <b>pw_create_date</b>
	 * Writes the PW Create Date into the User Table for the PW
	 * Expire Feature
	 *
	 * @param $user_id (Integer) - ID of the User
	 */
	function pw_create_date($user_id)
	{
		global $db, $lang, $ctracker_config;

		// Build expire date without relying on an undefined constant name.
		$exp_time_stamp = time() + intval($ctracker_config->settings['pwreset_time']) * 86400;

		// Ensure $user_id is integer
		$user_id = intval($user_id);

		$sql = 'UPDATE ' . USERS_TABLE . ' SET ct_last_pw_reset = ' . $exp_time_stamp . ' WHERE user_id = ' . $user_id;
		if ( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_updating_userdata'], '', __LINE__, __FILE__, $sql);
		}
	}

}

?>
