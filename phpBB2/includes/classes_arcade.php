<?php
/***************************************************************************
 *                           classes_arcade.php
 *                           ------------------
 *   begin                : Tuesday, Jan 2nd, 2007
 *   copyright            : (C) 2003-2007 www.phpbb-arcade.com
 *   email                : defenders_realm@yahoo.com
 *
 *   $Id: functions_arcade.php, v2.1.8 2007/01/02 11:26:00 dEfEndEr Exp $
 *
 ***************************************************************************
 *
 *  All Information Contained Within is Copyright www.phpbb-arcade.com
 *
 **************************************************************************/

if ( !defined('IN_PHPBB') || !empty($_GET['phpbb_root_path']))
{
	die("Hacking attempt");
}

if ( defined('ARCADE_CLASSES') )
{
	return;
}

define('ARCADE_CLASSES', TRUE);
define('ARCADE_VERSION', '2.1.8');
//
//  Arcade Class Definition
//  Currently the main reason i'm using classes is due to mod clashes.
//
class arcade
{
//
//  List of Varibles that are used in this Class
//
  var $last_game_id;
  var $random_game;
  var $cat_id;
  var $sid;
  var $start;
  var $order;
  var $last_cat_id;
  var $game_name;
  var $score;
  var $user_id;
  var $user_name;
  var $tour_id;
  var $time_taken;
  var $arcade_hash;
  var $arcade_cookie;
  var $sort;
  var $sort_mode;
  var $order_by;
  var $sort_order;
  var $version;
  var $message;
  var $score_type;
  var $url;
  var $arcade_config = array();
  var $arcade_session = array();
  var $categories = array();
  var $games = array();
  var $best_player = array();
  var $best_at_player = array();
//
//  Read and Return the categories listing - v2.1.2 Added Cache System.
//
  function read_cat($cache_path = '')
  {
    global $db;

    $this->categories = $this->read_cache('categories', $this->arcade_config['categories_cache'], $cache_path);

    if(!$this->categories)
    { 
     	$cat_sql = "SELECT c.*, g.game_name, game_id, game_desc, u.username, u.user_allow_viewonline 
         FROM " . iNA_CAT . " c
     	   LEFT JOIN ". iNA_GAMES . " g ON g.game_name = c.last_game 
         LEFT JOIN ". USERS_TABLE . " u ON c.last_player = u.user_id
         ORDER BY c.cat_order ASC, cat_id ASC";
      if($result = $db->sql_query($cat_sql))
      {
      	$this->categories = $db->sql_fetchrowset($result);
        $this->write_cache('categories', $this->categories, $cache_path);    
      }
    }
    return $this->categories;
  }
//
//  Jump Box for Categories
//
  function jump_cat()
  {
    global $phpEx, $lang;

    $pad = '';
    
    $this->read_cat();
    
  	$cat_jump = '<form action="activity.'.$phpEx.'" name="cat_jump" method="post"><select name="cat_id" onchange="'."if(this.options[this.selectedIndex].value >= 0){ forms['cat_jump'].submit() }".'"><option value="-2">' . $lang['cat_jump_to'] . '</option><option value="-1">--------------------</option>';
    
    if($this->arcade_config['games_show_all'])
    {
      $cat_jump .= '<option value="0">|__' . $this->arcade_config['games_cat_zero'] . '</option>';
      $pad = '&nbsp;&nbsp;&nbsp;&nbsp;';
    }

    for($i = 1; $i < count($this->categories); $i++)
    {

      if($this->categories[$i]['cat_type'] == 'l')
      {
        continue;
      }
      if($this->categories[$i]['cat_type'] == 's')
      {
        $cat_jump .= '<option value="'.$this->categories[$i]['cat_id'].'">'.$pad.'&nbsp;&nbsp;&nbsp;&nbsp;|__'.$this->categories[$i]['cat_name'].'</option>';
      }
      else
      {
        $cat_jump .= '<option value="'.$this->categories[$i]['cat_id'].'">'.$pad.'|__'.$this->categories[$i]['cat_name'].'</option>';
      }
    }
  	$cat_jump .= '<input type="hidden" name="mode" value="cat"></select></form>';

    return $cat_jump;
  }
//
//  Read in the games
//
  function read_games($cache_path = '')
  {
    global $db;

    $this->games = $this->read_cache('games', $this->arcade_config['games_cache'], $cache_path);

    if(!$this->games)
    {
    	$games_sql = "SELECT game_name, game_desc, reverse_list, game_avail, game_show_score, highscore_id, at_highscore_id, group_required, rank_required, level_required 
        FROM " . iNA_GAMES . "
    		ORDER BY game_name ASC"; 
    	if($result = $db->sql_query($games_sql))
      {
       	$this->games = $db->sql_fetchrowset($result);
        $this->write_cache('games', $this->games, $cache_path);
      }
    }
    return $this->games;

  }
//
//  Gets and returns the last cat_id
//
  function last_cat_id()
  {
    global $db;
    $sql = "SELECT MAX(cat_id) as last
      FROM " . iNA_CAT;
  	if($result = $db->sql_query($sql))
  	{
      $cat_row = $db->sql_fetchrow($result);
      $db->sql_freeresult($result);   
      return ($this->last_cat_id = $cat_row['last']);
    }
    return FALSE;
  }
//
//  Gets and returns the last game_id
//
  function last_game_id()
  {
    global $db, $lang;
    $sql = "SELECT MAX(game_id) as last
      FROM " . iNA_GAMES;
  	if($result = $db->sql_query($sql))
  	{
      $game_row = $db->sql_fetchrow($result);
      $db->sql_freeresult($result);   
      return ($this->last_game_id = $game_row['last']);
    }
    return FALSE;
  }

//
//  Convert a Arcade Score into Human Readable format
//  Called in other modules using $myvar = $arcade->convert_score($myscore);
//
  function convert_score($score)
  {
		$parts = explode('.', (string) $score, 2);
		$decimals = isset($parts[1]) ? strlen(rtrim($parts[1], '0')) : 0;
		$this->score = number_format((float) $score, $decimals, '.', ',');
		return $this->score;
  }
//
//  Convert the Arcade Time to Human Readable Format.
//
  function convert_time($time_taken)
  {
    global $lang;
		
    $this->time_taken = $time_taken;
    
	  $played_days = sprintf("%d", ($this->time_taken/86400));
    $played_weeks = sprintf("%d", $played_days/7);
    $played_years = sprintf("%d", $played_weeks/52);
	  $played_hours = sprintf("%d", ($this->time_taken - ($played_days*86400))/3600);
	  $played_minutes = sprintf("%d", ($this->time_taken -($played_days*86400)- ($played_hours*3600))/60);
	  $played_seconds = sprintf("%d", ($this->time_taken - ($played_days*86400) - ($played_hours*3600) - ($played_minutes*60)));
    if ($this->time_taken > 86399)
    {
       if($played_days == 1)
       {
         return sprintf($lang['games_day'], $played_days, $played_hours, $played_minutes, $played_seconds);
       }
       else if($played_days > 14)
       {
          if(($played_days-($played_weeks*7)) == 0)
          {
            return sprintf($lang['games_weeks_only'], $played_weeks);
          }
          else
          {
            return sprintf($lang['games_weeks'], $played_weeks, $played_days-($played_weeks*7));
          }
       }
       else
       {
         return sprintf($lang['games_days'], $played_days, $played_hours, $played_minutes, $played_seconds);
       }
    }
    else if ($this->time_taken > 3599)
    {
       return sprintf($lang['games_hours'], $played_hours, $played_minutes, $played_seconds);
    }
    else if ($this->time_taken > 59)
    {
      return sprintf($lang['games_minutes'], $played_minutes, $played_seconds);
    }
    else if ($this->time_taken > 0)
    {
      return sprintf($lang['games_seconds'], $this->time_taken);
    }
    else
    {
      return $lang['games_unrecorded'];
    }
  }
//
//  Pass the Mode of operation.
//
  function pass_mode($sort_mode)
  {
    switch( $sort_mode )
    {
    	case 'alphabetical':
		    $this->order_by = "game_desc";
		    break;
		
    	case 'allow_guest':
		    $this->order_by = "allow_guest";
		    break;

      case 'game_instructions':
		    $this->order_by = "instructions";
		    break;
		
	    case 'game_charge':
		    $this->order_by = "game_charge";
		    break;
		
	    case 'game_bonus':
		    $this->order_by = "game_bonus";
    		break;
		
    	case 'game_played':
		    $this->order_by = "played";
    		break;

      case 'rating':
        $this->order_by = "rating";
        break;
		
      case 'comments':
        $this->order_by = "comment_count";
        break;
		
    	case 'date_added':
    		$this->order_by = "date_added";
    		break;

    	default:
		    $this->order_by = "game_id";
		    break;
    }
    return $this->order_by;
  }
//
//  This is used to sort out each place holder..
//
  function check_place($game_id)
  {
    global $db, $lang;
	$game_name_sql = $db->sql_escape((string) $this->game_name);
	$sort = ($this->sort === 'ASC') ? 'ASC' : 'DESC';

		$sql = "SELECT player_id FROM " . iNA_SCORES . "
			WHERE game_name = '" . $game_name_sql . "'
			ORDER BY score ".$sort.", date ASC
      LIMIT 0,3";
		if(!$result = $db->sql_query($sql)) 
		{
			message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql); 
		}
  
