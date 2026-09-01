<?php
/***************************************************************************
 *                             album_comment.php
 *                            -------------------
 *   begin                : Wednesday, February 05, 2003
 *   copyright            : (C) 2003 Smartor
 *   email                : smartor_xp@hotmail.com
 *
 *   $Id: album_comment.php,v 2.0.8 2003/03/14 07:08:15 ngoctu Exp $
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

  /***************************************************************************
 *                            MODIFICATIONS
 *                           ---------------
 *   started            : Saturday, January 18, 2004
 *   copyright          : © Volodymyr (CLowN) Skoryk
 *   email              : blaatimmy72@yahoo.com
 *	 version            : 1.5.1
 *
 *	 MODIFICATIONS:
 *		-renamed page to album_showpage.php
 *		-combined rating and comment system to page
 *		-added smilies, user avatar, contact buttons and made layout have
 *		 forum type look
 *		-made the page use midthumbnail...enabled/desabled via admin panel
 *		-and more tweaks..
 *
 *      - in 1.5.1 fixed a little problem with how pagenation works
 *
 ***************************************************************************/

if( isset($_GET['mode']) && $_GET['mode'] == 'smilies' )
{
	define('IN_PHPBB', true);
    $phpbb_root_path = './';
    include($phpbb_root_path . 'extension.inc');
    include($phpbb_root_path . 'common.'.$phpEx);
    include($phpbb_root_path . 'album_mod/clown_album_functions.'.$phpEx);

    generate_album_smilies('window', PAGE_ALBUM_PICTURE);
    exit;
}

define('IN_PHPBB', true);
$phpbb_root_path = './';
$album_root_path = $phpbb_root_path . 'album_mod/';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);
include($phpbb_root_path . 'includes/functions_validate.'.$phpEx);

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_ALBUM_PICTURE);
init_userprefs($userdata);
//
// End session management
//


//
// Get general album information
//
include($album_root_path . 'album_common.'.$phpEx);
include_once($phpbb_root_path . 'includes/bbcode.'.$phpEx);


// ------------------------------------
// Check feature enabled
// ------------------------------------

// ------------------------------------
// Check the request
// ------------------------------------

if( isset($_GET['pic_id']) && is_scalar($_GET['pic_id']) )
{
	$pic_id = intval($_GET['pic_id']);
}
else if( isset($_POST['pic_id']) && is_scalar($_POST['pic_id']) )
{
	$pic_id = intval($_POST['pic_id']);
}
else
{
	if( isset($_GET['comment_id']) && is_scalar($_GET['comment_id']) )
	{
		$comment_id = intval($_GET['comment_id']);
	}
	else if( isset($_POST['comment_id']) && is_scalar($_POST['comment_id']) )
	{
		$comment_id = intval($_POST['comment_id']);
	}
	else
	{
		message_die(GENERAL_ERROR, 'Bad request');
	}
}

//for midthum or full pic
if ($album_sp_config['midthumb_use'] == 1)
{
	if( isset($_GET['full']) )
	{
		$picm = TRUE;
	}
	else if( isset($_POST['full']) )
	{
		$picm = TRUE;
	}
	else
	{
		$picm = FALSE;
	}
}
else
{
	$picm = TRUE;
}

// ------------------------------------
// PREVIOUS & NEXT
// ------------------------------------

if( isset($_GET['mode']) )
{
	if( ($_GET['mode'] == 'next') or ($_GET['mode'] == 'previous') )
	{
		$sql = "SELECT pic_id, pic_cat_id, pic_user_id
				FROM ". ALBUM_TABLE ."
				WHERE pic_id = $pic_id";

		if( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not query pic information', '', __LINE__, __FILE__, $sql);
		}

		$row = $db->sql_fetchrow($result);

		if( empty($row) )
		{
			message_die(GENERAL_ERROR, 'Bad pic_id');
		}

		$pic_cat_id_temp = $row['pic_cat_id'];
      	$pic_id_temp =  $row['pic_id'];

		$sql = "SELECT new.pic_id, new.pic_time
				FROM ". ALBUM_TABLE ." AS new, ". ALBUM_TABLE ." AS cur
				WHERE cur.pic_id = $pic_id
					AND new.pic_id <> cur.pic_id
					AND new.pic_cat_id = cur.pic_cat_id";

		$sql .= ($_GET['mode'] == 'next') ? " AND new.pic_time >= cur.pic_time" : " AND new.pic_time <= cur.pic_time";

		$sql .= ($row['pic_cat_id'] == PERSONAL_GALLERY) ? " AND new.pic_user_id = cur.pic_user_id" : "";

		$sql .= ($_GET['mode'] == 'next') ? " ORDER BY pic_time ASC LIMIT 1" : " ORDER BY pic_time DESC LIMIT 1";

		if( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not query pic information', '', __LINE__, __FILE__, $sql);
		}

		$row = $db->sql_fetchrow($result);

		if( empty($row) )
		{
			$sql = "SELECT new.pic_id, new.pic_time
	         	FROM ". ALBUM_TABLE ." AS new, ". ALBUM_TABLE ." AS cur
	         	WHERE cur.pic_id = $pic_id_temp
	            AND new.pic_id <> $pic_id_temp
	            AND new.pic_cat_id = $pic_cat_id_temp ";

	         $sql .= ($pic_cat_id_temp == PERSONAL_GALLERY) ? " AND new.pic_user_id = cur.pic_user_id" : "";

	         $sql .= ($_GET['mode'] == 'next') ? " ORDER BY pic_time ASC LIMIT 0,1" : " ORDER BY pic_time DESC LIMIT 0,1";


	         if( !($result = $db->sql_query($sql)) )
	         {
	            message_die(GENERAL_ERROR, 'Could not query pic information', '', __LINE__, __FILE__, $sql);
	         }

	         $row = $db->sql_fetchrow($result);

	         if( empty($row) )
	         {
	            message_die(GENERAL_ERROR, $lang['Pic_not_exist']);
	         }

			}

			$pic_id = $row['pic_id'];
	}
}


