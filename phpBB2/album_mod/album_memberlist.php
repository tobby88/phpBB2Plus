<?php
/***************************************************************************
 *                          album_memberlist.php
 *                          ------------------------------------------------
 *     begin                : Friday, August 14, 2004
 *     copyright            : (C) 2004 IdleVoid
 *     email                : idlevoid@slater.dk
 *     file version         : 1.0.1
 *     release              : 1.3.0
 ****************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

if ( !defined('IN_PHPBB') )
{
    die('Hacking attempt');
}

// ------------------------------------------------------------------------
// $album_user_id, $cat_id and $moderators_list are as default set
// in album.php file in the main folder.
// ------------------------------------------------------------------------

// ------------------------------------------------------------------------
// Get the name of this user
// ------------------------------------------------------------------------
$username = album_find_user_name($album_user_id);
if ($username === false)
{
	message_die(GENERAL_MESSAGE, $lang['No_user_id_specified']);
}
$username_html = album_html_text($username);

// ------------------------------------
// Build the thumbnail page
// ------------------------------------

$album_view_type = ALBUM_LISTTYPE_PICTURES;
if (isset ($_POST['type']))
{
	$album_view_type = strtolower(phpbb_request_scalar($_POST, 'type'));
}
elseif (isset ($_GET['type']))
{
	$album_view_type = strtolower(phpbb_request_scalar($_GET, 'type'));
}

if( isset($_GET['start']) )
{
	$start = max(0, min(1000000, intval(phpbb_request_scalar($_GET, 'start', 0))));
}
else if( isset($_POST['start']) )
{
	$start = max(0, min(1000000, intval(phpbb_request_scalar($_POST, 'start', 0))));
}
else
{
	$start = 0;
}

// Keep the historical default sort for member rating/comment views.
if (!in_array($album_view_type, array(ALBUM_LISTTYPE_RATINGS, ALBUM_LISTTYPE_COMMENTS), true)) { $album_view_type = ALBUM_LISTTYPE_PICTURES; }
if (!isset($_GET['sort_method']) && !isset($_POST['sort_method']))
{
	if ($album_view_type === ALBUM_LISTTYPE_RATINGS) { $sort_method = 'rating'; $sort_order = 'ASC'; }
	elseif ($album_view_type === ALBUM_LISTTYPE_COMMENTS) { $sort_method = 'comments'; $sort_order = 'ASC'; }
}
try
{
	$picture_listing = album_picture_page($db, null, empty($allowed_cat) ? array() : $allowed_cat, $sort_method, $sort_order, $start, (int)$album_user_id, $album_view_type);
}
catch (Exception $error) { message_die(GENERAL_ERROR, 'Could not query memberlist information'); }
catch (Error $error) { message_die(GENERAL_ERROR, 'Could not query memberlist information'); }
$cols_per_page = $picture_listing['columns']; $pics_per_page = min(100, $picture_listing['size']);
$total_pics = $picture_listing['total']; $start = $picture_listing['start']; $picrow = $picture_listing['rows'];
$sort_method = $picture_listing['sort']; $sort_order = $picture_listing['order'];
// Mutating links use the actual current actor, not an earlier role snapshot.
$picture_viewer = $picture_listing['viewer'];

// ------------------------------------
// Build up
// ------------------------------------

$album_view_mode_param = (!empty($album_view_mode)) ? '&mode=' . $album_view_mode : '';
$album_view_type_param = (!empty($album_view_type)) ? '&type=' . $album_view_type : '';

if ($total_pics > 0 && !empty($allowed_cat))
{
	// --------------------------------
	// Thumbnails table
	// --------------------------------

	if 	(defined('ALBUM_SP_CONFIG_TABLE'))
	{
		$album_show_pic_url = 'album_showpage.'.$phpEx;
        $album_rate_pic_url = $album_show_pic_url;
        $album_comment_pic_url = $album_show_pic_url;
	}
	else
    {
		$album_show_pic_url = 'album_page.'.$phpEx;
		$album_rate_pic_url = 'album_rate.'.$phpEx;
		$album_comment_pic_url = 'album_comment.'.$phpEx;
	}

	for ($i = 0; $i < count($picrow); $i += $cols_per_page)
	{
		$template->assign_block_vars('picrow', array());

		for ($j = $i; $j < ($i + $cols_per_page); $j++)
		{
			if( $j >= count($picrow) )
			{
				break;
			}

			$template->assign_block_vars('picrow.piccol', array(
				'U_PIC' => ($album_config['fullpic_popup']) ? append_sid("album_pic.$phpEx?pic_id=". $picrow[$j]['pic_id']) : append_sid("$album_show_pic_url?pic_id=". $picrow[$j]['pic_id']),
				'THUMBNAIL' => append_sid("album_thumbnail.$phpEx?pic_id=". $picrow[$j]['pic_id']),
				'DESC' => album_html_text($picrow[$j]['pic_desc'])
				)
			);

			if 	(defined('ALBUM_SP_CONFIG_TABLE'))
			{
				$image_rating = ImageRating($picrow[$j]['rating']);
                $image_rating_link_style = ($image_rating == $lang['Not_rated']) ? '' : 'style="text-decoration: none;"';
			}
			else
			{
				$image_rating = (empty($picrow[$j]['rating'])) ? $lang['Not_rated'] : $picrow[$j]['rating'];
                $image_rating_link_style = '';
			}

			$image_comment = ($picrow[$j]['comments'] == 0) ? $lang['Not_commented'] : $picrow[$j]['comments'];

			// is a personal category that the picture belongs to AND
			// is it the main category in the personal gallery ?
			if ($picrow[$j]['cat_user_id'] != 0 && $picrow[$j]['cat_id'] == album_get_personal_root_id($picrow[$j]['cat_user_id']))
			{
				$album_page_url = "album.$phpEx";
			}
			else
			{
				$album_page_url = "album_cat.$phpEx";
			}

			$image_cat_url = append_sid("$album_page_url?cat_id=". $picrow[$j]['cat_id'] . '&user_id=' . $picrow[$j]['cat_user_id']);

			$template->assign_block_vars('picrow.pic_detail', array(
				'TITLE' => album_html_text($picrow[$j]['pic_title']),

				'CATEGORY' => album_html_text($picrow[$j]['cat_title']),
				'U_PIC_CAT' => $image_cat_url,

				'TIME' => create_date($board_config['default_dateformat'], $picrow[$j]['pic_time'], $board_config['board_timezone']),

				'VIEW' => $picrow[$j]['pic_view_count'],

				'RATING' => ($album_config['rate'] == 1) ?( $lang['Rating'] . ' : <a href="'. append_sid(album_append_uid($album_rate_pic_url .'?pic_id='. $picrow[$j]['pic_id'])) . '" ' . $image_rating_link_style .'>' . $image_rating . '</a><br />') : '',

				'COMMENTS' => ($album_config['comment'] == 1) ? ($lang['Comments'] . ' : <a href="'. append_sid(album_append_uid($album_comment_pic_url .'?pic_id='. $picrow[$j]['pic_id'])) . '">' . $image_comment . '</a><br />') : '',

				'DELETE' => ( !empty($picture_listing['rights'][(int)$picrow[$j]['pic_cat_id']]['delete']) && (int)$picture_viewer['user_id'] > 0 && (int)$picture_viewer['user_id'] === (int)$picrow[$j]['pic_user_id'] || $picture_viewer['user_level'] == ADMIN ) ? '<a href="'. append_sid("album_delete.$phpEx?pic_id=". $picrow[$j]['pic_id']) . '">' . $lang['Delete_pic'] . '</a>' : '',

				'IP' => ($picture_viewer['user_level'] == ADMIN) ? $lang['IP_Address'] . ': ' . htmlspecialchars(decode_ip($picrow[$j]['pic_user_ip']), ENT_QUOTES, 'UTF-8') . '<br />' : ''
				)
			);
		}
	}

	// --------------------------------
	// Pagination
	// --------------------------------

	$template->assign_vars(array(
		'PAGINATION' => generate_pagination(append_sid("album.$phpEx?user_id=$album_user_id&amp;sort_method=$sort_method&amp;sort_order=$sort_order$album_view_mode_param$album_view_type_param"), $total_pics, $pics_per_page, $start),
		'PAGE_NUMBER' => sprintf($lang['Page_of'], ( floor( $start / $pics_per_page ) + 1 ), ceil( $total_pics / $pics_per_page ))
		)
	);
}
else
{
	$template->assign_block_vars('no_pics', array());
}


/*
+----------------------------------------------------------
| Main page...
+----------------------------------------------------------
*/