		$sql = "SELECT player_id FROM " . iNA_AT_SCORES . "
			WHERE game_name = '" . $game_name_sql . "'
			ORDER BY score ".$sort.", date ASC
      LIMIT 0,3";
		if(!$result = $db->sql_query($sql)) 
		{
			message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql); 
		}
  }
//
//  Update the Games table with the Current Highscores Users  
//
  function update_high($game_id)
  { 
    global $db, $lang;
	$game_id = (int) $game_id;

    if(empty($this->game_name) && $game_id > 0)
    {
  		$sql = "SELECT game_name FROM " . iNA_GAMES . "
  			WHERE game_id = $game_id";
  		if(!$result = $db->sql_query($sql)) 
  		{
  			message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql); 
  		}
      $game_info = $db->sql_fetchrow($result);
	  if (!$game_info)
	  {
		return FALSE;
	  }
      $this->game_name = $game_info['game_name'];
    }
    else if(empty($this->game_name))
    {
      return FALSE;
    }

    $this->check_place($game_id);
	$game_name_sql = $db->sql_escape((string) $this->game_name);
	$sort = ($this->sort === 'ASC') ? 'ASC' : 'DESC';
    
		$sql = "SELECT player_id FROM " . iNA_SCORES . "
			WHERE game_name = '" . $game_name_sql . "'
			ORDER BY score $sort, date ASC";
		if(!$result = $db->sql_query($sql)) 
		{
			message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql); 
		}
		if ($row = $db->sql_fetchrow($result)) 
    {
      $sql = "UPDATE " . iNA_GAMES . " SET highscore_id = " . (int) $row['player_id'] . "
        WHERE game_name = '" . $game_name_sql . "'";
  		if(!$result = $db->sql_query($sql)) 
  		{
  			message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql); 
  		}
    }
		$sql = "SELECT player_id, username FROM " . iNA_AT_SCORES . ", " . USERS_TABLE . "  
			WHERE game_name = '" . $game_name_sql . "'
			AND player_id = user_id
			ORDER BY score $sort, date ASC";
		if(!$result = $db->sql_query($sql)) 
		{
			message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql); 
		}
		if ($row = $db->sql_fetchrow($result)) 
    {
      $sql = "UPDATE " . iNA_GAMES . " SET at_highscore_id = " . (int) $row['player_id'] . "
        WHERE game_name = '" . $game_name_sql . "'";
  		if(!$result = $db->sql_query($sql)) 
  		{
  			message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql); 
  		}

	  $username_sql = $db->sql_escape($row['username']);
      $sql = "UPDATE " . iNA_AT_SCORES . " SET player_name = '" . $username_sql . "'
		WHERE game_name = '" . $game_name_sql . "'
		AND player_id = " . (int) $row['player_id'];
  		if(!$result = $db->sql_query($sql)) 
  		{
  			message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql); 
  		}
    }
  }
