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
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_ARCADE_RATE);
init_userprefs($userdata);
//
// End session management
//
$game_id = (int) $arcade->pass_var('game_id', 0);
//
// Check User Logged in
//
if (!$userdata['session_logged_in'])
{
	redirect(append_sid("login.$phpEx?redirect=arcade_rate.$phpEx?game_id=$game_id"));
}
//
// Initialize Arcade Config and Check feature enabled
//
if( $arcade->arcade_config('games_rate') == 0 )
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
// Check for the request game_id
//
if($game_id < 1)
{
	message_die(GENERAL_ERROR, $lang['no_activity']);
}
//
// Get the Activity info
//
$sql = "SELECT c.*, g.*, u.user_id, u.username, r.rate_game_name, count(r.rate_user_id) AS total, AVG(r.rate_point) AS rating
		FROM ". iNA_GAMES ." AS g
			LEFT JOIN ". iNA_CAT ." AS c ON c.cat_id = g.cat_id
			LEFT JOIN ". iNA_GAMES_RATE ." AS r ON g.game_name = r.rate_game_name
			LEFT JOIN ". USERS_TABLE ." AS u ON r.rate_user_id = u.user_id
		WHERE g.game_id = " . (int) $game_id . "
		GROUP BY g.game_id";
if( !($result = $db->sql_query($sql)) )
{
	message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
}
$thisgame = $db->sql_fetchrow($result);
if( empty($thisgame) )
{
	message_die(GENERAL_ERROR, $lang['does_not_exist']);
}
$rated_total = isset($thisgame['total']) ? $thisgame['total'] : 0;
$cat_id     = (int) $thisgame['cat_id'];
$game_name  = $thisgame['game_name'];
$game_name_sql = $db->sql_escape($game_name);
//
//  Additional Check: if this user already rated
//
$sql = "SELECT *
		FROM ". iNA_GAMES_RATE ."
		WHERE rate_game_name = '$game_name_sql'
			AND rate_user_id = ". (int) $userdata['user_id'] ."
		LIMIT 0,1";
if( !$result = $db->sql_query($sql) )
{
	message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
}
if ($db->sql_numrows($result) > 0)
{
	$already_rated = TRUE;
}
else
{
	$already_rated = FALSE;
}

if( !isset($HTTP_POST_VARS['rate']) )
{
//
// Rate Scale
//
	if (!$already_rated)
	{
		for ($i = 0; $i < $arcade->arcade_config['games_default_rate']; $i++)
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
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($HTTP_POST_VARS['sid']) || is_array($HTTP_POST_VARS['sid']) || !hash_equals((string) $userdata['session_id'], (string) $HTTP_POST_VARS['sid']))
	{
		message_die(GENERAL_ERROR, $lang['Not_Authorised']);
	}

	if (is_array($HTTP_POST_VARS['rate']))
	{
		message_die(GENERAL_ERROR, $lang['bad_submitted_value']);
	}
	$rate_point = intval($HTTP_POST_VARS['rate']);

	if( ($rate_point <= 0) or ($rate_point > $arcade->arcade_config['games_default_rate']) )
	{
		message_die(GENERAL_ERROR, $lang['bad_submitted_value']);
	}

	$rate_user_id = $userdata['user_id'];
	$rate_user_ip = $db->sql_escape($userdata['session_ip']);
//
// Check if this user already rated
//
	if ($already_rated)
	{
		message_die(GENERAL_ERROR, $lang['already_rated']);
	}
//
// Insert into the DB
//
	$sql = "INSERT INTO ". iNA_GAMES_RATE ." (rate_game_name, rate_user_id, rate_user_ip, rate_point)
			SELECT '$game_name_sql', ". (int) $rate_user_id .", '$rate_user_ip', $rate_point
			FROM DUAL
			WHERE NOT EXISTS (
				SELECT 1 FROM ". iNA_GAMES_RATE ."
				WHERE rate_game_name = '$game_name_sql'
					AND rate_user_id = ". (int) $rate_user_id ."
			)";

	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_rate_update'], '', __LINE__, __FILE__, $sql);
	}
	if (!$db->sql_affectedrows())
	{
		message_die(GENERAL_ERROR, $lang['already_rated']);
	}
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
