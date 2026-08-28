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

		$sql = "SELECT player_id FROM " . iNA_SCORES . "
			WHERE game_name = '" . $this->game_name . "'
			ORDER BY score ".$this->sort.", date ASC
      LIMIT 0,3";
		if(!$result = $db->sql_query($sql)) 
		{
			message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql); 
		}
  
		$sql = "SELECT player_id FROM " . iNA_AT_SCORES . "
			WHERE game_name = '" . $this->game_name . "'
			ORDER BY score ".$this->sort.", date ASC
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

    if(empty($this->game_name) && $game_id > 0)
    {
  		$sql = "SELECT game_name FROM " . iNA_GAMES . "
  			WHERE game_id = $game_id";
  		if(!$result = $db->sql_query($sql)) 
  		{
  			message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql); 
  		}
      $game_info = $db->sql_fetchrow($result);
      $this->game_name = $game_info['game_name'];
    }
    else if(empty($this->game_name))
    {
      return FALSE;
    }

    $this->check_place($game_id);
    
		$sql = "SELECT player_id FROM " . iNA_SCORES . "
			WHERE game_name = '" . $this->game_name . "'
			ORDER BY score $this->sort, date ASC";
		if(!$result = $db->sql_query($sql)) 
		{
			message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql); 
		}
		if ($row = $db->sql_fetchrow($result)) 
    {
      $sql = "UPDATE " . iNA_GAMES . " SET highscore_id = " . $row['player_id'] . "
        WHERE game_name = '" . $this->game_name . "'";
  		if(!$result = $db->sql_query($sql)) 
  		{
  			message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql); 
  		}
    }
		$sql = "SELECT player_id, username FROM " . iNA_AT_SCORES . ", " . USERS_TABLE . "  
			WHERE game_name = '" . $this->game_name . "'
			AND player_id = user_id
			ORDER BY score $this->sort, date ASC";
		if(!$result = $db->sql_query($sql)) 
		{
			message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql); 
		}
		if ($row = $db->sql_fetchrow($result)) 
    {
      $sql = "UPDATE " . iNA_GAMES . " SET at_highscore_id = " . $row['player_id'] . "
        WHERE game_name = '" . $this->game_name . "'";
  		if(!$result = $db->sql_query($sql)) 
  		{
  			message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql); 
  		}

      $sql = "UPDATE " . iNA_AT_SCORES . " SET player_name = '" . $row['username'] . "'
        WHERE game_name = '" . $this->game_name . "'";
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

    $this->game_name = $game_info['game_name'];
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
      WHERE game_name = '". $this->game_name ."'
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
             WHERE game_name = '" . $this->game_name . "'
             AND player_id = " . $scores[$i]['player_id'] . "
            AND score = " . $scores[$i]['score'];
          $remove_scores = $db->sql_fetchrowset($db->sql_query($sql));
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
        WHERE game_name = '". $this->game_name ."'
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
              WHERE game_name = '" . $this->game_name . "'
              AND player_id = " . $scores[$i]['player_id'] . "
              AND score = " . $scores[$i]['score'];
            $remove_scores = $db->sql_fetchrowset($db->sql_query($sql));
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
    
    if(!$this->score || !$this->game_name || (strtoupper($userdata['username']) == 'TEST'))
    {
      return false;
    }
//
//  First Get the Session Information
//
    if($userdata['user_id'] != ANONYMOUS)
    {
      $sql = "SELECT * FROM " . iNA_SESSIONS . "
    	   WHERE user_id = " . $userdata['user_id'];
    }
    else
    {
      if(empty($this->arcade_cookie) || (strlen($this->arcade_cookie) <> 32))
      {
    		$this->message = $lang['no_cookie_data'] . $newline; 
      }
      $sql = "SELECT * FROM " . iNA_SESSIONS . "
    	   WHERE arcade_hash = '".$this->arcade_cookie."'";
    }
    if(!$result = $db->sql_query($sql)) 
    {
	   $this->message .= $lang['session_error'] . $newline; 
    }
    $session_info = $db->sql_fetchrow($result);
    $db->sql_freeresult($result);
//
//  Get the 1st Place Score and User details.
//
    $sql = "SELECT g.reverse_list, s.score, s.player_id, a.score as at_score, a.player_id as at_player_id
      FROM " . iNA_GAMES . " AS g
      LEFT JOIN " . iNA_SCORES . " AS s on g.game_name = s.game_name
      LEFT JOIN " . iNA_AT_SCORES . " AS a on g.game_name = a.game_name 
      WHERE s.game_name = '" . $this->game_name . "'
      ORDER BY s.score, a.score";
    $result = $db->sql_query($sql);
    if(!$result)
    {
      $this->message .= $lang['no_game_data'] . $newline;
    }
    $old_score_info = $db->sql_fetchrowset($result);
    $db->sql_freeresult($result);