//
//  Automatic score pruning system
//
  function prune_scores($game_id)
  { 
    global $db;

    $game_id = intval($game_id);
    if($game_id < 1 || ($game_id > ($this->last_game_id())))
    {
      return;
    }
//
//  First get the game information
//
    $sql = "SELECT * FROM " . iNA_GAMES . "
      WHERE game_id = '". $game_id ."'";
    $game_info = $db->sql_fetchrow($db->sql_query($sql));
	if (!$game_info)
	{
		return;
	}

    $this->game_name = $game_info['game_name'];
	$game_name_sql = $db->sql_escape((string) $this->game_name);
    if(!$game_info['reverse_list'])
    {
      $sort = 'ASC';
    }
    else
    {
      $sort = 'DESC';
    }
//
//  Update the Games table with the Current Highscore User
//
    $this->update_high($game_id);
    
    if($game_info['highscore_limit'] < 1)
    {
      return;
    }
//
//  Next do the normal scores
//  
    $sql = "SELECT player_id, score FROM " . iNA_SCORES . "
      WHERE game_name = '". $game_name_sql ."'
        ORDER BY score " . $sort;
    if($result = $db->sql_query($sql))
    {
      $scores = $db->sql_fetchrowset($result);

      $total_scores = count($scores);
  
      if($total_scores > $game_info['highscore_limit'])
      {
        $remove_count = $total_scores - $game_info['highscore_limit'];
      
        for($i = 0; $i < $remove_count; $i++)
        {
          $sql = "DELETE FROM ". iNA_SCORES . "
             WHERE game_name = '" . $game_name_sql . "'
             AND player_id = " . (int) $scores[$i]['player_id'] . "
            AND score = " . (float) $scores[$i]['score'];
		  $db->sql_query($sql);
        }
      }
    }

    unset($scores);
//
//  Now do the All time scores (if ON)
//
    if($game_info['at_highscore_limit'] < 1)
    {
      return;
    }
    
    if($this->arcade_config['games_at_highscore'])
    {
      $sql = "SELECT * FROM " . iNA_AT_SCORES . "
        WHERE game_name = '". $game_name_sql ."'
        ORDER BY score " . $sort;
      if ($result = $db->sql_query($sql))
      {
        $scores = $db->sql_fetchrowset($result);

        $total_scores = count($scores);

        if($total_scores > $game_info['at_highscore_limit'])
        {
          $remove_count = $total_scores - $game_info['at_highscore_limit'];

          for($i = 0; $i < $remove_count; $i++)
          {
            $sql = "DELETE FROM ". iNA_AT_SCORES . "
              WHERE game_name = '" . $game_name_sql . "'
              AND player_id = " . (int) $scores[$i]['player_id'] . "
              AND score = " . (float) $scores[$i]['score'];
			$db->sql_query($sql);
          }
        }
      }
    
      unset($scores);
    }
  }
