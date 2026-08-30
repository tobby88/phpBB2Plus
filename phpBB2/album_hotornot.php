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

$rating_submitted = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hon_rating']) && is_scalar($_POST['hon_rating']));
$rate_point = $rating_submitted ? intval($_POST['hon_rating']) : 0;

//if user havent rated a picture, show page, else update database
if (!$rating_submitted)
{
	// ------------------------------------
	// get a random pic from album
	// ------------------------------------
	$hotornot_categories = array();
	foreach (preg_split('/[^0-9]+/', (string) $album_sp_config['hon_rate_where'], -1, PREG_SPLIT_NO_EMPTY) as $hotornot_category)
	{
		$hotornot_category = intval($hotornot_category);
		if ($hotornot_category > 0)
		{
			$hotornot_categories[] = $hotornot_category;
		}
	}

	if (!count($hotornot_categories))
	{
		$sql = "SELECT `pic_id`  FROM " . ALBUM_TABLE . " ORDER BY RAND() LIMIT 1";
	}
	else
	{
		$sql = "SELECT `pic_id`  FROM " . ALBUM_TABLE . " WHERE pic_cat_id IN (" . implode(',', array_unique($hotornot_categories)) . ") ORDER BY RAND() LIMIT 1";
	}

	if( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not query pic information', '', __LINE__, __FILE__, $sql);
	}
	$pic_id_temp = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$pic_id_temp || empty($pic_id_temp['pic_id']))
	{
		message_die(GENERAL_MESSAGE, $lang['Pic_not_exist']);
	}
	$pic_id = intval($pic_id_temp['pic_id']);


	// ------------------------------------
	// Get this pic info and current category info
	// ------------------------------------
	$rating_from = ($album_sp_config['hon_rate_sep'] == 1) ? 'AVG(r.rate_hon_point) AS rating' : 'AVG(r.rate_point) AS rating';

	//--- Album Category Hierarchy : begin
	//--- version : <= 1.1.0
	$sql = "SELECT p.*, cat.*,  u.user_id, u.username, r.rate_pic_id, " . $rating_from . ", COUNT(DISTINCT c.comment_id) AS comments
			FROM ". ALBUM_CAT_TABLE ."  AS cat, ". ALBUM_TABLE ." AS p
				LEFT JOIN ". USERS_TABLE ." AS u ON p.pic_user_id = u.user_id
				LEFT JOIN ". ALBUM_RATE_TABLE ." AS r ON p.pic_id = r.rate_pic_id
				LEFT JOIN ". ALBUM_COMMENT_TABLE ." AS c ON p.pic_id = c.comment_pic_id
			WHERE pic_id = '$pic_id'
				AND cat.cat_id = p.pic_cat_id
			GROUP BY p.pic_id";
	//--- Album Category Hierarchy : end

	if( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not query pic information', '', __LINE__, __FILE__, $sql);
	}
	$thispic = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	if (empty($thispic))
	{
		message_die(GENERAL_ERROR, $lang['Pic_not_exist']);
	}
	$pic_filename = str_replace('\\', '/', (string) $thispic['pic_filename']);
	if ($pic_filename === '' || basename($pic_filename) !== $pic_filename || !is_file(ALBUM_UPLOAD_PATH . $pic_filename))
	{
		message_die(GENERAL_ERROR, $lang['Pic_not_exist']);
	}

	$cat_id = intval($thispic['pic_cat_id']);
	$album_user_id = intval($thispic['cat_user_id']);

	// ------------------------------------
	// Check the permissions
	// ------------------------------------
	$album_user_access = album_permissions($album_user_id, $cat_id, ALBUM_AUTH_ALL, $thispic);

	if (empty($album_user_access['view']))
	{
		if (!$userdata['session_logged_in'])
		{
			redirect(append_sid("login.$phpEx?redirect=album_hotornot.$phpEx"));
		}
		else
		{
			message_die(GENERAL_ERROR, $lang['Not_Authorised']);
		}
	}



	// ------------------------------------
	// Check Pic Approval
	// ------------------------------------

	if ($userdata['user_level'] != ADMIN)
	{
		if (($thispic['cat_approval'] == ADMIN) || (($thispic['cat_approval'] == MOD) && empty($album_user_access['moderator'])))
		{
			if ($thispic['pic_approval'] != 1)
			{
				message_die(GENERAL_ERROR, $lang['Not_Authorised']);
			}
		}
	}


	/*
	+----------------------------------------------------------
	| Main work here...
	+----------------------------------------------------------
	*/

	//
	// Start output of page
	//
	$page_title = $lang['Album'];
	include($phpbb_root_path . 'includes/page_header.'.$phpEx);

	$template->set_filenames(array(
		'body' => 'album_hon.tpl')
	);

	if( ($thispic['pic_user_id'] == ALBUM_GUEST) or ($thispic['username'] == '') )
	{
		$poster = ($thispic['pic_username'] == '') ? $lang['Guest'] : $thispic['pic_username'];
	}
	else
	{
		$poster = '<a href="'. append_sid("profile.$phpEx?mode=viewprofile&amp;". POST_USERS_URL .'='. $thispic['user_id']) .'">'. $thispic['username'] .'</a>';
	}

	//deside how user wants to show their rating
	$image_rating = ImageRating($thispic['rating']);

	//hot or not rating
	$guest_may_rate = $userdata['session_logged_in'] || !empty($album_sp_config['hon_rate_users']);
	if (!empty($album_config['rate']) && !empty($album_user_access['rate']) && $guest_may_rate && CanRated($pic_id, $userdata['user_id']))
	{
		$template->assign_block_vars('hon_rating', array());

		for ($i = 0; $i < $album_config['rate_scale']; $i++)
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
		'CAT_TITLE' => $thispic['cat_title'],
		'U_VIEW_CAT' => append_sid(album_append_uid("album_cat.$phpEx?cat_id=$cat_id")),

		'U_PIC' => append_sid("album_pic.$phpEx?pic_id=$pic_id"),

		'PIC_TITLE' => $thispic['pic_title'],
		'PIC_DESC' => nl2br($thispic['pic_desc']),

		'POSTER' => $poster,

		'PIC_TIME' => create_date($board_config['default_dateformat'], $thispic['pic_time'], $board_config['board_timezone']),

		'PIC_VIEW' => $thispic['pic_view_count'],

		'PIC_RATING' => $image_rating,

		'PIC_COMMENTS' => $thispic['comments'],

		'U_COMMENT' => append_sid("album_showpage.$phpEx?pic_id=$pic_id"),

		'PICTURE_ID' => $pic_id,
		'S_FORM_TOKEN' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
		'L_RATE_ME' => $lang['Rating'],
		'L_ALREADY_RATED' => $lang['Already_rated'],

		'L_RATING' => $lang['Rating'],
		'L_PIC_TITLE' => $lang['Pic_Title'] . (isset($album_config['clown_rateType']) ? $album_config['clown_rateType'] : ''),
		'L_PIC_DESC' => $lang['Pic_Desc'],
		'L_POSTER' => $lang['Poster'],
		'L_POSTED' => $lang['Posted'],
		'L_VIEW' => $lang['View'],
		'L_COMMENTS' => $lang['Comments'])
	);

	if ($album_config['rate'])
	{
		$template->assign_block_vars('rate_switch', array());
	}

	if ($album_config['comment'])
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

	$rate_user_id = $userdata['user_id'];
	$rate_user_ip = $userdata['session_ip'];
	$rate_user_ip_sql = $db->sql_escape($rate_user_ip);
	$pic_id = (isset($_POST['pic_id']) && is_scalar($_POST['pic_id'])) ? intval($_POST['pic_id']) : 0;
	$max_rate = max(1, intval($album_config['rate_scale']));

	if ($pic_id <= 0 || $rate_point < 1 || $rate_point > $max_rate || empty($album_config['rate']))
	{
		message_die(GENERAL_ERROR, $lang['Not_Authorised']);
	}

	$sql = "SELECT p.*, cat.*
		FROM " . ALBUM_TABLE . " p, " . ALBUM_CAT_TABLE . " cat
		WHERE p.pic_id = " . intval($pic_id) . "
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

	if (!CanRated($pic_id, $rate_user_id))
	{
		message_die(GENERAL_MESSAGE, $lang['Already_rated']);
	}

	$prevent_duplicate = ($userdata['session_logged_in'] && empty($album_sp_config['hon_rate_times']));

	if ($album_sp_config['hon_rate_sep'] == 1)
	{
		$sql = "INSERT INTO ". ALBUM_RATE_TABLE ." (rate_pic_id, rate_user_id, rate_user_ip, rate_hon_point) ";
	}
	else
	{
		$sql = "INSERT INTO ". ALBUM_RATE_TABLE ." (rate_pic_id, rate_user_id, rate_user_ip, rate_point) ";
	}
	$sql .= "SELECT " . intval($pic_id) . ", " . intval($rate_user_id) . ", '$rate_user_ip_sql', " . intval($rate_point);
	if ($prevent_duplicate)
	{
		$sql .= " WHERE NOT EXISTS (SELECT 1 FROM " . ALBUM_RATE_TABLE . " WHERE rate_pic_id = " . intval($pic_id) . " AND rate_user_id = " . intval($rate_user_id) . ")";
	}

	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, 'Could not insert new rating', '', __LINE__, __FILE__, $sql);
	}
	if ($prevent_duplicate && !$db->sql_affectedrows())
	{
		message_die(GENERAL_MESSAGE, $lang['Already_rated']);
	}

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
