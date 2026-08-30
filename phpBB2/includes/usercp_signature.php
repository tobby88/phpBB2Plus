<?php
if ( !defined('IN_PHPBB') )
{
	die("Hacking attempt");
	exit;
}

function usercp_signature_post_scalar($name, $default = '')
{
	return (isset($_POST[$name]) && is_scalar($_POST[$name])) ? (string) $_POST[$name] : $default;
}

function usercp_signature_get_scalar($name, $default = '')
{
	return (isset($_GET[$name]) && is_scalar($_GET[$name])) ? (string) $_GET[$name] : $default;
}

function usercp_signature_avatar_file_scalar($name, $default = '')
{
	global $HTTP_POST_FILES;

	return (isset($HTTP_POST_FILES['avatar']) && is_array($HTTP_POST_FILES['avatar']) &&
		isset($HTTP_POST_FILES['avatar'][$name]) && is_scalar($HTTP_POST_FILES['avatar'][$name]))
		? (string) $HTTP_POST_FILES['avatar'][$name]
		: $default;
}

$strip_var_list = array('editprofile' => 'editprofile', 'username' => 'username', 'email' => 'email', 'icq' => 'icq', 'aim' => 'aim', 'msn' => 'msn', 'yim' => 'yim', 'fb' => 'fb', 'ig' => 'ig', 'pt' => 'pt', 'twr' => 'twr', 'skp' => 'skp', 'tg' => 'tg', 'li' => 'li', 'tt' => 'tt', 'dc' => 'dc', 'website' => 'website', 'location' => 'location', 'occupation' => 'occupation', 'interests' => 'interests');
foreach ($strip_var_list as $var => $param)
{
	if ( usercp_signature_post_scalar($param) !== '' )
	{
		$$var = trim(htmlspecialchars(usercp_signature_post_scalar($param)));
	}
}

$password_var_list = array('cur_password', 'new_password', 'password_confirm');
foreach ($password_var_list as $password_var)
{
	$$password_var = usercp_signature_post_scalar($password_var);
}
$signature = trim(usercp_signature_post_scalar('signature'));

$user_absence = ( isset($_POST['user_absence']) ) ? ( ($_POST['user_absence']) ? TRUE : 0 ) : 0;
$user_absence_mode = abs(intval(usercp_signature_post_scalar('user_absence_mode', '0')));
$user_absence_text = htmlspecialchars(usercp_signature_post_scalar('user_absence_text'));
$gender = intval(usercp_signature_post_scalar('gender', '0'));
$birthday = intval(usercp_signature_post_scalar('birthday', '0'));
$b_day = intval(usercp_signature_post_scalar('b_day', '0'));
$b_md = intval(usercp_signature_post_scalar('b_md', '0'));
$b_year = intval(usercp_signature_post_scalar('b_year', '0'));
$viewemail = ( isset($_POST['viewemail']) ) ? ( ($_POST['viewemail']) ? TRUE : 0 ) : 0;
$allowviewonline = ( isset($_POST['hideonline']) ) ? ( ($_POST['hideonline']) ? 0 : TRUE ) : TRUE;
$notifyreply = ( isset($_POST['notifyreply']) ) ? ( ($_POST['notifyreply']) ? TRUE : 0 ) : 0;
$notifypm = ( isset($_POST['notifypm']) ) ? ( ($_POST['notifypm']) ? TRUE : 0 ) : TRUE;
$games_block_pm = ( isset($_POST['games_block_pm']) ) ? ( ($_POST['games_block_pm']) ? TRUE : 0 ) : TRUE;
$popup_pm = ( isset($_POST['popup_pm']) ) ? ( ($_POST['popup_pm']) ? TRUE : 0 ) : TRUE;
$attachsig = ( isset($_POST['attachsig']) ) ? ( ($_POST['attachsig']) ? TRUE : 0 ) : 0;
$setbm = ( isset($_POST['setbm']) ) ? ( ($_POST['setbm']) ? TRUE : 0 ) : 0;
$allowbbcode = ( isset($_POST['allowbbcode']) ) ? ( ($_POST['allowbbcode']) ? TRUE : 0 ) : $userdata['user_allowbbcode'];
$allowhtml = ( isset($_POST['allowhtml']) ) ? ( ($_POST['allowhtml']) ? TRUE : 0 ) : $userdata['user_allowhtml'];
$allowsmilies = ( isset($_POST['allowsmilies']) ) ? ( ($_POST['allowsmilies']) ? TRUE : 0 ) : $userdata['user_allowsmile'];
$user_lang = htmlspecialchars(usercp_signature_post_scalar('language', $board_config['default_lang']));
$user_style = intval(usercp_signature_post_scalar('style', (string) $board_config['default_style']));
$timezone_value = usercp_signature_post_scalar('timezone');
$user_timezone = ( $timezone_value !== '' ) ? doubleval($timezone_value) : $board_config['board_timezone'];
$dateformat_value = usercp_signature_post_scalar('dateformat');
$user_dateformat = ( $dateformat_value !== '' ) ? trim(htmlspecialchars($dateformat_value)) : $board_config['default_dateformat'];
$user_avatar_name = usercp_signature_avatar_file_scalar('name');
$user_avatar_size = intval(usercp_signature_avatar_file_scalar('size', '0'));
$user_avatar_filetype = usercp_signature_avatar_file_scalar('type');
$avatarurl_value = usercp_signature_post_scalar('avatarurl');
$avatar_tmp_name = usercp_signature_avatar_file_scalar('tmp_name');
$user_avatar_upload = ( $avatarurl_value !== '' ) ? trim($avatarurl_value) : ( ( $avatar_tmp_name !== '' && $avatar_tmp_name != 'none') ? $avatar_tmp_name : '' );
$avatarremoteurl_value = usercp_signature_post_scalar('avatarremoteurl');
$user_avatar_remoteurl = ( $avatarremoteurl_value !== '' ) ? trim(htmlspecialchars($avatarremoteurl_value)) : '';
$user_flag_value = usercp_signature_post_scalar('user_flag');
$user_flag = ( $user_flag_value !== '' ) ? phpbb_profile_image_name($user_flag_value) : '' ;