//
//  Save the Information passed :)
//  
  function newscore()
  {
    global $userdata, $db, $lang;

    $newline = '|';
    unset($sql);
    $this->message = '';
	$user_id = (int) $userdata['user_id'];
	$score = (float) $this->score;

    if(!$score || !is_finite($score) || abs($score) > 9999999999.9999 || !$this->game_name || (strtoupper($userdata['username']) == 'TEST'))
    {
      return false;
    }
	$this->score = round($score, 4);
//
//  First Get the Session Information
//
	$session_info = !empty($this->arcade_session) ? $this->arcade_session : $this->get_session();
    if (!$session_info || !isset($session_info['arcade_hash']) || !preg_match('/^[a-f0-9]{32}$/iD', (string) $session_info['arcade_hash']) || ($user_id != ANONYMOUS && (int) $session_info['user_id'] !== $user_id))
    {
      return $lang['session_error'] . $newline;
    }
	$game_name_sql = $db->sql_escape($session_info['game_name']);
	$player_ip_sql = $db->sql_escape(decode_ip($userdata['session_ip']));
	$time_taken = max(0, (int) $this->time_taken);
	$player_name = (string) $this->get_username($user_id);
	$player_name_sql = $db->sql_escape($player_name);
//
//  Get the 1st Place Score and User details.
//

    $sql = "SELECT g.game_avail, g.reverse_list, g.score_type, s.score, s.player_id, a.score as at_score, a.player_id as at_player_id
      FROM " . iNA_GAMES . " AS g
      LEFT JOIN " . iNA_SCORES . " AS s on g.game_name = s.game_name
      LEFT JOIN " . iNA_AT_SCORES . " AS a on g.game_name = a.game_name 
      WHERE g.game_name = '" . $game_name_sql . "'
      ORDER BY s.score, a.score";
    $result = $db->sql_query($sql);
    if(!$result)
    {
      return $this->message . $lang['no_game_data'] . $newline;
    }
    $old_score_info = $db->sql_fetchrowset($result);
    $db->sql_freeresult($result);
	if (empty($old_score_info))
	{
		return $lang['no_game_data'] . $newline;
	}
	$this->game_name = $session_info['game_name'];
//
//  Check to see if the Game is Available (else we should ignore the call)
//
    if(($old_score_info[0]['game_avail'] != 1) || ($userdata['user_id'] == ANONYMOUS && !$this->arcade_config['games_guest_highscore']))
    {
      return FALSE;
    }

    if($old_score_info[0]['reverse_list'])
    {
      $this->sort = 'ASC';
      $top_player_id = intval($old_score_info[0]['player_id']);
	  $top_score = (float) $old_score_info[0]['score'];
      $top_at_player_id = intval($old_score_info[0]['at_player_id']);
	  $top_at_score = (float) $old_score_info[0]['at_score'];
      $score_type = intval($old_score_info[0]['score_type']);
    }
    else
    {
      $count = count($old_score_info);
      $top_player_id = intval($old_score_info[count($old_score_info)-1]['player_id']);
	  $top_score = (float) $old_score_info[count($old_score_info)-1]['score'];
      $top_at_player_id = intval($old_score_info[count($old_score_info)-1]['at_player_id']);
	  $top_at_score = (float) $old_score_info[count($old_score_info)-1]['at_score'];
      $score_type = intval($old_score_info[count($old_score_info)-1]['score_type']);
      $this->sort = 'DESC';
    }
    unset($old_score_info);
//
//  Look to see if the user has a highscore and see IF it needs updating.
//
    $sql = "SELECT g.game_id, g.game_desc, s.score, a.score AS at_score FROM " . iNA_GAMES . " g
      LEFT JOIN " . iNA_SCORES . " s ON g.game_name = s.game_name AND s.player_id = " . $user_id . "
      LEFT JOIN " . iNA_AT_SCORES . " a ON g.game_name = a.game_name AND a.player_id = " . $user_id . "
      WHERE g.game_name = '" . $game_name_sql . "'
      ORDER BY s.score, a.score " . $this->sort . " LIMIT 1";
    $result = $db->sql_query($sql);
    if(!$result)
    {
      $this->message .= $lang['no_game_data'] . $newline;
    }
    $old_score_info = $db->sql_fetchrow($result);
    $db->sql_freeresult($result);
	if (!$old_score_info)
	{
		return $this->message . $lang['no_game_data'] . $newline;
	}
    $old_score = (float) $old_score_info['score'];
    $old_at_score = (float) $old_score_info['at_score'];
    $game_id = intval($old_score_info['game_id']);
//
//  Check and Update HighScore
//
		if( $userdata['user_id'] != ANONYMOUS || $this->arcade_config['games_guest_highscore'])
		{
  		if (( ($this->score > $old_score) && $this->sort == 'DESC' ) || ( ($this->score < $old_score || $old_score == 0) && $this->sort == 'ASC' ))
      {
        if($old_score > 0)
        {
          $sql = "UPDATE ". iNA_SCORES ." 
              SET score = ". $this->score . ", date = " . time() . ", time_taken = " . $time_taken . ", player_ip = '$player_ip_sql'
            WHERE game_name = '$game_name_sql'
              AND player_id = ".$user_id;
        }
        else
        {
          $sql = "INSERT INTO " . iNA_SCORES . " (game_name, player_id, player_ip, score, date, time_taken) VALUES ('$game_name_sql', $user_id, '$player_ip_sql', ". $this->score . ", ".time().", $time_taken)";
        }
        $result = $db->sql_query($sql);
        if(!$result)
        {
          $this->message .= $lang['no_score_insert'] . $newline;
        }
      }
    }
//
//  Check and Update All Time Highscore.
//
		if ($userdata['user_id'] != ANONYMOUS && $this->arcade_config['games_at_highscore'])
		{
  		if (( ($this->score > $old_at_score) && $this->sort == 'DESC' ) || ( ($this->score < $old_at_score || $old_score == 0) && $this->sort == 'ASC' ))
      {
        if($old_at_score > 0)
        {
          $sql = "UPDATE ". iNA_AT_SCORES ." 
            SET score = ". $this->score . ", date = " . time() . ", time_taken = " . $time_taken . ", player_ip = '$player_ip_sql'
            WHERE game_name = '$game_name_sql'
              AND player_id = ".$user_id;
        }
        else
        {
          $sql = "INSERT INTO " . iNA_AT_SCORES . " (game_name, player_id, player_ip, player_name, score, date, time_taken) VALUES ('$game_name_sql', $user_id, '$player_ip_sql', '$player_name_sql', ". $this->score . ", ".time().", $time_taken)";
        }
        $result = $db->sql_query($sql);
        if(!$result)
        {
          $this->message .= $lang['no_score_insert'] . $newline;
        }
      }
//
//  Check and update the Top Player Info
//
  		if (( ($this->score > $top_score) && $this->sort == 'DESC' ) || ( ($this->score < $top_score || $top_score == 0) && $this->sort == 'ASC' ))
  		{
		   swap_place($top_player_id, $user_id,'first_places',$old_score_info);
      }
  		if (( ($this->score > $top_at_score) && $this->sort == 'DESC' ) || ( ($this->score < $top_at_score || $top_score == 0) && $this->sort == 'ASC' ))
      {
		 swap_place($top_at_player_id, $user_id, 'at_first_places',$old_score_info);
      }
      unset($old_score_info);

      if($userdata['user_level'] == ADMIN && $score_type == 0 && $this->score_type > 0)
      {
        $sql = "UPDATE " . iNA_GAMES . " SET score_type = " . $this->score_type . "
            WHERE game_name = '" . $game_name_sql . "'";
        $result = $db->sql_query($sql);
        if(!$result)
        {
          $this->message .= $lang['no_game_data'] . $newline;
        }
      }
//
//  Check and Update Monthly Highscore
//
		if ($this->score >= 0 && $this->score <= 99999999.9999)
		{
  		$sql = "SELECT highscore_id, highscore_score FROM " . iNA_HIGHSCORES . "
			WHERE highscore_year = '".date('Y')."'
				AND highscore_mon = '".date('m')."'
				AND highscore_game = '" . $game_name_sql . "'
  			ORDER BY highscore_score ".$this->sort."
  			LIMIT 0,1";
  		if(!$result = $db->sql_query($sql))
  		{
  			$this->message .= $lang['no_score_data'] . $newline;
  		}
  		$highscore = $db->sql_fetchrow($result);
      $db->sql_freeresult($result);
		if (!$highscore || $highscore['highscore_score'] === '')
  		{
// Add to Highscores list
  			$sql = "INSERT INTO " . iNA_HIGHSCORES . " (highscore_year, highscore_mon, highscore_game, highscore_player, highscore_score, highscore_date)
				VALUES ('".date('Y')."', '".date('m')."', '$game_name_sql', '$player_name_sql', ". $this->score .", " . time() . ")";
  			if( !$result = $db->sql_query($sql) )
  			{
  				$this->message .= $lang['no_score_insert'] . $newline;
  			}
  		}
  		else
  		{
// Update the Highscores list
    		if (( ($this->score > $highscore['highscore_score']) && $this->sort == 'DESC' ) || ( ($this->score < $top_score || $highscore['highscore_score'] == 0) && $this->sort == 'ASC' ))
  			{
				$sql = "UPDATE " . iNA_HIGHSCORES . "
					SET highscore_player = '$player_name_sql', highscore_score = ".$this->score.", highscore_date = " . time() . "
					WHERE highscore_id = ".(int) $highscore['highscore_id'];
  				if( !$result = $db->sql_query($sql) )
  				{
  					$this->message .= $lang['no_score_update'] . $newline;
  				}
  			}
      }
		}
    }
    $this->prune_scores($game_id);

    return ($this->message);
  }
