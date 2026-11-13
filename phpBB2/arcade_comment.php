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
include_once($phpbb_root_path . 'includes/constants_arcade.'.$phpEx);
include_once($phpbb_root_path . 'includes/functions_validate.'.$phpEx);
include_once($phpbb_root_path . 'includes/bbcode.' .$phpEx);

function arcade_comment_require_token($userdata, $post_vars, $lang)
{
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($post_vars['sid']) || !is_string($post_vars['sid']) || !hash_equals((string) $userdata['session_id'], (string) $post_vars['sid']))
	{
		message_die(GENERAL_ERROR, $lang['Not_Authorised']);
	}
}

function arcade_comment_can_manage($userdata, $owner_id, $moderator_id)
{
	return (int) $userdata['user_id'] === (int) $owner_id ||
		(int) $userdata['user_id'] === (int) $moderator_id ||
		(int) $userdata['user_level'] === ADMIN;
}

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_ARCADE_COMMENTS);
init_userprefs($userdata);
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
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
require_once($phpbb_root_path . 'includes/functions_arcade_comment_storage.' . $phpEx);
$mode = phpbb_request_scalar($HTTP_POST_VARS, 'mode', phpbb_request_scalar($HTTP_GET_VARS, 'mode', ''));
$game_value = isset($HTTP_POST_VARS['game_id']) ? $HTTP_POST_VARS['game_id'] : (isset($HTTP_GET_VARS['game_id']) ? $HTTP_GET_VARS['game_id'] : 0);
$comment_value = isset($HTTP_POST_VARS['comment_id']) ? $HTTP_POST_VARS['comment_id'] : (isset($HTTP_GET_VARS['comment_id']) ? $HTTP_GET_VARS['comment_id'] : 0);
if (($game_value !== 0 && $game_value !== '0' && !phpbb_arcade_rating_id($game_value)) || ($comment_value !== 0 && $comment_value !== '0' && !phpbb_arcade_comment_id($comment_value))) { message_die(GENERAL_ERROR, $lang['no_activity']); }
$game_id = (int)$game_value;
if ($comment_value) { $comment_id = (int)$comment_value; }
$page_options = array('sort'=>phpbb_request_scalar($HTTP_GET_VARS, 'sort_order', phpbb_request_scalar($HTTP_POST_VARS, 'sort_order', 'ASC')),
    'start'=>phpbb_request_scalar($HTTP_GET_VARS, 'start', phpbb_request_scalar($HTTP_POST_VARS, 'start', '0')),
    'size'=>isset($board_config['posts_per_page']) ? $board_config['posts_per_page'] : 20);
