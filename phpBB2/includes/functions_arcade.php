<?php
/***************************************************************************
 *                           functions_arcade.php
 *                           --------------------
 *   begin                : Tuesday, Jan 2nd, 2007
 *   copyright            : (C) 2003-2007 phpbb_arcade.com
 *   email                : defenders_realm@yahoo.com
 *
 *   $Id: functions_arcade.php, v2.1.8 2007/01/02 20:46:00 dEfEndEr Exp $
 *
 ***************************************************************************
 *
 *   These functions are apart of a free software package; you can redistribute
 *   them and/or modify them under the terms of the GNU General Public License as
 *   published by the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************
 * 	CREDITS: 
 *  Whoo - Games and part code
 *  Napoleon - Original Activity Mod v2.0.0
 *  Minesh - Add-On's
 *  Mark - All his Support and Mods
 *  PainKiller - _ditto_
 *  Buddystuart - Games
 *  ~Maverick~ - Add-On's
 *  Zorial - Add-On's
 *  qx17417 - beta testing
 *  Madman - Chief Tester :)
 ***************************************************************************/

if ( !defined('IN_PHPBB') || !empty($_GET['phpbb_root_path']))
{
	die("Hacking attempt");
}

if ( defined('ARCADE_FUNCTIONS') )
{
	return;
}

define('ARCADE_FUNCTIONS',217);

include_once($phpbb_root_path . 'includes/constants_arcade.' . $phpEx);
if (!(isset($language)))
{
	$language = $board_config['default_lang'];
}
if( !file_exists($phpbb_root_path . 'language/lang_' . $language . '/lang_extend_arcade.'.$phpEx) )
{     
	$language = 'english';
}
if(defined('ARCADE_ADMIN'))
{
  include_once($phpbb_root_path . 'language/lang_' . $language . '/lang_admin_arcade.' . $phpEx);
}
include_once($phpbb_root_path . 'language/lang_' . $language . '/lang_extend_arcade.' . $phpEx);
include_once($phpbb_root_path . 'includes/classes_arcade.' . $phpEx);
//
//  Sort Modes
//
$mode_types_text = array('', $lang['allow_guests'], $lang['date_added'], $lang['rating'], $lang['Comments'], $lang['alphabetically'], $lang['game_instructions'], $lang['game_cost'], $lang['game_bonuses'], $lang['game_played']);
$mode_types = array('default', 'allow_guest', 'date_added', 'rating', 'comments', 'alphabetical', 'game_instructions', 'game_charge', 'game_bonus', 'game_played');

function best_players_list($type = 'first_places', $extra = 'LIMIT 0,10')
{	global $db, $lang;
	$best_players_list = '';
	$sql = "SELECT u.user_id, u.username, a.$type FROM " . USERS_TABLE . " as u,
    LEFT JOIN " . iNA_USER_DATA . " as a
		WHERE u.user_id = a.user_id
		AND $type > 0
		ORDER by $type DESC, last_won_date $extra";
	if(!$user = $db->sql_query($sql)) 
	{
		message_die(GENERAL_ERROR, $lang['no_user_data'], "", __LINE__, __FILE__, $sql); 
	}
	$users_count = $db->sql_numrows($user);
	$users_rows = $db->sql_fetchrowset($user);
	for($i = 0; $i < $users_count; $i++)
	{
		$best_players_list .= '<b>' . $users_rows[$i][$type] . ' &raquo;</b> <a href="' . append_sid("profile.php?mode=viewprofile&u=$users_rows[$i]['user_id']") . '">' . $users_rows[$i]['username'] . '</a><br />';
	}
	return $best_players_list;
}

function best_player($type = 'first_places')
{
	global $db, $lang, $arcade, $phpEx;

  if ($type == 'first_places')
  {
    $best_player = $arcade->read_cache('best_player', $arcade->arcade_config['highscore_cache']);
  }
  else
  {
    $best_player = $arcade->read_cache('best_at_player', $arcade->arcade_config['at_highscore_cache']);
  }
  if (!$best_player)
  {
  	$sql = "SELECT u.user_id, u.username, a.$type FROM " . USERS_TABLE . " as u, " . iNA_USER_DATA . " as a
  		WHERE u.user_id = a.user_id
     	ORDER by $type DESC, last_won_date LIMIT 0,1";
  	if(!$user = $db->sql_query($sql)) 
  	{
  		message_die(GENERAL_ERROR, $lang['no_user_data'], "", __LINE__, __FILE__, $sql); 
  	}
  	$user_row = $db->sql_fetchrow($user);

  	if ($type == 'first_places')
  	{
      $best_player = sprintf($lang['games_best_player'] , append_sid("profile.$phpEx?mode=viewprofile&u=".$user_row['user_id']), $user_row['username'], append_sid("activity.$phpEx?mode=game_stats&amp;user_id=".$user_row['user_id']."&amp;id=4"), $user_row[$type]);
      $arcade->write_cache('best_player', $best_player);
  	}
    else
    {
      $best_player = sprintf($lang['games_best_at_player'] , append_sid("profile.$phpEx?mode=viewprofile&u=".$user_row['user_id']), $user_row['username'], append_sid("activity.$phpEx?mode=game_stats&amp;user_id=".$user_row['user_id']."&id=1"), $user_row[$type]);
      $arcade->write_cache('best_at_player', $best_player);
    }
  }
	return $best_player;
}
//
//  Get the Last game this user played
//
function last_played($user_id)
{	
  global $db, $lang;
  
	$sql = "SELECT g.game_desc FROM " . iNA_GAMES . " AS g, " . iNA_USER_DATA . " AS u
		WHERE user_id = '" . $user_id . "'
		AND g.game_name = u.last_played";
	if(!$user = $db->sql_query($sql)) 
	{
		message_die(GENERAL_ERROR, $lang['no_user_data'], "", __LINE__, __FILE__, $sql); 
	}
	$user_row = $db->sql_fetchrow($user);
	if ($user_row)
	{
		return sprintf($lang['games_last_u_viewed'], $user_row['game_desc']);
	}
	else
	{
		return NULL;
	}
}
//
//  Get the last Played game Score
//
function last_played_score()
{
  global $db, $lang, $arcade;
  $last_sql = "SELECT s.score, u.username, g.game_desc FROM " . iNA_SCORES . " s 
    LEFT JOIN " . iNA_GAMES . " g ON s.game_name = g.game_name 
    LEFT JOIN " . USERS_TABLE . " u ON u.user_id = s.player_id 
  ORDER BY date DESC LIMIT 0,1";
  if ( !($result = $db->sql_query($last_sql)) )
  {
    return '<br />ERROR<br />' . $sql;
  }
	$last_info = $db->sql_fetchrow($result);
	return $last_info ? sprintf($lang['games_last_score_gained'], $last_info['username'], $arcade->convert_score($last_info['score']), $last_info['game_desc']) : '';
}

