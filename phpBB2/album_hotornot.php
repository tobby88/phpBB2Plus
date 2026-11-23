<?php
/***************************************************************************
 *                             album_hotornot.php
 *                            -------------------
 *   started            : Saturday, January 18, 2004
 *   copyright          : © Volodymyr (CLowN) Skoryk
 *   email              : blaatimmy72@yahoo.com
 *	 version            : 1.5
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

define('IN_PHPBB', true);
$phpbb_root_path = './';
$album_root_path = $phpbb_root_path . 'album_mod/';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_ALBUM);
init_userprefs($userdata);
//
// End session management

include($album_root_path . 'album_common.'.$phpEx);
require_once $album_root_path . 'album_picture_discussion.php';

$rating_submitted = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hon_rating']) && is_scalar($_POST['hon_rating']));
$rate_point = $rating_submitted ? intval($_POST['hon_rating']) : 0;

if (!$rating_submitted)
{
	try { $selection = album_hotornot_picture($db); }
	catch (PhpbbForumReadFailure $error) { message_die(GENERAL_ERROR, $lang['Album_hon_load_failed']); }
	if (!$selection) { message_die(GENERAL_MESSAGE, $lang['Album_hon_empty']); }
	$thispic = $selection['picture'];
	$pic_id = album_read_id($thispic['pic_id']);
	$pic_filename = str_replace('\\', '/', (string) $thispic['pic_filename']);
	if ($pic_filename === '' || basename($pic_filename) !== $pic_filename || !is_file(ALBUM_UPLOAD_PATH . $pic_filename))
	{
		message_die(GENERAL_ERROR, $lang['Pic_not_exist']);
	}

	$cat_id = intval($thispic['pic_cat_id']);
	$album_user_id = intval($thispic['cat_user_id']);

	$page_title = $lang['Album'];
	include($phpbb_root_path . 'includes/page_header.'.$phpEx);

	$template->set_filenames(array(
		'body' => 'album_hon.tpl')
	);

	if( ($thispic['pic_user_id'] == ALBUM_GUEST) or ($thispic['username'] == '') )
	{
		$poster = phpbb_author_name($thispic['pic_user_id'],($thispic['pic_username']===''?$lang['Guest']:$thispic['pic_username']));
	}
	else
	{
		$poster = '<a href="'. append_sid("profile.$phpEx?mode=viewprofile&amp;". POST_USERS_URL .'='. (int) $thispic['user_id']) .'">'. album_html_text($thispic['username']) .'</a>';
	}

	$image_rating = ImageRating($thispic['rating']);

	if ($selection['can_rate'])
	{
		$template->assign_block_vars('hon_rating', array());

		for ($i = 0; $i < $selection['scale']; $i++)
		{
			$template->assign_block_vars('hon_rating.hon_row', array(
				'VALUE' => ($i + 1)));
		}
	}
	else
	{
		$template->assign_block_vars('hon_rating_cant', array());
	}

	$template->assign_vars(array(
		'CAT_TITLE' => album_html_text($thispic['cat_title']),
		'U_VIEW_CAT' => append_sid(album_append_uid("album_cat.$phpEx?cat_id=$cat_id")),

		'U_PIC' => append_sid("album_pic.$phpEx?pic_id=$pic_id"),

		'PIC_TITLE' => album_html_text($thispic['pic_title']),
		'PIC_DESC' => nl2br(album_html_text($thispic['pic_desc'])),

		'POSTER' => $poster,

		'PIC_TIME' => create_date($board_config['default_dateformat'], $thispic['pic_time'], $board_config['board_timezone']),

		'PIC_VIEW' => $thispic['pic_view_count'],

		'PIC_RATING' => $image_rating,

		'PIC_COMMENTS' => $thispic['comments_count'],

		'U_COMMENT' => append_sid("album_showpage.$phpEx?pic_id=$pic_id"),

		'PICTURE_ID' => $pic_id,
		'S_RATE_ACTION' => append_sid("album_hotornot.$phpEx"),
		'S_FORM_TOKEN' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
		'L_RATE_ME' => $lang['Rating'],
		'L_RATING_STATUS' => $selection['already_rated'] ? $lang['Already_rated'] : $lang['Album_hon_rating_unavailable'],

		'L_RATING' => $lang['Rating'],
		'L_PIC_TITLE' => $lang['Pic_Title'],
		'L_PIC_DESC' => $lang['Pic_Desc'],
		'L_POSTER' => $lang['Poster'],
		'L_POSTED' => $lang['Posted'],
		'L_VIEW' => $lang['View'],
		'L_COMMENTS' => $lang['Comments'])
	);

	if ($selection['rating_enabled'])
	{
		$template->assign_block_vars('rate_switch', array());
	}

	if ($selection['comment_enabled'])
	{
		$template->assign_block_vars('comment_switch', array());
	}

	//
	// Generate the page
	//
	$template->pparse('body');

	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
}
else
{
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['sid']) || !is_scalar($_POST['sid']) || !hash_equals((string) $userdata['session_id'], (string) $_POST['sid']))
	{
		message_die(GENERAL_ERROR, $lang['Not_Authorised']);
	}

	$pic_id = isset($_POST['pic_id']) ? album_read_id($_POST['pic_id']) : 0;
	$max_rate = max(1, intval($album_config['rate_scale']));

	if ($pic_id <= 0 || $rate_point < 1 || $rate_point > $max_rate || empty($album_config['rate']))
	{
		message_die(GENERAL_ERROR, $lang['Not_Authorised']);
	}

	$sql = "SELECT p.*, cat.*
		FROM " . ALBUM_TABLE . " p, " . ALBUM_CAT_TABLE . " cat
		WHERE p.pic_id = " . $pic_id . "
			AND cat.cat_id = p.pic_cat_id";
	if (!($result = $db->sql_query($sql)) || !($rated_pic = $db->sql_fetchrow($result)))
	{
		message_die(GENERAL_ERROR, $lang['Pic_not_exist']);
	}
	$db->sql_freeresult($result);
	$rated_filename = str_replace('\\', '/', (string) $rated_pic['pic_filename']);
	if ($rated_filename === '' || basename($rated_filename) !== $rated_filename || !is_file(ALBUM_UPLOAD_PATH . $rated_filename))
	{
		message_die(GENERAL_ERROR, $lang['Pic_not_exist']);
	}

	$album_user_access = album_permissions($rated_pic['cat_user_id'], $rated_pic['pic_cat_id'], ALBUM_AUTH_ALL, $rated_pic);
	$guest_may_rate = $userdata['session_logged_in'] || !empty($album_sp_config['hon_rate_users']);
	$approval_required = ($rated_pic['cat_approval'] == ADMIN) || (($rated_pic['cat_approval'] == MOD) && empty($album_user_access['moderator']));
	if (empty($album_user_access['view']) || empty($album_user_access['rate']) || !$guest_may_rate || ($userdata['user_level'] != ADMIN && $approval_required && empty($rated_pic['pic_approval'])))
	{
		message_die(GENERAL_ERROR, $lang['Not_Authorised']);
	}

	// Recheck the current category list, guest/repeat/channel configuration and
	// current authority while holding the same namespace as picture deletion.
	album_discussion_request('hotornot', array(
		$userdata, (int) $rated_pic['cat_user_id'], (int) $rated_pic['pic_cat_id'],
		$pic_id, (int) $rate_point));

	// --------------------------------
	// Complete... now send a message to user
	// --------------------------------

	$template->assign_vars(array(
		'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid("album_hotornot.$phpEx") . '">')
	);
	$message = $lang['Album_rate_successfully'] . '<br /><br />' . sprintf($lang['Click_return_album_index'], '<a href="' . append_sid(album_append_uid("album.$phpEx")) . '">', '</a>');
	message_die(GENERAL_MESSAGE, $message);
}


// +-------------------------------------------------------------+
// |  Powered by Photo Album 2.x.x (c) 2002-2003 Smartor         |
// |  with Volodymyr (CLowN) Skoryk's Service Pack 1 © 2003-2004 |
// +-------------------------------------------------------------+

?>
