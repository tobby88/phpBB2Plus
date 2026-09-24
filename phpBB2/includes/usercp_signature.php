<?php
if ( !defined('IN_PHPBB') )
{
	die("Hacking attempt");
	exit;
}

function usercp_signature_post_scalar($name, $default = '')
{
	return (isset($_POST[$name]) && is_scalar($_POST[$name])) ? (string) phpbb_request_raw_value($_POST[$name]) : $default;
}

// Profile editing stays in its original tab; never transport unrelated fields.
$s_hidden_fields = '<input type="hidden" name="sid" value="' . phpbb_profile_text($userdata['session_id']) . '" />';
$user_sig = $preview_sig = $save_message = $bbcode_uid = '';

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
$signature_text = trim(usercp_signature_post_scalar('signature_text'));

$sig_link = append_sid("profile.$phpEx?mode=signature", true);
if ($current)
{
	$submit = $preview = '';
}

$page_title = $lang['Signature'];

include_once($phpbb_root_path . 'includes/bbcode.'.$phpEx);
include_once($phpbb_root_path . 'includes/functions_post.'.$phpEx);
require_once($phpbb_root_path . 'includes/functions_signature.' . $phpEx);
include($phpbb_root_path . 'includes/page_header.'.$phpEx);

// save new signature
if ($submit)
{
	$signature_saved = false;
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
		require_once($phpbb_root_path . 'includes/functions_signature_storage.' . $phpEx);
		try
		{
			$saved_signature = phpbb_signature_save($db, $signature_text, $submitted_sid);
			$signature_saved = true;
			$save_message = $lang['sig_save_message'];
		}
		catch (PhpbbSignatureException $signature_failure)
		{
			$save_message = $signature_failure->getMessage();
		}
	}
	if (!$signature_saved) { $template->assign_block_vars('switch_save_sig.switch_retry_sig', array()); }
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
			$bbcode_uid = ( $bbcode_on ) ? make_bbcode_uid() : '';
			$preview_sig = phpbb_signature_prepare($preview_sig, $html_on, $bbcode_on, $smilies_on, $bbcode_uid);
			$preview_sig = phpbb_signature_render($preview_sig, $bbcode_uid, $html_on, $bbcode_on, $smilies_on);
		}
	}
}

// read current signature and prepare it for a preview
else
{

	$template->assign_block_vars('switch_current_sig', array());

	$signature_bbcode_uid = $userdata['user_sig_bbcode_uid'];
	$signature_text = phpbb_signature_edit_text($userdata['user_sig'], $signature_bbcode_uid);
	$bbcode_uid = $userdata['user_sig_bbcode_uid'];
	$user_sig = phpbb_signature_render($userdata['user_sig'], $bbcode_uid, $html_on, !empty($board_config['allow_bbcode']), $smilies_on);
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

		'SIG_SAVE' => $lang['sig_save'],
		'SIG_CANCEL' => $lang['Cancel'],
		'SIG_PREVIEW' => $lang['Preview'],
		'SIG_EDIT' => $lang['sig_edit'],
		'SIG_CURRENT' => $lang['sig_current'],
		'SIG_LINK' => phpbb_profile_text($sig_link),
		'U_PROFILE' => phpbb_profile_text(append_sid("profile.$phpEx?mode=editprofile", true)),
		'L_PROFILE' => $lang['Profile'],

		'L_SIGNATURE' => $lang['Signature'],
		'L_SIGNATURE_EXPLAIN' => sprintf($lang['Signature_explain'], $board_config['max_sig_chars']),
		'HTML_STATUS' => $html_status,
		'BBCODE_STATUS' => sprintf($bbcode_status, '<a href="' . append_sid("faq.$phpEx?mode=bbcode") . '" target="_phpbbcode">', '</a>'),
		'SMILIES_STATUS' => $smilies_status,

		'SIGNATURE' => phpbb_profile_text($signature_text),
		'CURRENT_PREVIEW' => $user_sig,
		'PREVIEW' => phpbb_profile_text($signature_text),
		'REAL_PREVIEW' => $preview_sig,
		'SAVE_MESSAGE' => $save_message,

		'S_HIDDEN_FIELDS' => $s_hidden_fields,
	));

	$template->pparse('body');

include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
?>