function games_position($games_place)
{	global $games_position_text;
	$place_text = '';

	if($games_place > 3)
	{
		if($games_place < 21)
		{
			$place_text = $games_place . $games_position_text[4];
		}
		else
		{
			$place_text = $games_position_text[0];
			
		}
	}
	else
	{
		$place_text = $games_place . $games_position_text[$games_place];
	}
	
	return $place_text;
}

function update_ina_session($user_id, $user_ip, $page, $game, $old_hash = FALSE, $win = "NORM", $tour_id = 0)
{	global $db, $userdata, $lang, $board_config;

	$start_time		= time();
	$string			= sprintf("ARCADE_MOD %s %s %s %d %d %d", $game, $board_config['sitename'], $user_ip, $page, $user_id, $userdata['session_ip']);
	$arcade_hash	= md5($string);
	//
	// The next two lines are used only for the human readable part of the MCP and can be commented out if they cause problems
	// (some sites have been set-up on a private network and do not use TCP/IP)
	//
  $ip_num				= decode_ip($userdata['session_ip']); 
  $ip_nam				= @gethostbyaddr($ip_num); 
	
	$sql = "UPDATE " . iNA_SESSIONS . "
		SET start_time = '$start_time', page = '$page', game_name = '$game', arcade_hash = '$arcade_hash', user_win = '$win', tour_id = $tour_id";
	if($old_hash != FALSE)
	{
		$sql .= " WHERE arcade_hash = '$old_hash'";
	}
	else
	{
		$sql .= " WHERE user_id = '$user_id'";
		if($user_id == ANONYMOUS)
		{
			$sql .= " AND session_ip = '$user_ip'";
		}
	}
	if(!$db->sql_query($sql))
  {
    message_die(GENERAL_ERROR, $lang['session_error'], '', __LINE__, __FILE__, $sql);
  } 
	if ( !$db->sql_affectedrows() )
	{
		$sql = "INSERT INTO " . iNA_SESSIONS . "
			(game_name, arcade_hash, user_id, start_time, session_ip, page, user_ip, ip_name, tour_id)
			VALUES ('$game', '$arcade_hash', '$user_id', '$start_time', '$user_ip', '$page', '$ip_num', '$ip_nam', $tour_id)";
		if ( !$db->sql_query($sql) )
		{
			message_die(CRITICAL_ERROR, $lang['session_error'], '', __LINE__, __FILE__, $sql);
		}
	}
	return $arcade_hash;
}

function user_fav_list($user_id, $number)
{	global $db;

	$sql = "SELECT g.game_desc FROM " . iNA_FAV . " f, " . iNA_GAMES . " g
		WHERE f.user_id = '" . $user_id . "'
			AND f.game_id = g.game_id
			ORDER BY g.desc ASC
			LIMIT 0,$number";
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
	}
	while($fav_games = $db->sql_fetchrow($result))
	{
		$fav_list .= $fav_games['game_desc'] . '<br />';
	}
	return $fav_list;
}