try { $comment_context = phpbb_arcade_comment_view($db, $userdata, $game_id, isset($comment_id) ? $comment_id : 0, $page_options); }
catch (Exception $error) { message_die(GENERAL_MESSAGE, phpbb_arcade_comment_message($error)); }
catch (Error $error) { message_die(GENERAL_MESSAGE, phpbb_arcade_comment_message($error)); }
$userdata = array_merge($userdata, $comment_context['user']);
$thisgame = $comment_context['game']; $game_id = (int)$thisgame['game_id'];
$selected_comment = $comment_context['comment'];
if ($selected_comment) { $selected_comment['mod_id'] = $thisgame['mod_id']; }
$cat_id = (int)$thisgame['cat_id']; $total_comments = (int)$thisgame['comments_count'];
$comments_per_page = $comment_context['page']['size'];
$start = $comment_context['page']['start']; $sort_order = $comment_context['page']['sort'];
$first_commenter = $comment_context['first'];
$arcade->arcade_config['games_comment_size'] = $comment_context['comment_limit'];
//
//  See if we are posting a comment
//
if( !isset($HTTP_POST_VARS['comment']) )
{
//
//  Get the Comment Information
//
	if (isset($comment_id) && $mode == 'delete')
	{
//
//  Check to see if the comment needs Deleting
//
    if( !arcade_comment_can_manage($userdata, $selected_comment['comment_user_id'], $selected_comment['mod_id']) )
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
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
	   	));

	//
	// Generate the page
	//
  	$template->pparse('body');

  	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
	exit;
  }
  else
  {
//
//  Check to make sure this isn't a hijack attempt
//
    arcade_comment_require_token($userdata, $HTTP_POST_VARS, $lang);
    if( !arcade_comment_can_manage($userdata, $selected_comment['comment_user_id'], $selected_comment['mod_id']) )
    {
    	message_die(GENERAL_ERROR, $lang['Not_Authorised']);
    }
//
//  Delete the Comment from the database
//
    try { $deleted = phpbb_arcade_comment_change($db, $userdata, 'delete', $game_id, $comment_id); }
    catch (Exception $error) { message_die(GENERAL_MESSAGE, phpbb_arcade_comment_message($error)); }
    catch (Error $error) { message_die(GENERAL_MESSAGE, phpbb_arcade_comment_message($error)); }
    $cat_id = $deleted['cat_id'];

	// --------------------------------
	// Complete... now send a message to user
	// --------------------------------

  	$message = $lang['Deleted'];

		$template->assign_vars(array(
			'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid("activity.$phpEx?mode=cat&amp;cat_id=$cat_id") . '">')
  		);
		$message .= "<br /><br />" . sprintf($lang['arcade_rate_return_cat'], "<a href=\"" . append_sid("activity.$phpEx?mode=cat&amp;cat_id=$cat_id") . "\">", "</a>");
    	$message .= "<br /><br />" . sprintf($lang['return_to_arcade'], "<a href=\"" . append_sid("activity.$phpEx") . "\">", "</a>");
      message_die(GENERAL_MESSAGE, $message);
    }
  }
	else if (isset($comment_id) && $mode == 'edit')
	{
    $thiscomment = array_merge($thisgame, $selected_comment);
    $game_id = (int)$thisgame['game_id']; $cat_id = (int)$thisgame['cat_id'];
    $user_id = (int)$thiscomment['comment_user_id'];

    if( !arcade_comment_can_manage($userdata, $user_id, $thiscomment['mod_id']) )
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
		'GAME_TITLE' => phpbb_profile_text($thiscomment['game_desc']),
  		'DATE_ADDED' => create_date($board_config['default_dateformat'], $thiscomment['date_added'], $board_config['board_timezone']),
	   	'PLAYED' => $thisgame['played'],
		  'GAME_COMMENTS' => $total_comments,
		'POSTER' => phpbb_author_name($thiscomment['comment_user_id'],$thiscomment['comment_username']),

  		'U_GAME' => append_sid("activity.$phpEx?mode=game&amp;id=$game_id"),
  		'U_GAME_TITLE' => append_sid("activity.$phpEx?mode=game&amp;id=$game_id&amp;win=self"),
      'U_ARCADE' => append_sid("activity.$phpEx?mode=cat&amp;cat_id=$cat_id"),
      'U_ARCADE_CAT' => append_sid("activity.$phpEx"),

		'L_ARCADE' => isset($thisgame['cat_name']) ? phpbb_profile_text($thisgame['cat_name']) : $lang['all_games'],
    'L_ARCADE_CAT' => $lang['games_catagories'],
		'L_GAME_TITLE' => phpbb_profile_text($thiscomment['game_desc']),
		'L_POSTER' => $thiscomment['comment_username'] ? $lang['comment_poster'] : '',
		'L_ADDED' => $lang['arcade_added'],
		'L_PLAYED' => $lang['arcade_played'],
		'L_COMMENTS' => $lang['Comments'],
		'L_POST_YOUR_COMMENT' => $lang['Post_your_comment'],
		'L_MESSAGE' => $lang['Message'],
		'L_USERNAME' => $lang['Username'],
		'L_COMMENT_NO_TEXT' => $lang['no_comment_text'],
		'L_COMMENT_TOO_LONG' => sprintf($lang['to_much_comment_text'], $comment_context['comment_limit']),
		'L_MAX_LENGTH' => isset($lang['Max_length']) ? $lang['Max_length'] : 'Maximale Länge',
		'L_SUBMIT' => $lang['Submit'],

		'S_MAX_LENGTH' => max(1, (int) $arcade->arcade_config['games_comment_size']),
		'S_MESSAGE' => htmlspecialchars(html_entity_decode((string) $thiscomment['comment_text'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'),
		'S_ARCADE_ACTION' => append_sid("arcade_comment.$phpEx?comment_id=$comment_id"),
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" /><input type="hidden" name="action" value="edit" />'
		));

	//
	// Generate the page
	//
	$template->pparse('body');

	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
	exit;
  }
	if ($total_comments > 0)
	{
		$commentrow = $comment_context['page']['rows'];

		for ($i = 0; $i < count($commentrow); $i++)
		{
			$poster = (int)$commentrow[$i]['user_id']>0 ? '<a href="'. append_sid("profile.$phpEx?mode=viewprofile&amp;". POST_USERS_URL .'='. (int) $commentrow[$i]['user_id']) .'">'. phpbb_stored_text($commentrow[$i]['username']) .'</a>' : phpbb_author_name($commentrow[$i]['comment_user_id'],$commentrow[$i]['comment_username']!==''?$commentrow[$i]['comment_username']:$lang['Guest']);

			if ($commentrow[$i]['comment_edit_count'] > 0)
			{

				$edit_info = ($commentrow[$i]['comment_edit_count'] == 1) ? $lang['Edited_time_total'] : $lang['Edited_times_total'];

				$edit_info = '<br /><br />&raquo;&nbsp;'. sprintf($edit_info, phpbb_author_name($commentrow[$i]['comment_edit_user_id'],$commentrow[$i]['edit_username']!==null?$commentrow[$i]['edit_username']:$lang['Guest']), create_date($board_config['default_dateformat'], $commentrow[$i]['comment_edit_time'], $board_config['board_timezone']), (int) $commentrow[$i]['comment_edit_count']) .'<br />';
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
				'ID' => (int) $commentrow[$i]['comment_id'],
				'POSTER' => $poster,
				'TIME' => create_date($board_config['default_dateformat'], $commentrow[$i]['comment_time'], $board_config['board_timezone']),

				'USER_STATS' => $lang['game_score'] . ': ' . $arcade->convert_score($commentrow[$i]['score']) . '<br />' . $lang['games_time_taken'] . ': ' . $arcade->convert_time($commentrow[$i]['time_taken']),

				'TEXT' => $comment_text,
				'EDIT_INFO' => $edit_info,

				'IP_IMG' => ( $userdata['user_level'] == ADMIN) ? $lang['View_IP'] . ': ' . htmlspecialchars(decode_ip($commentrow[$i]['comment_user_ip']), ENT_QUOTES, 'UTF-8') : '',
				'EDIT_IMG' => arcade_comment_can_manage($userdata, $commentrow[$i]['comment_user_id'], $thisgame['mod_id']) ? '<a href="'. append_sid("arcade_comment.$phpEx?mode=edit&amp;comment_id=". (int) $commentrow[$i]['comment_id']) .'"><img src="' . $images['icon_edit'] . '" alt="' . $lang['Edit_delete_post'] . '" title="' . $lang['Edit_delete_post'] . '" border="0" /></a>' : '',
				'DELETE_IMG' => arcade_comment_can_manage($userdata, $commentrow[$i]['comment_user_id'], $thisgame['mod_id']) ? '<a href="'. append_sid("arcade_comment.$phpEx?mode=delete&amp;comment_id=". (int) $commentrow[$i]['comment_id']) .'"><img src="' . $images['icon_delpost'] . '" alt="' . $lang['Delete_post'] . '" title="' . $lang['Delete_post'] . '" border="0" /></a>' : '',
				)
			);
		}

		$template->assign_block_vars('switch_comment', array());

		$template->assign_vars(array(
			'USER_HEADER' => $lang['Information'],
			'PAGINATION' => generate_pagination(append_sid("arcade_comment.$phpEx?game_id=$game_id&amp;sort_order=$sort_order"), $total_comments, $comments_per_page, $start),
			'PAGE_NUMBER' => sprintf($lang['Page_of'], ( floor( $start / $comments_per_page ) + 1 ), ceil( $total_comments / $comments_per_page ))
			)
		);
	}

	//
	// Start output of page
	//
	$page_title = $lang['arcade_comments'] . strip_tags((string) $thisgame['game_desc']);
	include($phpbb_root_path . 'includes/page_header.'.$phpEx);

	$template->set_filenames(array(
		'body' => 'arcade_comment_body.tpl')
	);

	$poster = ((int) $first_commenter['user_id'] > 0 && $first_commenter['username'] !== null) ? '<a href="'. append_sid("profile.$phpEx?mode=viewprofile&amp;". POST_USERS_URL .'='. (int) $first_commenter['user_id']) .'">'. phpbb_stored_text($first_commenter['username']) .'</a>' : '';

	//---------------------------------
	// Comment Posting Form
	//---------------------------------
	if( $mode == 'add_comment' )
	{
    if ($comment_context['can_post']) { $template->assign_block_vars('switch_comment_post', array()); }

  }
	if( !$userdata['session_logged_in'])
	{
	 $template->assign_block_vars('switch_comment_post.logout', array());
	}

	$template->assign_vars(array(
		'GAME_TITLE' => phpbb_profile_text($thisgame['game_desc']),
		'S_GAME_ACTION' => append_sid("arcade_comment.$phpEx?game_id=$game_id"),
		'DATE_ADDED' => create_date($board_config['default_dateformat'], $thisgame['date_added'], $board_config['board_timezone']),
		'PLAYED' => $thisgame['played'] . $lang['times'],
		'GAME_COMMENTS' => $total_comments,

		'SORT_ASC' => ($sort_order == 'ASC') ? 'selected="selected"' : '',
		'SORT_DESC' => ($sort_order == 'DESC') ? 'selected="selected"' : '',

		'U_GAME_TITLE' => append_sid("activity.$phpEx?mode=game&amp;id=$game_id&amp;win=self"),
    'U_ARCADE' => append_sid("activity.$phpEx?mode=cat&amp;cat_id=$cat_id"),
    'U_ARCADE_CAT' => append_sid("activity.$phpEx"),

		'L_ARCADE' => isset($thisgame['cat_name']) ? phpbb_profile_text($thisgame['cat_name']) : $lang['all_games'],
    'L_ARCADE_CAT' => $lang['games_catagories'],
		'L_GAME_TITLE' => phpbb_profile_text($thisgame['game_desc']),
		'L_ADDED' => $lang['arcade_added'],
		'L_PLAYED' => $lang['arcade_played'],
		'L_COMMENTS' => $lang['arcade_comments'],
		'L_POST_YOUR_COMMENT' => $lang['Post_your_comment'],
		'L_MESSAGE' => $lang['Message'],
		'L_USERNAME' => $lang['Username'],
		'L_COMMENT_NO_TEXT' => $lang['no_comment_text'],
		'L_COMMENT_TOO_LONG' => sprintf($lang['to_much_comment_text'], $comment_context['comment_limit']),
		'L_MAX_LENGTH' => isset($lang['Max_length']) ? $lang['Max_length'] : 'Maximale Länge',
		'L_ORDER' => $lang['Order'],
		'L_SORT' => $lang['Sort'],
		'L_ASC' => $lang['Sort_Ascending'],
		'L_DESC' => $lang['Sort_Descending'],
		'L_SUBMIT' => $lang['Submit'],

		'S_MAX_LENGTH' => (int) $arcade->arcade_config['games_comment_size'],
		'S_ARCADE_ACTION' => append_sid("arcade_comment.$phpEx?game_id=$game_id"),
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />'
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
  arcade_comment_require_token($userdata, $HTTP_POST_VARS, $lang);
  if (isset($HTTP_POST_VARS['action']) && !is_string($HTTP_POST_VARS['action'])) { message_die(GENERAL_ERROR, $lang['Not_Authorised']); }
  $action = isset($HTTP_POST_VARS['action']) ? trim($HTTP_POST_VARS['action']) : 'add';
  if ($action !== 'add' && $action !== 'edit')
  {
    message_die(GENERAL_ERROR, $lang['Not_Authorised']);
  }
  if ($action === 'edit' && (empty($selected_comment) || !arcade_comment_can_manage($userdata, $selected_comment['comment_user_id'], $selected_comment['mod_id'])))
  {
    message_die(GENERAL_ERROR, $lang['Not_Authorised']);
  }

//
// Pass the message through the Word Censor.
//
// Thanks to the phpBB Group for the code, taken from posting.php
//
	$orig_word = array();
	$replacement_word = array();
	if (!isset($HTTP_POST_VARS['comment']) || is_array($HTTP_POST_VARS['comment']))
	{
		message_die(GENERAL_ERROR, $lang['no_comment_text']);
	}
	$comment_raw = trim(stripslashes((string) $HTTP_POST_VARS['comment']));
	$maximum_comment_length = max(1, (int) $arcade->arcade_config['games_comment_size']);
	if ($comment_raw === '')
	{
		message_die(GENERAL_ERROR, $lang['no_comment_text']);
	}
	if (strlen($comment_raw) > $maximum_comment_length)
	{
		message_die(GENERAL_ERROR, sprintf($lang['to_much_comment_text'], $maximum_comment_length));
	}
	$comment_passed = htmlspecialchars($comment_raw, ENT_QUOTES, 'UTF-8');
	obtain_word_list($orig_word, $replacement_word);
	if( !empty($orig_word) )
	{
		$comment_text = ( !empty($comment_passed) ) ? preg_replace($orig_word, $replacement_word, $comment_passed) : '';
	}
	else
  {
		$comment_text = $comment_passed;
  }
    try { $changed = phpbb_arcade_comment_change($db, $userdata, $action, $game_id, $action === 'edit' ? $comment_id : 0, $comment_raw, $comment_text); }
    catch (Exception $error) { message_die(GENERAL_MESSAGE, phpbb_arcade_comment_message($error, $maximum_comment_length)); }
    catch (Error $error) { message_die(GENERAL_MESSAGE, phpbb_arcade_comment_message($error, $maximum_comment_length)); }
    $comment_id = $changed['comment_id'];
    // Optional delivery runs only after the comment transaction and locks end.
    if ($changed['notify_user'])
    {
        try { ina_send_user_pm($changed['notify_user'], $lang['games_comment_info'], sprintf($lang['games_comment_added_info'], $changed['game_name']), (int)$userdata['user_id'], 'NO', true); }
        catch (Exception $ignored) {} catch (Error $ignored) {}
    }

	$template->assign_vars(array(
		'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid("arcade_comment.$phpEx?refresh=TRUE&amp;comment_id=$comment_id") . '#comment-'.$comment_id.'">')
	);

	$message = $lang['Stored'] . "<br /><br />" . sprintf($lang['Click_view_message'], "<a href=\"" . append_sid("arcade_comment.$phpEx?refresh=TRUE&amp;comment_id=$comment_id") . "#comment-$comment_id\">", "</a>") . "<br /><br />" . sprintf($lang['return_to_arcade'], "<a href=\"" . append_sid("activity.$phpEx") . "\">", "</a>");

	message_die(GENERAL_MESSAGE, $message);
}

?>
