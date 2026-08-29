<?php
 /****************************************************************************
  *
  *                            arcade_highscore.php
  *                            ----------------------
  *   begin                : Sunday, January 29, 2006
  *   copyright            : (c) 2006 Painkiller
  *   email                : painkiller@runequake.com
  *
  *   $Id: arcade_highscore.php,v 2.0.13 2006/01/29 Painkiller Exp $
  *   Support @ http://deadzone.runecentral.com/forums/
  *   v 2.0.0 2006/01/29  23:00:35 Painkiller
  *
  ****************************************************************************
  *
  *   This program is free software; you can redistribute it and/or modify
  *   it under the terms of the GNU General Public License as published by
  *   the Free Software Foundation; either version 2 of the License, or
  *   (at your option) any later version.
  *
  ****************************************************************************
  *
  *   This is a MOD for phpbb v2.0.x + and the Activity/Arcade Mod v2.0.x +
  *   through v2.1.0.  The phpbb group has all rights to the phpbb source.
  *   They can be contacted at :
  *
  *      I-Net : www.phpbb.com
  *      E-Mail: support@phpbb.com
  *
  ****************************************************************************
  * 	CREDITS:
  *  dEfEndEr - phpBB Activity / Arcade Mod © 2006 - v2.0.1 through v2.1.2
  *  -  Support: http://www.phpbb-arcade.com
  *  -    Email: < support@phpbb-arcade.com >
  *  Napoleon - Original Activity Mod v2.0.0
  ****************************************************************************/

define('IN_PHPBB', true);
$phpbb_root_path = './';
$filename = basename(__FILE__);

include_once($phpbb_root_path . 'extension.inc');
include_once($phpbb_root_path . 'common.'.$phpEx);
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
include_once($phpbb_root_path . 'includes/bbcode.' .$phpEx);

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_HIGHSCORE);
init_userprefs($userdata);
$page_title = $board_config['sitename'] . ' - ' . $lang['highscore_table_header'];
$user_id = $userdata['user_id'];
include($phpbb_root_path . 'includes/page_header.'.$phpEx);
$arcade_version = $arcade->arcade_config('version');
//
// End session management
//

$template->set_filenames(array(
	'body' => 'arcade_highscore_body.tpl')
);

$requested_month = !empty($HTTP_POST_VARS['month']) ? $HTTP_POST_VARS['month'] : (!empty($HTTP_GET_VARS['month']) ? $HTTP_GET_VARS['month'] : '');
$legacy_date = !empty($HTTP_POST_VARS['date']) ? $HTTP_POST_VARS['date'] : (!empty($HTTP_GET_VARS['date']) ? $HTTP_GET_VARS['date'] : 0);
if (is_array($requested_month) || is_array($legacy_date))
{
	message_die(GENERAL_ERROR, $lang['Not_Authorised']);
}

$cat_id = 0;
$start = 0;
$sort_mode = '';
$order = '';
$highscore_id = 0;

if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', (string) $requested_month, $month_match))
{
	$highscore_date_y = (int) $month_match[1];
	$highscore_date_m = (int) $month_match[2];
}
else
{
	$months_ago = max(0, min(120, (int) $legacy_date));
	$month_timestamp = mktime(12, 0, 0, (int) date('n') - $months_ago, 1, (int) date('Y'));
	$highscore_date_m = (int) date('n', $month_timestamp);
	$highscore_date_y = (int) date('Y', $month_timestamp);
}

$highscore_month_names = array($lang['highscore_jan'], $lang['highscore_feb'], $lang['highscore_mar'], $lang['highscore_apr'], $lang['highscore_may'], $lang['highscore_jun'], $lang['highscore_jul'], $lang['highscore_aug'], $lang['highscore_sep'], $lang['highscore_oct'], $lang['highscore_nov'], $lang['highscore_dec']);
$highscore_date = $highscore_month_names[$highscore_date_m - 1] . ' ' . $highscore_date_y;
//
//  Get User Information (Group Membership, Rank and Level)
//  ready for the Activies Passing
//
$sql = "SELECT g.group_id
	FROM " . GROUPS_TABLE . " g, " . USER_GROUP_TABLE . " ug
   	WHERE ug.user_id = " . $userdata['user_id'] . "
	    AND ug.group_id = g.group_id
			AND ug.user_pending = 0
			AND g.group_single_user <> " . TRUE . "
 			ORDER BY g.group_name, ug.user_id";
if ( !($result = $db->sql_query($sql)) )
{
	message_die(GENERAL_ERROR, 'Error getting group information', '', __LINE__, __FILE__, $sql);
}
$group_ids = $db->sql_fetchrowset($result);
//
//  Build a list of Groups that this user is a member of (add Group Zero)
//
$group_list = array(0);
for ($group_count = 0; $group_count < count($group_ids); $group_count++)
{
	$group_list[] = (int) $group_ids[$group_count]['group_id'];
}
$level_required = isset($userdata['user_level']) ? $userdata['user_level'] : 0;
$rank_required = isset($userdata['user_rank']) ? $userdata['user_rank'] : 0;
//
// Main query
//
$sql = "SELECT highscore_game, highscore_player, highscore_score, game_path, game_desc, game_id, image_path, win_width, win_height, allow_guest, level_required, rank_required, group_required
		FROM ".iNA_HIGHSCORES .", ". iNA_GAMES ."
		WHERE highscore_year = ". (int) $highscore_date_y ."
		AND highscore_mon = ". (int) $highscore_date_m ."
		AND highscore_game != ''
		AND game_name = highscore_game
		AND game_avail = 1
		ORDER BY highscore_score DESC LIMIT 0,60";