function games_list($mode, $number, $cat_id, $option = 'played', $image_path = FALSE)
{	
  global $db, $phpEx, $SID, $userdata, $board_config, $arcade;

	if (empty($mode))
	{
		$mode = 'DESC';
	}
	if (empty($option))
	{
		$option = 'played';
	}
	$number = intval($number);
	$cat_id = intval($cat_id);

  $level_required = isset($userdata['user_level']) ? $userdata['user_level'] : 0;
  $rank_required = isset($userdata['user_rank']) ? $userdata['user_rank'] : 0;
  
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
  $group_list = array('0');
  for ($group_count = 0; $group_count < count($group_ids); $group_count++)
  {
    $group_list[$group_count+1] = $group_ids[$group_count]['group_id'];
  }
  unset($group_ids);

	$sql = "SELECT * FROM " . iNA_GAMES . "
		WHERE game_id <> 0
		AND game_avail = 1";
		if($cat_id > 0)
		{
			$sql .= " AND cat_id = '$cat_id'";
		}
		$sql .= " ORDER BY $option $mode
		LIMIT 0,$number";
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
	}
	while($top_games = $db->sql_fetchrow($result))
	{
		if($top_games['game_flash'] && $arcade->arcade_config['games_auto_size'] && !$top_games['game_autosize'])
		{
			$game_name = $top_games['game_name'] . '.swf';
			$game_size = @getimagesize($top_games['game_path'].$game_name); 
			$width = $game_size[0] + 20; 
			$height = $game_size[1] + 25; 
		}
		else
		{
			$width = $top_games['win_width'] + 20;
			$height = $top_games['win_height'] + 25; 
		}
		if($image_path != FALSE)
		{
			$image_path = $top_games['image_path'];
			if ( $image_path == '' )
			{
				if( file_exists("./". $top_games['game_path'] ."/". $top_games['game_name'] .".gif") )
				{
					$image_path = './' . $top_games['game_path'] . $top_games['game_name'] . '.gif';
				}
				else
				{
					$image_path = $arcade->arcade_config['games_default_img'];
				}
			}
			else if ( strlen( $image_path ) < 5 )
			{
				$image_path = './' . $top_games['game_path'] . $top_games['game_name'] . $top_games['image_path'];
			}
			if ( @file_exists($image_path ) && ($userdata['user_id'] == ANONYMOUS && $top_games['allow_guest'] == 0) || ( intval($userdata['user_posts']) < intval($board_config['games_posts_required']) && $top_games['allow_guest'] == 0) || (intval($userdata['user_rank']) < intval($arcade->arcade_config['games_rank_required']) && $top_games['allow_guest'] == 0) && $userdata['user_level'] != ADMIN )
			{
				$games_list .= "<img src=\"". $image_path ."\" height=\"25\" width=\"25\" border=\"0\"> " . $top_games['game_desc'] . '<br />';
			}
			else if (@file_exists($image_path ))
			{
        $games_list .= "<a href=\"". append_sid("activity.$phpEx?mode=game&amp;id=".$top_games['game_id']) ."\" class=\"gensmall\" onClick=\"Gk_PopTart('activity.$phpEx?mode=game&amp;id=" . $top_games['game_id'] . "$SID', 'New_Window', '$width', '$height', 'no'); return false; blur()\"><img src=\"". $image_path ."\" height=\"25\" width=\"25\" border=\"0\"> " . $top_games['game_desc'] . "</a><br />";
			}
			else
			{
        $games_list .= "<a href=\"". append_sid("activity.$phpEx?mode=game&amp;id=".$top_games['game_id']) ."\" class=\"gensmall\" onClick=\"Gk_PopTart('activity.$phpEx?mode=game&amp;id=" . $top_games['game_id'] . "$SID', 'New_Window', '$width', '$height', 'no'); return false; blur()\"> " . $top_games['game_desc'] . "</a><br />";
			}
		}
		else
		{
		  if(($userdata['user_id'] == ANONYMOUS && $top_games['allow_guest'] == 0) || $top_games['level_required'] > $level_required  || $top_games['rank_required'] > $rank_required || in_array($top_games['group_required'], $group_list) == FALSE)
		  {
        $games_list .= $top_games['game_desc'] . '<br />';
      }
      else
      {
			 $games_list .= "<a href=\"". append_sid("activity.$phpEx?mode=game&amp;id=".$top_games['game_id']) ."\" class=\"gensmall\" onClick=\"Gk_PopTart('activity.$phpEx?mode=game&amp;id=" . $top_games['game_id'] . "$SID', 'New_Window', '$width', '$height', 'no'); return false; blur()\"> " . $top_games['game_desc'] . "</a><br />";
			}
		}
	}
	return $games_list;
}

function best_game_player($tablename, $gamename, $type)
{	global $db;

	$sql = "SELECT s.player_id, s.score, u.username, u.user_allow_viewonline FROM " . $tablename . " s, " . USERS_TABLE . " u
		WHERE s.player_id = u.user_id
		AND game_name = '" . $gamename . "'
		ORDER BY score $type, date ASC";
	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql);
	}
	$score_info = $db->sql_fetchrow($result);
	if ($score_info['score'] > 0)
	{
		return $score_info;
	}
	else
	{
		return NULL;
	}
}

function get_games_total($data, $extra = '')
{	global $db;

	$sql = "SELECT $data AS total
		FROM " . iNA_GAMES . "
		WHERE game_id <> 0
		AND game_avail = 1";
	if($extra)
	{
		$sql .= " AND $extra";
	}
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_total'], '', __LINE__, __FILE__, $sql);
	}
	$total = $db->sql_fetchrow($result);

	return $total['total'];
}