//
//  Check to see if the Game is Available (else we should ignore the call)
//
    if(($old_score_info[0]['game_avail'] == 1) || ( $userdata['user_id'] == ANONYMOUS && !$this->arcade_config['games_guest_highscore']))
    {
      return FALSE;
    }

    if($old_score_info[0]['reverse_list'])
    {
      $this->sort = 'ASC';
      $top_player_id = intval($old_score_info[0]['player_id']);
      $top_score = intval($old_score_info[0]['score']);
      $top_at_player_id = intval($old_score_info[0]['at_player_id']);
      $top_at_score = intval($old_score_info[0]['at_score']);
      $score_type = intval($old_score_info[0]['score_type']);
    }
    else
    {
      $count = count($old_score_info);
      $top_player_id = intval($old_score_info[count($old_score_info)-1]['player_id']);
      $top_score = intval($old_score_info[count($old_score_info)-1]['score']);
      $top_at_player_id = intval($old_score_info[count($old_score_info)-1]['at_player_id']);
      $top_at_score = intval($old_score_info[count($old_score_info)-1]['at_score']);
      $score_type = intval($old_score_info[count($old_score_info)-1]['score_type']);
      $this->sort = 'DESC';
    }
    unset($old_score_info);
//
//  Look to see if the user has a highscore and see IF it needs updating.
//
    $sql = "SELECT g.game_id, g.game_desc, s.score, a.score AS at_score FROM " . iNA_GAMES . " g
      LEFT JOIN " . iNA_SCORES . " s ON g.game_name = s.game_name AND s.player_id = " . $userdata['user_id'] . "
      LEFT JOIN " . iNA_AT_SCORES . " a ON g.game_name = a.game_name AND a.player_id = " . $userdata['user_id'] . "
      WHERE g.game_name = '" . $this->game_name . "'
      ORDER BY s.score, a.score " . $this->sort . " LIMIT 1";
    $result = $db->sql_query($sql);
    if(!$result)
    {
      $this->message .= $lang['no_game_data'] . $newline;
    }
    $old_score_info = $db->sql_fetchrow($result);
    $db->sql_freeresult($result);
    $old_score = intval($old_score_info['score']);
    $old_at_score = intval($old_score_info['at_score']);
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
              SET score = ". $this->score . ", date = '" . time() . "', time_taken = '" . $this->time_taken . "', player_ip = '".decode_ip($userdata['session_ip'])."'
            WHERE game_name = '".$this->game_name."'
              AND player_id = ".$userdata['user_id'];
        }
        else
        {
          $sql = "INSERT INTO " . iNA_SCORES . " (game_name, player_id, player_ip, score, date, time_taken ) VALUES ('".$this->game_name."', ".$userdata['user_id'].", '".decode_ip($userdata['session_ip'])."', ". $this->score . ", '".time()."', '".$this->time_taken."')";
        }
        $result = $db->sql_query($sql);
        if(!result)
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
            SET score = ". $this->score . ", date = '" . time() . "', time_taken = '" . $this->time_taken . "', player_ip = '".decode_ip($userdata['session_ip'])."'
            WHERE game_name = '".$this->game_name."'
              AND player_id = ".$userdata['user_id'];
        }
        else
        {
          $sql = "INSERT INTO " . iNA_AT_SCORES . " (game_name, player_id, player_ip, player_name, score, date, time_taken ) VALUES ('".$this->game_name."', ".$userdata['user_id'].", '".decode_ip($userdata['session_ip'])."', '".addslashes($this->user_name)."', ". $this->score . ", '".time()."', '".$this->time_taken."')";
        }
        $result = $db->sql_query($sql);
        if(!result)
        {
          $this->message .= $lang['no_score_insert'] . $newline;
        }
      }