if( !$result = $db->sql_query($sql) )
{
	message_die(GENERAL_ERROR, $lang['highscore_table_error'], "", __LINE__, __FILE__, $sql);
}
$i = "0";
$bgcounter = 0;
$highscore_temp = '';

while($row = $db->sql_fetchrow($result))
{
	$required_group = (int) $row['group_required'];
	if (($userdata['user_id'] == ANONYMOUS && !(int) $row['allow_guest']) ||
		(int) $row['level_required'] > (int) $level_required ||
		(int) $row['rank_required'] > (int) $rank_required ||
		!in_array($required_group, $group_list, true))
	{
		continue;
	}

	$i++;

	if ($i == "1")
	{
  	$highscore_temp .= "<tr align=\"center\" valign=\"top\">\n";
	}
//
//  Get User Information (Group Membership, Rank and Level)
//  ready for the Activies Passing
//

	$highscore_game = (string) $row['highscore_game'];
	$highscore_player = phpbb_profile_text($row['highscore_player']);
	$converted_score = $arcade->convert_score($row['highscore_score']);
	$highscore_score = ($converted_score !== false && $converted_score !== null) ? htmlspecialchars((string) $converted_score, ENT_QUOTES, 'UTF-8') : '';
  $row_bg_number = ($bgcounter++ % 2 == 0) ? 1 : 2;

	$image_path = ina_find_image($row['game_path'], $highscore_game, $row['image_path']);
	$game_desc = phpbb_profile_text($row['game_desc']);
	$image_width = max(1, min(500, (int) $arcade->arcade_config['games_image_width']));
	$image_height = max(1, min(500, (int) $arcade->arcade_config['games_image_height']));
	$window_width = max(200, min(1920, (int) $row['win_width']));
	$window_height = max(150, min(1200, (int) $row['win_height']));
	$highscore_game_pic = $image_path ? '<img src="' . htmlspecialchars($image_path, ENT_QUOTES, 'UTF-8') . '" border="0" alt="' . $game_desc . '" align="middle" width="' . $image_width . '" height="' . $image_height . '" />' : '';
	$game_url = append_sid("activity.$phpEx?mode=game&amp;id=" . (int) $row['game_id']);
	$highscore_temp .= '<td width="20%" height="19" class="row' . $row_bg_number . '"><div align="center"><a href="' . $game_url . '" onclick="Gk_PopTart(\'' . $game_url . '\', \'Game_Windows\', ' . $window_width . ', ' . $window_height . ', \'no\'); return false;">' . $highscore_game_pic . '</a><br /><span class="gen">' . $game_desc . '</span><br /><b><span class="gen">' . $highscore_player . ' : ' . $highscore_score . "</span></b></div></td>\n";

// Set output to be 5 rows across
	if ($i == "5")
	{
  	$highscore_temp .= "</tr>\n";
  	$i = "0";
	}
}

if ($i > "0")
{
  $leftover = (5 - $i);
  if ($leftover > 0)
  {
  	for ($j = 0; $j < $leftover; $j++)
  	{
			$row_bg_number = ($bgcounter++ % 2 == 0) ? 1 : 2;
			$highscore_temp .= "<td width=\"20%\" height=\"19\" class=\"row".$row_bg_number."\">&nbsp;</td>\n";
	  }
		$highscore_temp .= "</tr>\n";
	}
}

if ($bgcounter == "0")
{
	$highscore_temp = "<tr align=\"center\" valign=\"top\">\n<td class=\"row1\">".$lang['highscore_no_score']."</td></tr>";
}

if(!empty($HTTP_POST_VARS['cat_id']) && !is_array($HTTP_POST_VARS['cat_id']))
{
  $cat_id = intval($HTTP_POST_VARS['cat_id']);

  $sql = "SELECT * FROM " . iNA_CAT . "
        	WHERE cat_id = " . $cat_id;
  if(!$result = $db->sql_query($sql))
	{
	 	message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
	}
	$cat_info = $db->sql_fetchrow($result);
	$catagory_name = $cat_info ? phpbb_profile_text($cat_info['cat_name']) : $lang['all_games'];
}
else
{
  $catagory_name = $lang['all_games'];
}

// Display Board Index and Games Category navigation at the bottom of the Monthly Highscore page
$url = ' &raquo; <a href="activity.' . $phpEx . '" class="nav">' . $lang['games_catagories'] . '</a> &raquo; <a href="activity.' . $phpEx . '?mode=cat&amp;cat_id=' . $cat_id . '&amp;start=' . $start . '&amp;sort_mode=' . $sort_mode . '&amp;order=' . $order . '" class="nav">'.$catagory_name.'</a>';

if($arcade->arcade_config['games_per_page'] > 0)
{
		$sql = "SELECT count(*) AS total FROM " . iNA_HIGHSCORES;
		if($highscore_id > 0)
		{
			$sql .= " WHERE highscore_id = " . (int) $highscore_id;
		}
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, $lang['no_game_total'], '', __LINE__, __FILE__, $sql);
		}
		$total = $db->sql_fetchrow($result);
		$total_highscores = $total['total'];
}

$template->assign_vars(array(
		'HIGHSCORE_INPUT' => highscore_jump_box(),
		'HIGHSCORE_TEMP' => $highscore_temp,
		'HIGHSCORE_DATE' => $lang['highscore_table_header']." ".$highscore_date,
		'U_CAT' => $url,
		'S_HIDDEN_OPTIONS' => '<input type="hidden" name="redirect" value="' . $filename . '">',
		'ARCADE_MOD' => sprintf($lang['activitiy_mod_info'], $arcade->version() )));

//
// Generate the page
//
$template->pparse('body');
		 echo "<br /><center><font face='Arial' size='-2'>Arcade Highscore Mod by Painkiller</font></center>";

include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>
