<?php
/**
* <b>CrackerTracker File: ct_visual_confirm.php</b> <br><br>
*
* This File implements the functions for the visual confirmation system used
* in CrackerTracker. We used the Visual Confirm generator from the phpBB Group
* that we don't have to include new files.
*
* We can use this file to generate the visual code on login and guest postings
* if we need it.
*
*
* @author Christian Knerr (cback)
* @package ctracker
* @version 5.0.0
* @since 25.07.2006 - 17:09:31
* @copyright (c) 2006 www.cback.de
*
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*/

if( !defined('IN_PHPBB') || !defined('CRACKER_TRACKER_VCONFIRM') )
{
	die("Hacking attempt!");
}


/*
 * Visual Confirmation Check
 */

$error = isset($error) ? (bool) $error : false;

if ( $mode == 'check' || defined('POST_CONFIRM_CHECK') )
{
	if ( empty($HTTP_POST_VARS['confirm_id']) || !is_scalar($HTTP_POST_VARS['confirm_id'])
		|| !isset($HTTP_POST_VARS['confirm_code']) || !is_scalar($HTTP_POST_VARS['confirm_code']) )
	{
		$error = TRUE;
		$error_msg = ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['ctracker_login_wrong'];
	}
	else
	{
		$confirm_id = trim((string) $HTTP_POST_VARS['confirm_id']);
		$confirm_code = strtoupper(trim((string) $HTTP_POST_VARS['confirm_code']));
		$session_id = isset($userdata['session_id']) && is_scalar($userdata['session_id']) ? (string) $userdata['session_id'] : '';

		if (!preg_match('/^[a-f0-9]{32}$/Di', $confirm_id) ||
			!preg_match('/^[A-Z0-9]{1,6}$/D', $confirm_code) ||
			!preg_match('/^[a-f0-9]{32}$/Di', $session_id))
		{
			$error = TRUE;
			$error_msg = ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['ctracker_login_wrong'];
		}
		else
		{
			$sql = 'SELECT code
				FROM ' . CONFIRM_TABLE . "
				WHERE confirm_id = '" . $db->sql_escape($confirm_id) . "'
					AND session_id = '" . $db->sql_escape($session_id) . "'";

			if (!($result = $db->sql_query($sql)))
			{
				message_die(GENERAL_ERROR, $lang['ctracker_code_dbconn'], '', __LINE__, __FILE__, $sql);
			}

			if ($row = $db->sql_fetchrow($result))
			{
				if (!hash_equals((string) $row['code'], $confirm_code))
				{
					$error = TRUE;
					$error_msg = ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['ctracker_login_wrong'];
				}
				else
				{
					$sql = 'DELETE FROM ' . CONFIRM_TABLE . "
						WHERE confirm_id = '" . $db->sql_escape($confirm_id) . "'
							AND session_id = '" . $db->sql_escape($session_id) . "'
							AND code = '" . $db->sql_escape($confirm_code) . "'";

					if (!$db->sql_query($sql) || $db->sql_affectedrows() !== 1)
					{
						$error = TRUE;
						$error_msg = ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['ctracker_login_wrong'];
					}
				}
			}
			else
			{
				$error = TRUE;
				$error_msg = ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['ctracker_login_wrong'];
			}

			$db->sql_freeresult($result);
		}
	}

	if ( $error )
	{
		if ( defined('IN_LOGIN') )
    {
		  $error_msg .= '<br /><br />' . sprintf($lang['Click_return_login'], "<a href='ctracker_login.$phpEx'>", '</a>');
		}
    message_die(GENERAL_MESSAGE, $error_msg);
	}
 	else if( defined('CTRACKER_ACCOUNT_FREE') )
	{
		$ctracker_config->reset_login_system($user_id);

		$message_text = '';
		$message_text = sprintf($lang['ctracker_login_success'], 'login.' . $phpEx );

		message_die(GENERAL_MESSAGE, $message_text);
	}
}
else
{
	$confirm_image = '';
	$session_id = isset($userdata['session_id']) && is_scalar($userdata['session_id']) ? (string) $userdata['session_id'] : '';
	if (!preg_match('/^[a-f0-9]{32}$/Di', $session_id))
	{
		message_die(GENERAL_ERROR, $lang['ctracker_code_dbconn']);
	}

	// Remove orphaned challenges without loading every active session into PHP.
	$sql = 'DELETE confirm_entry FROM ' . CONFIRM_TABLE . ' confirm_entry
		LEFT JOIN ' . SESSIONS_TABLE . ' active_session
			ON active_session.session_id = confirm_entry.session_id
		WHERE active_session.session_id IS NULL';
	if (!$db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['ctracker_code_dbconn'], '', __LINE__, __FILE__, $sql);
	}

	$sql = 'SELECT COUNT(session_id) AS attempts
		FROM ' . CONFIRM_TABLE . "
		WHERE session_id = '" . $db->sql_escape($session_id) . "'";

	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, $lang['ctracker_code_dbconn'], '', __LINE__, __FILE__, $sql);
	}

	if ($row = $db->sql_fetchrow($result))
	{
		if ($row['attempts'] > 3)
		{
			message_die(GENERAL_MESSAGE, $lang['ctracker_code_count']);
		}
	}

	$db->sql_freeresult($result);


	// Generate the required confirmation code
	// NB 0 (zero) could get confused with O (the letter) so we make change it
	$code = dss_rand();
	$code = substr(str_replace('0', 'Z', strtoupper(base_convert($code, 16, 35))), 2, 6);

	$confirm_id = md5(dss_rand() . dss_rand());

	$sql = 'INSERT INTO ' . CONFIRM_TABLE . " (confirm_id, session_id, code)
		VALUES ('" . $db->sql_escape($confirm_id) . "', '" . $db->sql_escape($session_id) . "', '" . $db->sql_escape($code) . "')";

	if (!$db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['ctracker_code_dbconn'], '', __LINE__, __FILE__, $sql);
	}

	unset($code);

	$confirm_image 	  = '<img src="' . append_sid("profile.$phpEx?mode=confirm&amp;id=$confirm_id") . '" alt="" title="" />';
	$s_hidden_fields = isset($s_hidden_fields) && is_string($s_hidden_fields) ? $s_hidden_fields : '';
	$s_hidden_fields .= '<input type="hidden" name="confirm_id" value="' . $confirm_id . '" />';
}

?>