function total_highscores($player_id, $table = iNA_SCORES)
{	
  global $db, $lang, $arcade;

	$player[0] = $player_id;
	$player[1] = $player[2] = $player[3] = 0;
	$player[4] = $player[5] = $player[6] = '';

  $rows = $arcade->read_games();

	for($i=0; $i < count($rows); $i++)
	{
    if(($rows[$i]['game_avail'] != 1) || ($rows[$i]['game_show_score'] != 1))
    {
      continue;
    }
    $place = 1;
    $sort = $rows[$i]['reverse_list'] ? 'ASC' : 'DESC';

    $sql = "SELECT player_id, score FROM " . $table . "
      WHERE game_name = '".$rows[$i]['game_name']."'
        ORDER BY score " . $sort . "
        LIMIT 0,3";
    $best_score = $db->sql_query($sql);
  	while($scorerow = $db->sql_fetchrow($best_score))
    {
  		if($scorerow['player_id'] == $player_id)
  		{
				$player[$place]++;
				$player[$place+3] .= $rows[$i]['game_name'].'|';
      }
      $place++;
    }
	}
  unset($rows);

  if($table == iNA_SCORES)
  {
    $sql = "UPDATE " . iNA_USER_DATA . "
       SET first_places = $player[1], second_places = $player[2], third_places = $player[3],
         first_list = '$player[4]', second_list = '$player[5]', third_list = '$player[6]'
      WHERE user_id = $player_id";
	}
	else
	{
    $sql = "UPDATE " . iNA_USER_DATA . " 
      SET at_first_places = $player[1], at_second_places = $player[2], at_third_places = $player[3],
         at_first_list = '$player[4]', at_second_list = '$player[5]', at_third_list = '$player[6]'
      WHERE user_id = $player_id";
  
  }
  $db->sql_query($sql);

 	return $player;
}

function get_ina_extension($name)
{
	$extension		= strrchr(strtolower($name), '.');
	$extension[0]	= ' ';
	$extension		= strtolower(trim($extension));
	
	return $extension;
}

function ina_ban_user($game, $users_ip, $user_id, $username, $score)
{	global $db,$board_config;

	$currdate = create_date('d-m-Y', time(), $board_config['board_timezone']) ;

	$sql = "INSERT INTO ". iNA_BANNED . " (username, user_id, player_ip, game, score, date)
		VALUES ('$username', '$user_id', '$users_ip', '$game', '$score', '$currdate')";
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_total'], '', __LINE__, __FILE__, $sql);
	}
//  $db->sql_freeresult($result);   
}

function check_ina_user($users_ip, $user_id, $username)
{	global $db;

	$sql = "SELECT* FROM ". iNA_BANNED . "
		WHERE $user_id = '$user_id'";
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_total'], '', __LINE__, __FILE__, $sql);
	}
	$row = $db->sql_fetchrow($result);
	if($row)
	{
		return TRUE;
	}
	else
	{
		return FALSE;
	}
}

function check_ina_game($game_name)
{	global $db;

	if($game_name)
	{
		$old_game_sql = "SELECT game_id, game_name FROM " . iNA_GAMES . "
			WHERE game_name = '" . $game_name . "'";
		if( !$old_game_result = $db->sql_query($old_game_sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_read_game_data'], "", __LINE__, __FILE__, $sql);
		}
		$old_game = $db->sql_fetchrow($old_game_result);

		if( $old_game['game_name'] == $game_name )
		{
			return $old_game['game_id'];
		}
	}
	return FALSE;
}

function insert_ina_game($game_name, $game_path, $reverse_list = 0, $game_desc = '', $game_flash = 1, $game_avail = 0, $win_width = 0, $win_height = 0, $cat_id = -1)
{	
  if ( CH_CURRENT_VERSION >= '2.1.6' )
  {
    global $db, $phpbb_root_path, $arcade, $config, $user, $forums, $censored_words, $icons, $navigation, $themes, $smilies;
  }
  else
  {
    global $db, $phpbb_root_path, $arcade;
  }
	if($win_width ==0 && $game_flash == 1)
	{
		$game_size = @getimagesize($phpbb_root_path.$game_path.'/'.$game_name.'.swf'); 
		$win_width = $game_size[0];
		$win_height = $game_size[1];
	}
	if($cat_id == 0)
	{
    $cat_id = -1;
  }
	if($game_desc == '')
	{
		$game_desc = trim(str_replace("_", " ", $game_name));
		$game_desc[0] = strtoupper($game_desc[0]);
	}
	$inst_sql = "INSERT INTO " . iNA_GAMES . "
		(cat_id, date_added, game_name, game_path, game_desc, win_width, win_height, reverse_list , game_flash, game_avail)
			VALUES ('".$cat_id."', '" . (time()) . "', '$game_name', '$game_path', '$game_desc', '$win_width', '$win_height', '$rev', $game_flash, $game_avail)";
	if( !$inst_result = $db->sql_query($inst_sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_read_game_data'], "", __LINE__, __FILE__, $inst_sql);
	}
	if($game_avail == 1)
	{
  	$inst_sql = "UPDATE " . iNA_CAT . "
  		SET total_games = total_games+1
  			WHERE cat_id = '".$cat_id."'";
  	if( !$inst_result = $db->sql_query($inst_sql) )
  	{
  		message_die(GENERAL_ERROR, $lang['no_read_game_data'], "", __LINE__, __FILE__, $inst_sql);
  	}
    if($cat_id > 0)
    {
    	$inst_sql = "UPDATE " . iNA_CAT . "
    		SET total_games = total_games+1
    			WHERE cat_id = -1";
    	if( !$inst_result = $db->sql_query($inst_sql) )
    	{
    		message_die(GENERAL_ERROR, $lang['no_read_game_data'], "", __LINE__, __FILE__, $inst_sql);
    	}
    }
  }
//
//  Send users a PM to introduce the NEW GAME :)
//
  if($arcade->arcade_config('games_pm_new'))
  {
    $message = sprintf($lang['games_new_game_added'], $game_desc );
    ina_send_user_pm($old_id, $lang['games_new_game_added_info'], $message, $new_id);
  }
}

function get_total_tour()
{	global $db, $arcade;

	if (!($arcade->arcade_config['games_tournament_mode']))
	{
    return;
  }

	$sql = "SELECT count(*) as total FROM " . iNA_TOUR . "
    WHERE tour_active <> 3
    AND tour_active <> 0";
	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql);
	}
	$row = $db->sql_fetchrow($result);
	$total_tournaments = $row['total'];

	return $total_tournaments;
}

