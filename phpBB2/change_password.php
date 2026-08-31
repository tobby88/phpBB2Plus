<?php
define('IN_PHPBB', true);
define('EXTRA_SECURE', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);
//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_LOGIN);
init_userprefs($userdata);
//
// End session management
//
$error = false;
$error_msg = '';
$updated = false;
$new_password = '';
$password_confirm = '';
$cur_password = '';

if (!$userdata['session_logged_in'])
{
	message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}

$submit = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) ? 1 : '';
$password_var_list = array('new_password', 'password_confirm', 'cur_password');
foreach ($password_var_list as $var)
{
	if (isset($_POST[$var]) && is_scalar($_POST[$var]))
	{
		$$var = (string) $_POST[$var];
	}
}

if ($submit)
{
	if (!isset($_POST['sid']) || !is_scalar($_POST['sid']) || !hash_equals((string) $userdata['session_id'], (string) $_POST['sid']))
	{
		message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
	}

	//verify that user is already logged in from this IP
	$ip_check_s = substr($userdata['session_ip'], 0, 6);
	$ip_check_u = substr($user_ip, 0, 6);
	if ( !$userdata['session_logged_in'] || $ip_check_s != $ip_check_u)
	{
		die("Hacking attempt");
		exit;
	}
	// verify that all info is pressent and valid
	if (empty($new_password) || empty($password_confirm))
	{
		$error = true;
		$error_msg .= $lang['Fields_empty'];
	} 
	if ($new_password != $password_confirm)
	{
		$error=true;
		$error_msg .= $lang['Password_mismatch'];
	} 
	include($phpbb_root_path . 'includes/functions_validate.'.$phpEx);
	$error_text = validate_complex_password($userdata['username'], $new_password);
	if ( $error_text['error'] )
	{
		$error = true;
		$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $error_text['error_msg'];
	} 
	if (!$error)
	{
		if ($cur_password === '' || !phpbb_password_verify($cur_password, $userdata['user_password']))
		{
			$error = true;
			$error_msg .= $lang['Current_password_mismatch'];
		}
	}
	if (!$error)
	{	//update new password + time
		$new_password_hash = phpbb_password_hash($new_password);
		$new_password_hash_sql = $db->sql_escape($new_password_hash);
		$user_id = (int) $userdata['user_id'];
		$sql = "UPDATE " . USERS_TABLE . " SET user_password='" . $new_password_hash_sql . "', user_passwd_change=".time()."
		WHERE user_active = 1 AND user_id=". $user_id;
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not update users password', '', __LINE__, __FILE__, $sql);
		}
		if ( $updated = $db->sql_affectedrows() )
		{
			$sql = "DELETE FROM " . SESSIONS_KEYS_TABLE . " WHERE user_id = " . $user_id;
			if (!$db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not invalidate persistent login keys', '', __LINE__, __FILE__, $sql);
			}
			$session_id_sql = $db->sql_escape($userdata['session_id']);
			$sql = "DELETE FROM " . SESSIONS_TABLE . "
				WHERE session_user_id = " . $user_id . "
				AND session_id <> '" . $session_id_sql . "'";
			if (!$db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not invalidate other sessions', '', __LINE__, __FILE__, $sql);
			}
			$template->assign_var("CLOSE_POPUP", "onLoad='setTimeout(window.close, 5000)'");
		} else
		{
			$error=true;
			$error_msg .= $lang['Current_password_mismatch'];
		}
	}
}

if ( $error )
{
	$template->set_filenames(array(
		'reg_header' => 'error_body.tpl'));
	$template->assign_vars(array(
			'ERROR_MESSAGE' => $error_msg));
	$template->assign_var_from_handle('ERROR_BOX', 'reg_header');
}

// default view
$gen_simple_header = TRUE; 
$page_title = $lang['Password']; 
include($phpbb_root_path . 'includes/page_header.'.$phpEx); 
if ($updated)
{
	$template->set_filenames(array( 
      	'body' => 'privmsgs_popup.tpl')); 
	$template->assign_vars(array( 
		'L_MESSAGE' => $lang['Passwd_updated']
	));
} else
{
	$template->set_filenames(array( 
      	'body' => 'change_password_popup.tpl')); 
	$template->assign_block_vars('switch_cur_passwd_on', array());
	if ($userdata['user_passwd_change']=='-9999')
	{
		$message = $lang['Passwd_expired'];
	} else
	{
	$message = sprintf( $lang['Passwd_soon_expired'],$board_config['max_password_age']-intval( (time()-$userdata['user_passwd_change'])/86400));
	}
	$template->assign_vars(array( 
		'USERNAME' => $userdata['username'],
		'L_CUR_PASSWORD' => $lang['Current_password'],
		'L_NEW_PASSWORD' => $lang['New_password'],
		'L_CONFIRM_PASSWORD' => $lang['Confirm_password'],
		'L_SUBMIT' => $lang['Submit'],
		'L_RESET' => $lang['Reset'],
		'L_CHANGE_PASSWD' => $lang['Passwd_title'],
	      'L_CLOSE_WINDOW' => $lang['Close_window'], 
      	'L_WELCOME' => $message,
		'S_ACTION' => append_sid('change_password.'.$phpEx),
		'S_FORM_TOKEN' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />'
	 )); 
}
$template->pparse('body'); 
include($phpbb_root_path . 'includes/page_tail.'.$phpEx); 

?>
