<?php
/*************************************************************************** 
 *                          admin_arcade_scores.php 
 *                          ------------------------ 
 *   begin                : Tuesday, Jan 2nd, 2007
 *   copyright            : (c) 2006-2007 dEfEndEr
 *   email                : defenders_realm@yahoo.com
 *
 *   $Id: admin_arcade_scores.php,v 1.0.1 2007/01/02 12:59:59 dEfEndEr Exp $
 ***************************************************************************
 * 
 *   This program is free software; you can redistribute it and/or modify 
 *   it under the terms of the GNU General Public License as published by 
 *   the Free Software Foundation; either version 2 of the License, or 
 *   (at your option) any later version. 
 * 
 ***************************************************************************/
//
//  Make this file apart of the phpBB system files.
//
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if (!defined('ARCADE_ADMIN')) { define('ARCADE_ADMIN', 1); }
//
//  Make sure the ACP doesn't go and run something.
//
if( !empty($setmodules) )
{
	return;
}
//
//  Set the system ROOT directory
//
$phpbb_root_path = './../';
//
//  Load phpBB System required files
//
require($phpbb_root_path . 'extension.inc');
require('pagestart.' . $phpEx);
//
//  Load the Arcade required files
//
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
//
//  Check the phpBB Arcade Mod version
//
$version = $arcade->version('./../');
//
//  Set filename
//
$file = basename(__FILE__);
//
//  Get the Required Information
//
$mode               = (string) $arcade->pass_var('mode', '');
$allowed_modes      = array('scores', 'at_scores', 'delete', 'delete_at');
if (!in_array($mode, $allowed_modes, true))
{
  message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}
$edit               = max(0, (int) $arcade->pass_var('edit', 0));
$arcade->game_name  = $arcade->pass_var('game_name', '');
$game_id            = max(0, (int) $arcade->pass_var('game_id', 0));
$player_id          = max(0, (int) $arcade->pass_var('player_id', 0));
$score              = (float) $arcade->pass_var('score', 0);
$old_score          = (float) $arcade->pass_var('old_score', 0);
if (!is_finite($score) || !is_finite($old_score) || abs($score) > 9999999999.9999 || abs($old_score) > 9999999999.9999)
{
  message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}
$score = round($score, 4);
$old_score = round($old_score, 4);
$s_mode             = $lang['admin_edit_games'];
$s_action           = append_sid("admin_arcade_games.$phpEx?mode=edit_games");
$s_hidden           = '';
//
//  Set the Table we are working on
//
if($mode === 'scores' || $mode === 'delete')
{
  $table = iNA_SCORES;
  $action = 'delete';
}
else
{
  $table = iNA_AT_SCORES;
  $action = 'delete_at';
}
//
//  Start Work.. Hi-Ho, Hi-Ho, it's off to work we go..!
//
if($game_id > 0)
{
  $sql = "SELECT * FROM " . iNA_GAMES . "
    WHERE game_id = " . $game_id;
  if(!$result = $db->sql_query($sql))
  {
    message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
  }
  $game_info = $db->sql_fetchrow($result);
  if (!$game_info)
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }
  $arcade->game_name = $game_info['game_name'];
}
if(!empty($arcade->game_name))
{
  $sql = "SELECT * FROM " . iNA_GAMES . "
    WHERE game_name = '" . $db->sql_escape(html_entity_decode($arcade->game_name, ENT_QUOTES, 'UTF-8')) . "'";
  if(!$result = $db->sql_query($sql))
  {
    message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
  }
  $game_info = $db->sql_fetchrow($result);
  if (!$game_info)
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }
  $game_id = $game_info['game_id'];
  $arcade->game_name = $game_info['game_name'];
}
if (empty($game_info))
{
  message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}