//
//  Return USERNAME from passed USER_ID
//
  function get_username($user_id)
  {
    global $db;
		$sql = "SELECT username FROM " . USERS_TABLE . "
			WHERE user_id = " . (int) $user_id;
		if($result = $db->sql_query($sql))
		{
  		$info = $db->sql_fetchrow($result);
		if (!$info)
		{
			return FALSE;
		}
  		$this->user_name = $info['username'];
  		
  		return ($this->user_name);
    }
    return FALSE;  
  }
//
//  Return USER_ID from passed USERNAME
//
  function get_user_id($username)
  {
    global $db;
	$username_sql = $db->sql_escape((string) $username);
		$sql = "SELECT user_id FROM " . USERS_TABLE . "
			WHERE username = '". $username_sql . "'";
		if($result = $db->sql_query($sql))
		{
  		$info = $db->sql_fetchrow($result);
      $db->sql_freeresult($result);
      $this->user_id = $info['user_id'];
      
  		return $this->user_id;
    }
    return FALSE;  
  }
//
//
//
  function get_tour_name($tour_id)
  {
    global $db;
		$sql = "SELECT tour_name FROM " . iNA_TOUR  . "
			WHERE tour_id = ". (int) $tour_id;
		if($result = $db->sql_query($sql))
		{
  		$info = $db->sql_fetchrow($result);
      $db->sql_freeresult($result);   
  		return $info['tour_name'];
    }
    return FALSE;  
  }
//
//
//
  function get_tour_id($tourname)
  {
    global $db;
	$tourname_sql = $db->sql_escape((string) $tourname);
		$sql = "SELECT tour_id FROM " . iNA_TOUR . "
			WHERE tour_name = '". $tourname_sql . "'";
		if($result = $db->sql_query($sql))
		{
  		$info = $db->sql_fetchrow($result);
      $db->sql_freeresult($result);   
  		return $info['tour_id'];
    }
    return FALSE;  
  }