$s_hidden_fields = '<input type="hidden" name="username" value="' . str_replace("\"", "&quot;", $username) . '" />';
$s_hidden_fields .= '<input type="hidden" name="user_id" value="' . str_replace("\"", "&quot;", $userdata['user_id']) . '" />';
$s_hidden_fields .= '<input type="hidden" name="email" value="' . str_replace("\"", "&quot;", $email) . '" />';
$s_hidden_fields .= '<input type="hidden" name="icq" value="' . str_replace("\"", "&quot;", $icq) . '" />';
$s_hidden_fields .= '<input type="hidden" name="aim" value="' . str_replace("\"", "&quot;", $aim) . '" />';
$s_hidden_fields .= '<input type="hidden" name="msn" value="' . str_replace("\"", "&quot;", $msn) . '" />';
$s_hidden_fields .= '<input type="hidden" name="yim" value="' . str_replace("\"", "&quot;", $yim) . '" />';
$s_hidden_fields .= '<input type="hidden" name="fb" value="' . str_replace("\"", "&quot;", isset($fb) ? $fb : '') . '" />';
$s_hidden_fields .= '<input type="hidden" name="ig" value="' . str_replace("\"", "&quot;", isset($ig) ? $ig : '') . '" />';
$s_hidden_fields .= '<input type="hidden" name="pt" value="' . str_replace("\"", "&quot;", isset($pt) ? $pt : '') . '" />';
$s_hidden_fields .= '<input type="hidden" name="twr" value="' . str_replace("\"", "&quot;", isset($twr) ? $twr : '') . '" />';
$s_hidden_fields .= '<input type="hidden" name="skp" value="' . str_replace("\"", "&quot;", isset($skp) ? $skp : '') . '" />';
$s_hidden_fields .= '<input type="hidden" name="tg" value="' . str_replace("\"", "&quot;", isset($tg) ? $tg : '') . '" />';
$s_hidden_fields .= '<input type="hidden" name="li" value="' . str_replace("\"", "&quot;", isset($li) ? $li : '') . '" />';
$s_hidden_fields .= '<input type="hidden" name="tt" value="' . str_replace("\"", "&quot;", isset($tt) ? $tt : '') . '" />';
$s_hidden_fields .= '<input type="hidden" name="dc" value="' . str_replace("\"", "&quot;", isset($dc) ? $dc : '') . '" />';
$s_hidden_fields .= '<input type="hidden" name="website" value="' . str_replace("\"", "&quot;", $website) . '" />';
$s_hidden_fields .= '<input type="hidden" name="location" value="' . str_replace("\"", "&quot;", $location) . '" />';
$s_hidden_fields .= '<input type="hidden" name="occupation" value="' . str_replace("\"", "&quot;", $occupation) . '" />';
$s_hidden_fields .= '<input type="hidden" name="interests" value="' . str_replace("\"", "&quot;", $interests) . '" />';
$s_hidden_fields .= '<input type="hidden" name="user_absence_mode" value="' . $user_absence_mode . '" />';
$s_hidden_fields .= '<input type="hidden" name="user_absence" value="' . $user_absence . '" />';
$s_hidden_fields .= '<input type="hidden" name="user_absence_text" value="' . $user_absence_text . '" />';
$s_hidden_fields .= '<input type="hidden" name="gender" value="' . $gender . '" />';
$s_hidden_fields .= '<input type="hidden" name="birthday" value="'.$birthday.'" />';
$s_hidden_fields .= '<input type="hidden" name="b_day" value="'.$b_day.'" />';
$s_hidden_fields .= '<input type="hidden" name="b_md" value="'.$b_md.'" />';
$s_hidden_fields .= '<input type="hidden" name="b_year" value="'.$b_year.'" />';
$s_hidden_fields .= '<input type="hidden" name="viewemail" value="' . $viewemail . '" />';
$s_hidden_fields .= '<input type="hidden" name="hideonline" value="' . !$allowviewonline . '" />';
$s_hidden_fields .= '<input type="hidden" name="notifyreply" value="' . $notifyreply . '" />';
$s_hidden_fields .= '<input type="hidden" name="notifypm" value="' . $notifypm . '" />';
$s_hidden_fields .= '<input type="hidden" name="games_block_pm" value="' . $games_block_pm . '" />';
$s_hidden_fields .= '<input type="hidden" name="popup_pm" value="' . $popup_pm . '" />';
$s_hidden_fields .= '<input type="hidden" name="attachsig" value="' . $attachsig . '" />';
$s_hidden_fields .= '<input type="hidden" name="setbm" value="' . $setbm . '" />';
$s_hidden_fields .= '<input type="hidden" name="allowbbcode" value="' . $allowbbcode . '" />';
$s_hidden_fields .= '<input type="hidden" name="allowhtml" value="' . $allowhtml . '" />';
$s_hidden_fields .= '<input type="hidden" name="allowsmilies" value="' . $allowsmilies . '" />';
$s_hidden_fields .= '<input type="hidden" name="language" value="' . $user_lang . '" />';
$s_hidden_fields .= '<input type="hidden" name="style" value="' . $user_style . '" />';
$s_hidden_fields .= '<input type="hidden" name="timezone" value="' . $user_timezone . '" />';
$s_hidden_fields .= '<input type="hidden" name="dateformat" value="' . str_replace("\"", "&quot;", $user_dateformat) . '" />';
$s_hidden_fields .= '<input type="hidden" name="user_flag" value="' . $user_flag . '" />';
$s_hidden_fields .= '<input type="hidden" name="avatarurl" value="' . $user_avatar_upload . '" />';
$s_hidden_fields .= '<input type="hidden" name="avatarremoteurl" value="' . $user_avatar_remoteurl . '" />';
$s_hidden_fields .= '<input type="hidden" name="sid" value="' . phpbb_profile_text($userdata['session_id']) . '" />';