$arcade->sort_method($game_info['reverse_list']);
//
//  Edited score has been submitted
//
if(isset($HTTP_POST_VARS['submit']) && isset($HTTP_POST_VARS['score']))
{
  phpbb_admin_require_post_session();
  $sql = "UPDATE " . $table . " SET score = " . $score . "
    WHERE player_id = " . $player_id . "
    AND game_name = '" . $db->sql_escape($arcade->game_name) . "'
    AND score = " . (float) $old_score . " LIMIT 1";
    
  if(!$result = $db->sql_query($sql))
  {
    message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
  }
  $arcade->update_high($game_id);
}
//
//  Default View
//
if($mode != '' && $game_id > 0)
{
//
//  Has the user selected to delete the score?
//
  if($mode == 'delete' || $mode == 'delete_at')
  {
    if( !isset($HTTP_POST_VARS['confirm']) )
    {
//
// Was CANCEL Selected ?
//
  	 if( isset($HTTP_POST_VARS['cancel']) )
	   {
        $mode = ($mode == 'delete') ? 'scores' : 'at_scores';
	   }
	   else
	   {
//
// Output Confirm 
//
		$message = sprintf($lang['arcade_score_sure'], $score, $player_id . ' (' . htmlspecialchars($arcade->get_username($player_id), ENT_QUOTES, 'UTF-8') . ')');
		$message .= sprintf($lang['admin_score_options'], append_sid("admin_arcade_scores.$phpEx?mode=$action&amp;game_id=$game_id&amp;score=" . rawurlencode((string) $score) . "&amp;player_id=$player_id"), phpbb_admin_session_field());
    		message_die(GENERAL_MESSAGE, $message, '', __LINE__, __FILE__, $sql);
     }
    }
    else
    {
      phpbb_admin_require_post_session();
//
//  Delete Score Selected.
//  
      $sql = "DELETE FROM " . $table . "
        WHERE game_name = '" . $db->sql_escape($arcade->game_name) . "'
          AND score = " . $score . " 
          AND player_id = " . $player_id . " LIMIT 1";
      if(!$result = $db->sql_query($sql))
      {
        message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
      }
//
//  New we need to check that the highscore is set correctly.
//
      $arcade->update_high($game_id);
      
      $mode = ($mode == 'delete') ? 'scores' : 'at_scores';
    }
  }
//
//  Get the score informatrion
//
  $sql = "SELECT g.game_id, s.*, u.username FROM ". iNA_GAMES ." AS g
      LEFT JOIN ". $table ." AS s ON g.game_name = s.game_name
      LEFT JOIN " . USERS_TABLE . " as u ON s.player_id = u.user_id
        WHERE game_id = " . $game_id . "
        ORDER by s.score " .$arcade->sort . ", date ASC";
	if(!$result = $db->sql_query($sql))
	{
    message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
  }
  $score_info = $db->sql_fetchrowset($result);
//
//  Loop around the score Information.
//
  for($i = 0; $i < count($score_info); $i++)
  {
    if (!isset($score_info[$i]['player_id']) || $score_info[$i]['player_id'] === null)
    {
      continue;
    }
    if($edit > 0 && $edit == $score_info[$i]['player_id'])
    {
      $score = '<input class="post" type="text" size="10" name="score" value="' . $score_info[$i]['score'] . '" />';
      $s_mode = $lang['Submit'];
      $s_action = append_sid("$file");
      $s_hidden = '<input type="hidden" name="game_id" value="'.(int) $game_id.'"><input type="hidden" name="mode" value="'.phpbb_admin_html($mode).'"><input type="hidden" name="player_id" value="'.(int) $score_info[$i]['player_id'].'"><input type="hidden" name="old_score" value="'.phpbb_admin_html($score_info[$i]['score']).'">';
    }
    else
    {
      $score = $arcade->convert_score($score_info[$i]['score']);
    }
    
		$template->assign_block_vars("highscores", array(
		'PLAYER' => phpbb_admin_html(isset($score_info[$i]['username']) ? $score_info[$i]['username'] : ''),
        'SCORE' => $score,
        'DATE' => create_date($board_config['default_dateformat'], $score_info[$i]['date'], $board_config['board_timezone']),
        'TIME' => ($score_info[$i]['time_taken']) ? ($arcade->convert_time($score_info[$i]['time_taken'])) : '',
				'EDIT_IMG' => '<a href="'. append_sid("$file?mode=$mode&amp;edit=".(int) $score_info[$i]['player_id']."&amp;game_id=$game_id&amp;player_id=".(int) $score_info[$i]['player_id']) .'"><img src="./../' . $images['icon_edit'] . '" alt="' . $lang['Edit'] . '" title="' . $lang['Edit'] . '" border="0" /></a>',
				'DELETE_IMG' => '<a href="'. append_sid("$file?mode=$action&amp;game_id=$game_id&amp;player_id=".(int) $score_info[$i]['player_id'] . "&amp;score=".rawurlencode((string) $score_info[$i]['score'])) .'"><img src="./../' . $images['icon_delpost'] . '" alt="' . $lang['Delete'] . '" title="' . $lang['Delete'] . '" border="0" /></a>',
				'IP_IMG' => '<a href="https://network-tools.com/default.asp?host='.rawurlencode($score_info[$i]['player_ip']).'" target="_blank" rel="noopener noreferrer"><img src="./../' . $images['icon_ip'] . '" alt="' . $lang['View_IP'] . '" title="' . $lang['View_IP'] . '" border="0" /></a>'
       ));     
  }
}
//
//  Set template information
//
$template->assign_vars(array(
		'L_SCORE_HEADER' => $lang['admin_score_header'],
		'L_SCORE_INFO' => $lang['admin_score_info'],
		'L_SCORE_EDITOR' => $lang['admin_score_editor'],
		
		'GAME_DESC' => htmlspecialchars($game_info['game_desc'], ENT_QUOTES, 'UTF-8'),
    'VERSION' => $arcade->version,
		
		'S_MODE' => '<input type="submit" name="submit" value="'.$s_mode.'" class="mainoption" />',
		'S_HIDDEN_FIELDS' => $s_hidden . phpbb_admin_session_field(),
		'S_ACTION' => $s_action
		));
//
//  Set the current template
//
$template->set_filenames(array('body' => 'admin/arcade_scores_body.tpl'));
//
// Generate the Main page
//
$template->pparse('body');
include('page_footer_admin.' . $phpEx);

?>
