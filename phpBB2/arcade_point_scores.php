<?php
/***************************************************************************
 *                            arcade_point_scores.php
 *                            -----------------------
 *		Version  		: 2.0.0
 *		Email			: painkiller@sympatico.ca
 *		Site			: http://deadzone.runecentral.com
 *      Original Author : Gumfuzi - http://www.gumfuzi.com
 *		Copyright		: Painkiller January 12, 2007
 *
 ***************************************************************************
                          >>>> Instructions <<<<

 - Run the attached SQL install.php from the root of your phpBB directory
 - Upload arcade_point_scores.php to your phpBB root directory
 - Upload arcade_point_scores.tpl to each of your template directories

 ***************************************************************************/

define('IN_PHPBB', true);

if(isset($_GET['phpbb_root_path']))
{
	die("Hacking attempt");
}
$phpbb_root_path = './';

include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);

$filename = basename(__FILE__);
$phpEx    = substr(strrchr(__FILE__, '.'), 1);

$userdata = session_pagestart($user_ip, PAGE_INDEX);
init_userprefs($userdata);

if (!$userdata['session_logged_in'] )
{
  redirect(append_sid("login.$phpEx?redirect=arcade_point_scores.$phpEx", true));
}
//
// Change "Deadzone" to your sitename
//
$page_title = 'The Best Players At '. $board_config['sitename'];

include($phpbb_root_path . 'includes/page_header.'.$phpEx);

$template->set_filenames(array('body' => 'arcade_point_scores.tpl'));

######## Set Values Here - Begin ########

$month = 1;      # Points for Current HighScores
$alltime = 4;    # Points for All Time HighScores
$ui = 180;       # Cache update interval in seconds
$mon2 = 1;       # 2 month interval between reset of Current Scores?  0 = no // 1 = yes

######## Change data to suit your requirements - End ########

$time = time();
$s_time = microtime();
$sx_time = $time + $s_time;
$time_date = create_date($board_config['default_dateformat'], $time, $board_config['board_timezone']);

$sql = "SELECT * from phpbb_config WHERE config_name = 'last_update_points'";
if(!$result = $db->sql_query($sql)) {
   message_die(GENERAL_ERROR, 'Error retrieving last_update_points', '', __LINE__, __FILE__, $sql);
}
while($row = $db->sql_fetchrow($result))
{
   $tlu = $row['config_value'];
   $tlu_date = create_date($board_config['default_dateformat'], $tlu, $board_config['board_timezone']);
}

