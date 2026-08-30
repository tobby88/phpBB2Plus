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
$language = phpbb_ltrim(basename(phpbb_rtrim((string) $board_config['default_lang'])), "'");
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

function phpbb_arcade_local_asset($path)
{
	$path = str_replace('\\', '/', trim((string) $path));
	if ($path === '' || strpos($path, "\0") !== false || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) ||
		preg_match('#(^|/)\.\.(/|$)#', $path) || preg_match('#^/|^[A-Za-z]:/#', $path))
	{
		return '';
	}
	return $path;
}

function best_player($type = 'first_places')
{
	global $db, $lang, $arcade, $phpEx;
	if (!in_array($type, array('first_places', 'at_first_places'), true))
	{
		$type = 'first_places';
	}

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
	if (!$user_row)
	{
		return '';
	}

  	if ($type == 'first_places')
  	{
      $best_player = sprintf($lang['games_best_player'] , append_sid("profile.$phpEx?mode=viewprofile&u=".(int) $user_row['user_id']), phpbb_profile_text($user_row['username']), append_sid("activity.$phpEx?mode=game_stats&amp;user_id=".(int) $user_row['user_id']."&amp;id=4"), (int) $user_row[$type]);
      $arcade->write_cache('best_player', $best_player);
  	}
    else
    {
      $best_player = sprintf($lang['games_best_at_player'] , append_sid("profile.$phpEx?mode=viewprofile&u=".(int) $user_row['user_id']), phpbb_profile_text($user_row['username']), append_sid("activity.$phpEx?mode=game_stats&amp;user_id=".(int) $user_row['user_id']."&id=1"), (int) $user_row[$type]);
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
  $user_id = (int) $user_id;
  
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
		return sprintf($lang['games_last_u_viewed'], phpbb_profile_text($user_row['game_desc']));
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
{
	global $lang, $games_position_text;

	$default_position_text = array('> 20th place', 'st place', 'nd place', 'rd place', 'th place');
	if (isset($lang['games_position_text']) && is_array($lang['games_position_text']))
	{
		$position_text = $lang['games_position_text'];
	}
	elseif (isset($games_position_text) && is_array($games_position_text))
	{
		// Compatibility with language packs written for the original Arcade MOD.
		$position_text = $games_position_text;
	}
	else
	{
		$position_text = $default_position_text;
	}
	$position_text = array_replace($default_position_text, $position_text);
	$games_place = max(0, (int) $games_place);

	if($games_place > 20)
	{
		return $position_text[0];
	}
	if($games_place > 3 || $games_place < 1)
	{
		return $games_place . $position_text[4];
	}

	return $games_place . $position_text[$games_place];
}

function update_ina_session($user_id, $user_ip, $page, $game, $old_hash = FALSE, $win = "NORM", $tour_id = 0)
{	global $db, $userdata, $lang, $board_config;

	$user_id = (int) $user_id;
	$page = (int) $page;
	$tour_id = (int) $tour_id;
	$user_ip = $db->sql_escape($user_ip);
	$game = $db->sql_escape($game);
	$win = preg_match('/^[A-Za-z0-9_-]{1,20}$/D', (string) $win) ? (string) $win : 'NORM';
	$start_time		= time();
	$string			= sprintf("ARCADE_MOD %s %s %s %d %d %d", $game, $board_config['sitename'], $user_ip, $page, $user_id, $userdata['session_ip']);
	$arcade_hash	= md5(dss_rand() . dss_rand() . $string);
	//
	$ip_num = $db->sql_escape(decode_ip($userdata['session_ip']));
	$ip_nam = '';
	
	$sql = "UPDATE " . iNA_SESSIONS . "
		SET start_time = '$start_time', page = '$page', game_name = '$game', arcade_hash = '$arcade_hash', user_win = '$win', tour_id = $tour_id";
	if($old_hash != FALSE)
	{
		$sql .= " WHERE arcade_hash = '" . $db->sql_escape($old_hash) . "'";
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

function games_list($mode, $number, $cat_id, $option = 'played', $image_path = FALSE)
{	
  global $db, $phpEx, $SID, $userdata, $board_config, $arcade;
	$games_list = '';

	$mode = strtoupper((string) $mode);
	if (!in_array($mode, array('ASC', 'DESC'), true))
	{
		$mode = 'DESC';
	}
	if (!in_array($option, array('played', 'date_added DESC, game_id'), true))
	{
		$option = 'played';
	}
	$number = max(1, min(100, intval($number)));
	$cat_id = intval($cat_id);
	$user_id = (int) $userdata['user_id'];

  $level_required = isset($userdata['user_level']) ? $userdata['user_level'] : 0;
  $rank_required = isset($userdata['user_rank']) ? $userdata['user_rank'] : 0;
  
	$sql = "SELECT g.group_id
		FROM " . GROUPS_TABLE . " g, " . USER_GROUP_TABLE . " ug
		WHERE ug.user_id = " . $user_id . "
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
		$width = max(20, (int) $top_games['win_width'] + 20);
		$height = max(25, (int) $top_games['win_height'] + 25);
		$game_desc = phpbb_profile_text($top_games['game_desc']);
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
			$image_path = phpbb_arcade_local_asset($image_path);
			$image_exists = ($image_path !== '' && @file_exists($image_path));
			if ( $image_exists && (($userdata['user_id'] == ANONYMOUS && $top_games['allow_guest'] == 0) || ( intval($userdata['user_posts']) < intval($board_config['games_posts_required']) && $top_games['allow_guest'] == 0) || ((intval($userdata['user_rank']) < intval($arcade->arcade_config['games_rank_required']) && $top_games['allow_guest'] == 0) && $userdata['user_level'] != ADMIN)) )
			{
				$games_list .= "<img src=\"". phpbb_profile_text($image_path) ."\" height=\"25\" width=\"25\" border=\"0\"> " . $game_desc . '<br />';
			}
			else if ($image_exists)
			{
        $games_list .= "<a href=\"". append_sid("activity.$phpEx?mode=game&amp;id=".(int) $top_games['game_id']) ."\" class=\"gensmall\" onClick=\"Gk_PopTart('activity.$phpEx?mode=game&amp;id=" . (int) $top_games['game_id'] . "$SID', 'New_Window', '$width', '$height', 'no'); return false; blur()\"><img src=\"". phpbb_profile_text($image_path) ."\" height=\"25\" width=\"25\" border=\"0\"> " . $game_desc . "</a><br />";
			}
			else
			{
        $games_list .= "<a href=\"". append_sid("activity.$phpEx?mode=game&amp;id=".(int) $top_games['game_id']) ."\" class=\"gensmall\" onClick=\"Gk_PopTart('activity.$phpEx?mode=game&amp;id=" . (int) $top_games['game_id'] . "$SID', 'New_Window', '$width', '$height', 'no'); return false; blur()\"> " . $game_desc . "</a><br />";
			}
		}
		else
		{
		  if(($userdata['user_id'] == ANONYMOUS && $top_games['allow_guest'] == 0) || $top_games['level_required'] > $level_required  || $top_games['rank_required'] > $rank_required || in_array($top_games['group_required'], $group_list) == FALSE)
		  {
			$games_list .= $game_desc . '<br />';
      }
      else
      {
			 $games_list .= "<a href=\"". append_sid("activity.$phpEx?mode=game&amp;id=".(int) $top_games['game_id']) ."\" class=\"gensmall\" onClick=\"Gk_PopTart('activity.$phpEx?mode=game&amp;id=" . (int) $top_games['game_id'] . "$SID', 'New_Window', '$width', '$height', 'no'); return false; blur()\"> " . $game_desc . "</a><br />";
			}
		}
	}
	return $games_list;
}

function best_game_player($tablename, $gamename, $type)
{	global $db, $lang;
	$tablename = in_array($tablename, array(iNA_SCORES, iNA_AT_SCORES), true) ? $tablename : iNA_SCORES;
	$type = (strtoupper((string) $type) === 'ASC') ? 'ASC' : 'DESC';
	$gamename = $db->sql_escape($gamename);

	$sql = "SELECT s.player_id, s.score, u.username, u.user_allow_viewonline FROM " . $tablename . " s, " . USERS_TABLE . " u
		WHERE s.player_id = u.user_id
		AND game_name = '" . $gamename . "'
		ORDER BY score $type, date ASC";
	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql);
	}
	$score_info = $db->sql_fetchrow($result);
	if (!$score_info)
	{
		return array('player_id' => 0, 'score' => null, 'username' => '', 'user_allow_viewonline' => 0);
	}
	return $score_info;
}