// ------------------------------------
// additional sorting options
// ------------------------------------

$sort_rating_option = '';
$sort_comments_option = '';
if( $album_config['rate'] == 1 )
{
	$sort_rating_option = '<option value="rating" ';
	$sort_rating_option .= ($sort_method == 'rating') ? 'selected="selected"' : '';
	$sort_rating_option .= '>' . $lang['Rating'] .'</option>';
}
if( $album_config['comment'] == 1 )
{
	$sort_comments_option = '<option value="comments" ';
	$sort_comments_option .= ($sort_method == 'comments') ? 'selected="selected"' : '';
	$sort_comments_option .= '>' . $lang['Comments'] .'</option>';

	$sort_new_comment_option = '<option value="new_comment" ';
	$sort_new_comment_option .= ($sort_method == 'new_comment') ? 'selected="selected"' : '';
	$sort_new_comment_option .= '>' . $lang['New_Comment'] .'</option>';
}

//
// Start output of page
//
$page_title = $lang['Album'];
include($phpbb_root_path . 'includes/page_header.'.$phpEx);

$template->set_filenames(array(
	'body' => 'album_memberlist_body.tpl')
);

switch (strtolower($album_view_type))
{
	case 'comment':
		$template->assign_block_vars('switch_show_all_pics', array());
		$template->assign_block_vars('switch_show_all_ratings', array());
		$list_title = sprintf($lang['Comment_List_Of_User'], $username_html);
		break;
	case 'rating':
		$template->assign_block_vars('switch_show_all_pics', array());
		$template->assign_block_vars('switch_show_all_comments', array());
		$list_title = sprintf($lang['Rating_List_Of_User'], $username_html);
		break;
	default:
		$template->assign_block_vars('switch_show_all_ratings', array());
		$template->assign_block_vars('switch_show_all_comments', array());
		$list_title = sprintf($lang['Picture_List_Of_User'], $username_html);
}