if (($tlu + $ui) < $time)
{
   $infotext = "List generated on " . $time_date;

   $sql = 'DELETE FROM `phpbb_total_scores`';
   $db->sql_query($sql);

   $t_year = date("Y", time());
   $t_month = date("m", time());
$mon_sql = "`highscore_mon` = $t_month AND `highscore_year` = $t_year";
if ($mon2 == 1)
{
   if ($t_month >= 2)
   {
      if ($t_month <= 10)
      {
         $t_month_alt = "0" . $t_month - 1;
         $t_year_alt = $t_year;
      }
      elseif ($t_month <= 12)
      {
         $t_month_alt = $t_month - 1;
         $t_year_alt = $t_year;
      }
   }
   elseif ($t_month == 1)
   {
      $t_month_alt = $t_month = 12;
      $t_year_alt = $t_year - 1;
   }
   $mon_sql =  "(" . $mon_sql . ") OR (`highscore_mon` = $t_month_alt AND `highscore_year` = $t_year_alt)";
}

$sql="SELECT highscore_player, Count(*) AS Number FROM `phpbb_ina_highscore` WHERE $mon_sql GROUP BY highscore_player";
if(!$result = $db->sql_query($sql)) {
   message_die(GENERAL_ERROR, 'Error retrieving highscore_player', '', __LINE__, __FILE__, $sql);
}
while($row = $db->sql_fetchrow($result))
{
   $mon[$row['highscore_player']] = $row['Number'];
}

   $sql="SELECT s.game_name, u.user_id, u.username AS player, s.player_id, s.score, s.date, g.reverse_list, g.game_show_score,    g.game_avail
      FROM phpbb_ina_at_scores s, phpbb_ina_games g, phpbb_users u
      WHERE u.user_id = s.player_id AND s.game_name = g.game_name AND g.reverse_list = 0 AND g.game_avail = 1 AND g.game_show_score = 1
      ORDER BY game_name ASC, score DESC, date ASC";

   if(!$result = $db->sql_query($sql))
   {
      message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql);
   }
   $last_game = "";
   while($row = $db->sql_fetchrow($result))
   {
      if ($row['game_name'] <> $last_game)
      {
         $i++;
         $best_player[$i] = $row['player'];
         $hi[$row['player']] = $hi[$row['player']] +1;
      }
      $last_game = $row['game_name'];
   }

   $sql="SELECT s.game_name, u.user_id, u.username AS player, s.player_id, s.score, s.date, g.reverse_list, g.game_show_score, g.game_avail
      FROM phpbb_ina_at_scores s, phpbb_ina_games g, phpbb_users u
      WHERE u.user_id = s.player_id AND s.game_name = g.game_name AND g.reverse_list = 1 AND g.game_avail = 1 AND g.game_show_score = 1
      ORDER BY game_name ASC, score ASC, date ASC";

   if(!$result = $db->sql_query($sql))
   {
      message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql);
   }
   $last_game = "";
   while($row = $db->sql_fetchrow($result))
   {
      if ($row['game_name'] <> $last_game)
      {
         $i++;
         $best_player[$i] = $row['player'];
         $hi[$row['player']] = $hi[$row['player']] +1;
      }
      $last_game = $row['game_name'];
   }

   $sql = "SELECT username FROM " . USERS_TABLE . " ORDER BY username";
   if( !$result = $db->sql_query($sql) )
   {
      message_die(GENERAL_ERROR, $lang['no_user_data'], "", __LINE__, __FILE__, $sql);
   }
   $sql="INSERT INTO `phpbb_total_scores` VALUES ";
   $c = 0;
   while($row = $db->sql_fetchrow($result))
   {
      $user = $row['username'];
      if ($mon[$user] > 0 OR $hi[$user] > 0)
      {
         $c = $c + 1;
         if ($c >=2) { $sql .= ", "; }
         $total = $mon[$user] * $month + $hi[$user] * $alltime;
         if ($mon[$user] == 0) {$mon[$user] = "0";}
         if ($hi[$user] == 0) {$hi[$user] = "0";}
         $sql .= "($c, '$user', {$mon[$user]}, {$hi[$user]}, $total)";
      }
   }
   $db->sql_query($sql);
   unset($mon, $hi, $total, $best_player);

   $sql = "UPDATE phpbb_config SET config_value = '$time' WHERE config_name = 'last_update_points' LIMIT 1";
   if(!$result = $db->sql_query($sql))
   {
      message_die(GENERAL_ERROR, 'Error updating last_update points', '', __LINE__, __FILE__, $sql);
   }
}
else
{
   $infotext = "List loaded from cache on " . $tlu_date . "<br />Next list generation in " . ($ui - ($time - $tlu)) . " seconds";
}

$sql = 'SELECT * FROM `phpbb_total_scores` ORDER BY total DESC, hi_total DESC';
if( !$result = $db->sql_query($sql) )
{
   message_die(GENERAL_ERROR, $lang['no_user_data'], "", __LINE__, __FILE__, $sql);
}
while($row = $db->sql_fetchrow($result))
{
   $x = $x + 1;
   $template->assign_block_vars("total", array(
      "RANK" => $x,
      "NAME" => $row['player'],
      "MONTH_TOTAL" => $row['mon_total'],
      "AT_TOTAL" => $row['hi_total'],
      "TOTAL" => $row['total']
   ));
}
$akt_ti = time();
$akt_tim = microtime();
$akt_time = $akt_ti + $akt_tim;
$template->assign_vars(array(
   'TITLE' => $page_title,
   'C_MONTH' => $month,
   'C_ALL_TIME' => $alltime,
   'INFOTEXT' => $infotext . "<br />List Generated in " . round(($akt_time - $sx_time),4) . " Seconds"
));

$template->pparse('body');
include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>