function game_list_tour()
{	global $db, $SID, $phpEx, $arcade;

	if (!($arcade->arcade_config['games_tournament_mode']))
	{
    return;
  }

	$tour_list = '<br />';

	$sql = "SELECT * FROM " . iNA_TOUR . "
    WHERE tour_active <> 3
    AND tour_active <> 0";
	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['no_tour_data'], "", __LINE__, __FILE__, $sql);
	}
	$rows = $db->sql_fetchrowset($result);
	for($i = 0; $i < (count($rows)); $i++)
	{
  	$tour_list .= "&nbsp;&nbsp;&nbsp;[<a href=\"arcade_tournament.".$phpEx."?mode=tour&amp;id=" . $rows[$i]['tour_id'] . "$SID\" class=\"gensmall\">" . $rows[$i]['tour_name'] . "</a>]&nbsp;-&nbsp;<i>".$rows[$i]['tour_desc']."</i><br />";
  }

	return $tour_list;
}

function game_get_last_tour()
{ global $db, $SID, $phpEx, $arcade;

	if (!($arcade->arcade_config['games_tournament_mode']))
	{
    return;
  }
	$sql = "SELECT * FROM " . iNA_TOUR_PLAY . " as p
    LEFT JOIN " . iNA_TOUR . " as t ON p.tour_id = t.tour_id
      ORDER BY p.last_played_time DESC LIMIT 0,1";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
	$last_tour = $db->sql_fetchrow($result);
  if($last_tour)
  {
    return $last_tour;
  }
  else
  {
    return FALSE;
  }
}

