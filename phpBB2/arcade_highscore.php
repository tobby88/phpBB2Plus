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

include_once($phpbb_root_path . 'extension.inc');
include_once($phpbb_root_path . 'common.'.$phpEx);
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
include_once($phpbb_root_path . 'includes/bbcode.' .$phpEx);

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_HIGHSCORE);
init_userprefs($userdata);
$page_title = $board_config['sitename'] . ' Monthly Highscores';
$user_id = $userdata['user_id'];
include($phpbb_root_path . 'includes/page_header.'.$phpEx);
$arcade_version = $arcade->arcade_config('version');
//
// End session management
//

$template->set_filenames(array(
	'body' => 'arcade_highscore_body.tpl')
);

if ( !empty($HTTP_POST_VARS['date']) || !empty($HTTP_GET_VARS['date']) )
{
	$date = ( !empty($HTTP_POST_VARS['date']) ) ? $HTTP_POST_VARS['date'] : $HTTP_GET_VARS['date'];
}
else
{
	$date = "0";
}

$curr_m = date(m);
$curr_y = date(Y);

if ($date >= $curr_m)
{
	$date = $date - $curr_m;
	if ($data == "0")
	{
		$highscore_date_y = "1";
	}
	else
	{
		$highscore_date_y = floor($date/12)+1;
	}
	$highscore_date_m = 12 - ($date - (($highscore_date_y - 1)*12));
	$highscore_date_y = $curr_y - $highscore_date_y;
}
else
{
    $highscore_date_m = $curr_m - $date;
    $highscore_date_y = $curr_y;
}

if ($highscore_date_m == "1")
{
  $highscore_date = $lang['highscore_jan']." ".$highscore_date_y;
}
elseif ($highscore_date_m == "2") {$highscore_date = $lang['highscore_feb']." ".$highscore_date_y;}
elseif ($highscore_date_m == "3") {$highscore_date = $lang['highscore_mar']." ".$highscore_date_y;}
elseif ($highscore_date_m == "4") {$highscore_date = $lang['highscore_apr']." ".$highscore_date_y;}
elseif ($highscore_date_m == "5") {$highscore_date = $lang['highscore_may']." ".$highscore_date_y;}
elseif ($highscore_date_m == "6") {$highscore_date = $lang['highscore_jun']." ".$highscore_date_y;}
elseif ($highscore_date_m == "7") {$highscore_date = $lang['highscore_jul']." ".$highscore_date_y;}
elseif ($highscore_date_m == "8") {$highscore_date = $lang['highscore_aug']." ".$highscore_date_y;}
elseif ($highscore_date_m == "9") {$highscore_date = $lang['highscore_sep']." ".$highscore_date_y;}
elseif ($highscore_date_m == "10") {$highscore_date = $lang['highscore_oct']." ".$highscore_date_y;}
elseif ($highscore_date_m == "11") {$highscore_date = $lang['highscore_nov']." ".$highscore_date_y;}
elseif ($highscore_date_m == "12") {$highscore_date = $lang['highscore_dec']." ".$highscore_date_y;}
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
$group_list = '0';
for ($group_count = 0; $group_count < count($group_ids); $group_count++)
{
   $group_list .= ', ' . $group_ids[$group_count]['group_id'];
}
$level_required = isset($userdata['user_level']) ? $userdata['user_level'] : 0;
$rank_required = isset($userdata['user_rank']) ? $userdata['user_rank'] : 0;
//
// Main query
//
$sql = "SELECT highscore_game, highscore_player, highscore_score, game_path, game_desc, game_id, image_path, win_width, win_height
		FROM ".iNA_HIGHSCORES .", ". iNA_GAMES ."
  		WHERE highscore_year = '".$highscore_date_y."'
  	 	AND highscore_mon = '".$highscore_date_m."'
  		AND highscore_game != ''
  		AND game_name = highscore_game
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
	$i++;

	if ($i == "1")
	{
  	$highscore_temp .= "<tr align=\"center\" valign=\"top\">\n";
	}
//
//  Get User Information (Group Membership, Rank and Level)
//  ready for the Activies Passing
//

	$highscore_game = $row['highscore_game'];
	$highscore_image = $row['image_path'];
	$highscore_player = $row['highscore_player'];
	$highscore_score  = $arcade->convert_score($row['highscore_score']) ? $arcade->convert_score($row['highscore_score']) : '';
  $row_bg_number = ($bgcounter++ % 2 == 0) ? 1 : 2;

	$image_path = ina_find_image($row['game_path'], $highscore_game, $row['image_path']);

  $highscore_game_pic = '<img src ="'. $image_path .'" border="0" alt="'.$row['game_desc'].'" align="middle" width="'.$arcade->arcade_config['games_image_width'].'" height="'.$arcade->arcade_config['games_image_height'].'" >';
  $highscore_temp .= "<td width=\"20%\" height=\"19\" class=\"row".$row_bg_number."\"> <div align=\"center\"><a href=\"javascript:Gk_PopTart('activity.php?mode=game&amp;id=".$row['game_id']. "', 'Game_Windows', ".$row['win_width'].", ".$row['win_height'].", 'no')\">".$highscore_game_pic."</a><br /><span class=\"gen\">$row[game_desc]</span><br /><b><span class=\"gen\">".$highscore_player." : ".$highscore_score."</span></b></div></td>\n";

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

if($HTTP_POST_VARS['cat_id'] > 0)
{
  $cat_id = intval($HTTP_POST_VARS['cat_id']);

  $sql = "SELECT * FROM " . iNA_CAT . "
        	WHERE cat_id = " . $cat_id;
  if(!$result = $db->sql_query($sql))
	{
	 	message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
	}
	$cat_info = $db->sql_fetchrow($result);
	$catagory_name = $cat_info['cat_name'];
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
			$sql .= " WHERE highscore_id = '" . $highscore_id . "'";
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