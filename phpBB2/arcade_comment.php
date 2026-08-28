<?php
/***************************************************************************
 *                             arcade_comment.php
 *                            --------------------
 *   begin                : Tuesday, Jan 2nd, 2007
 *   copyright            : (C) 2003-2007 dEfEndEr
 *   email                : defenders_realm@yahoo.com
 *   support              : http://www.phpbb-arcade.com
 *
 *   $Id: arcade_comment.php, v2.1.6 2007/01/02 20:32:00 dEfEndEr Exp $
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is freeware; you can redistribute it under the terms of
 *   the License as published by the Arcade Support Site above.
 *
 ***************************************************************************/

define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
include_once($phpbb_root_path . 'includes/functions_validate.'.$phpEx);
include_once($phpbb_root_path . 'includes/bbcode.' .$phpEx);

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_ARCADE_COMMENTS);
init_userprefs($userdata);
//
// End session management
//
//
// Check User Logged in
//
$game_id = $arcade->pass_var('game_id', 0);
if (!$userdata['session_logged_in'])
{
	redirect(append_sid("login.$phpEx?redirect=arcade_comment.$phpEx?game_id=$game_id"));
}
//
// Initialize Arcade Config and Check that the comments feature is enabled
//
if( $arcade->arcade_config('games_comments') == 0)
{
	message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}
//
//  Check System ONLINE.
//
if($arcade->arcade_config['games_offline'] && $userdata['user_level'] != ADMIN && $userdata['user_level'] != MOD)
{
	message_die(GENERAL_MESSAGE, $lang['games_are_offline'], $lang['Information']);
}
//
// Get the Required information
//
$mode = $arcade->pass_var('mode', '');
if(($game_id = $arcade->pass_var('game_id', 0)) < 1)
{
	if( ($comment_id = $arcade->pass_var('comment_id', 0)) < 1)
  {
		message_die(GENERAL_ERROR, $lang['no_activity']);
	}
}
//
//  Show single comment
//
if( isset($comment_id) )
{
	$sql = "SELECT c.comment_id, c.comment_game_name, g.game_id
			FROM ". iNA_GAMES_COMMENT ." c
			LEFT JOIN " . iNA_GAMES . " g ON c.comment_game_name = g.game_name
			WHERE comment_id = '$comment_id'";
	if( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, $lang['no_comment_data'], '', __LINE__, __FILE__, $sql);
	}
	$row = $db->sql_fetchrow($result);
	if( empty($row) )
	{
		message_die(GENERAL_ERROR, $lang['no_comment_found']);
	}
	$game_id = $row['game_id'];
}
//
//  Collect as much information about this comment as we can
//
$sql = "SELECT t.*, g.*, u.user_id, u.username, COUNT(c.comment_id) as comments_count
 	FROM ". iNA_GAMES ." AS g
 		LEFT JOIN ". iNA_CAT ." AS t ON t.cat_id = g.cat_id
 		LEFT JOIN ". iNA_GAMES_COMMENT ." AS c ON g.game_name = c.comment_game_name
 		LEFT JOIN ". USERS_TABLE ." AS u ON c.comment_user_id = u.user_id
 	WHERE game_id = '$game_id'
 	GROUP BY g.game_id
  LIMIT 0,1";
if( !($result = $db->sql_query($sql)) )
{
  message_die(GENERAL_ERROR, $lang['no_comment_data'], '', __LINE__, __FILE__, $sql);
}
$thisgame = $db->sql_fetchrow($result);