function game_tour_played()
{ global $db, $lang, $arcade;
  
	if (!($arcade->arcade_config['games_tournament_mode']))
	{
    return;
  }

  $played_games = 0;
  
	$sql = "SELECT user_id, gamedata FROM " . iNA_TOUR_PLAY . "
    WHERE gamedata IS NOT NULL";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
	$tour_gamedata = $db->sql_fetchrowset($result);
	$total_players = count($tour_gamedata);

  for($i = 0; $i < $total_players; $i++)
  {
    $GameData = unserialize(stripslashes($tour_gamedata[$i]['gamedata']));
    for($count = 0; $count < count($GameData); $count++)
    {
      $played_games = ($played_games+$GameData[$count]['played']);
    }
  }
  return $played_games;
}
//
// Code from ADR
//
function ina_send_user_pm($dest_user, $subject, $message, $from_id = -1, $quiet = 'NO')
{
  if ( CH_CURRENT_VERSION >= '2.1.6' )
  {
    global $db, $phpbb_root_path, $phpEx, $lang, $user_ip, $board_config, $userdata, $arcade, $config, $user, $forums, $censored_words, $icons, $navigation, $themes, $smilies;
  }
  else
  {
  	global $db, $phpbb_root_path, $phpEx, $lang, $user_ip, $board_config, $userdata, $arcade;
  }
	if(!($arcade->arcade_config('games_use_pms')) || $userdata['games_block_pm'] || !$dest_user)
	{
		return;
	}

	$dest_user 	= intval($dest_user);
  if($dest_user < 1)
  {
    return;
  }

	$msg_time 	= time();
	if($from_id == FALSE || !isset($from_id))
	{
		$from_id 	= $userdata['user_id'];
	}
	
	if($dest_user != $from_id)
	{
		$html_on 	= 1;
		$bbcode_on 	= 1;
	    $smilies_on = 1;

		include_once($phpbb_root_path . 'includes/functions_post.'.$phpEx);
		include_once($phpbb_root_path . 'includes/bbcode.'.$phpEx);
   
		$privmsg_subject 	= trim(strip_tags($subject));
		$bbcode_uid 		= make_bbcode_uid();
		$privmsg_message 	= trim(strip_tags($message));

// APM compliance
		if ( defined('PRIVMSGA_TABLE'))
		{
			include_once($phpbb_root_path . 'includes/functions_messages.'.$phpEx);
			send_pm( 0 , '' , $dest_user , $privmsg_subject, $privmsg_message, '' );
		}
		else
		{
			$sql = "SELECT user_id, user_notify_pm, user_email, user_lang, user_active, games_block_pm
			 	FROM ". USERS_TABLE ."
			 	WHERE user_id = '". $dest_user ."'";
			if (!($result = $db->sql_query($sql)))
			{
				$error = TRUE;
				$error_msg = $lang['No_such_user'];
				return;
			}
			$to_userdata = $db->sql_fetchrow($result);

			if($to_userdata['games_block_pm'] || !$to_userdata)
			{
				return;
			}

			$sql = "SELECT COUNT(privmsgs_id) AS inbox_items, MIN(privmsgs_date) AS oldest_post_time
				FROM ". PRIVMSGS_TABLE ."
				WHERE ( privmsgs_type = ". PRIVMSGS_NEW_MAIL ."
			  	OR privmsgs_type = ". PRIVMSGS_READ_MAIL ." 
				OR privmsgs_type = ". PRIVMSGS_UNREAD_MAIL ." )
				AND privmsgs_to_userid = '". $dest_user ."'";
			if (!($result = $db->sql_query($sql)))
			{
				message_die(GENERAL_MESSAGE, $lang['No_such_user']);
			}

			$sql_priority = (SQL_LAYER == 'mysql') ? 'LOW_PRIORITY' : '';

			if($inbox_info = $db->sql_fetchrow($result))
			{
				if ($inbox_info['inbox_items'] >= $board_config['max_inbox_privmsgs'])
				{
					$sql = "SELECT privmsgs_id 
						FROM ". PRIVMSGS_TABLE ."
						WHERE ( privmsgs_type = ". PRIVMSGS_NEW_MAIL ."
						OR privmsgs_type = ". PRIVMSGS_READ_MAIL ."
						OR privmsgs_type = ". PRIVMSGS_UNREAD_MAIL ."  )
						AND privmsgs_date = ". $inbox_info['oldest_post_time'] . "
						AND privmsgs_to_userid = '". $dest_user ."'";
					if (!$result = $db->sql_query($sql))
					{	
						message_die(GENERAL_ERROR, 'Could not find oldest privmsgs (inbox)', '', __LINE__, __FILE__, $sql);
					}
					$old_privmsgs_id = $db->sql_fetchrow($result);
					$old_privmsgs_id = $old_privmsgs_id['privmsgs_id'];
           
					$sql = "DELETE $sql_priority 
						FROM ". PRIVMSGS_TABLE ."
						WHERE privmsgs_id = '". $old_privmsgs_id ."'";
					if(!$db->sql_query($sql))
					{
						message_die(GENERAL_ERROR, 'Could not delete oldest privmsgs (inbox)'.$sql, '', __LINE__, __FILE__, $sql);
					}

					$sql = "DELETE $sql_priority 
						FROM " . PRIVMSGS_TEXT_TABLE . "
						WHERE privmsgs_text_id = '". $old_privmsgs_id ."'";
					if (!$db->sql_query($sql))
					{
						message_die(GENERAL_ERROR, 'Could not delete oldest privmsgs text (inbox)', '', __LINE__, __FILE__, $sql);
					}
				}
			}
     
			$sql_info = "INSERT INTO ". PRIVMSGS_TABLE ." 
					(privmsgs_type, privmsgs_subject, privmsgs_from_userid, privmsgs_to_userid, privmsgs_date, privmsgs_ip, privmsgs_enable_html, privmsgs_enable_bbcode, privmsgs_enable_smilies)
					VALUES ( 1 , '". str_replace("\'", "''", addslashes($privmsg_subject)) ."' , ". $from_id .", ". $to_userdata['user_id'] .", $msg_time, '$user_ip' , $html_on, $bbcode_on, $smilies_on)";
			if(!$db->sql_query($sql_info))
			{
				message_die(GENERAL_ERROR, 'Could not delete oldest privmsgs text (inbox)', '', __LINE__, __FILE__, $sql_info);
			}

			$privmsg_sent_id = $db->sql_nextid();

			$sql = "INSERT INTO ". PRIVMSGS_TEXT_TABLE ." (privmsgs_text_id, privmsgs_bbcode_uid, privmsgs_text)
				VALUES ($privmsg_sent_id, '" . $bbcode_uid . "', '" . str_replace("\'", "''", addslashes($privmsg_message)) . "')"; 
			if (!$db->sql_query($sql, END_TRANSACTION))
			{
				message_die(GENERAL_ERROR, "Could not insert/update private message sent text.", "", __LINE__, __FILE__, $sql);
			}

			$sql = "UPDATE ". USERS_TABLE ."
				SET user_new_privmsg = user_new_privmsg + 1, user_last_privmsg = " . time() . " 
				WHERE user_id = '". $to_userdata['user_id'] ."'";
			if(!$status = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not update private message new/read status for user', '', __LINE__, __FILE__, $sql);
			}

			if($to_userdata['user_notify_pm'] && !empty($to_userdata['user_email']) && $to_userdata['user_active'] && $quiet == 'NO')
			{
//
//  Get the mailer to send email
//  Due to a number of server crashes when sending out PM's
//  I'm going to check for a MX record for the users email address here...!
//
   			$DomainPass = explode("@", $to_userdata['user_email']);
   			$Domain = isset($DomainPass[1]) ? $DomainPass[1] : '';
   			$DomainPass = explode(".", $Domain);
   			$DomainSuffix = isset($DomainPass[1]) ? $DomainPass[1] : '';
  			if ($Domain != '' && $DomainSuffix != '')
        {
    			$mx = new mxlookup($Domain);
          if(intval($mx->ANCOUNT) > 0) 
          {
    				$script_name 		= preg_replace('/^\/?(.*?)\/?$/', "\\1", trim($board_config['script_path']));
    				$script_name 		= ( $script_name != '' ) ? $script_name . '/privmsg.'.$phpEx : 'privmsg.'.$phpEx;
    				$server_name 		= trim($board_config['server_name']);
    				$server_protocol 	= ( $board_config['cookie_secure'] ) ? 'https://' : 'http://';
    				$server_port 		= ( $board_config['server_port'] <> 80 ) ? ':' . trim($board_config['server_port']) . '/' : '/';

//				$mail_header = "From: ".$board_config['board_email']."\r\nReply-to: ".$board_config['board_email']."\r\n";
//				$message = "A New PM has arrived from the Arcade ";
//				$mail_send = mail($to_userdata['user_email'], $lang['Notification_subject'], $message, $mail_header);
//        if($mail_send == FALSE)
//        {
//					echo("Failed to send to " . $to_userdata['user_email'] . "<br />");
//        }

    				include_once($phpbb_root_path . './includes/emailer.'.$phpEx);
    				$emailer = new emailer($board_config['smtp_delivery']);
        
    				if($board_config['version'] >= '.0.5' )
    				{   
    					$emailer->from($board_config['board_email']);
    					$emailer->replyto($board_config['board_email']);
    					$emailer->use_template('privmsg_notify', $to_userdata['user_lang']);
						}
    				else
		    		{
    					$email_headers = 'From: ' . $board_config['board_email'] . "\nReturn-Path: " . $board_config['board_email'] . "\n";
    					$emailer->use_template('privmsg_notify', $to_userdata['user_lang']);
    					$emailer->extra_headers($email_headers);
    				}
    				$emailer->email_address($to_userdata['user_email']);
    				$emailer->set_subject($lang['Notification_subject']);
    				$emailer->assign_vars(array(
    					'USERNAME' => $to_username,
    					'SITENAME' => $board_config['sitename'],
    					'EMAIL_SIG' => (!empty($board_config['board_email_sig'])) ? str_replace('<br />', "\n", "-- \n" . $board_config['board_email_sig']) : '',
    					'U_INBOX' => $server_protocol . $server_name . $server_port . $script_name . '?folder=inbox')
		  			 );

    				$emailer->send();
    				$emailer->reset();
    			}
    			else
    			{
            $updatesql = "UPDATE " . USERS_TABLE . "
              SET arcade_banned = 1
              WHERE user_id = " . $to_userdata['user_id'];
            $db->sql_query($updatesql);
          }
        }
			}
		}
	}
	return;
}