//
//  New total highscores function (does not compute but uses the ina_user_data table)
//
  function total_highscores($player_id, $table = iNA_SCORES)
  {
    global $db, $lang;

    $sql = "SELECT * FROM " . iNA_USER_DATA . "
      WHERE user_id = " . (int) $player_id;
   	if ( !($best_result = $db->sql_query($sql)) )
   	{
   		message_die(GENERAL_ERROR, $lang['no_game_total'], '', __LINE__, __FILE__, $sql);
   	}
	$row = $db->sql_fetchrow($best_result);
	if (!$row)
	{
		$row = array(
			'first_places' => 0, 'second_places' => 0, 'third_places' => 0,
			'first_list' => '', 'second_list' => '', 'third_list' => '',
			'at_first_places' => 0, 'at_second_places' => 0, 'at_third_places' => 0,
			'at_first_list' => '', 'at_second_list' => '', 'at_third_list' => ''
		);
	}

    $player[0] = $player_id;
  
    if($table == iNA_SCORES)
    {
      $player[1] = $row['first_places'];
      $player[2] = $row['second_places'];
      $player[3] = $row['third_places'];
      $player[4] = $row['first_list'];
      $player[5] = $row['second_list'];
      $player[6] = $row['third_list'];
    }
    else
    {
      $player[1] = $row['at_first_places'];
      $player[2] = $row['at_second_places'];
      $player[3] = $row['at_third_places'];
      $player[4] = $row['at_first_list'];
      $player[5] = $row['at_second_list'];
      $player[6] = $row['at_third_list'];
    }
    return $player;  
  }
//
//  Check and Return the sort methods based on 0 & 1
//
  function sort_method($method)
  {
    if ($method > 0)
    {
      return $this->sort = "ASC";
    }
    else
    {
      return $this->sort = "DESC";
    }
  }
//
//  Pass the Vars sent to the Arcade (idea taken from phpBB3)
//
  function pass_var($var, $type, $quiet = false)
  {
    global $db, $HTTP_GET_VARS, $HTTP_POST_VARS, $userdata;
//    
//  Get the Varible
//
    if(isset($HTTP_GET_VARS[$var]))
    {
      $passed_var = $HTTP_GET_VARS[$var];
    }
    else if(isset($HTTP_POST_VARS[$var]))
    {
      $passed_var = $HTTP_POST_VARS[$var];
      if(!is_array($passed_var) && $quiet == false && ($this->arcade_config['games_use_log'] == 1))
      {
		$log_value = $db->sql_escape(substr($var . '=>' . (string) $passed_var, 0, 1024));
        $post_sql = "INSERT INTO " . iNA_LOG . " (user_id, name, value, date) 
          VALUES (" . (int) $userdata['user_id'] . ", 'POST', '$log_value', " . time() . ")";
        $db->sql_query($post_sql);
      }
    }
    else
    {
      return (is_int($type)) ? 0 : FALSE;
    }
	if (is_array($passed_var) && !is_array($type))
	{
		return is_int($type) ? 0 : false;
	}
//
//  Check that we have the correct type passed to us from the URL
//
    if(is_array($type))
    {
      return $passed_var;
    }
    else if(is_int($type))
    {
      $passed_var = empty($passed_var) ? 0 : doubleval($passed_var);
	  if (!is_finite($passed_var))
	  {
		$passed_var = 0;
	  }
    }
    else
    {
      $passed_var = substr(trim(htmlspecialchars((string) $passed_var)), 0, 4096);
    }
    return $passed_var;
  }
//
//  I can never get this from pre-mods so, collect it here.
//
  function get_sid()
  {
    global $board_config, $_COOKIE;
    
    $this->sid = $this->pass_var('sid', '');
    if(!$this->sid)
    {
      $cookie_name = $board_config['cookie_name'] . '_sid';
      $this->sid = isset($_COOKIE[$cookie_name]) ? $_COOKIE[$cookie_name] : '';
    }
    
    return $this->sid;
  }
//
//  A Simple Routine to get the version info from the DB
//
//  This information is checked every time the file activity.php and is stored in $arcade->version
//  so can be used thoughout the mod (saving SQL queries) - 2.1.2 Cache Added to reduce SQL even more ;)
//
  function version($cache_path = './')
  {
  	global $db;
  	
  	$this->get_sid();
  	
  	$this->version = $this->read_cache('version', 86400, $cache_path);
  	if(!$this->version)
  	{
      $this->version = $this->arcade_config('version', $cache_path);
      $this->write_cache('version', $this->version, $cache_path);
    }
  	return $this->version;
  } 
//
//  Load arcade config value from either CACHE or the database for the arcade
//
  function arcade_config($arcade_config_name, $cache_path = './')
  {
  	global $db;

    $sql = "SELECT config_value FROM " . iNA . "
      WHERE config_name = 'config_cache'";
    $config_info = $db->sql_fetchrow($db->sql_query($sql));
  	$cache_time = $config_info['config_value'];
    $this->arcade_config = $this->read_cache('config', $cache_time, $cache_path);
  	if(!$this->arcade_config)
  	{
      $build_array = array();
      $sql = "SELECT * FROM " . iNA;
    	$ina_info = $db->sql_fetchrowset($db->sql_query($sql));
    	if(is_array($ina_info))
    	{
        foreach ($ina_info as $key => $value)
        {
          $build_array[$value['config_name']]  = $value['config_value'];
        }      
    		unset($ina_info);
      	$this->arcade_config = $build_array;
        $this->write_cache('config', $this->arcade_config, $cache_path);
      }
    }
    return $this->arcade_config[$arcade_config_name];
  } 