if (defined('ALBUM_SP_CONFIG_TABLE'))
{
	$template->assign_block_vars('switch_show_album_search', array());
}

$template->assign_vars(array(
	'TARGET_BLANK' => ($album_config['fullpic_popup']) ? 'target="_blank" rel="noopener noreferrer"' : '',

	'S_COLS' => $cols_per_page,
	'S_COL_WIDTH' => (100/$cols_per_page) . '%',

	'L_NO_PICTURES_BY_USER' => $lang['No_Pics'],
	'U_MEMBERLIST_GALLERY' => append_sid("album.$phpEx?user_id=$album_user_id&mode=$album_view_mode&type=$album_view_type"),
	'L_MEMBERLIST_GALLERY_EXPLAIN' => $lang['Member_Picture_List_Explain'],

	'U_SHOW_ALL_PICS' => append_sid("album.$phpEx?user_id=$album_user_id$album_view_mode_param&type=pic"),
	'L_SHOW_ALL_PICS' => sprintf($lang['Show_All_Pictures_Of_user'], $username_html),
	'SHOW_ALL_PICS_IMG' => $images['show_all_pics'],

	'U_SHOW_ALL_RATINGS' => append_sid("album.$phpEx?user_id=$album_user_id$album_view_mode_param&type=rating"),
	'L_SHOW_ALL_RATINGS' => sprintf($lang['Show_All_Ratings_Of_user'], $username_html),
	'SHOW_ALL_RATINGS_IMG' => $images['show_all_ratings'],

	'U_SHOW_ALL_COMMENTS' => append_sid("album.$phpEx?user_id=$album_user_id$album_view_mode_param&type=comment"),
	'L_SHOW_ALL_COMMENTS' => sprintf($lang['Show_All_Comments_Of_user'], $username_html),
	'SHOW_ALL_COMMENTS_IMG' => $images['show_all_comments'],

	'L_PICTURES_OF_USER' => $list_title,

	'L_PIC_TITLE' => $lang['Pic_Title'],
	'L_PIC_CAT' => $lang['Pic_Cat'],
	'L_POSTED' => $lang['Posted'],
	'L_VIEW' => $lang['View'],
	'L_TIME' => $lang['Time'],

	'L_SELECT_SORT_METHOD' => $lang['Select_sort_method'],
	'L_ORDER' => $lang['Order'],
	'L_SORT' => $lang['Sort'],

	'SORT_TIME' => ($sort_method == 'pic_time') ? 'selected="selected"' : '',
	'SORT_PIC_TITLE' => ($sort_method == 'pic_title') ? 'selected="selected"' : '',
	'SORT_VIEW' => ($sort_method == 'pic_view_count') ? 'selected="selected"' : '',

	'SORT_RATING_OPTION' => $sort_rating_option,
	'SORT_COMMENTS_OPTION' => $sort_comments_option,
	'SORT_NEW_COMMENT_OPTION' => $sort_new_comment_option,

	'L_ASC' => $lang['Sort_Ascending'],
	'L_DESC' => $lang['Sort_Descending'],

	'SORT_ASC' => ($sort_order == 'ASC') ? 'selected="selected"' : '',
	'SORT_DESC' => ($sort_order == 'DESC') ? 'selected="selected"' : '')
);


//
// Generate the page
//
$template->pparse('body');

include($phpbb_root_path . 'includes/page_tail.'.$phpEx);


// +-------------------------------------------------------------+
// |  Powered by Photo Album 2.x.x (c) 2002-2003 Smartor         |
// |  with Volodymyr (CLowN) Skoryk's Service Pack 1 © 2003-2004 |
// +-------------------------------------------------------------+

?>
