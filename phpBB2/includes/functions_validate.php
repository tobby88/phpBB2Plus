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
function validate_username($username, $check_stopforumspam = false)
{
	global $db, $lang, $userdata, $board_config;

	// Remove doubled up spaces
	$username = preg_replace('#\s+#', ' ', trim($username)); 
	$username = phpbb_clean_username($username);

	$sql = "SELECT username 
		FROM " . USERS_TABLE . "
		WHERE LOWER(username) = '" . strtolower($username) . "'";
	if ($result = $db->sql_query($sql))
	{
		while ($row = $db->sql_fetchrow($result))
		{
			if (($userdata['session_logged_in'] && $row['username'] != $userdata['username']) || !$userdata['session_logged_in'])
			{
				$db->sql_freeresult($result);
				return array('error' => true, 'error_msg' => $lang['Username_taken']);
			}
		}
	}
	$db->sql_freeresult($result);

	$sql = "SELECT group_name
		FROM " . GROUPS_TABLE . " 
		WHERE LOWER(group_name) = '" . strtolower($username) . "'";
	if ($result = $db->sql_query($sql))
	{
		if ($row = $db->sql_fetchrow($result))
		{
			$db->sql_freeresult($result);
			return array('error' => true, 'error_msg' => $lang['Username_taken']);
		}
	}
	$db->sql_freeresult($result);

	$sql = "SELECT disallow_username
		FROM " . DISALLOW_TABLE;
	if ($result = $db->sql_query($sql))
	{
		if ($row = $db->sql_fetchrow($result))
		{
			do
			{
				if (preg_match("#\b(" . str_replace("\*", ".*?", preg_quote($row['disallow_username'], '#')) . ")\b#i", $username))
				{
					$db->sql_freeresult($result);
					return array('error' => true, 'error_msg' => $lang['Username_disallowed']);
				}
			}
			while($row = $db->sql_fetchrow($result));
		}
	}
	$db->sql_freeresult($result);

	$sql = "SELECT word 
		FROM  " . WORDS_TABLE;
	if ($result = $db->sql_query($sql))
	{
		if ($row = $db->sql_fetchrow($result))
		{
			do
			{
				if (preg_match("#\b(" . str_replace("\*", ".*?", preg_quote($row['word'], '#')) . ")\b#i", $username))
				{
					$db->sql_freeresult($result);
					return array('error' => true, 'error_msg' => $lang['Username_disallowed']);
				}
			}
			while ($row = $db->sql_fetchrow($result));
		}
	}
	$db->sql_freeresult($result);

	// Don't allow " and ALT-255 in username.
	if (strstr($username, '"') || strstr($username, '&quot;') || strstr($username, chr(160))|| strstr($username, chr(173)))
	{
		return array('error' => true, 'error_msg' => $lang['Username_invalid']);
	}

	if ($check_stopforumspam && !empty($board_config['sfs_enable']))
	{
		$sfs_check = stopforumspam($username, 'username');
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
function validate_email($email, $check_stopforumspam = false)
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
						$match_email = str_replace('*', '.*?', $row['ban_email']);
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
				WHERE user_email = '" . str_replace("\'", "''", $email) . "'";
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
	$check_var_length = array('aim', 'msnm', 'yim', 'location', 'occupation', 'interests', 'sig');

	for($i = 0; $i < count($check_var_length); $i++)
	{
		if (strlen($$check_var_length[$i]) < 2)
		{
			$$check_var_length[$i] = '';
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

function stopforumspam($value, $type)
{
	global $lang;

	if (!in_array($type, array('username', 'email', 'ip'), true))
	{
		return array('error' => true, 'error_msg' => $lang['sfs_invalid_response']);
	}
	if (!function_exists('file_get_contents') || !class_exists('DOMDocument'))
	{
		return array('error' => true, 'error_msg' => $lang['sfs_missing_extension']);
	}

	$context = stream_context_create(array('http' => array(
		'timeout' => 4,
		'user_agent' => 'phpBB2 Plus StopForumSpam integration')));
	$url = 'https://api.stopforumspam.org/api?' . $type . '=' . urlencode($value) . '&xml';
	$xml = @file_get_contents($url, false, $context);
	if ($xml === false)
	{
		return array('error' => true, 'error_msg' => $lang['sfs_service_unavailable']);
	}

	$dom = new DOMDocument();
	$previous_errors = libxml_use_internal_errors(true);
	$loaded = $dom->loadXML($xml, LIBXML_NONET);
	libxml_clear_errors();
	libxml_use_internal_errors($previous_errors);
	if (!$loaded)
	{
		return array('error' => true, 'error_msg' => $lang['sfs_invalid_response']);
	}

	$tags = $dom->getElementsByTagName('appears');
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
function validate_complex_password ($username, $password)
{
	global $board_config, $lang;
	$ret = FALSE;
	//verify minimum length
	if ( strlen($password) < $board_config['min_password_len'] )
	{
		$ret= TRUE;
		$msg_explain .= sprintf ($lang['Password_to_short'],$board_config['min_password_len']);
	}
	// verify password not the same as login
	if ($board_config['password_not_login'] && $username == $password )
	{	
		$ret = TRUE;
		$msg_explain .= ($msg_explain) ? ', ' : '';
		$msg_explain .= $lang['Password_not_same'];

	}
	// verify password holds both alfa and numeric
	if ( $board_config['force_complex_password'] )
	{	
		if ( ! (preg_match("/[a-zA-Z\.]/",$password) && preg_match("/[0-9\.]/",$password))) 
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
