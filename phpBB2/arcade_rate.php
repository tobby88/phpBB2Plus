<?php
/***************************************************************************
 *                              arcade_rate.php
 *                              ---------------
 *   begin                : Tuesday, May 30th, 2006
 *   copyright            : (C) 2003-2006 dEfEndEr
 *   email                : defenders_realm@yahoo.com
 *   support              : http://www.phpbb-arcade.com
 *
 *   $Id: arcade_rate.php, v2.1.3 2006/05/30 23:59:00 dEfEndEr Exp $
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
//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_ARCADE_RATE);
init_userprefs($userdata);
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
//
// End session management
//
require_once($phpbb_root_path . 'includes/functions_arcade_rating_storage.' . $phpEx);
$game_id_value = isset($HTTP_POST_VARS['game_id']) ? $HTTP_POST_VARS['game_id'] : (isset($HTTP_GET_VARS['game_id']) ? $HTTP_GET_VARS['game_id'] : 0);
$game_id = phpbb_arcade_rating_id($game_id_value) ? (int)$game_id_value : 0;
if (empty($userdata['session_logged_in']))
{
	redirect(append_sid("login.$phpEx?redirect=arcade_rate.$phpEx?game_id=$game_id"));
}
if (!$game_id) { message_die(GENERAL_ERROR, $lang['no_activity']); }

if( !isset($HTTP_POST_VARS['rate']) )
{
	try { $rating_context = phpbb_arcade_rating_view($db, $userdata, $game_id); }
	catch (Exception $error) { message_die(GENERAL_MESSAGE, phpbb_arcade_rating_message($error)); }
	catch (Error $error) { message_die(GENERAL_MESSAGE, phpbb_arcade_rating_message($error)); }
	$thisgame = $rating_context['game']; $rated_total = $thisgame['total'];
	$cat_id = (int)$thisgame['cat_id']; $already_rated = $rating_context['already_rated'];
//
// Rate Scale
//
	if (!$already_rated)
	{
		for ($i = 0; $i < $rating_context['scale']; $i++)
		{
			$template->assign_block_vars('rate_row', array(
				'POINT' => ($i + 1)
				));
		}
	}
	//
	// Start output of page
	//
	$page_title = strip_tags((string) $thisgame['game_desc']);
	include($phpbb_root_path . 'includes/page_header.'.$phpEx);

	$template->set_filenames(array(
		'body' => 'arcade_rate_body.tpl')
	);

	$template->assign_vars(array(
		'RATE_TITLE' => phpbb_profile_text($thisgame['game_desc']),
		'TITLE' => phpbb_profile_text($thisgame['game_desc']),

		'GAME_TIME' => isset($thisgame['date_added']) ? create_date($board_config['default_dateformat'], $thisgame['date_added'], $board_config['board_timezone']) : $lang['Unknown'],
		'GAME_PLAYED' => $thisgame['played'] . $lang['times'],
		'GAME_RATED' => $rated_total . $lang['times'],
		'ACTIVITY_RATING' => ($thisgame['rating'] != 0) ? round($thisgame['rating'], 2) : $lang['arcade_not_rated'],

		'S_RATE_MSG' => ($already_rated) ? $lang['already_rated'] : $lang['rating'],

    'U_ARCADE' => append_sid("activity.$phpEx?mode=cat&amp;cat_id=$cat_id"),
    'U_ARCADE_CAT' => append_sid("activity.$phpEx"),
		'L_ARCADE' => isset($thisgame['cat_name']) ? phpbb_profile_text($thisgame['cat_name']) : $lang['all_games'],
    'L_ARCADE_CAT' => $lang['games_catagories'],
    'L_RATE_TITLE' => append_sid("activity.$phpEx?mode=game&amp;id=$game_id&amp;win=self"),
		'L_RATED' => $lang['arcade_rated'],
		'L_RATING' => $lang['rating'],
		'S_RATE_DISABLED' => $already_rated ? ' disabled="disabled"' : '',
		'L_TITLE' => $lang['arcade_title'],
		'L_POSTED' => $lang['Posted'],
		'L_PLAYED' => $lang['arcade_played'],
		'L_CURRENT_RATING' => $lang['arcade_current_rating'],
		'L_PLEASE_RATE_IT' => $lang['arcade_rate'],
		'L_SUBMIT' => $lang['Submit'],

		'S_ARCADE_ACTION' => append_sid("arcade_rate.$phpEx?game_id=$game_id"),
		'S_FORM_TOKEN' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',

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
// Get the submited rating
//
	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($HTTP_POST_VARS['sid']) || !is_string($HTTP_POST_VARS['sid']) || !hash_equals((string) $userdata['session_id'], (string) $HTTP_POST_VARS['sid']))
	{
		message_die(GENERAL_ERROR, $lang['Not_Authorised']);
	}

	if (!is_scalar($HTTP_POST_VARS['rate']))
	{
		message_die(GENERAL_ERROR, $lang['bad_submitted_value']);
	}
	try { $rating_result = phpbb_arcade_rate_game($db, $userdata, $game_id, $HTTP_POST_VARS['rate']); }
	catch (Exception $error) { message_die(GENERAL_MESSAGE, phpbb_arcade_rating_message($error)); }
	catch (Error $error) { message_die(GENERAL_MESSAGE, phpbb_arcade_rating_message($error)); }
	$cat_id = $rating_result['cat_id'];
//
// Complete... send a message to user
//
	$template->assign_vars(array(
			'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid("activity.$phpEx?mode=cat&amp;cat_id=$cat_id") . '">')
	);

	$message = '<b>' . $lang['Stored'] . '</b>';
	$message .= "<br /><br />" . sprintf($lang['arcade_rate_return_cat'], "<a href=\"" . append_sid("activity.$phpEx?mode=cat&amp;cat_id=$cat_id") . "\">", "</a>");
	$message .= "<br /><br />" . sprintf($lang['arcade_rate_return_forum'], "<a href=\"" . append_sid("index.$phpEx") . "\">", "</a>");
	message_die(GENERAL_MESSAGE, $message);
}

?>