$cat_id = $thisgame['cat_id'];
$user_id = isset($thisgame['comment_user_id']) ? intval($thisgame['comment_user_id']) : ANONYMOUS;
$total_comments = $thisgame['comments_count'];
$comments_per_page = $board_config['posts_per_page'];
$game_name = $thisgame['game_name'];
//
//  Check we have some information to carry on with
//
if( empty($thisgame) )
{
  message_die(GENERAL_ERROR, $lang['does_not_exist']);
}
//
//  See if we are posting a comment
//
if( !isset($HTTP_POST_VARS['comment']) )
{
//
//  Get the Comment Information
//
	if( !isset($comment_id) )
	{
		if( isset($HTTP_GET_VARS['start']) )
		{
			$start = intval($HTTP_GET_VARS['start']);
		}
		else if( isset($HTTP_POST_VARS['start']) )
		{
			$start = intval($HTTP_POST_VARS['start']);		}
		else
		{
			$start = 0;
		}
	}
	else if ($mode == 'delete')
	{
//
//  Check to see if the comment needs Deleting
//
    if( $userdata['user_id'] != $user_id && $userdata['user_id'] != $thisgame['mod_id'] && $userdata['user_level'] != ADMIN)
    {
    	message_die(GENERAL_ERROR, $lang['Not_Authorised']);
    }
    if( !isset($HTTP_POST_VARS['confirm']) )
    {
//
// Was CANCEL Selected ?
//
  	 if( isset($HTTP_POST_VARS['cancel']) )
	   {
		    redirect(append_sid("arcade_comment.$phpEx?comment_id=$comment_id"));
		    exit;
	   }
//
// Start output of page
//
	  $page_title = $lang['arcade_comment_delete'];
	  include($phpbb_root_path . 'includes/page_header.'.$phpEx);

    $template->set_filenames(array(
		  'body' => 'confirm_body.tpl')
  	);

  	$template->assign_vars(array(
	   	'MESSAGE_TITLE' => $lang['Confirm'],

  		'MESSAGE_TEXT' => $lang['arcade_comment_sure'],

  		'L_NO' => $lang['No'],
	   	'L_YES' => $lang['Yes'],

  		'S_CONFIRM_ACTION' => append_sid("arcade_comment.$phpEx?mode=delete&amp;comment_id=$comment_id"),
	   	));

	//
	// Generate the page
	//
  	$template->pparse('body');

  	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
  }
  else
  {
//
//  Check to make sure this isn't a hijack attempt
//
    if( $userdata['user_id'] != $user_id && $userdata['user_id'] != $thisgame['mod_id'] && $userdata['user_level'] != ADMIN)
    {
    	message_die(GENERAL_ERROR, $lang['Not_Authorised']);
    }
//
//  Delete the Comment from the database
//
  	$sql = "DELETE
			FROM ". iNA_GAMES_COMMENT ."
			WHERE comment_id = '$comment_id'";

  	if( !$result = $db->sql_query($sql) )
    {
		  message_die(GENERAL_ERROR, $lang['no_comment_update'], '', __LINE__, __FILE__, $sql);
	  }

	// --------------------------------
	// Complete... now send a message to user
	// --------------------------------

  	$message = $lang['Deleted'];

		$template->assign_vars(array(
			'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid("activity.$phpEx?mode=cat&amp;cat_id=$cat_id") . '">')
  		);
  		$message .= "<br /><br />" . sprintf($lang['admin_return_cats'], "<a href=\"" . append_sid("activity.$phpEx?mode=cat&amp;cat_id=$cat_id") . "\">", "</a>");
    	$message .= "<br /><br />" . sprintf($lang['return_to_arcade'], "<a href=\"" . append_sid("activity.$phpEx") . "\">", "</a>");
      message_die(GENERAL_MESSAGE, $message);
    }
  }
	else if ($mode == 'edit')
	{
    $sql = "SELECT c.*, g.*, COUNT(c.comment_id) as comments_count
	   	FROM ". iNA_GAMES_COMMENT ." AS c
  		LEFT JOIN ". iNA_GAMES ." AS g ON c.comment_game_name = g.game_name
			LEFT JOIN ". USERS_TABLE ." AS u ON c.comment_user_id = u.user_id
		    WHERE comment_id = '$comment_id'
       	GROUP BY g.game_id";

    if( !($result = $db->sql_query($sql)) )
    {
    	message_die(GENERAL_ERROR, $lang['no_comment_data'], '', __LINE__, __FILE__, $sql);
    }
    $thiscomment = $db->sql_fetchrow($result);

    $game_id = $thiscomment['game_id'];
    $cat_id = $thiscomment['cat_id'];
    $user_id = $thiscomment['comment_user_id'];

    $total_comments = $thiscomment['comments_count'];
    $comments_per_page = $board_config['posts_per_page'];

    if( empty($thiscomment) || $userdata['user_id'] != $user_id && $userdata['user_id'] != $thisgame['mod_id'] && $userdata['user_level'] != ADMIN)
    {
    	message_die(GENERAL_ERROR, $lang['Not_Authorised']);
    }
	//
	// Start output of page
	//
  	$page_title = $lang['arcade_comment_edit'];
	  include($phpbb_root_path . 'includes/page_header.'.$phpEx);

  	$template->set_filenames(array(
	   	'body' => 'arcade_comment_body.tpl')
	   );

  	$template->assign_block_vars('switch_comment_post', array());

  	$template->assign_vars(array(
	   	'GAME_TITLE' => $thiscomment['game_desc'],
  		'DATE_ADDED' => create_date($board_config['default_dateformat'], $thiscomment['date_added'], $board_config['board_timezone']),
	   	'PLAYED' => $thisgame['played'],
		  'GAME_COMMENTS' => $total_comments,
      'POSTER' => $thiscomment['comment_username'],

  		'U_GAME' => append_sid("activity.$phpEx?mode=game&amp;id=$game_id"),
  		'U_GAME_TITLE' => append_sid("activity.$phpEx?mode=game&amp;id=$game_id&amp;win=self"),
      'U_ARCADE' => append_sid("activity.$phpEx?mode=cat&amp;cat_id=$cat_id"),
      'U_ARCADE_CAT' => append_sid("activity.$phpEx"),

		'L_ARCADE' => isset($thisgame['cat_name']) ? $thisgame['cat_name'] : $lang['all_games'],
    'L_ARCADE_CAT' => $lang['games_catagories'],
		'L_GAME_TITLE' => $thiscomment['game_desc'],
		'L_POSTER' => $thiscomment['comment_username'] ? $lang['comment_poster'] : '',
		'L_ADDED' => $lang['arcade_added'],
		'L_PLAYED' => $lang['arcade_played'],
		'L_COMMENTS' => $lang['Comments'],
		'L_POST_YOUR_COMMENT' => $lang['Post_your_comment'],
		'L_MESSAGE' => $lang['Message'],
		'L_USERNAME' => $lang['Username'],
		'L_COMMENT_NO_TEXT' => $lang['no_comment_text'],
		'L_COMMENT_TOO_LONG' => sprintf($lang['to_much_comment_text'], $arcade->arcade_config['games_comment_size']),
		'L_MAX_LENGTH' => isset($lang['Max_length']) ? $lang['Max_length'] : 'Maximale Länge',
		'L_SUBMIT' => $lang['Submit'],

		'S_MAX_LENGTH' => $arcade->arcade_config['games_comment_size'],
		'S_MESSAGE' => $thiscomment['comment_text'],
		'S_ARCADE_ACTION' => append_sid("arcade_comment.$phpEx?comment_id=$comment_id"),
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="action" value="edit">'
		));

	//
	// Generate the page
	//
	$template->pparse('body');

	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
  }
	else
  {
		// We must do a query to co-ordinate this comment
		$sql = "SELECT COUNT(comment_id) AS count
				FROM ". iNA_GAMES_COMMENT ."
				WHERE comment_game_name = '". $game_name ."'
					AND comment_id < $comment_id";

		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_comment_data'], '', __LINE__, __FILE__, $sql);
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

	if( isset($HTTP_GET_VARS['sort_order']) )
	{
		switch ($HTTP_GET_VARS['sort_order'])
		{
			case 'ASC':
				$sort_order = 'ASC';
				break;
			default:
				$sort_order = 'DESC';
		}
	}
	else if( isset($HTTP_POST_VARS['sort_order']) )
	{
		switch ($HTTP_POST_VARS['sort_order'])
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
//		$limit_sql = ($start == 0) ? $comments_per_page : $start .','. $comments_per_page;

		$sql = "SELECT c.*, u.user_id, u.username, s.score, s.time_taken
				FROM ". iNA_GAMES_COMMENT ." AS c
					LEFT JOIN ". USERS_TABLE ." AS u ON c.comment_user_id = u.user_id
					LEFT JOIN ". iNA_SCORES ." AS s ON c.comment_game_name = s.game_name AND s.player_id = u.user_id
 				WHERE c.comment_game_name = '".$game_name."'
				ORDER BY c.comment_id $sort_order
				LIMIT $start, $comments_per_page"; // $limit_sql";

		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_comment_data'], '', __LINE__, __FILE__, $sql);
		}

		$commentrow = array();

		while( $row = $db->sql_fetchrow($result) )
		{
			$commentrow[] = $row;
		}

		for ($i = 0; $i < count($commentrow); $i++)
		{
			$poster = '<a href="'. append_sid("profile.$phpEx?mode=viewprofile&amp;". POST_USERS_URL .'='. $commentrow[$i]['user_id']) .'">'. $commentrow[$i]['username'] .'</a>';

			if ($commentrow[$i]['comment_edit_count'] > 0)
			{
				$sql = "SELECT c.comment_id, c.comment_edit_user_id, u.user_id, u.username
						FROM ". iNA_GAMES_COMMENT ." AS c
						LEFT JOIN ". USERS_TABLE ." AS u ON c.comment_edit_user_id = u.user_id
						WHERE c.comment_id = '".$commentrow[$i]['comment_id']."'
						LIMIT 0,1";

				if( !$result = $db->sql_query($sql) )
				{
					message_die(GENERAL_ERROR, $lang['no_comment_edit_data'], '', __LINE__, __FILE__, $sql);
				}

				$lastedit_row = $db->sql_fetchrow($result);

				$edit_info = ($commentrow[$i]['comment_edit_count'] == 1) ? $lang['Edited_time_total'] : $lang['Edited_times_total'];

				$edit_info = '<br /><br />&raquo;&nbsp;'. sprintf($edit_info, $lastedit_row['username'], create_date($board_config['default_dateformat'], $commentrow[$i]['comment_edit_time'], $board_config['board_timezone']), $commentrow[$i]['comment_edit_count']) .'<br />';
			}
			else
			{
				$edit_info = '';
			}
//
// Check to make sure that since the comment was entered, the words in the message
// have not been added to the censor list.
//
// Thanks to the phpBB Group for the code, taken from posting.php
//
      $comment_text = nl2br(smilies_pass($commentrow[$i]['comment_text']));
    	$orig_word = array();
    	$replacement_word = array();
    	obtain_word_list($orig_word, $replacement_word);
    	if( !empty($orig_word) )
    	{
		    $comment_text = ( !empty($comment_text) ) ? preg_replace($orig_word, $replacement_word, $comment_text) : '';
	    }
      else
      {
  	     $comment_text = str_replace("\'", "''", $comment_text);
      }

			$template->assign_block_vars('commentrow', array(
				'ID' => $commentrow[$i]['comment_id'],
				'POSTER' => $poster,
				'TIME' => create_date($board_config['default_dateformat'], $commentrow[$i]['comment_time'], $board_config['board_timezone']),

'USER_STATS' => 'Score: ' . $arcade->convert_score($commentrow[$i]['score']) . '<br />Time: ' . $arcade->convert_time($commentrow[$i]['time_taken']),

				'TEXT' => $comment_text,
				'EDIT_INFO' => $edit_info,

				'IP_IMG' => ( $userdata['user_level'] == ADMIN) ? '<a href="http://network-tools.com/default.asp?host=' . decode_ip($commentrow[$i]['comment_user_ip']) . '" target="_blank"><img src="' . $images['icon_ip'] . '" alt="' . $lang['View_IP'] . '" title="' . $lang['View_IP'] . '" border="0" /></a>' : '',
				'EDIT_IMG' => ( ( $commentrow[$i]['comment_user_id'] == $userdata['user_id'] ) || ($userdata['user_level'] == ADMIN) || $thisgame['mod_id'] == $userdata['user_id']) ? '<a href="'. append_sid("arcade_comment.$phpEx?mode=edit&amp;comment_id=". $commentrow[$i]['comment_id']) .'"><img src="' . $images['icon_edit'] . '" alt="' . $lang['Edit_delete_post'] . '" title="' . $lang['Edit_delete_post'] . '" border="0" /></a></a>' : '',
				'DELETE_IMG' => ( ( $commentrow[$i]['comment_user_id'] == $userdata['user_id'] ) || ($userdata['user_level'] == ADMIN) || $thisgame['mod_id'] == $userdata['user_id']) ? '<a href="'. append_sid("arcade_comment.$phpEx?mode=delete&amp;comment_id=". $commentrow[$i]['comment_id']) .'"><img src="' . $images['icon_delpost'] . '" alt="' . $lang['Delete_post'] . '" title="' . $lang['Delete_post'] . '" border="0" /></a></a>' : '',
				)
			);
		}

		$template->assign_block_vars('switch_comment', array());

		$template->assign_vars(array(
      'USER_HEADER' => 'User Info',
			'PAGINATION' => generate_pagination(append_sid("arcade_comment.$phpEx?game_id=$game_id&amp;sort_order=$sort_order"), $total_comments, $comments_per_page, $start),
			'PAGE_NUMBER' => sprintf($lang['Page_of'], ( floor( $start / $comments_per_page ) + 1 ), ceil( $total_comments / $comments_per_page ))
			)
		);
	}

	//
	// Start output of page
	//
  $page_title = "Arcade Comments for " . $thisgame['game_desc'];
	include($phpbb_root_path . 'includes/page_header.'.$phpEx);

	$template->set_filenames(array(
		'body' => 'arcade_comment_body.tpl')
	);

	$poster = '<a href="'. append_sid("profile.$phpEx?mode=viewprofile&amp;". POST_USERS_URL .'='. $thisgame['user_id']) .'">'. $thisgame['username'] .'</a>';

	//---------------------------------
	// Comment Posting Form
	//---------------------------------
	if( $mode == 'add_comment' )
	{
	  $last_played = time()-3600;
    $sql = "SELECT * from " . iNA_SCORES . " s
      LEFT JOIN " . iNA_GAMES . " g ON g.game_name = s.game_name
      LEFT JOIN " . iNA_CAT . " c ON g.cat_id = c.cat_id
      WHERE s.player_id = " . $userdata['user_id'] . "
      AND g.game_id = " . $game_id . "
      AND s.date > " . $last_played . "
      ORDER by date DESC LIMIT 0,1";
  	if( !$result = $db->sql_query($sql) )
	  {
		  message_die(GENERAL_ERROR, $lang['no_score_data'], '', __LINE__, __FILE__, $sql);
	  }
    $row_count = $db->sql_numrows($result);
    $game_info = $db->sql_fetchrow($result);
    if(($row_count > 0) && $game_info)
    {
		  $template->assign_block_vars('switch_comment_post', array());
		}
    else
    {
      $sql = "SELECT * from " . iNA_AT_SCORES . " s
        LEFT JOIN " . iNA_GAMES . " g ON g.game_name = s.game_name
        LEFT JOIN " . iNA_CAT . " c ON g.cat_id = c.cat_id
        WHERE s.player_id = " . $userdata['user_id'] . "
        AND g.game_id = " . $game_id . "
        AND s.date > " . $last_played . "
        ORDER by date DESC LIMIT 0,1";
    	if( !$result = $db->sql_query($sql) )
  	  {
  		  message_die(GENERAL_ERROR, $lang['no_score_data'], '', __LINE__, __FILE__, $sql);
  	  }
      $row_count = $db->sql_numrows($result);
      $game_info = $db->sql_fetchrow($result);
      if(($row_count > 0) && $game_info)
      {
  		  $template->assign_block_vars('switch_comment_post', array());
  		}
    }
  }
	if( !$userdata['session_logged_in'])
	{
	 $template->assign_block_vars('switch_comment_post.logout', array());
	}

	$template->assign_vars(array(
		'DATE_ADDED' => create_date($board_config['default_dateformat'], $thisgame['date_added'], $board_config['board_timezone']),
		'PLAYED' => $thisgame['played'] . ' times',
		'GAME_COMMENTS' => $total_comments,

		'SORT_ASC' => ($sort_order == 'ASC') ? 'selected="selected"' : '',
		'SORT_DESC' => ($sort_order == 'DESC') ? 'selected="selected"' : '',

		'U_GAME_TITLE' => append_sid("activity.$phpEx?mode=game&amp;id=$game_id&amp;win=self"),
    'U_ARCADE' => append_sid("activity.$phpEx?mode=cat&amp;cat_id=$cat_id"),
    'U_ARCADE_CAT' => append_sid("activity.$phpEx"),

		'L_ARCADE' => isset($thisgame['cat_name']) ? $thisgame['cat_name'] : $lang['all_games'],
    'L_ARCADE_CAT' => $lang['games_catagories'],
		'L_GAME_TITLE' => $thisgame['game_desc'],
		'L_ADDED' => $lang['arcade_added'],
		'L_PLAYED' => $lang['arcade_played'],
		'L_COMMENTS' => $lang['arcade_comments'],
		'L_POST_YOUR_COMMENT' => $lang['Post_your_comment'],
		'L_MESSAGE' => $lang['Message'],
		'L_USERNAME' => $lang['Username'],
		'L_COMMENT_NO_TEXT' => $lang['no_comment_text'],
		'L_COMMENT_TOO_LONG' => sprintf($lang['to_much_comment_text'], $arcade->arcade_config['games_comment_size']),
		'L_MAX_LENGTH' => isset($lang['Max_length']) ? $lang['Max_length'] : 'Maximale Länge',
		'L_ORDER' => $lang['Order'],
		'L_SORT' => $lang['Sort'],
		'L_ASC' => $lang['Sort_Ascending'],
		'L_DESC' => $lang['Sort_Descending'],
		'L_SUBMIT' => $lang['Submit'],

		'S_MAX_LENGTH' => $arcade->arcade_config['games_comment_size'],
		'S_ARCADE_ACTION' => append_sid("arcade_comment.$phpEx?game_id=$game_id")
		)
	);

	//
	// Generate the page
	//
	$template->pparse('body');

	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
}
else
{
//
//  Comment Submited. Check that they have played the game etc.
//
  $action = trim(htmlspecialchars($HTTP_POST_VARS['action']));
  $last_played = time()-3600;
  $sql = "SELECT * from " . iNA_SCORES . " s
    LEFT JOIN " . iNA_GAMES . " g ON g.game_name = s.game_name
    WHERE s.player_id = " . $userdata['user_id'] . "
    AND g.game_id = " . $game_id . "
    AND s.date > " . $last_played . "
    ORDER by date DESC LIMIT 0,1";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_score_data'], '', __LINE__, __FILE__, $sql);
	}
  $row_count = $db->sql_numrows($result);
  $game_info = $db->sql_fetchrow($result);
  if((($row_count < 1) || !$game_info) && ($action != "edit"))
  {
    $sql = "SELECT * from " . iNA_AT_SCORES . " s
      LEFT JOIN " . iNA_GAMES . " g ON g.game_name = s.game_name
      WHERE s.player_id = " . $userdata['user_id'] . "
      AND g.game_id = " . $game_id . "
      AND s.date > " . $last_played . "
      ORDER by date DESC LIMIT 0,1";
  	if( !$result = $db->sql_query($sql) )
  	{
  		message_die(GENERAL_ERROR, $lang['no_score_data'], '', __LINE__, __FILE__, $sql);
  	}
    $row_count = $db->sql_numrows($result);
    $game_info = $db->sql_fetchrow($result);
    if((($row_count < 1) || !$game_info) && ($action != "edit"))
    {
    	message_die(GENERAL_ERROR, $lang['Not_Authorised']);
  	}
	}
//
// Pass the message through the Word Censor.
//
// Thanks to the phpBB Group for the code, taken from posting.php
//
	$orig_word = array();
	$replacement_word = array();
	$comment_passed = addslashes(trim(stripslashes(htmlspecialchars($HTTP_POST_VARS['comment']))));
	obtain_word_list($orig_word, $replacement_word);
	if( !empty($orig_word) )
	{
		$comment_text = ( !empty($comment_passed) ) ? preg_replace($orig_word, $replacement_word, $comment_passed) : '';
	}
  else
  {
  	$comment_text = str_replace("\'", "''", $comment_passed);
  }
//
// Clean the username up a little.
//
	$comment_username = (!$userdata['session_logged_in']) ? str_replace("\'", "''", substr(htmlspecialchars(trim($HTTP_POST_VARS['comment_username'])), 0, 32)) : str_replace("'", "''", htmlspecialchars(trim($userdata['username'])));

	if( empty($comment_text) )
	{
		message_die(GENERAL_ERROR, 'No comment text');
	}


	// --------------------------------
	// Check username for guest posting
	// --------------------------------

	if (!$userdata['session_logged_in'])
	{
		if ($comment_username != '')
		{
			$result = validate_username($comment_username);
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

//
// Insert/Update the DB
//
  if($action == 'edit')
  {
    $user_id = $userdata['user_id'];
   	$sql = "UPDATE ". iNA_GAMES_COMMENT ."
			SET comment_text = '$comment_text', comment_edit_time = '$comment_time', comment_edit_count = comment_edit_count + 1, comment_edit_user_id = '$user_id'
			WHERE comment_id = '$comment_id'";
  }
  else
  {
  	$sql = "SELECT MAX(comment_id) AS max
			FROM ". iNA_GAMES_COMMENT;
  	if( !$result = $db->sql_query($sql) )
  	{
  		message_die(GENERAL_ERROR, $lang['no_comment_found'], '', __LINE__, __FILE__, $sql);
  	}
  	$row = $db->sql_fetchrow($result);

    $comment_id = $row['max'] + 1;
	  $comment_user_id = $userdata['user_id'];
	  $comment_user_ip = $userdata['session_ip'];
//
//  Send the original comment user a PM
//
    if($arcade->arcade_config['games_pm_comment'] && $total_comments > 0)
    {
     	$message = sprintf($lang['games_comment_added_info'], $game_name);
      ina_send_user_pm(intval($thisgame['user_id']), $lang['games_comment_info'], $message, intval($userdata['user_id']));
    }
    $sql = "INSERT INTO ". iNA_GAMES_COMMENT ." (comment_id, comment_game_name, comment_user_id, comment_username, comment_user_ip, comment_time, comment_text)
			VALUES ('$comment_id', '$game_name', '$comment_user_id', '$comment_username', '$comment_user_ip', '$comment_time', '$comment_text')";
	}
  if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_comment_update'], '', __LINE__, __FILE__, $sql);
	}


	$template->assign_vars(array(
		'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid("arcade_comment.$phpEx?refresh=TRUE&amp;comment_id=$comment_id") . '#'.$comment_id.'">')
	);

	$message = $lang['Stored'] . "<br /><br />" . sprintf($lang['Click_view_message'], "<a href=\"" . append_sid("arcade_comment.$phpEx?refresh=TRUE&amp;comment_id=$comment_id") . "#$comment_id\">", "</a>") . "<br /><br />" . sprintf($lang['return_to_arcade'], "<a href=\"" . append_sid("activity.$phpEx") . "\">", "</a>");

	message_die(GENERAL_MESSAGE, $message);
}

?>
