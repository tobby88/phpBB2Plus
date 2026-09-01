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
	 * Return a privacy-preserving network key and label for login comparison.
	 * IPv4 keeps the historical /16 behavior; IPv6 uses a stable /48 prefix.
	 */
	function ip_range($ip)
	{
		$ip = is_scalar($ip) ? trim((string) $ip) : '';
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
		{
			$parts = explode('.', $ip);
			return array(
				'key' => '4:' . $parts[0] . '.' . $parts[1],
				'label' => $parts[0] . '.' . $parts[1] . '.x.x',
			);
		}

		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false)
		{
			$packed = @inet_pton($ip);
			if (is_string($packed) && strlen($packed) === 16)
			{
				$prefix = unpack('nfirst/nsecond/nthird', substr($packed, 0, 6));
				return array(
					'key' => '6:' . bin2hex(substr($packed, 0, 6)),
					'label' => sprintf('%x:%x:%x::/48', $prefix['first'], $prefix['second'], $prefix['third']),
				);
			}
		}

		return false;
	}

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
		if ($last_ip === '0.0.0.0' || $last_used_ip === '0.0.0.0')
		{
			return 'allclear';
		}

		$current_range = $this->ip_range($last_used_ip);
		$previous_range = $this->ip_range($last_ip);
		if ($current_range === false || $previous_range === false || $current_range['key'] === $previous_range['key'])
		{
			return 'allclear';
		}

		return sprintf($lang['ctracker_ipwarn_chng'], $current_range['label'], $previous_range['label']);
	}


	/**
	 * <b>handle_postings</b>
	 * Apply a bounded per-account posting rate and the low-post-count content
	 * checks. A posting burst is not proof that an account is malicious, so it
	 * must never create a permanent ban or deactivate the account.
	 */
	function handle_postings()
	{
		global $lang, $ctracker_config, $userdata, $phpbb_root_path, $phpEx;

		// MOD or ADMIN? - No Action please.
		if ( $userdata['user_level'] > 0 )
		{
			return;
		}


		// The old modes 1 (ban) and 2 (deactivate) are both treated as enabled
		// while upgraded databases normalize them to 1. This preserves the
		// administrator's on/off choice without preserving the destructive action.
		if (intval($ctracker_config->settings['spammer_blockmode']) > 0 && function_exists('ctracker_rate_limit_increment'))
		{
			$window = max(1, min(90, intval($ctracker_config->settings['spammer_time'])));
			$limit = max(1, min(12, intval($ctracker_config->settings['spammer_postcount'])));
			$retry_after = ctracker_rate_limit_increment('posting-user', 'user:' . intval($userdata['user_id']), $window, $limit);
			if ($retry_after !== false && $retry_after > 0)
			{
				if (!headers_sent())
				{
					http_response_code(429);
					header('Retry-After: ' . intval($retry_after));
					header('Cache-Control: no-store');
				}
				message_die(GENERAL_MESSAGE, sprintf($lang['ctracker_binf_spammer'], $limit, $window, intval($retry_after)));
			}
		}

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

		// Apply a rolling cooldown per verified client IP, and only to a real
		// submission. The original implementation stored one global timestamp,
		// allowing any successful registration to block every other visitor.
		if (isset($HTTP_POST_VARS['submit']) && intval($ctracker_config->settings['reg_protection']) == 1 && $mode == 'register')
		{
			$cooldown = max(1, min(200, intval($ctracker_config->settings['reg_blocktime'])));
			$remaining = function_exists('ctracker_rate_limit_cooldown_remaining')
				? ctracker_rate_limit_cooldown_remaining('registration-success', (string) $ctracker_config->user_ip_value, $cooldown)
				: false;
			if ($remaining !== false && $remaining > 0)
			{
				if (!headers_sent())
				{
					http_response_code(429);
					header('Retry-After: ' . intval($remaining));
					header('Cache-Control: no-store');
				}
				message_die(GENERAL_MESSAGE, sprintf($lang['ctracker_info_regist_time'], $cooldown, intval($remaining)));
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

		if (intval($ctracker_config->settings['reg_protection']) == 1 && function_exists('ctracker_rate_limit_mark_success'))
		{
			ctracker_rate_limit_mark_success('registration-success', (string) $ctracker_config->user_ip_value);
		}
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
		global $db, $lang;

		// Keep the password-change timestamp separate from the password-reset
		// request cooldown. The original MOD reused ct_last_pw_reset for both,
		// which could block reset requests for days and then report a freshly
		// reset password as expired after only a few minutes.
		$change_time = time();

		// Ensure $user_id is integer
		$user_id = intval($user_id);

		$sql = 'UPDATE ' . USERS_TABLE . ' SET ct_last_pw_change = ' . $change_time . ' WHERE user_id = ' . $user_id;
		if ( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['ctracker_error_updating_userdata'], '', __LINE__, __FILE__, $sql);
		}
	}

}

?>
