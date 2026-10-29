<?php
/***************************************************************************
 *                                functions_comments.php
 *                            -------------------
 *   begin                : Sunday, July 27, 2003
 *   copyright            : (C) 2003 Mohd
 *   email                : mohd@mohd.tk
 *
 *   $Id: index.php,v 1.99.2.1 2002/12/19 17:17:40 psotfx Exp $
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

require_once dirname(__FILE__) . '/functions_comment_text.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions_author_visibility.php';

function display_comments(&$file_data)
{
	global $pafiledb_template, $lang, $board_config, $phpEx, $pafiledb_config, $db, $images;
	global $_REQUEST, $phpbb_root_path, $userdata, $db, $pafiledb, $pafiledb_functions;
	include_once($phpbb_root_path . 'includes/bbcode.'.$phpEx);

	//
	// Define censored word matches
	//

	$orig_word = array();
	$replacement_word = array();
	obtain_word_list($orig_word, $replacement_word);

	$pafiledb_template->assign_vars(array(
		'L_COMMENTS' => $lang['Comments']) 
	);

	$sql = 'SELECT c.*, u.*
		FROM ' . PA_COMMENTS_TABLE . ' AS c 
			LEFT JOIN ' . USERS_TABLE . " AS u ON c.poster_id = u.user_id
		WHERE c.file_id = '" . $file_data['file_id'] . "'
		ORDER BY c.comments_time ASC";

	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Couldnt select comments', '', __LINE__, __FILE__, $sql);
	}

	if (!($comment_number = $db->sql_numrows($result)))
	{
		$pafiledb_template->assign_vars(array(
			'L_NO_COMMENTS' => $lang['No_comments'],
			'NO_COMMENTS' => TRUE) 
		);
	}

	$ranksrow = array();
	$pafiledb_functions->obtain_ranks($ranksrow);

	while ($comments_row = $db->sql_fetchrow($result)) 
	{     
		$time = create_date($board_config['default_dateformat'], $comments_row['comments_time'], $board_config['board_timezone']);

		$comments_text = pafiledb_comment_render($comments_row['comments_text'], $comments_row['comment_bbcode_uid'],
			$pafiledb_config, !empty($userdata['user_allowsmile']), $orig_word, $replacement_word);
		$registered = isset($comments_row['user_id']) && (int)$comments_row['user_id'] > 0;

		$poster = phpbb_author_name((int)$comments_row['poster_id'], $registered ? $comments_row['username'] : $lang['Guest']);

		$poster_posts = ( $registered ) ? $lang['Posts'] . ': ' . $comments_row['user_posts'] : '';

		$poster_from = ( phpbb_profile_details_visible() && $comments_row['user_from'] && $registered ) ? $lang['Location'] . ': ' . phpbb_profile_text($comments_row['user_from']) : '';

		$poster_joined = ( $registered ) ? $lang['Joined'] . ': ' . create_date($lang['DATE_FORMAT'], $comments_row['user_regdate'], $board_config['board_timezone']) : '';

		$poster_avatar = '';
		if ( $comments_row['user_avatar_type'] && $registered && $comments_row['user_allowavatar'] )
		{
			$poster_avatar = phpbb_avatar_image($comments_row['user_avatar'], $comments_row['user_avatar_type']);
		}
 
		//
		// Generate ranks, set them to empty string initially.
		//
		$poster_rank = '';
		$rank_image = '';
		if ( !$registered )
		{
		}
		else if ( $comments_row['user_rank'] )
		{
			for($j = 0; $j < count($ranksrow); $j++)
			{
				if ( $comments_row['user_rank'] == $ranksrow[$j]['rank_id'] && $ranksrow[$j]['rank_special'] )
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
				if ( $comments_row['user_posts'] >= $ranksrow[$j]['rank_min'] && !$ranksrow[$j]['rank_special'] )
				{
					$poster_rank = phpbb_profile_text($ranksrow[$j]['rank_title']);
					$rank_image_name = phpbb_profile_image_name($ranksrow[$j]['rank_image']);
					$rank_image = ( $rank_image_name ) ? '<img src="' . $images['rank_path'] . $rank_image_name . '" alt="' . $poster_rank . '" title="' . $poster_rank . '" border="0" /><br />' : '';
				}
			}
		}

		$auth = $pafiledb->modules[$pafiledb->module_name]->auth[$file_data['file_catid']];
		$can_delete_comment = !empty($userdata['session_logged_in']) && (!empty($auth['auth_mod']) ||
			(!empty($auth['auth_delete_comment']) && (int)$comments_row['poster_id'] === (int)$userdata['user_id']));

		$pafiledb_template->assign_block_vars('text', array(
			'POSTER' => $poster,
			'U_COMMENT_DELETE' => $can_delete_comment ? append_sid("dload.php?action=post_comment&cid={$comments_row['comments_id']}&delete=do&file_id={$file_data['file_id']}") : '',
			'AUTH_COMMENT_DELETE' => $can_delete_comment,
			'DELETE_IMG' => $can_delete_comment ? $images['icon_delpost'] : '',
			'ICON_MINIPOST_IMG' => $phpbb_root_path . $images['icon_minipost'],
			'ICON_SPACER' => $phpbb_root_path . 'images/spacer.gif',
			'POSTER_RANK' => $poster_rank,
			'RANK_IMAGE' => $rank_image,
			'POSTER_JOINED' => $poster_joined,
			'POSTER_POSTS' => $poster_posts,
			'POSTER_FROM' => $poster_from,
			'POSTER_AVATAR' => $poster_avatar,
			'TITLE' => htmlspecialchars($comments_row['comments_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
			'TIME' => $time,
			'TEXT' => $comments_text) 
		);
	}

	$db->sql_freeresult($result);

	$can_post = !empty($pafiledb->modules[$pafiledb->module_name]->auth[$file_data['file_catid']]['auth_post_comment']) &&
		!empty($pafiledb->modules[$pafiledb->module_name]->cat_rowset[$file_data['file_catid']]['cat_allow_comments']);
	$pafiledb_template->assign_vars(array(
		'REPLY_IMG' => $can_post ? $images['pa_comment_post'] : '',
		'AUTH_POST' => $can_post ? TRUE : FALSE,
		'L_COMMENT_DO' => $can_post ? (isset($lang['Comment_do']) ? $lang['Comment_do'] : $lang['Comment_add']) : '',
		'L_COMMENTS' => $lang['Comments'],
		'L_AUTHOR' => $lang['Author'],
		'L_POSTED' => $lang['Posted'],
		'L_COMMENT_SUBJECT' => $lang['Comment_subject'],
		'L_COMMENT_ADD' => $lang['Comment_add'],
		'L_COMMENT_DELETE' => $lang['Comment_delete'],
		'L_COMMENTS_NAME' => $lang['Name'],
		'L_BACK_TO_TOP' => $lang['Back_to_top'],
		'U_COMMENT_DO' => append_sid('dload.php?action=post_comment&file_id='.$file_data['file_id']))
	);
}