//
//  Check and update the Top Player Info
//
  		if (( ($this->score > $top_score) && $this->sort == 'DESC' ) || ( ($this->score < $top_score || $top_score == 0) && $this->sort == 'ASC' ))
  		{
  		   swap_place($top_player_id, $userdata['user_id'],'first_places',$old_score_info);
      }
  		if (( ($this->score > $top_at_score) && $this->sort == 'DESC' ) || ( ($this->score < $top_at_score || $top_score == 0) && $this->sort == 'ASC' ))
      {
         swap_place($top_at_player_id, $userdata['user_id'], 'at_first_places',$old_score_info);
      }
      unset($old_score_info);

      if($userdata['user_level'] == ADMIN && $score_type == 0 && $this->score_type > 0)
      {
        $sql = "UPDATE " . iNA_GAMES . " SET score_type = " . $this->score_type . "
            WHERE game_name = '" . $this->game_name . "'";
        $result = $db->sql_query($sql);
        if(!$result)
        {
          $this->message .= $lang['no_game_data'] . $newline;
        }
      }
//
//  Check and Update Monthly Highscore
//
  		$sql = "SELECT highscore_id, highscore_score FROM " . iNA_HIGHSCORES . "
  			WHERE highscore_year = '".date(Y)."'
   				AND highscore_mon = '".date(m)."'
   				AND highscore_game = '" . $this->game_name . "'
  			ORDER BY highscore_score ".$this->sort."
  			LIMIT 0,1";
  		if(!$result = $db->sql_query($sql))
  		{
  			$this->message .= $lang['no_score_data'] . $newline;
  		}
  		$highscore = $db->sql_fetchrow($result);
      $db->sql_freeresult($result);
  		if ($highscore['highscore_score'] == "")
  		{
// Add to Highscores list
  			$sql = "INSERT INTO " . iNA_HIGHSCORES . " (highscore_year, highscore_mon, highscore_game, highscore_player, highscore_score, highscore_date)
  				VALUES ('".date(Y)."', '".date(m)."', '".$this->game_name."', '".addslashes($this->get_username($userdata['user_id']))."', '$this->score', '" . time() . "')";
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
  					SET highscore_player = '".addslashes($this->get_username($userdata['user_id']))."', highscore_score = '".$this->score."', highscore_date = '" . time() . "'
   						WHERE highscore_id = ".$highscore['highscore_id'];
  				if( !$result = $db->sql_query($sql) )
  				{
  					$this->message .= $lang['no_score_update'] . $newline;
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
			WHERE user_id = " . $user_id;
		if($result = $db->sql_query($sql))
		{
  		$info = $db->sql_fetchrow($result);
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
		$sql = "SELECT user_id FROM " . USERS_TABLE . "
			WHERE username = '". $username . "'";
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
			WHERE tour_id = '". $tour_id . "'";
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
		$sql = "SELECT tour_id FROM " . iNA_TOUR . "
			WHERE tour_name = '". $tourname . "'";
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
      WHERE user_id = $player_id";
   	if ( !($best_result = $db->sql_query($sql)) )
   	{
   		message_die(GENERAL_ERROR, $lang['no_game_total'], '', __LINE__, __FILE__, $sql);
   	}
   	$row = $db->sql_fetchrow($best_result);

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
      if($quiet == false && ($this->arcade_config['games_use_log'] == 1))
      {
        $post_sql = "INSERT INTO " . iNA_LOG . " (user_id, name, value, date) 
          VALUES ('".$userdata['user_id']."', 'POST', '$var=>$passed_var', '".time()."')";
        echo($sql);
        $db->sql_query($post_sql);
      }
    }
    else
    {
      return (is_int($type)) ? 0 : FALSE;
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
    }
    else
    {
      $passed_var = trim(htmlspecialchars($passed_var));
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
    global $db, $phpEx;

    if(empty($this->arcade_config['use_cache']) && $cache_file != 'config')
    {
      return FALSE;
    }

    $cache_file = $cache_prefix . 'cache/arcade_' . $cache_file . '.' .$phpEx;
    if(@file_exists($cache_file)) 
    {  
      @include($cache_file);
      if($file_cached > (time() - $cache_time))
      {
        return unserialize(stripslashes($arcade_data));
      }
    } 
    return FALSE;
  }

  function write_cache($cache_file, $cache_data, $cache_prefix = './')
  {
    global $phpEx;
    if(!$this->arcade_config['use_cache'])
    {
      return FALSE;
    }
    $cache_file = $cache_prefix. 'cache/arcade_' . $cache_file . '.' .$phpEx;
    if(@$f = fopen($cache_file, 'w')) 
    { 
      fwrite($f, "<?php\n//\n// File Generated by the phpBB Arcade Mod - " . date('g:ia - D d M Y') . "\n//\nif ( !defined('IN_PHPBB') )\n{\n	die('Hack attempt');\n}\n\n". '$file_cached = ' . (time()) . ";\n\n" . '$arcade_data = "'. (addslashes(serialize($cache_data))));
      fwrite($f, "\";\n\n//\n// End of Cache File - phpBB Arcade Mod\n//\n?" . ">");  
      fflush($f);
      fclose($f);
      return TRUE;
    }
    return FALSE;
  }
  
  function clear_cache($cache_file, $cache_prefix = './../')
  { 
    global $phpEx;
//
//  Remove arcade_config cache file.
//
    if(!$this->arcade_config['use_cache'])
    {
      return FALSE;
    }
   	$cache_file = $cache_prefix. 'cache/arcade_' . $cache_file . '.' .$phpEx;
   	@CHMOD($cache_file, 0766);
    @unlink($cache_file);
    return TRUE;
  }
//
//  Tournament Update
//  
  function tour_score()
  { 
    global $db;
    
    $sql = "SELECT * FROM " . iNA_GAMES . "
      WHERE game_name = '" . $this->game_name . "'";
   	$game_info = $db->sql_fetchrow($db->sql_query($sql));
    
    $sql = "SELECT * FROM " . iNA_TOUR_DATA . "
      WHERE tour_id = ". $this->tour_id . " 
        AND game_name = '" . $this->game_name . "'";
   	$tour_data = $db->sql_fetchrow($db->sql_query($sql));

    if(!$game_info || !$tour_data)
    {
      return false;
    }

    $old_score = doubleval($tour_data['top_score']);       	
    if(($old_score < $this->score && !$game_info['reverse_list']) || ($old_score > $this->score && $game_info['reverse_list']))
    {
      $sql = "UPDATE " . iNA_TOUR_DATA . "
        SET top_score = ". $this->score .", top_player = '". $this->user_id ."'
          WHERE tour_id = " . $this->tour_id . " 
        AND game_name = '" . $this->game_name . "'";
    	if( !$result = $db->sql_query($sql) )
     	{
         return false;
     	}
    }
//
//  Update the Users score
//
    $sql = "SELECT gamedata FROM " . iNA_TOUR_PLAY . "
      WHERE user_id = " . $this->user_id . "
      AND tour_id = ". $this->tour_id;
  	if( !$result = $db->sql_query($sql) )
   	{
   		return false;
    }
    $user_GameData = $db->sql_fetchrow($result);
    $old_GameData = unserialize(stripslashes($user_GameData['gamedata']));
    $games_count = count($old_GameData );

    if(is_array($old_GameData))
    {
      for($i = 0; $i < $games_count; $i++)
      {
        if($old_GameData[$i]['game_name'] == $game_info['game_name'])
        {
          $old_score = doubleval($old_GameData[$i]['score']);
          if(($old_score < $this->score && !$game_info['reverse_list']) || ($old_score > $this->score && $game_info['reverse_list']))
          {
            $old_GameData[$i]['score'] = $this->score;
          }
          continue;
        }
      }
    }
    $GameData = $old_GameData;

    $sql = "UPDATE " . iNA_TOUR_PLAY . "
      SET last_played_game = '". $this->game_name . "', last_played_time = ".time().", gamedata = '".addslashes(serialize($GameData))."'
        WHERE user_id = ". $this->user_id . "
        AND tour_id = ". $this->tour_id ;
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
    $this->arcade_cookie  = $_COOKIE[$board_config['cookie_name'].'_arcade'];
    
    if(!empty($this->arcade_hash))
    {
     	if(strlen($this->arcade_hash) <> 32)
     	{
     		$this->message_die(GENERAL_ERROR, $lang['incorrect_game_info_data'] . '[' . $this->arcade_hash . ']'. $lang['newscore_close']);
     	}
     	$sql = "SELECT * FROM " . iNA_SESSIONS . "
     		WHERE arcade_hash = '" . $this->arcade_hash . "'";
    }
    else if($this->user_id != ANONYMOUS)
    {
      $sql = "SELECT * FROM " . iNA_SESSIONS . "
     	   WHERE user_id = " . $this->user_id;
    }
    else
    {
      if(empty($this->arcade_cookie) || (strlen($this->arcade_cookie) <> 32))
      {
        $this->message_die(GENERAL_ERROR, $lang['no_cookie_data'] . $lang['newscore_close']); 
      }
      $sql = "SELECT * FROM " . iNA_SESSIONS . "
    	   WHERE arcade_hash = '" . $this->arcade_cookie . "'";
    }
    if(!$result = $db->sql_query($sql)) 
    {
    	$this->message_die(GENERAL_ERROR, $lang['session_data_error'] . $lang['newscore_close'], "", __LINE__, __FILE__, $sql); 
    }
    $this->arcade_session = $db->sql_fetchrow($result);
    if (!$this->arcade_session)
    {
      $err_sql = "INSERT INTO " . iNA_LOG . " (user_id, name, value, date) 
          VALUES ('".$userdata['user_id']."', 'ERROR', '".$lang['no_session_data']."', '".time()."')";
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

      $err_sql = "INSERT INTO " . iNA_LOG . " (user_id, name, value, date) 
          VALUES ('".$userdata['user_id']."', 'ERROR', '".addslashes($error)."', '".time()."')";
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

      $err_sql = "INSERT INTO " . iNA_LOG . " (user_id, name, value, date) 
          VALUES ('".$userdata['user_id']."', 'ERROR', '".addslashes($arcade_text)."', '".time()."')";
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
//
//  Simple Routine to get MX record from DNS Server (used in testing)
//
class mxlookup
{
     var $dns_socket = NULL;
     var $QNAME = "";
     var $dns_packet= NULL;
     var $ANCOUNT = 0;
     var $cIx = 0;
     var $dns_repl_domain;
     var $arrMX = array();

     function __construct($domain, $dns="localhost")
     {
        $this->mxlookup($domain, $dns);
     }

     function mxlookup($domain, $dns="localhost")
     {
        $this->QNAME($domain);
        $this->pack_dns_packet();
        $dns_socket = @fsockopen("udp://$dns", 53);
        if($dns_socket)
        {
          @fwrite($dns_socket,$this->dns_packet,strlen($this->dns_packet));
         $this->dns_reply  = @fread($dns_socket,1);
         $bytes = stream_get_meta_data($dns_socket);
         $this->dns_reply .= @fread($dns_socket,$bytes['unread_bytes']);
         @fclose($dns_socket);
         $this->cIx=6;
         $this->ANCOUNT  = $this->gord(2);
         $this->cIx+=4;
         $this->parse_data($this->dns_repl_domain);
         $this->cIx+=7;

         for($ic=1;$ic<=$this->ANCOUNT;$ic++)
         {
           $QTYPE = ord($this->gdi($this->cIx));
           if($QTYPE!==15)
           {
             die();
           }
           $this->cIx+=9;
           $mxPref = ord($this->gdi($this->cIx));
           $this->parse_data($curmx);
           $this->arrMX[] = array("MX_Pref" => $mxPref, "MX" => $curmx);
           $this->cIx+=3;
         }
       }
     }

     function parse_data(&$retval)
     {
       $arName = array();
       $byte = ord($this->gdi($this->cIx));
       while($byte!==0)
       {
         if($byte==192) //compressed
         {
           $tmpIx = $this->cIx;
           $this->cIx = ord($this->gdi($cIx));
           $tmpName = $retval;
           $this->parse_data($tmpName);
           $retval=$retval.".".$tmpName;
           $this->cIx = $tmpIx+1;
           return;
         }
         $retval="";
         $bCount = $byte;
         for($b=0;$b<$bCount;$b++)
         {
           $retval .= $this->gdi($this->cIx);
         }
         $arName[]=$retval;
         $byte = ord($this->gdi($this->cIx));
       }
       $retval=join(".",$arName);
     }

     function gdi(&$cIx,$bytes=1)
     {
       $this->cIx++;
       return(substr($this->dns_reply, $this->cIx-1, $bytes));
     }

     function QNAME($domain)
     {
       $dot_pos = 0; $temp = "";
       while($dot_pos=strpos($domain,"."))
       {
         $temp  = substr($domain,0,$dot_pos);
         $domain = substr($domain,$dot_pos+1);
         $this->QNAME .= chr(strlen($temp)).$temp;
       }
       $this->QNAME .= chr(strlen($domain)).$domain.chr(0);
     }

     function gord($ln=1)
     {
       $reply="";
       for($i=0;$i<$ln;$i++){
         $reply.=ord(substr($this->dns_reply,$this->cIx,1));
         $this->cIx++;
         }

       return $reply;
     }

     function pack_dns_packet()
     {
       $this->dns_packet = chr(0).chr(1).
                           chr(1).chr(0).
                           chr(0).chr(1).
                           chr(0).chr(0).
                           chr(0).chr(0).
                           chr(0).chr(0).
                           $this->QNAME.
                           chr(0).chr(15).
                           chr(0).chr(1);
     }

}
 
$arcade = new arcade();

/******************************************************************************
*  Copyright :) yer right..! 
******************************************************************************/
?>