function swap_place($old_id, $new_id, $type, $game_info = NULL)
{	
  if ( CH_CURRENT_VERSION >= '2.1.6' )
  {
    global $db, $lang, $board_config, $arcade, $config, $user, $forums, $censored_words, $icons, $navigation, $themes, $smilies;
  }
  else
  {
    global $db, $lang, $board_config, $arcade;
  }
	$old_id = intval($old_id);
	$new_id = intval($new_id);
	if($old_id == $new_id)
	{
    return;
  }
	if ($old_id > 0)
	{
		$sql = "UPDATE " . iNA_USER_DATA . "
			SET $type = $type-1
			WHERE user_id = " . intval($old_id) . "
			AND $type > 0";
		if(!$result = $db->sql_query($sql)) 
		{
			message_die(GENERAL_ERROR, $lang['no_user_update'] . $lang['newscore_close'], "", __LINE__, __FILE__, $sql); 
		}

   	if($type == 'first_places' && $arcade->arcade_config('games_pm_highscore'))
   	{
     	$message = sprintf($lang['games_pm_info_lost'], $board_config['server_name'] . $board_config['script_path'], $game_info['game_id'] , $game_info['game_desc'] );
      ina_send_user_pm($old_id, $lang['games_important_info'], $message, $new_id);
   	}
   	else if ($arcade->arcade_config['games_pm_at_highscore'])
   	{
       $message = sprintf($lang['games_pm_info_lost_at'], $board_config['server_name'] . $board_config['script_path'], $game_info['game_id'] , $game_info['game_desc'] );
       ina_send_user_pm($old_id, $lang['games_important_info'], $message, $new_id);
    }
  }
 	$sql = "UPDATE " . iNA_USER_DATA . "
 		SET $type = $type+1, last_won_date = '" . (time()) . "'
 		WHERE user_id = " . intval($new_id) . "";
   if(!$result = $db->sql_query($sql)) 
 	{
 		message_die(GENERAL_ERROR, $lang['no_user_update'] . $lang['newscore_close'], "", __LINE__, __FILE__, $sql); 
 	}

	return;
}