// get the board & user settings ...
$html_status    = ( $userdata['user_allowhtml'] && $board_config['allow_html'] ) ? $lang['HTML_is_ON'] : $lang['HTML_is_OFF'];
$bbcode_status  = ( $userdata['user_allowbbcode'] && $board_config['allow_bbcode']  ) ? $lang['BBCode_is_ON'] : $lang['BBCode_is_OFF'];
$smilies_status = ( $userdata['user_allowsmile'] && $board_config['allow_smilies']  ) ? $lang['Smilies_are_ON'] : $lang['Smilies_are_OFF'];

$html_on    = ( $userdata['user_allowhtml'] && $board_config['allow_html'] ) ? 1 : 0 ;
$bbcode_on  = ( $userdata['user_allowbbcode'] && $board_config['allow_bbcode']  ) ? 1 : 0 ;
$smilies_on = ( $userdata['user_allowsmile'] && $board_config['allow_smilies']  ) ? 1 : 0 ;

// check and set various parameters
$submit = usercp_signature_post_scalar('save');
$preview = usercp_signature_post_scalar('preview');
$current = usercp_signature_post_scalar('current');
$mode_value = usercp_signature_post_scalar('mode');
$mode = ($mode_value !== '') ? $mode_value : usercp_signature_get_scalar('mode');
$signature_text = trim(usercp_signature_post_scalar('signature_text'));