function get_games_total($data, $extra = '')
{	global $db, $lang;
	if (!in_array($data, array('COUNT(*)', 'SUM(played)'), true))
	{
		return 0;
	}
	if ($extra !== '' && preg_match('/^cat_id\s*=\s*[\'\"]?(\d+)[\'\"]?$/D', trim($extra), $match))
	{
		$extra = 'cat_id = ' . (int) $match[1];
	}
	else if ($extra !== '')
	{
		return 0;
	}

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
	$position = strrpos((string) $name, '.');
	return ($position === false) ? '' : strtolower(substr($name, $position + 1));
}

function check_ina_game($game_name)
{	global $db, $lang;

	if($game_name)
	{
		$game_name_sql = $db->sql_escape($game_name);
		$old_game_sql = "SELECT game_id, game_name FROM " . iNA_GAMES . "
			WHERE game_name = '" . $game_name_sql . "'";
		if( !$old_game_result = $db->sql_query($old_game_sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_read_game_data'], "", __LINE__, __FILE__, $old_game_sql);
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
  if ( defined('CH_CURRENT_VERSION') && CH_CURRENT_VERSION >= '2.1.6' )
  {
    global $db, $phpbb_root_path, $arcade, $lang, $config, $user, $forums, $censored_words, $icons, $navigation, $themes, $smilies;
  }
  else
  {
    global $db, $phpbb_root_path, $arcade, $lang;
  }
	$game_name = trim((string) $game_name);
	$game_path = phpbb_arcade_local_asset($game_path);
	if ($game_name === '' || $game_path === '')
	{
		return false;
	}
	$reverse_list = !empty($reverse_list) ? 1 : 0;
	$game_flash = !empty($game_flash) ? 1 : 0;
	$game_avail = !empty($game_avail) ? 1 : 0;
	$win_width = max(0, (int) $win_width);
	$win_height = max(0, (int) $win_height);
	$cat_id = (int) $cat_id;
	if ($win_width === 0)
	{
		$win_width = 550;
	}
	if ($win_height === 0)
	{
		$win_height = 450;
	}
	if($cat_id == 0)
	{
    $cat_id = -1;
  }
	if($game_desc == '')
	{
		$game_desc = trim(str_replace("_", " ", $game_name));
		$game_desc = ($game_desc !== '') ? ucfirst($game_desc) : $game_name;
	}
	$game_name_sql = $db->sql_escape($game_name);
	$game_path_sql = $db->sql_escape($game_path);
	$game_desc_sql = $db->sql_escape($game_desc);
	$inst_sql = "INSERT INTO " . iNA_GAMES . "
		(cat_id, date_added, game_name, game_path, game_desc, win_width, win_height, reverse_list , game_flash, game_avail)
			VALUES ($cat_id, " . time() . ", '$game_name_sql', '$game_path_sql', '$game_desc_sql', $win_width, $win_height, $reverse_list, $game_flash, $game_avail)";
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
	return (int) $db->sql_nextid();
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
    $GameData = phpbb_safe_unserialize(stripslashes($tour_gamedata[$i]['gamedata']));
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
  if ( defined('CH_CURRENT_VERSION') && CH_CURRENT_VERSION >= '2.1.6' )
  {
    global $db, $phpbb_root_path, $phpEx, $lang, $user_ip, $board_config, $userdata, $arcade, $config, $user, $forums, $censored_words, $icons, $navigation, $themes, $smilies;
  }
  else
  {
  	global $db, $phpbb_root_path, $phpEx, $lang, $user_ip, $board_config, $userdata, $arcade;
  }
	if(!($arcade->arcade_config('games_use_pms')) || !empty($userdata['games_block_pm']) || !$dest_user)
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
			$sql = "SELECT user_id, username, user_notify_pm, user_email, user_lang, user_active, games_block_pm
			 	FROM ". USERS_TABLE ."
				WHERE user_id = ". $dest_user;
			if (!($result = $db->sql_query($sql)))
			{
				$error = TRUE;
				$error_msg = $lang['No_such_user'];
				return;
			}
			$to_userdata = $db->sql_fetchrow($result);

			if(!$to_userdata || $to_userdata['games_block_pm'])
			{
				return;
			}

			$sql = "SELECT COUNT(privmsgs_id) AS inbox_items, MIN(privmsgs_date) AS oldest_post_time
				FROM ". PRIVMSGS_TABLE ."
				WHERE ( privmsgs_type = ". PRIVMSGS_NEW_MAIL ."
			  	OR privmsgs_type = ". PRIVMSGS_READ_MAIL ." 
				OR privmsgs_type = ". PRIVMSGS_UNREAD_MAIL ." )
				AND privmsgs_to_userid = ". $dest_user;
			if (!($result = $db->sql_query($sql)))
			{
				message_die(GENERAL_MESSAGE, $lang['No_such_user']);
			}

			$sql_priority = (SQL_LAYER == 'mysql') ? 'LOW_PRIORITY' : '';

			if($inbox_info = $db->sql_fetchrow($result))
			{
				$max_inbox_privmsgs = max(1, intval($board_config['max_inbox_privmsgs']));
				if ((int) $inbox_info['inbox_items'] >= $max_inbox_privmsgs)
				{
					$sql = "SELECT privmsgs_id 
						FROM ". PRIVMSGS_TABLE ."
						WHERE ( privmsgs_type = ". PRIVMSGS_NEW_MAIL ."
						OR privmsgs_type = ". PRIVMSGS_READ_MAIL ."
						OR privmsgs_type = ". PRIVMSGS_UNREAD_MAIL ."  )
						AND privmsgs_date = ". $inbox_info['oldest_post_time'] . "
						AND privmsgs_to_userid = ". $dest_user;
					if (!$result = $db->sql_query($sql))
					{	
						message_die(GENERAL_ERROR, 'Could not find oldest privmsgs (inbox)', '', __LINE__, __FILE__, $sql);
					}
					$old_privmsgs_id = $db->sql_fetchrow($result);
					$old_privmsgs_id = $old_privmsgs_id ? intval($old_privmsgs_id['privmsgs_id']) : 0;
					if ($old_privmsgs_id < 1)
					{
						return;
					}
           
					$sql = "DELETE $sql_priority 
						FROM ". PRIVMSGS_TABLE ."
						WHERE privmsgs_id = ". $old_privmsgs_id;
					if(!$db->sql_query($sql))
					{
						message_die(GENERAL_ERROR, 'Could not delete oldest privmsgs (inbox)'.$sql, '', __LINE__, __FILE__, $sql);
					}

					$sql = "DELETE $sql_priority 
						FROM " . PRIVMSGS_TEXT_TABLE . "
						WHERE privmsgs_text_id = ". $old_privmsgs_id;
					if (!$db->sql_query($sql))
					{
						message_die(GENERAL_ERROR, 'Could not delete oldest privmsgs text (inbox)', '', __LINE__, __FILE__, $sql);
					}
				}
			}
			$privmsg_subject_sql = $db->sql_escape($privmsg_subject);
			$privmsg_message_sql = $db->sql_escape($privmsg_message);
			$bbcode_uid_sql = $db->sql_escape($bbcode_uid);
			$user_ip_sql = $db->sql_escape($user_ip);
			$from_id = intval($from_id);
			$to_user_id = intval($to_userdata['user_id']);
			$sql_info = "INSERT INTO ". PRIVMSGS_TABLE ." 
					(privmsgs_type, privmsgs_subject, privmsgs_from_userid, privmsgs_to_userid, privmsgs_date, privmsgs_ip, privmsgs_enable_html, privmsgs_enable_bbcode, privmsgs_enable_smilies)
					VALUES (1, '$privmsg_subject_sql', $from_id, $to_user_id, $msg_time, '$user_ip_sql', $html_on, $bbcode_on, $smilies_on)";
			if(!$db->sql_query($sql_info))
			{
				message_die(GENERAL_ERROR, 'Could not delete oldest privmsgs text (inbox)', '', __LINE__, __FILE__, $sql_info);
			}

			$privmsg_sent_id = $db->sql_nextid();

			$sql = "INSERT INTO ". PRIVMSGS_TEXT_TABLE ." (privmsgs_text_id, privmsgs_bbcode_uid, privmsgs_text)
				VALUES (" . intval($privmsg_sent_id) . ", '$bbcode_uid_sql', '$privmsg_message_sql')";
			if (!$db->sql_query($sql, END_TRANSACTION))
			{
				message_die(GENERAL_ERROR, "Could not insert/update private message sent text.", "", __LINE__, __FILE__, $sql);
			}

			$sql = "UPDATE ". USERS_TABLE ."
				SET user_new_privmsg = user_new_privmsg + 1, user_last_privmsg = " . time() . " 
				WHERE user_id = ". $to_user_id;
			if(!$status = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not update private message new/read status for user', '', __LINE__, __FILE__, $sql);
			}

			if($to_userdata['user_notify_pm'] && !empty($to_userdata['user_email']) && $to_userdata['user_active'] && $quiet == 'NO')
			{
			include_once($phpbb_root_path . 'includes/emailer.'.$phpEx);
			$emailer = new emailer($board_config['smtp_delivery']);
			$emailer->from($board_config['board_email']);
			$emailer->replyto($board_config['board_email']);
			$default_mail_language = preg_match('/^[a-z0-9_-]+$/iD', (string) $board_config['default_lang']) &&
				is_dir($phpbb_root_path . 'language/lang_' . $board_config['default_lang'])
				? (string) $board_config['default_lang']
				: 'english';
			$mail_language = preg_match('/^[a-z0-9_-]+$/iD', (string) $to_userdata['user_lang']) &&
				is_dir($phpbb_root_path . 'language/lang_' . $to_userdata['user_lang'])
				? (string) $to_userdata['user_lang']
				: $default_mail_language;
			$emailer->use_template('privmsg_notify', $mail_language);
			$emailer->email_address($to_userdata['user_email']);
			$emailer->set_subject($lang['Notification_subject']);
			$emailer->assign_vars(array(
				'USERNAME' => $to_userdata['username'],
				'SITENAME' => $board_config['sitename'],
				'EMAIL_SIG' => (!empty($board_config['board_email_sig'])) ? str_replace('<br />', "\n", "-- \n" . $board_config['board_email_sig']) : '',
				'U_INBOX' => phpbb_board_url('privmsg.'.$phpEx.'?folder=inbox'))
			);
			$emailer->send();
			$emailer->reset();
			}
		}
	}
	return;
}

function swap_place($old_id, $new_id, $type, $game_info = NULL)
{	
  if ( defined('CH_CURRENT_VERSION') && CH_CURRENT_VERSION >= '2.1.6' )
  {
    global $db, $lang, $board_config, $arcade, $config, $user, $forums, $censored_words, $icons, $navigation, $themes, $smilies;
  }
  else
  {
    global $db, $lang, $board_config, $arcade;
  }
	$old_id = intval($old_id);
	$new_id = intval($new_id);
	if (!in_array($type, array('first_places', 'at_first_places'), true))
	{
		return;
	}
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

	if($type == 'first_places' && $arcade->arcade_config['games_pm_highscore'])
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
  if ( defined('CH_CURRENT_VERSION') && CH_CURRENT_VERSION >= '2.1.6' )
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

	$game_path = phpbb_arcade_local_asset($game_path);
	$games_path = phpbb_arcade_local_asset($arcade->arcade_config['games_path']);
	$image_path = phpbb_arcade_local_asset($image_path);
	$game_name = trim((string) $game_name);
	if ($game_name === '' || strpos($game_name, "\0") !== false || basename(str_replace('\\', '/', $game_name)) !== $game_name)
	{
		return false;
	}

	$candidates = array();
	if ($image_path === '')
	{
		if ($games_path !== '')
		{
			$candidates[] = rtrim($games_path, '/') . '/' . $game_name . '.gif';
		}
		if ($game_path !== '')
		{
			$candidates[] = rtrim($game_path, '/') . '/' . $game_name . '.gif';
		}
	}
	else
	{
		if (strlen($image_path) < 5 && $game_path !== '')
		{
			$candidates[] = rtrim($game_path, '/') . '/' . $game_name . $image_path;
		}
		$candidates[] = $image_path;
		if ($game_path !== '')
		{
			$candidates[] = rtrim($game_path, '/') . '/' . $game_name . $image_path;
		}
	}

	foreach (array_unique($candidates) as $candidate)
	{
		$candidate = phpbb_arcade_local_asset($candidate);
		if ($candidate !== '' && @is_file($phpbb_root_path . $candidate))
		{
			return $phpbb_root_path . $candidate;
		}
	}
//
//  OK, so we've checked what we can, give up and look for the default
//
	$default_image = phpbb_arcade_local_asset($arcade->arcade_config['games_default_img']);
  if ($default_image !== '' && @is_file($phpbb_root_path . $default_image))
	{
		return $phpbb_root_path . $default_image;
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
    <select name="month" onchange="'."if(this.options[this.selectedIndex].value !== ''){ forms['mon_jump'].submit() }".'">
    <option value=""></option>';
//
//  Loop Through the Months
//
	for ($i = "0"; $i < count($rows); $i++)
	{
		$month = max(1, min(12, (int) $rows[$i]['highscore_mon']));
		$year = (int) $rows[$i]['highscore_year'];
		$highscore_date = $highscore_mon[$month - 1]." ".$year;
		$input .= '<option value="' . sprintf('%04d-%02d', $year, $month) . '">' . $lang['highscore_for'] . ' ' . $highscore_date . "</option>\n";
	}
	$input .= '</select><br><input type="hidden" name="sid" value="' . htmlspecialchars((string) $arcade->sid, ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="cat_id" value="' . (int) $arcade->cat_id . '"><input type="hidden" name="start" value="' . max(0, (int) $arcade->start) . '"><input type="hidden" name="sort_mode" value="' . htmlspecialchars((string) $arcade->sort_mode, ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="order" value="' . htmlspecialchars((string) $arcade->order, ENT_QUOTES, 'UTF-8') . '">';
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