//
// Simple code that makes sure a user does not get any more than one PM from each user every 24 hours.
//
function ina_check_last_pm($to_id, $from_id)
{
  if ( CH_CURRENT_VERSION >= '2.1.6' )
  {
  	global $db, $config, $user, $forums, $censored_words, $icons, $navigation, $themes, $smilies;
  }
  else
  {
  	global $db;
  }
	if($to_id == $from_id)
	{
		return TRUE;
	}
	$sql = "SELECT * FROM " . iNA_PMs_TABLE . "
		WHERE to_id = '" . $to_id . "'
			AND from_id = '" . $from_id . "'";
	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql);
	}
	$row = $db->sql_fetchrow($result);
	if($row['last_sent'] < (time() - 86400))
	{
		$sql = "UPDATE " . iNA_PMs_TABLE . "
			SET last_sent = '" . time() . "', total_sent = total_sent+1
				WHERE to_id = '" . $to_id . "'
			AND from_id = '" . $from_id . "'";
		if ( !$db->sql_query($sql) || !$db->sql_affectedrows() )
		{
			$sql = "INSERT INTO " . iNA_PMs_TABLE . "
				(to_id, from_id, last_sent, total_sent)
				VALUES ('$to_id', '$from_id', '". time() . "', 1)";
			if ( !$db->sql_query($sql) )
			{
				message_die(CRITICAL_ERROR, $lang['session_error'], '', __LINE__, __FILE__, $sql);
			}
		}
		return FALSE;
	
	}
	
	return TRUE;
}

function ina_find_image($game_path, $game_name, $image_path = "", $phpbb_root_path = './')
{
  global $arcade;
  
	if ( empty($image_path) )
	{
 		if( @file_exists($phpbb_root_path . $arcade->arcade_config['games_path'] . $game_name .".gif") )
  	{
    	return ($phpbb_root_path . $arcade->arcade_config['games_path'] . $game_name . '.gif');
 		}
  	else if( @file_exists($phpbb_root_path . $game_path . $game_name .".gif") )
		{
      @copy($phpbb_root_path . $game_path . $game_name .".gif", $phpbb_root_path . $arcade->arcade_config['games_path'] . $game_name .".gif");
			return ($phpbb_root_path . $game_path . $game_name . '.gif');
		}
	}
	else if (( strlen( $image_path ) < 5 ) && @file_exists($phpbb_root_path . $game_path . $game_name . $image_path))
	{
		return ($phpbb_root_path . $game_path . $game_name . $image_path);
	}
	else if ( @file_exists($phpbb_root_path . $image_path))
	{
    return ($phpbb_root_path . $image_path);
  }
	else if ( @file_exists($image_path))
	{
    return ($image_path);
  }
	else if ( @file_exists($phpbb_root_path . $game_path . '/' . $game_name . $image_path))
	{
    return ($phpbb_root_path . $game_path . '/' . $game_name . $image_path);
  }
//
//  OK, so we've checked what we can, give up and look for the default
//
  if ( @file_exists($arcade->arcade_config['games_default_img']))
	{
		return ($arcade->arcade_config['games_default_img']);
	}
  return FALSE;
}

function highscore_jump_box()
{
	global $lang, $db, $phpEx, $arcade;

  $highscore_mon = array($lang['highscore_jan'],$lang['highscore_feb'],$lang['highscore_mar'],$lang['highscore_apr'],$lang['highscore_may'],$lang['highscore_jun'],$lang['highscore_jul'],$lang['highscore_aug'],$lang['highscore_sep'],$lang['highscore_oct'],$lang['highscore_nov'],$lang['highscore_dec']);
//
//  Pull the data
//
	$sql = "SELECT highscore_year, highscore_mon FROM " . iNA_HIGHSCORES . "
      GROUP BY highscore_year, highscore_mon
      ORDER BY highscore_year DESC, highscore_mon DESC
      LIMIT 12";
	if( !($result = $db->sql_query($sql)) )
	{
			message_die(GENERAL_ERROR, $lang['highscore_count_err'], '', __LINE__, __FILE__, $sql);
	}
	$rows = $db->sql_fetchrowset($result);
//
//  Build the Input
//
	$input = '<table cellspacing="2" cellpadding="2" border="1" align="center">
  <tr>
    <td class="row1" align="center"><div align="center"><span class="nav">'.$lang['highscore_other_score'].':<br />
    <form action="'.append_sid("arcade_highscore.$phpEx").'" name="mon_jump" method="post">
    <select name="date" onchange="'."if(this.options[this.selectedIndex].value >= 0){ forms['mon_jump'].submit() }".'">
    <option value="-1"></option>';
//
//  Loop Through the Months
//
	for ($i = "0"; $i < count($rows); $i++)
	{
    $highscore_date = $highscore_mon[($rows[$i]['highscore_mon']-1)]." ".$rows[$i]['highscore_year'];
 		$input .= "<option value=\"".$i."\">".$lang['highscore_for']." ".$highscore_date."</option>\n";
	}
	$input .= '</select><br><input type="hidden" name="sid" value="' . $arcade->sid . '"><input type="hidden" name="cat_id" value="' . $arcade->cat_id . '"><input type="hidden" name="start" value="' . $arcade->start . '"><input type="hidden" name="sort_mode" value="' . $arcade->sort_mode . '"><input type="hidden" name="order" value="' . $arcade->order . '">';
	$input .= //'<input type="submit" value="'.$lang['highscore_submit'].'" name="submit" class="post">
  '</form></span></div>
  </td>
  </tr>
  </table>';
//
//  Return Built Info
//
	return $input;
}

?>