//
//  Cache File Handling (default cache = 5 Minutes)
//
  function read_cache($cache_file, $cache_time = 300, $cache_prefix = './')
  {
	if (!preg_match('/^[a-z0-9_-]+$/iD', (string) $cache_file))
	{
		return FALSE;
	}

    if(empty($this->arcade_config['use_cache']) && $cache_file != 'config')
    {
      return FALSE;
    }

    $cache_file = $cache_prefix . 'cache/arcade_' . $cache_file . '.cache';
    if(@file_exists($cache_file)) 
    {  
      $arcade_cache = phpbb_data_cache_read($cache_file);
	  if(is_array($arcade_cache) && isset($arcade_cache['cached']) && array_key_exists('data', $arcade_cache) &&
		is_numeric($arcade_cache['cached']) && (int) $arcade_cache['cached'] > (time() - (int) $cache_time))
      {
		return $arcade_cache['data'];
      }
    } 
    return FALSE;
  }

  function write_cache($cache_file, $cache_data, $cache_prefix = './')
  {
	if(!$this->arcade_config['use_cache'] || !preg_match('/^[a-z0-9_-]+$/iD', (string) $cache_file))
    {
      return FALSE;
    }
    $cache_file = $cache_prefix . 'cache/arcade_' . $cache_file . '.cache';
	return phpbb_data_cache_write($cache_file, array(
		'cached' => time(),
		'data' => $cache_data,
	));
  }
  
  function clear_cache($cache_file, $cache_prefix = './../')
  { 
	if (!preg_match('/^[a-z0-9_-]+$/iD', (string) $cache_file))
	{
		return FALSE;
	}
//
//  Remove arcade_config cache file.
//
	$cache_file = $cache_prefix . 'cache/arcade_' . $cache_file . '.cache';
    @unlink($cache_file);
    return TRUE;
  }
//
//  Tournament Update
//  
  function tour_score()
  { 
    global $db;
	$game_name_sql = $db->sql_escape((string) $this->game_name);
	$tour_id = (int) $this->tour_id;
	$user_id = (int) $this->user_id;
	$score = (float) $this->score;
	if (!is_finite($score) || abs($score) > 9999999999.9999 || $tour_id < 1)
	{
		return false;
	}
    
    $sql = "SELECT * FROM " . iNA_GAMES . "
	  WHERE game_name = '" . $game_name_sql . "'";
   	$game_info = $db->sql_fetchrow($db->sql_query($sql));
    
    $sql = "SELECT * FROM " . iNA_TOUR_DATA . "
	  WHERE tour_id = ". $tour_id . "
		AND game_name = '" . $game_name_sql . "'";
   	$tour_data = $db->sql_fetchrow($db->sql_query($sql));

    if(!$game_info || !$tour_data)
    {
      return false;
    }

    $old_score = doubleval($tour_data['top_score']);       	
    if(($old_score < $this->score && !$game_info['reverse_list']) || ($old_score > $this->score && $game_info['reverse_list']))
    {
      $sql = "UPDATE " . iNA_TOUR_DATA . "
		SET top_score = ". $score .", top_player = ". $user_id ."
		WHERE tour_id = " . $tour_id . "
		AND game_name = '" . $game_name_sql . "'";
    	if( !$result = $db->sql_query($sql) )
     	{
         return false;
     	}
    }
//
//  Update the Users score
//
    $sql = "SELECT gamedata FROM " . iNA_TOUR_PLAY . "
	  WHERE user_id = " . $user_id . "
	  AND tour_id = ". $tour_id;
  	if( !$result = $db->sql_query($sql) )
   	{
   		return false;
    }
    $user_GameData = $db->sql_fetchrow($result);
	if (!$user_GameData)
	{
		return false;
	}
    $old_GameData = phpbb_safe_unserialize_array(stripslashes((string) $user_GameData['gamedata']));
	if (!is_array($old_GameData))
	{
		$old_GameData = array();
	}
    $games_count = count($old_GameData);

    if(is_array($old_GameData))
    {
      for($i = 0; $i < $games_count; $i++)
      {
		if (!isset($old_GameData[$i]) || !is_array($old_GameData[$i]) || !isset($old_GameData[$i]['game_name']) || !is_scalar($old_GameData[$i]['game_name']))
		{
			continue;
		}
        if((string) $old_GameData[$i]['game_name'] === (string) $game_info['game_name'])
        {
		  $old_score = isset($old_GameData[$i]['score']) && is_scalar($old_GameData[$i]['score']) ? doubleval($old_GameData[$i]['score']) : 0.0;
          if(($old_score < $this->score && !$game_info['reverse_list']) || ($old_score > $this->score && $game_info['reverse_list']))
          {
            $old_GameData[$i]['score'] = $this->score;
          }
          continue;
        }
      }
    }
    $GameData = $old_GameData;
	$serialized_game_data_sql = $db->sql_escape(serialize($GameData));

    $sql = "UPDATE " . iNA_TOUR_PLAY . "
	  SET last_played_game = '". $game_name_sql . "', last_played_time = ".time().", gamedata = '". $serialized_game_data_sql ."'
	  WHERE user_id = ". $user_id . "
	  AND tour_id = ". $tour_id;
  	if( !$result = $db->sql_query($sql) )
   	{
   		return false;
   	}
   return true;
  }