// ------------------------------------
// Get $pic_id from $comment_id
// ------------------------------------

if( isset($comment_id) )
{
	$sql = "SELECT comment_id, comment_pic_id
			FROM ". ALBUM_COMMENT_TABLE ."
			WHERE comment_id = '$comment_id'";

	if( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not query comment and pic information', '', __LINE__, __FILE__, $sql);
	}

	$row = $db->sql_fetchrow($result);

	if( empty($row) )
	{
		message_die(GENERAL_ERROR, 'This comment does not exist');
	}

	$pic_id = $row['comment_pic_id'];
}


//--- Album Category Hierarchy : begin
//--- version : 1.1.0
// ------------------------------------
// Get this pic info and current category info
// ------------------------------------

$sql = "SELECT p.*, ac.*, u.user_id, u.username, u.user_rank, r.rate_pic_id, AVG(r.rate_point) AS rating, COUNT( DISTINCT c.comment_id) AS comments_count
		FROM ". ALBUM_CAT_TABLE ." AS ac, ". ALBUM_TABLE ." AS p
			LEFT JOIN ". USERS_TABLE ." AS u ON p.pic_user_id = u.user_id
			LEFT JOIN ". ALBUM_COMMENT_TABLE ." AS c ON p.pic_id = c.comment_pic_id
			LEFT JOIN ". ALBUM_RATE_TABLE ." AS r ON p.pic_id = r.rate_pic_id
		WHERE pic_id = '$pic_id'
		    AND ac.cat_id = p.pic_cat_id
		GROUP BY p.pic_id
		LIMIT 1";

if( !($result = $db->sql_query($sql)) )
{
	message_die(GENERAL_ERROR, 'Could not query pic information', '', __LINE__, __FILE__, $sql);
}
$thispic = $db->sql_fetchrow($result);

if( empty($thispic) )
{
	message_die(GENERAL_ERROR, $lang['Pic_not_exist'] . ' -> ' . $pic_id);
}

$cat_id = ($thispic['pic_cat_id'] != 0) ? $thispic['pic_cat_id'] : $thispic['cat_id'];
$album_user_id = $thispic['cat_user_id'];

$total_comments = $thispic['comments_count'];
$comments_per_page = max(1, isset($board_config['posts_per_page']) ? intval($board_config['posts_per_page']) : 10);

// ------------------------------------
// Check the permissions
// ------------------------------------
$check_permissions = ALBUM_AUTH_VIEW|ALBUM_AUTH_RATE|ALBUM_AUTH_COMMENT|ALBUM_AUTH_EDIT|ALBUM_AUTH_DELETE;
$auth_data = album_permissions($album_user_id, $cat_id, $check_permissions, $thispic);
//--- Album Category Hierarchy : end


if ($auth_data['view'] == 0)
{
	if (!$userdata['session_logged_in'])
	{
		redirect(append_sid("login.$phpEx?redirect=album_showpage.$phpEx&amp;pic_id=$pic_id"));
		exit;
	}
	else
	{
		message_die(GENERAL_ERROR, $lang['Not_Authorised']);
	}
}
// ------------------------------------
//RATING:  Additional Check: if this user already rated
// ------------------------------------

$rate_identity = $userdata['session_logged_in']
	? 'rate_user_id = ' . intval($userdata['user_id'])
	: 'rate_user_id = ' . ANONYMOUS . " AND rate_user_ip = '" . $db->sql_escape($userdata['session_ip']) . "'";