if ($editprofile)
{
        $template->assign_vars(array('RETURN_PROFILE' => 1));
        $sig_link = append_sid("profile.$phpEx?mode=editprofile");
}
else
{
	$template->assign_vars(array('RETURN_PROFILE' => 0));
	$sig_link = append_sid("profile.$phpEx?mode=signature");
}

$signature = str_replace('<br />', "\n", $signature);
if ($current)
{
	$mode = '';
	$submit = '';
}

$page_title = $lang['Signature'];

include($phpbb_root_path . 'includes/bbcode.'.$phpEx);
include($phpbb_root_path . 'includes/functions_post.'.$phpEx);
include($phpbb_root_path . 'includes/page_header.'.$phpEx);

// save new signature
if ($submit)
{
	$submitted_sid = usercp_signature_post_scalar('sid');
	if ($submitted_sid === '' || !hash_equals((string) $userdata['session_id'], $submitted_sid))
	{
		message_die(GENERAL_ERROR, $lang['Session_invalid']);
	}

	$template->assign_block_vars('switch_save_sig', array());

	if ( strlen( $signature_text ) > $board_config['max_sig_chars'] )
	{
		$save_message = $lang['Signature_too_long'];
	}
	else
	{
		$bbcode_uid = ( $bbcode_on ) ? make_bbcode_uid() : '';
		$signature_text = prepare_message($signature_text, $html_on, $bbcode_on, $smilies_on, $bbcode_uid);
		$user_id =  $userdata['user_id'];

		$sql = "UPDATE " . USERS_TABLE . "
		SET user_sig = '" . str_replace("\'", "''", $signature_text) . "', user_sig_bbcode_uid = '$bbcode_uid'
		WHERE user_id = $user_id";

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not update users table', '', __LINE__, __FILE__, $sql);
		}

		else
		{
			$save_message = $lang['sig_save_message'];
		}
	}
}

// catch the submitted message and prepare it for a preview
else if ($preview)
{
	$template->assign_block_vars('switch_preview_sig', array());

	if ( isset($signature_text) )
	{
		$preview_sig = $signature_text;

		if ( strlen( $preview_sig ) > $board_config['max_sig_chars'] )
		{
			$preview_sig = $lang['Signature_too_long'];
		}

		else
		{
			$preview_sig = htmlspecialchars(stripslashes($preview_sig));
			$bbcode_uid = ( $bbcode_on ) ? make_bbcode_uid() : '';
			$preview_sig = stripslashes(prepare_message(addslashes(unprepare_message($preview_sig)), $html_on, $bbcode_on, $smilies_on, $bbcode_uid));

			if( $preview_sig != '' )
			{
				if ( $bbcode_on  == 1 ) { $preview_sig = bbencode_second_pass($preview_sig, $bbcode_uid); }
				if ( $bbcode_on  == 1 ) { $preview_sig = bbencode_first_pass($preview_sig, $bbcode_uid); }
				if ( $bbcode_on  == 1 ) { $preview_sig = make_clickable($preview_sig); }
				if ( $smilies_on == 1 ) { $preview_sig = smilies_pass($preview_sig); }

				$preview_sig = '_________________<br />' . $preview_sig;
				$preview_sig = nl2br($preview_sig);
			}

			else
			{
				$preview_sig = $lang['sig_none'];
			}
		}
	}
}

