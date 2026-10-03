<?php
/***************************************************************************
 *                          functions_validate.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: functions_validate.php,v 1.6.2.12 2003/06/09 19:13:05 psotfx Exp $
 *
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

//
// Check to see if the username has been taken, or if it is disallowed.
// Also checks if it includes the " character, which we don't allow in usernames.
// Used for registering, changing names, and posting anonymously with a username
//
function validate_username($username, $check_stopforumspam = false, $exclude_user_id = 0, $stored_identity = false)
{
	global $db, $lang, $board_config;
	// An owning profile transaction may exclude its already-authorized target,
	// e.g. for case-only edits. Ordinary registration keeps the default of zero.

	$invalid = array('error'=>true, 'error_msg'=>$lang['Username_invalid']);
	if (!is_string($username) || ($stored_identity && (strpos($username, "\0") !== false || preg_match('//u', $username) !== 1))) { return $invalid; }
	$raw = $stored_identity ? html_entity_decode($username, ENT_QUOTES, 'UTF-8') : $username;
	$raw = phpbb_username_input($raw);
	if ($raw === null) { return $invalid; }
	if (!$stored_identity) { $username = phpbb_username_key($raw); }
	if ($username === '' || preg_match_all('/./us', $username, $characters) === false || count($characters[0]) > 25) { return $invalid; }
	$keys = phpbb_username_keys($raw); if (!$keys) { return $invalid; }
	if ($stored_identity && !in_array($username, $keys, true)) { return $invalid; }
	$literals = array(); foreach ($keys as $key) { $literals[] = "'" . $db->sql_escape($key) . "'"; }
	// Use the database's UTF-8 collation, never byte-wise strtolower or a
	// second HTML/SQL normalization of the already prepared account identity.
	foreach (array(USERS_TABLE=>'username', GROUPS_TABLE=>'group_name') as $table=>$column) {
		$sql = 'SELECT '.$column.' FROM '.$table.' WHERE '.$column.' IN ('.implode(',', $literals).')'
			. ($table === USERS_TABLE && is_int($exclude_user_id) && $exclude_user_id > 0 ? ' AND user_id<>'.$exclude_user_id : '');
		$result = $db->sql_query($sql); if (!$result) { return $invalid; }
		$row = $db->sql_fetchrow($result); $db->sql_freeresult($result);
		if ($row) { return array('error'=>true, 'error_msg'=>$lang['Username_taken']); }
	}
	foreach (array(DISALLOW_TABLE=>'disallow_username', WORDS_TABLE=>'word') as $table=>$column) {
		$result = $db->sql_query('SELECT '.$column.' FROM '.$table); if (!$result) { return $invalid; }
		$rule_error = null;
		try {
			while ($row = $db->sql_fetchrow($result)) {
				$pattern = str_replace('\\*', '.*?', preg_quote($row[$column], '#'));
				foreach (array_unique(array($raw, $username)) as $subject) {
					$matched = @preg_match('#(?:^('.$pattern.')$|\\b('.$pattern.')\\b)#iu', $subject);
					if ($matched === false) { $rule_error = $invalid; break 2; }
					if ($matched) { $rule_error = array('error'=>true, 'error_msg'=>$lang['Username_disallowed']); break 2; }
				}
			}
		} finally { $db->sql_freeresult($result); }
		if ($rule_error !== null) { return $rule_error; }
	}

	// Don't allow " and ALT-255 in username.
	if (strpos($raw, '"') !== false || strpos($username, '&quot;') !== false || preg_match('/[\x{00a0}\x{00ad}]/u', $raw))
	{
		return array('error' => true, 'error_msg' => $lang['Username_invalid']);
	}

	if ($check_stopforumspam && !empty($board_config['sfs_enable']))
	{
		$sfs_check = stopforumspam($raw, 'username');
		if ($sfs_check === true)
		{
			return array('error' => true, 'error_msg' => $lang['Username_disallowed']);
		}
		if (is_array($sfs_check) && !empty($sfs_check['error']))
		{
			return $sfs_check;
		}
	}

	return array('error' => false, 'error_msg' => '');
}

//
// Check to see if email address is banned
// or already present in the DB
//
function validate_email($email, $check_stopforumspam = false, $exclude_user_id = 0)
{
	global $db, $lang, $board_config;

	if ($email != '')
	{
		if (preg_match('/^[a-z0-9&\'\.\-_\+]+@[a-z0-9\-]+\.([a-z0-9\-]+\.)*?[a-z]+$/is', $email))
		{
			$sql = "SELECT ban_email
				FROM " . BANLIST_TABLE;
			if ($result = $db->sql_query($sql))
			{
				if ($row = $db->sql_fetchrow($result))
				{
					do
					{
						// IP/user-only bans legitimately have a NULL email value.
						$match_email = str_replace('*', '.*?', (string) $row['ban_email']);
						if (preg_match('/^' . $match_email . '$/is', $email))
						{
							$db->sql_freeresult($result);
							return array('error' => true, 'error_msg' => $lang['Email_banned']);
						}
					}
					while($row = $db->sql_fetchrow($result));
				}
			}
			$db->sql_freeresult($result);

			$sql = "SELECT user_email
				FROM " . USERS_TABLE . "
				WHERE user_email = '" . $db->sql_escape($email) . "'"
				. (is_int($exclude_user_id) && $exclude_user_id > 0 ? ' AND user_id <> ' . $exclude_user_id : '');
			if (!($result = $db->sql_query($sql)))
			{
				message_die(GENERAL_ERROR, "Couldn't obtain user email information.", "", __LINE__, __FILE__, $sql);
			}
		
			if ($row = $db->sql_fetchrow($result))
			{
				return array('error' => true, 'error_msg' => $lang['Email_taken']);
			}
			$db->sql_freeresult($result);

			if ($check_stopforumspam && !empty($board_config['sfs_enable']))
			{
				$sfs_check = stopforumspam($email, 'email');
				if ($sfs_check === true)
				{
					return array('error' => true, 'error_msg' => $lang['Email_banned']);
				}
				if (is_array($sfs_check) && !empty($sfs_check['error']))
				{
					return $sfs_check;
				}
			}

			return array('error' => false, 'error_msg' => '');
		}
	}

	return array('error' => true, 'error_msg' => $lang['Email_invalid']);
}

//
// Does supplementary validation of optional profile fields. This expects common stuff like trim() and strip_tags()
// to have already been run. Params are passed by-ref, so we can set them to the empty string if they fail.
//
function validate_optional_fields(&$icq, &$aim, &$msnm, &$yim, &$website, &$location, &$occupation, &$interests, &$sig)
{
	// Only legacy messenger identifiers have a minimum length here. A single
	// character (including "0") is valid free text, not an absent profile field.
	$check_var_length = array('aim', 'msnm', 'yim');

	for($i = 0; $i < count($check_var_length); $i++)
	{
		$variable_name = $check_var_length[$i];
		if (strlen(${$variable_name}) < 2)
		{
			${$variable_name} = '';
		}
	}

	// ICQ number has to be only numbers.
	if (!preg_match('/^[0-9]+$/', $icq))
	{
		$icq = '';
	}
	
	// website has to start with http://, followed by something with length at least 3 that
	// contains at least one dot.
	if ($website != "")
	{
		if (!preg_match('#^http[s]?:\/\/#i', $website))
		{
			$website = 'http://' . $website;
		}

		if (!preg_match('#^http[s]?\\:\\/\\/[a-z0-9\-]+\.([a-z0-9\-]+\.)?[a-z]+#i', $website))
		{
			$website = '';
		}
	}

	return;
}

function validate_stopforumspam_address($address)
{
	global $lang, $board_config;

	if (empty($board_config['sfs_enable']))
	{
		return array('error' => false, 'error_msg' => '');
	}

	$sfs_check = stopforumspam($address, 'ip');
	if ($sfs_check === true)
	{
		return array('error' => true, 'error_msg' => $lang['You_been_banned']);
	}
	if (is_array($sfs_check) && !empty($sfs_check['error']))
	{
		return $sfs_check;
	}

	return array('error' => false, 'error_msg' => '');
}

function stopforumspam_service_error($language_key)
{
	global $lang, $board_config, $stopforumspam_request_unavailable;
	static $logged = false;

	$stopforumspam_request_unavailable = true;
	if (!$logged)
	{
		error_log('StopForumSpam registration check unavailable: ' . preg_replace('/[^a-z_]/i', '', (string) $language_key));
		$logged = true;
	}
	if (!empty($board_config['sfs_fail_closed']))
	{
		return array(
			'error' => true,
			'error_msg' => isset($lang[$language_key]) ? $lang[$language_key] : 'Registration spam check unavailable.'
		);
	}

	return false;
}

function stopforumspam($value, $type)
{
	global $lang, $stopforumspam_request_unavailable;

	if (!in_array($type, array('username', 'email', 'ip'), true))
	{
		return array('error' => true, 'error_msg' => $lang['sfs_invalid_response']);
	}
	if (!function_exists('file_get_contents') || !class_exists('DOMDocument'))
	{
		return stopforumspam_service_error('sfs_missing_extension');
	}

	$value = trim((string) $value);
	if (($type === 'ip' && filter_var($value, FILTER_VALIDATE_IP) === false) ||
		($type === 'email' && (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false)) ||
		($type === 'username' && ($value === '' || strlen($value) > 100)))
	{
		return false;
	}
	if (!empty($stopforumspam_request_unavailable))
	{
		return stopforumspam_service_error('sfs_service_unavailable');
	}

	$context = stream_context_create(array(
		'http' => array(
			'timeout' => 4,
			'follow_location' => 0,
			'max_redirects' => 0,
			'ignore_errors' => true,
			'user_agent' => 'phpBB2 Plus StopForumSpam integration'),
		'ssl' => array(
			'verify_peer' => true,
			'verify_peer_name' => true)));
	$url = 'https://api.stopforumspam.org/api?' . $type . '=' . rawurlencode($value) . '&xml';
	$xml = @file_get_contents($url, false, $context, 0, 262144);
	if (function_exists('http_get_last_response_headers'))
	{
		$response_headers = http_get_last_response_headers();
	}
	else
	{
		// PHP 8.5 deprecates naming the implicit local variable directly.
		$local_variables = get_defined_vars();
		$response_headers = isset($local_variables['http_response_header']) ? $local_variables['http_response_header'] : array();
	}
	$status_ok = is_array($response_headers) && isset($response_headers[0]) && preg_match('#^HTTP/\S+\s+200(?:\s|$)#i', (string) $response_headers[0]);
	if ($xml === false || !$status_ok)
	{
		return stopforumspam_service_error('sfs_service_unavailable');
	}

	$dom = new DOMDocument();
	$previous_errors = libxml_use_internal_errors(true);
	$loaded = $dom->loadXML($xml, LIBXML_NONET);
	libxml_clear_errors();
	libxml_use_internal_errors($previous_errors);
	if (!$loaded)
	{
		return stopforumspam_service_error('sfs_invalid_response');
	}

	$tags = $dom->getElementsByTagName('appears');
	if ($tags->length < 1)
	{
		return stopforumspam_service_error('sfs_invalid_response');
	}
	foreach ($tags as $node)
	{
		if (strtolower(trim($node->nodeValue)) === 'yes')
		{
			return true;
		}
	}

	return false;
}
// Start add - Protect user account MOD
function validate_complex_password ($username, $password, $request_escaped = null)
{
	global $board_config, $lang;
	$ret = FALSE;
	$msg_explain = '';
	$input_error = phpbb_password_input_error($password);
	if ($input_error !== '')
	{
		return array('error' => TRUE, 'error_msg' => $lang[$input_error]);
	}
	// Decode only a validation copy. Existing login hashes and byte-length rules
	// use the legacy escaped representation and must not change here. Standalone
	// callers (installer) explicitly declare it; common.php supplies the default.
	$policy_password = ($request_escaped === null) ? phpbb_request_raw_value($password)
		: ($request_escaped ? stripslashes($password) : $password);
	if (strpos($policy_password, "\0") !== false)
	{
		return array('error' => TRUE, 'error_msg' => $lang['Password_invalid']);
	}
	//verify minimum length
	$minimum_length = max(0, min(72, (int) $board_config['min_password_len']));
	if (strlen($password) < $minimum_length)
	{
		$ret= TRUE;
		$msg_explain .= sprintf($lang['Password_to_short'], $minimum_length);
	}
	// verify password not the same as login
	if ($board_config['password_not_login'] && (string) $username === $password)
	{	
		$ret = TRUE;
		$msg_explain .= ($msg_explain) ? ', ' : '';
		$msg_explain .= $lang['Password_not_same'];

	}
	// Require actual Unicode letters and decimal digits, not punctuation. The
	// /u checks fail closed for malformed UTF-8; no normalization is applied.
	if ( $board_config['force_complex_password'] )
	{	
		if (preg_match('/\p{L}/u', $policy_password) !== 1 || preg_match('/\p{Nd}/u', $policy_password) !== 1)
		{
			$ret = TRUE;
			$msg_explain .= ($msg_explain) ? ', ' : '';
			$msg_explain .= $lang['Password_mixed'];
		}
	}
	$msg_explain = ($ret) ? $lang['Password_not_complex'].$msg_explain : '';
	return array('error' => ($ret) ? TRUE : FALSE , 'error_msg' => $msg_explain);
}
// End add - Protect user account MOD

?>