$sql = "SELECT rate_pic_id
		FROM ". ALBUM_RATE_TABLE ."
		WHERE rate_pic_id = " . intval($pic_id) . "
			AND $rate_identity
		LIMIT 1";

if( !$result = $db->sql_query($sql) )
{
	message_die(GENERAL_ERROR, 'Could not query rating information', '', __LINE__, __FILE__, $sql);
}

$already_rated = ($db->sql_numrows($result) > 0);
$db->sql_freeresult($result);




/*
+----------------------------------------------------------
| Main work here...
+----------------------------------------------------------
*/
//--- Album Category Hierarchy : begin
//--- version : 1.3.0
album_read_tree($album_user_id);
//--- version : 1.2.0
// Update the navigation tree
$album_nav_cat_desc = album_make_nav_tree($cat_id, "album_cat.$phpEx", "nav" , $album_user_id);
if ($album_nav_cat_desc != '') $album_nav_cat_desc = ALBUM_NAV_ARROW . $album_nav_cat_desc;
//--- Album Category Hierarchy : end

if( !isset($_POST['comment']) && !isset($_POST['rate']) )
{

	/* ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
               Comments Screen
	   ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ */

	// ------------------------------------
	// Get the comments thread
	// Beware: when this script was called with comment_id (without start)
	// ------------------------------------

	if( !isset($comment_id) )
	{
		if( isset($_GET['start']) )
		{
			$start = intval($_GET['start']);
		}
		else if( isset($_POST['start']) )
		{
			$start = intval($_POST['start']);
		}
		else
		{
			$start = 0;
		}
	}
	else
	{
		// We must do a query to co-ordinate this comment
		$sql = "SELECT COUNT(comment_id) AS count
				FROM ". ALBUM_COMMENT_TABLE ."
				WHERE comment_pic_id = $pic_id
					AND comment_id < $comment_id";

		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, 'Could not obtain comments information from the database', '', __LINE__, __FILE__, $sql);
		}

		$row = $db->sql_fetchrow($result);

		if( !empty($row) )
		{
			$start = floor( $row['count'] / $comments_per_page ) * $comments_per_page;
		}
		else
		{
			$start = 0;
		}
	}

	if( isset($_GET['sort_order']) )
	{
		switch ($_GET['sort_order'])
		{
			case 'ASC':
				$sort_order = 'ASC';
				break;
			default:
				$sort_order = 'DESC';
		}
	}
	else if( isset($_POST['sort_order']) )
	{
		switch ($_POST['sort_order'])
		{
			case 'ASC':
				$sort_order = 'ASC';
				break;
			default:
				$sort_order = 'DESC';
		}
	}
	else
	{
		$sort_order = 'ASC';
	}

	if ($total_comments > 0)
	{
		$template->assign_block_vars('coment_switcharo_top', array());

		$limit_sql = ($start == 0) ? $comments_per_page : $start .','. $comments_per_page;

		$sql = "SELECT c.*, u.user_id, u.username, u.user_regdate, u.user_posts, u.user_allowavatar, u.user_rank, u.user_avatar, u.user_avatar_type, u.user_email, u.user_icq, u.user_website, u.user_from, u.user_aim, u.user_yim, u.user_msnm
				FROM ". ALBUM_COMMENT_TABLE ." AS c
					LEFT JOIN ". USERS_TABLE ." AS u ON c.comment_user_id = u.user_id
				WHERE c.comment_pic_id = '$pic_id'
				ORDER BY c.comment_id $sort_order
				LIMIT $limit_sql";

		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, 'Could not obtain comments information from the database', '', __LINE__, __FILE__, $sql);
		}

		$commentrow = array();

		while( $row = $db->sql_fetchrow($result) )
		{
			$commentrow[] = $row;
		}

		for ($i = 0; $i < count($commentrow); $i++)
		{
			$commentrow[$i]['user_icq'] = phpbb_profile_contact($commentrow[$i]['user_icq']);
			$commentrow[$i]['user_aim'] = phpbb_profile_contact($commentrow[$i]['user_aim']);
			$commentrow[$i]['user_yim'] = phpbb_profile_contact($commentrow[$i]['user_yim']);
			$comment_website_url = phpbb_profile_http_url($commentrow[$i]['user_website']);
			if( ($commentrow[$i]['user_id'] == ALBUM_GUEST) or ($commentrow[$i]['username'] == '') )
			{
				$poster = ($commentrow[$i]['comment_username'] == '') ? $lang['Guest'] : album_html_text($commentrow[$i]['comment_username']);
			}
			else
			{
				$poster = '<a href="'. append_sid("profile.$phpEx?mode=viewprofile&amp;". POST_USERS_URL .'='. (int) $commentrow[$i]['user_id']) .'">'. album_html_text($commentrow[$i]['username']) .'</a>';
			}

			if ($commentrow[$i]['comment_edit_count'] > 0)
			{
				$sql = "SELECT c.comment_id, c.comment_edit_user_id, u.user_id, u.username
						FROM ". ALBUM_COMMENT_TABLE ." AS c
							LEFT JOIN ". USERS_TABLE ." AS u ON c.comment_edit_user_id = u.user_id
						WHERE c.comment_id = '".$commentrow[$i]['comment_id']."'
						LIMIT 1";

				if( !$result = $db->sql_query($sql) )
				{
					message_die(GENERAL_ERROR, 'Could not obtain last edit information from the database', '', __LINE__, __FILE__, $sql);
				}

				$lastedit_row = $db->sql_fetchrow($result);

				$edit_info = ($commentrow[$i]['comment_edit_count'] == 1) ? $lang['Edited_time_total'] : $lang['Edited_times_total'];

				$edit_info = '<br /><br />&raquo;&nbsp;'. sprintf($edit_info, album_html_text($lastedit_row['username']), create_date($board_config['default_dateformat'], $commentrow[$i]['comment_edit_time'], $board_config['board_timezone']), (int) $commentrow[$i]['comment_edit_count']) .'<br />';
			}
			else
			{
				$edit_info = '';
			}

			// Smilies
			$commentrow[$i]['comment_text'] = smilies_pass(album_html_text($commentrow[$i]['comment_text']));
			$commentrow[$i]['comment_text'] = make_clickable($commentrow[$i]['comment_text']);
			$commentrow[$i]['comment_text'] = nl2br($commentrow[$i]['comment_text']);

			//email, profile, pm links
			$comment_user_id = (int) $commentrow[$i]['user_id'];
			$email_form_url = ( $board_config['board_email_form'] ) ? append_sid("profile.$phpEx?mode=email&amp;" . POST_USERS_URL . '=' . $comment_user_id) : '';
			$email_uri = phpbb_profile_email_uri($commentrow[$i]['user_email'], $email_form_url);
			$profile_url = append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=" . $comment_user_id);
			$pm_url = append_sid("privmsg.$phpEx?mode=post&amp;" . POST_USERS_URL . "=" . $comment_user_id);

			//avatar
			$poster_avatar = '';
			if ( $commentrow[$i]['user_avatar_type'] && $commentrow[$i]['user_id'] != ANONYMOUS && $commentrow[$i]['user_allowavatar'] )
			{
				$poster_avatar = phpbb_avatar_image($commentrow[$i]['user_avatar'], $commentrow[$i]['user_avatar_type']);
			}

			//rank & rank image
			$sql = "SELECT *
				FROM " . RANKS_TABLE . "
				ORDER BY rank_special, rank_min";
			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, "Could not obtain ranks information.", '', __LINE__, __FILE__, $sql);
			}

			$ranksrow = array();
			while ( $row = $db->sql_fetchrow($result) )
			{
				$ranksrow[] = $row;
			}
			$db->sql_freeresult($result);

			$poster_rank = '';
			$rank_image = '';
			if ($commentrow[$i]['user_id'] == ANONYMOUS)
			{
				$poster_rank = $lang['Guest'];
			}
			else if ( $commentrow[$i]['user_rank'] )
			{
				for($j = 0; $j < count($ranksrow); $j++)
				{
					if ( $commentrow[$i]['user_rank'] == $ranksrow[$j]['rank_id'] && $ranksrow[$j]['rank_special'] )
					{
						$poster_rank = phpbb_profile_text($ranksrow[$j]['rank_title']);
						$rank_image_name = phpbb_profile_image_name($ranksrow[$j]['rank_image']);
						$rank_image = ( $rank_image_name ) ? '<img src="' . $images['rank_path'] . $rank_image_name . '" alt="' . $poster_rank . '" title="' . $poster_rank . '" border="0" /><br />' : '';
					}
				}
			}
			else
			{
				for($j = 0; $j < count($ranksrow); $j++)
				{
					if ( $commentrow[$i]['user_posts'] >= $ranksrow[$j]['rank_min'] && !$ranksrow[$j]['rank_special'] )
					{
						$poster_rank = phpbb_profile_text($ranksrow[$j]['rank_title']);
						$rank_image_name = phpbb_profile_image_name($ranksrow[$j]['rank_image']);
						$rank_image = ( $rank_image_name ) ? '<img src="' . $images['rank_path'] . $rank_image_name . '" alt="' . $poster_rank . '" title="' . $poster_rank . '" border="0" /><br />' : '';
					}
				}
			}

			//
			// Handle anon users posting with usernames
			//
			if ( $commentrow[$i]['user_id'] == ANONYMOUS && $commentrow[$i]['comment_username'] != '' )
			{
				$poster = album_html_text($commentrow[$i]['comment_username']);
				$poster_rank = $lang['Guest'];
			}

			$comment_id = (int) $commentrow[$i]['comment_id'];
			$mini_post_url = append_sid("album_showpage.$phpEx?pic_id=" . (int) $pic_id) . '#comment-' . $comment_id;

			$template->assign_block_vars('commentrow', array(
				'ID' => $comment_id,
				'POSTER_NAME' => $poster,
				'TIME' => create_date($board_config['default_dateformat'], $commentrow[$i]['comment_time'], $board_config['board_timezone']),
				'IP' => ($userdata['user_level'] == ADMIN) ? htmlspecialchars(decode_ip($commentrow[$i]['comment_user_ip']), ENT_QUOTES, 'UTF-8') . '<br />' : '',
				'MINI_POST_IMG' => $images['icon_minipost'],
				'L_MINI_POST_ALT' => $lang['Post'],
				'U_MINI_POST' => $mini_post_url,

				//users mesangers, website, email
				'PROFILE_IMG' => ( $commentrow[$i]['user_id'] != ANONYMOUS ) ? '<a href="' . $profile_url . '"><img src="' . $images['icon_profile'] . '" alt="' . $lang['Read_profile'] . '" title="' . $lang['Read_profile'] . '" border="0" /></a>' : '',
				'PM_IMG' => ( $commentrow[$i]['user_id'] != ANONYMOUS ) ? '<a href="' . $pm_url . '"><img src="' . $images['icon_pm'] . '" alt="' . $lang['Send_private_message'] . '" title="' . $lang['Send_private_message'] . '" border="0" /></a>' : '',
				'AIM_IMG' => '',
				'YIM_IMG' => '',
				'MSNM_IMG' => '',
				'ICQ_IMG' => '',
				'EMAIL_IMG' => ( $comment_user_id != ANONYMOUS && $email_uri !== '' ) ? '<a href="' . $email_uri . '"><img src="' . $images['icon_email'] . '" alt="' . $lang['Send_email'] . '" title="' . $lang['Send_email'] . '" border="0" /></a>' : '',
				'WWW_IMG' => ( $commentrow[$i]['user_id'] != ANONYMOUS && $comment_website_url ) ? '<a href="' . $comment_website_url . '" target="_userwww" rel="noopener noreferrer"><img src="' . $images['icon_www'] . '" alt="' . $lang['Visit_website'] . '" title="' . $lang['Visit_website'] . '" border="0" /></a>' : '',

				'POSTER_AVATAR' => $poster_avatar,
				'POSTER_RANK' => $poster_rank,
				'POSTER_RANK_IMAGE' => $rank_image,
				'POSTER_JOINED' => ( $commentrow[$i]['user_id'] != ANONYMOUS ) ? $lang['Joined'] . ': ' . create_date($lang['DATE_FORMAT'], $commentrow[$i]['user_regdate'], $board_config['board_timezone']) : '',
				'POSTER_POSTS' => ( $commentrow[$i]['user_id'] != ANONYMOUS ) ? $lang['Posts'] . ': ' . $commentrow[$i]['user_posts'] : '',
				'POSTER_FROM' => ( $commentrow[$i]['user_from'] && $commentrow[$i]['user_id'] != ANONYMOUS ) ? $lang['Location'] . ': ' . phpbb_profile_text($commentrow[$i]['user_from']) : '',


				'TEXT' => $commentrow[$i]['comment_text'],
				'EDIT_INFO' => $edit_info,

				'EDIT' => ( ( $auth_data['edit'] and ($commentrow[$i]['comment_user_id'] == $userdata['user_id']) ) or ($auth_data['moderator'] and ($thispic['cat_edit_level'] != ALBUM_ADMIN) ) or ($userdata['user_level'] == ADMIN) ) ? '<a href="'. append_sid("album_comment_edit.$phpEx?comment_id=". $commentrow[$i]['comment_id']) .'">'. $lang['Edit_pic'] .'</a>' : '',

				'DELETE' => ( ( $auth_data['delete'] and ($commentrow[$i]['comment_user_id'] == $userdata['user_id']) ) or ($auth_data['moderator'] and ($thispic['cat_delete_level'] != ALBUM_ADMIN) ) or ($userdata['user_level'] == ADMIN) ) ? '<a href="'. append_sid("album_comment_delete.$phpEx?comment_id=". $commentrow[$i]['comment_id']) .'">'. $lang['Delete_pic'] .'</a>' : ''
				)
			);
		}

		$template->assign_block_vars('switch_comment', array());

		$template->assign_vars(array(
			'PAGINATION' => generate_pagination(append_sid("album_showpage.$phpEx?pic_id=$pic_id&amp;sort_order=$sort_order"), $total_comments, $comments_per_page, $start),
			'PAGE_NUMBER' => sprintf($lang['Page_of'], ( floor( $start / $comments_per_page ) + 1 ), ceil( $total_comments / $comments_per_page ))
			)
		);
		$template->assign_block_vars('coment_switcharo_bottom', array());
	}

	//
	// Start output of page
	//
	$page_title = $lang['Album'];
	include($phpbb_root_path . 'includes/page_header.'.$phpEx);

	$template->set_filenames(array(
		'body' => 'album_showpage_body.tpl')
	);

	if( ($thispic['pic_user_id'] == ALBUM_GUEST) or ($thispic['username'] == '') )
	{
		$poster = ($thispic['pic_username'] == '') ? $lang['Guest'] : album_html_text($thispic['pic_username']);
	}
	else
	{
		$poster = '<a href="'. append_sid("profile.$phpEx?mode=viewprofile&amp;". POST_USERS_URL .'='. (int) $thispic['user_id']) .'">'. album_html_text($thispic['username']) .'</a>';
	}

	//---------------------------------
	// Comment Posting Form
	//---------------------------------

	$image_rating = ImageRating($thispic['rating']);

	if ($album_config['comment'] == 1 && !$auth_data['comment'] == 0)
	{
		$template->assign_block_vars('switch_comment_post', array());

		if( !$userdata['session_logged_in'] )
		{
			$template->assign_block_vars('switch_comment_post.logout', array());
		}

        //begin shows smilies
        $max_smilies = 20;

         $sql = 'SELECT emoticon, code, smile_url
                        FROM ' . SMILIES_TABLE . '
                        GROUP BY smile_url
                        ORDER BY smilies_id LIMIT ' . $max_smilies;

        if (!$result = $db->sql_query($sql))
        {
                message_die(GENERAL_ERROR, "Couldn't retrieve smilies list", '', __LINE__, __FILE__, $sql);
        }
        $smilies_count = $db->sql_numrows($result);
        $smilies_data = $db->sql_fetchrowset($result);

		for ($i = 1; $i < $smilies_count+1; $i++)
		{
			$smiley_name = phpbb_profile_image_name($smilies_data[$i - 1]['smile_url']);
			$smiley_path = phpbb_profile_asset_path($board_config['smilies_path']);
			if ($smiley_name === '' || $smiley_path === '')
			{
				continue;
			}
			$template->assign_block_vars('switch_comment_post.smilies', array(
				'CODE' => album_html_text($smilies_data[$i - 1]['code']),
				'URL' => $smiley_path . '/' . rawurlencode($smiley_name),
				'DESC' => album_html_text($smilies_data[$i - 1]['emoticon'])
			));

			if ( ($i % 5) === 0 )
			{
				$template->assign_block_vars('switch_comment_post.smilies.new_col', array());
			}
		}
        }

    // --------------------------------
    // Rate Scale
    // --------------------------------

    if ($album_config['rate'] && !$auth_data['rate'] == 0)
    {
		$template->assign_block_vars('rate_switch', array());

		if ($album_config['comment'] == 0 || $auth_data['comment'] == 0)
		{
		  $template->assign_block_vars('rate_switch_only', array());
		}
		else
		{
		  $template->assign_block_vars('switch_comment_post.rate_comment', array());
		}

		if (!$already_rated && ($album_config['comment'] == 1 && !$auth_data['comment'] == 0))
		{
			for ($i = 0; $i < $album_config['rate_scale']; $i++)
			{
				$template->assign_block_vars('switch_comment_post.rate_comment.rate_row', array(
					'POINT' => ($i + 1)
				));
			}
		}
		elseif (!$already_rated && ($album_config['comment'] == 0 || $auth_data['comment'] == 0))
		{
			for ($i = 0; $i < $album_config['rate_scale']; $i++)
			{
				$template->assign_block_vars('rate_switch_only.rate_row', array(
					'POINT' => ($i + 1)
				));
			}
		}
  }
  else if ($album_config['rate'])
  {
	$template->assign_block_vars('rate_switch', array());
  }

	$template->assign_vars(array(
		'CAT_TITLE' => album_html_text($thispic['cat_title']),
//--- Album Category Hierarchy : begin
//--- version : 1.1.0
		'U_VIEW_CAT' => append_sid(album_append_uid("album_cat.$phpEx?cat_id=$cat_id")),
//--- version : 1.2.0
        'ALBUM_NAVIGATION_ARROW' => ALBUM_NAV_ARROW,
        'NAV_CAT_DESC' => $album_nav_cat_desc,
//--- Album Category Hierarchy : end

		'U_PIC' => ( $picm ) ? append_sid("album_pic.$phpEx?pic_id=$pic_id") : append_sid("album_picm.$phpEx?pic_id=$pic_id"),
		'U_PIC_L1' => ( $picm ) ? '' : '<a href="album_showpage.php?full=&pic_id=' . $pic_id . '">',
		'U_PIC_L2' => ( $picm ) ? '' : '</a>',
		'U_PIC_CLICK' => ( $picm ) ? '' : $lang['Click_larger'],

		'U_NEXT' => append_sid("album_showpage.$phpEx?pic_id=$pic_id&amp;mode=next"),
		'U_PREVIOUS' => append_sid("album_showpage.$phpEx?pic_id=$pic_id&amp;mode=previous"),

		'PIC_RATING' => $image_rating,

		'PIC_TITLE' => album_html_text($thispic['pic_title']),
		'PIC_DESC' => nl2br(album_html_text($thispic['pic_desc'])),

		'POSTER' => $poster,

		'PIC_TIME' => create_date($board_config['default_dateformat'], $thispic['pic_time'], $board_config['board_timezone']),
		'PIC_VIEW' => $thispic['pic_view_count'],
		'PIC_COMMENTS' => $total_comments,

		'TARGET_BLANK' => ($album_config['fullpic_popup']) ? 'target="_blank" rel="noopener noreferrer"' : '',

		'L_PIC_TITLE' => $lang['Pic_Title'],
		'L_PIC_DESC' => $lang['Pic_Desc'],
		'L_POSTER' => $lang['Poster'],
		'L_POSTED' => $lang['Posted'],
		'L_VIEW' => $lang['View'],
		'L_COMMENTS' => $lang['Comments'],
		'L_RATING' => $lang['Rating'],

		'L_POST_YOUR_COMMENT' => $lang['Post_your_comment'],
		'L_MESSAGE' => $lang['Message'],
		'L_USERNAME' => $lang['Username'],
		'L_COMMENT_NO_TEXT' => $lang['Comment_no_text'],
		'L_COMMENT_TOO_LONG' => $lang['Comment_too_long'],
		'L_MAX_LENGTH' => $lang['Max_length'],
		'S_MAX_LENGTH' => $album_config['desc_length'],

		'L_ORDER' => $lang['Order'],
		'L_SORT' => $lang['Sort'],
		'L_ASC' => $lang['Sort_Ascending'],
		'L_DESC' => $lang['Sort_Descending'],

		'SORT_ASC' => ($sort_order == 'ASC') ? 'selected="selected"' : '',
		'SORT_DESC' => ($sort_order == 'DESC') ? 'selected="selected"' : '',

		'ALBUM_PREVIOUS_IMG' => $images['icon_album_prev'],
		'ALBUM_NEXT_IMG' => $images['icon_album_next'],

		'L_SUBMIT' => $lang['Submit'],

		'S_ALBUM_ACTION' => append_sid("album_showpage.$phpEx?pic_id=$pic_id"),
		'S_FORM_TOKEN' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
		'L_MORE_SMILIES' => $lang['More_emoticons'],
		//rating
		'S_RATE_MSG' => (  !$userdata['session_logged_in'] && $auth_data['rate'] == 0 ) ? 'Login to vote!' : ( ($already_rated) ? $lang['Already_rated'] : $lang['Rating'] ),
		'L_CURRENT_RATING' => $lang['Current_Rating'],
		'L_PLEASE_RATE_IT' => $lang['Please_Rate_It']
	));

	//
	// Generate the page
	//
	$template->pparse('body');

	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
}
else
{
	/* ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
              Comment Submited
	   ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ */

	// ------------------------------------
	// Check the permissions: COMMENT
	// ------------------------------------

	if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST' || !isset($_POST['sid']) || !is_scalar($_POST['sid']) || !hash_equals((string) $userdata['session_id'], (string) $_POST['sid']))
	{
		message_die(GENERAL_ERROR, $lang['Not_Authorised']);
	}

	$comment_input = (isset($_POST['comment']) && is_scalar($_POST['comment'])) ? (string) $_POST['comment'] : '';
	$comment_username_input = (isset($_POST['comment_username']) && is_scalar($_POST['comment_username'])) ? (string) $_POST['comment_username'] : '';
	$rate_point = (isset($_POST['rate']) && is_scalar($_POST['rate'])) ? intval($_POST['rate']) : -1;
	$has_comment = (trim($comment_input) !== '');
	$has_rating = ($rate_point !== -1);

	if ((!$has_comment && !$has_rating) || ($has_comment && (empty($album_config['comment']) || empty($auth_data['comment']))) || ($has_rating && (empty($album_config['rate']) || empty($auth_data['rate']))))
	{
		if (!$userdata['session_logged_in'])
		{
			redirect(append_sid("login.$phpEx?redirect=album_showpage.$phpEx&amp;pic_id=$pic_id"));
		}
		else
		{
			message_die(GENERAL_ERROR, $lang['Not_Authorised']);
		}
	}

	$comment_max_length = max(1, isset($album_config['desc_length']) ? intval($album_config['desc_length']) : 255);
	$comment_raw = substr(trim(stripslashes($comment_input)), 0, $comment_max_length);
	$comment_text = htmlspecialchars($comment_raw, ENT_QUOTES, 'UTF-8');
	$comment_username_raw = (!$userdata['session_logged_in']) ? substr(trim(stripslashes($comment_username_input)), 0, 32) : trim((string) $userdata['username']);
	$comment_username = htmlspecialchars($comment_username_raw, ENT_QUOTES, 'UTF-8');




	// --------------------------------
	// Check Pic Locked
	// --------------------------------

	if( ($thispic['pic_lock'] == 1) and (!$auth_data['moderator']) )
	{
		message_die(GENERAL_ERROR, $lang['Pic_Locked']);
	}
	if ($has_rating && (($rate_point <= 0) || ($rate_point > $album_config['rate_scale']) || $already_rated))
	{
		message_die(GENERAL_ERROR, $already_rated ? $lang['Already_rated'] : $lang['Not_Authorised']);
	}


	// --------------------------------
	// Check username for guest posting
	// --------------------------------

	if (!$userdata['session_logged_in'])
	{
		if ($comment_username_raw != '')
		{
			$result = validate_username($comment_username_raw);
			if ( $result['error'] )
			{
				message_die(GENERAL_MESSAGE, $result['error_msg']);
			}
		}
	}


	// --------------------------------
	// Prepare variables
	// --------------------------------

	$comment_time = time();
	$comment_user_id = intval($userdata['user_id']);
	$comment_user_ip = $db->sql_escape($userdata['session_ip']);
	$comment_username_sql = $db->sql_escape($comment_username);
	$comment_text_sql = $db->sql_escape($comment_text);


	// --------------------------------
	// Insert into DB
	// --------------------------------

	if ($comment_text != '')//if user only rated, but didnt enter a comment ..... only update rating
	{
		$sql = "INSERT INTO ". ALBUM_COMMENT_TABLE ." (comment_pic_id, comment_cat_id, comment_user_id, comment_username, comment_user_ip, comment_time, comment_text)
				VALUES (" . intval($pic_id) . ", " . intval($cat_id) . ", $comment_user_id, '$comment_username_sql', '$comment_user_ip', $comment_time, '$comment_text_sql')";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, 'Could not insert new entry', '', __LINE__, __FILE__, $sql);
		}
		$comment_id = $db->sql_nextid();
	}

	//rating
	if ($has_rating)
	{
		$rate_user_id = intval($userdata['user_id']);
		$rate_user_ip = $db->sql_escape($userdata['session_ip']);

		$sql = "INSERT INTO ". ALBUM_RATE_TABLE ." (rate_pic_id, rate_user_id, rate_user_ip, rate_point)
				SELECT " . intval($pic_id) . ", $rate_user_id, '$rate_user_ip', " . intval($rate_point) . "
				WHERE NOT EXISTS (SELECT 1 FROM " . ALBUM_RATE_TABLE . " WHERE rate_pic_id = " . intval($pic_id) . " AND $rate_identity)";

		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, 'Could not insert new rating', '', __LINE__, __FILE__, $sql);
		}
		if (!$db->sql_affectedrows())
		{
			message_die(GENERAL_MESSAGE, $lang['Already_rated']);
		}
	}

	// --------------------------------
	// Complete... now send a message to user
	// --------------------------------

	$template->assign_vars(array(
		'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid("album_showpage.$phpEx?pic_id=$pic_id") . '">')
	);

	$message = $lang['Stored'] . "<br /><br />" . sprintf($lang['Click_view_message'], "<a href=\"" . append_sid("album_showpage.$phpEx?pic_id=$pic_id ") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_album_index'], "<a href=\"" . append_sid(album_append_uid("album.$phpEx")) . "\">", "</a>");

	message_die(GENERAL_MESSAGE, $message);
}


// +-------------------------------------------------------------+
// |  Powered by Photo Album 2.x.x (c) 2002-2003 Smartor         |
// |  with Volodymyr (CLowN) Skoryk's Service Pack 1 © 2003-2004 |
// +-------------------------------------------------------------+
?>