// read current signature and prepare it for a preview
else if ($mode || empty($mode))
{

	$template->assign_block_vars('switch_current_sig', array());

	$signature_bbcode_uid = $userdata['user_sig_bbcode_uid'];
	$signature_text = ( $signature_bbcode_uid != '' ) ? preg_replace("/:(([a-z0-9]+:)?)$signature_bbcode_uid\]/si", ']', $userdata['user_sig']) : $userdata['user_sig'];
	$bbcode_uid = $userdata['user_sig_bbcode_uid'];
	$user_sig = prepare_message($userdata['user_sig'], $html_on, $bbcode_on, $smilies_on, $bbcode_uid);

	if( $user_sig != '' )
	{
		if ( $bbcode_on  == 1 ) { $user_sig = bbencode_second_pass($user_sig, $bbcode_uid); }
		if ( $bbcode_on  == 1 ) { $user_sig = bbencode_first_pass($user_sig, $bbcode_uid); }
		if ( $bbcode_on  == 1 ) { $user_sig = make_clickable($user_sig); }
		if ( $smilies_on == 1 ) { $user_sig = smilies_pass($user_sig); }
		$user_sig = '_________________<br />' . $user_sig;
		$user_sig = nl2br($user_sig);
	}
	else
	{
		$user_sig = $lang['sig_none'];
	}
}

// template
	$template->set_filenames(array(
		'body' => 'profile_signature.tpl'

	));

	$template->assign_vars(array(

		// added some pic´s for a better preview ;)
		'PROFIL_IMG' => '<img src="' . $images['icon_profile'] . '" alt="' . $lang['Read_profile'] . '" title="' . $lang['Read_profile'] . '" border="0" />',
		'EMAIL_IMG'  => '<img src="' . $images['icon_email'] . '" alt="' . $lang['Send_email'] . '" title="' . $lang['Send_email'] . '" border="0" />',
		'PM_IMG'     => '<img src="' . $images['icon_pm'] . '" alt="' . $lang['Send_private_message'] . '" title="' . $lang['Send_private_message'] . '" border="0" />',
		'WWW_IMG'    => '<img src="' . $images['icon_www'] . '" alt="' . $lang['Visit_website'] . '" title="' . $lang['Visit_website'] . '" border="0" />',
		'AIM_IMG'    => '<img src="' . $images['icon_aim'] . '" alt="' . $lang['AIM'] . '" title="' . $lang['AIM'] . '" border="0" />',
		'YIM_IMG'    => '<img src="' . $images['icon_yim'] . '" alt="' . $lang['YIM'] . '" title="' . $lang['YIM'] . '" border="0" />',
		'MSN_IMG'    => '<img src="' . $images['icon_msnm'] . '" alt="' . $lang['MSNM'] . '" title="' . $lang['MSNM'] . '" border="0" />',
		'ICQ_IMG'    => '<img src="' . $images['icon_icq'] . '" alt="' . $lang['ICQ'] . '" title="' . $lang['ICQ'] . '" border="0" />',

		'SIG_SAVE' => $lang['sig_save'],
		'SIG_CANCEL' => $lang['Cancel'],
		'SIG_PREVIEW' => $lang['Preview'],
		'SIG_EDIT' => $lang['sig_edit'],
		'SIG_CURRENT' => $lang['sig_current'],
		'SIG_LINK' => $sig_link,

		'L_SIGNATURE' => $lang['Signature'],
		'L_SIGNATURE_EXPLAIN' => sprintf($lang['Signature_explain'], $board_config['max_sig_chars']),
		'HTML_STATUS' => $html_status,
		'BBCODE_STATUS' => sprintf($bbcode_status, '<a href="' . append_sid("faq.$phpEx?mode=bbcode") . '" target="_phpbbcode">', '</a>'),
		'SMILIES_STATUS' => $smilies_status,

		'SIGNATURE' => phpbb_profile_text($signature_text),
		'CURRENT_PREVIEW' => $user_sig,
		'PREVIEW' => htmlspecialchars(stripslashes($signature_text)),
		'REAL_PREVIEW' => $preview_sig,
		'SAVE_MESSAGE' => $save_message,

		'S_HIDDEN_FIELDS' => $s_hidden_fields,
	));

	$template->pparse('body');

include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
?>