//
//  Get Arcade Session Information
//
  function get_session()
  { global $db, $lang, $userdata, $board_config, $_COOKIE;
  
    $this->user_id        = $userdata['user_id'];
    $this->arcade_hash    = $this->pass_var('arcade_hash', '');
    $cookie_name = $board_config['cookie_name'] . '_arcade';
	$this->arcade_cookie  = (isset($_COOKIE[$cookie_name]) && is_scalar($_COOKIE[$cookie_name])) ? (string) $_COOKIE[$cookie_name] : '';
	$minimum_start = time() - 86400;
    
    if(!empty($this->arcade_hash))
    {
		if(!preg_match('/^[a-f0-9]{32}$/iD', $this->arcade_hash))
		{
			$this->message_die(GENERAL_ERROR, $lang['incorrect_game_info_data'] . $lang['newscore_close']);
		}
		if ($this->user_id == ANONYMOUS && !hash_equals((string) $this->arcade_cookie, (string) $this->arcade_hash))
		{
			$this->message_die(GENERAL_ERROR, $lang['incorrect_game_info_data'] . $lang['newscore_close']);
		}
		$sql = "SELECT * FROM " . iNA_SESSIONS . "
			WHERE arcade_hash = '" . $this->arcade_hash . "'" .
			(($this->user_id != ANONYMOUS) ? " AND user_id = " . (int) $this->user_id : " AND user_id = " . ANONYMOUS) . "
			AND start_time >= " . $minimum_start . "
			LIMIT 1";
    }
    else if($this->user_id != ANONYMOUS)
    {
      $sql = "SELECT * FROM " . iNA_SESSIONS . "
		   WHERE user_id = " . (int) $this->user_id . "
		   AND start_time >= " . $minimum_start . "
		   ORDER BY start_time DESC
		   LIMIT 1";
    }
    else
    {
	  if(!preg_match('/^[a-f0-9]{32}$/iD', $this->arcade_cookie))
      {
        $this->message_die(GENERAL_ERROR, $lang['no_cookie_data'] . $lang['newscore_close']); 
      }
      $sql = "SELECT * FROM " . iNA_SESSIONS . "
		   WHERE arcade_hash = '" . $this->arcade_cookie . "'
		   AND user_id = " . ANONYMOUS . "
		   AND start_time >= " . $minimum_start . "
		   LIMIT 1";
    }
    if(!$result = $db->sql_query($sql)) 
    {
    	$this->message_die(GENERAL_ERROR, $lang['session_data_error'] . $lang['newscore_close'], "", __LINE__, __FILE__, $sql); 
    }
    $this->arcade_session = $db->sql_fetchrow($result);
    if (!$this->arcade_session)
    {
	  $error_text_sql = $db->sql_escape($lang['no_session_data']);
      $err_sql = "INSERT INTO " . iNA_LOG . " (user_id, name, value, date) 
		  VALUES (".(int) $userdata['user_id'].", 'ERROR', '".$error_text_sql."', ".time().")";
      $db->sql_query($err_sql);
      message_die(GENERAL_ERROR, $lang['no_session_data'] . $lang['newscore_close']);
    }

    return $this->arcade_session;
  }
//
//  Some arcade issues require the program to continue, we need these LOGGED
//  
  function log_error( $line, $file, $sql )
  {
    global $db, $userdata;
    
    if($this->arcade_config['games_use_log'] == 1)
    {
  		$sql_error = $db->sql_error();

      $error = $sql_error['message'] . ' ' . $sql;    

	  $error_sql = $db->sql_escape(substr($error, 0, 4000));
      $err_sql = "INSERT INTO " . iNA_LOG . " (user_id, name, value, date)
		  VALUES (".(int) $userdata['user_id'].", 'ERROR', '".$error_sql."', ".time().")";
      $db->sql_query($err_sql);
    }
  
  }
//
//  Arcade's version of Message die 
//    (repliced from phpBB's message_die() to make my life simpler)
//
  function message_die( $type, $text = '', $title = '', $line = '', $file = '', $sql = '')
  {
    global $db, $userdata, $sql_error;

    if($this->arcade_config['games_use_log'] == 1)
    {
  		$sql_error = $db->sql_error();
 
      $arcade_text = $text . ' #' . $sql_error['code'] . '->' . $sql_error['message'] . '=>' .$sql;
 
    	if ( DEBUG )
    	{
        $text .= '<br><br>' . $sql_error['message'];
      }

	  $arcade_text_sql = $db->sql_escape(substr($arcade_text, 0, 4000));
      $err_sql = "INSERT INTO " . iNA_LOG . " (user_id, name, value, date)
		  VALUES (".(int) $userdata['user_id'].", 'ERROR', '".$arcade_text_sql."', ".time().")";
      $db->sql_query($err_sql);
    }
//
//  now call the phpBB version to close the system down :)
//
    message_die($type, $text, $title, $line, $file, $sql);
  }
//
//  End of arcade class
//
}
$arcade = new arcade();

/******************************************************************************
*  Copyright :) yer right..! 
******************************************************************************/
?>
