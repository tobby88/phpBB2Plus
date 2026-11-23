<?php
/***************************************************************************
 *                             arcade_tournament.php
 *                            -----------------------
 *   begin                : Thuesday, May 11, 2006
 *   copyright            : (C) 2003-2006 dEfEndEr
 *   email                : defenders_realm@yahoo.com
 *   support              : http://www.phpbb-arcade.com
 *
 *   $Id: arcade__tournament.php, v2.1.3 2006/05/11 23:59:02 dEfEndEr Exp $
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is shareware; you can redistribute it under the terms of
 *   the License as published by the Arcade Support Site above.
 *
 *   You may evaluate this software for 30 days, after this period you should
 *   make a donation to help support the software via the support site above.
 *
 ***************************************************************************/

define('IN_PHPBB', true);
define('ARCADE_ADMIN', 1);
// Set the defaults.
$phpbb_root_path = './';
$filename = basename(__FILE__);
// required for people with footer stats...
include($phpbb_root_path . 'extension.inc');
// phpBB 3 routine to get true extension info
$phpEx    = substr(strrchr(__FILE__, '.'), 1);
// include standard files
include($phpbb_root_path . 'common.'.$phpEx);
include_once($phpbb_root_path . 'includes/constants_arcade.'.$phpEx);
//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_ARCADE_TOUR);
init_userprefs($userdata);
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
$version = $arcade->version();
$page_title = $lang['Arcade'] . ' ' . $lang['tournaments']; 
//
// End session management
//-----------------------//
// Check User Logged in
//-----------------------//
if (!$userdata['session_logged_in'])
{
	redirect(append_sid("login.$phpEx?redirect=arcade_tournament.$phpEx"));
}
//
//  Initialize Arcade Config and Check System ONLINE.
//
if($arcade->arcade_config('games_offline') && ($userdata['user_level'] != ADMIN) && ($userdata['user_level'] != MOD))
{
	message_die(GENERAL_MESSAGE, $lang['games_are_offline'], $lang['Information']);
}
//  Get inputed data / set-up remaining defaults.
//
$mode       = $arcade->pass_var('mode', '');
$tour_id    = (int) $arcade->pass_var('id', 0);
$game_id    = (int) $arcade->pass_var('game_id', 0);
$join_tour  = $arcade->pass_var('join_tour', array());
$s_options  = '';
$in_tour = false;
$games_played_list = array();
$tour = array('tour_name' => '', 'tour_desc' => '', 'tour_max_players' => 2, 'tour_player_turns' => 1);
$url		    = '&nbsp;&raquo;&nbsp;<a href="activity.'.$phpEx.'" class="nav">' . $lang['games_catagories'] . '</a>&nbsp;&raquo;&nbsp;' . $lang['tournaments'];
$champions_heading = sprintf($lang['Champions'], 5);

function arcade_tournament_player_html($user_id, $username, $link = true)
{
  global $lang, $phpEx;
  $user_id = (int) $user_id;
  if ($user_id <= 0 || !is_string($username))
  {
    return phpbb_profile_text($user_id > 0 ? $lang['Former_member'] : $lang['Guest']);
  }
  $label = phpbb_author_name($user_id, $username);
  return $link ? '<a href="' . append_sid('profile.' . $phpEx . '?mode=viewprofile&amp;u=' . $user_id)
    . '" class="gensmall">' . $label . '</a>' : $label;
}

function arcade_tournament_champions($db, $limit, $start = 0)
{
  global $lang;
  $sql = 'SELECT t.tour_name, t.champion, u.username
    FROM ' . iNA_TOUR . ' t
    LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = t.champion
    WHERE t.tour_active = 3 AND t.champion > 0
    ORDER BY t.tour_id DESC
    LIMIT ' . (int) $start . ', ' . (int) $limit;
  $result = $db->sql_query($sql);
  if (!$result)
  {
    message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
  }
  $rows = $db->sql_fetchrowset($result);
  $db->sql_freeresult($result);
  return $rows;
}

function arcade_tournament_champions_block($template, $champions, $links)
{
  global $lang;
  $names = array();
  $tournaments = array();
  foreach ($champions as $champion)
  {
    $names[] = '<img src="images/crown.gif" alt="" /> ' .
      arcade_tournament_player_html($champion['champion'], $champion['username']) .
      ' <img src="images/crown.gif" alt="" />';
    $tournaments[] = htmlspecialchars((string) $champion['tour_name'], ENT_QUOTES, 'UTF-8');
  }
  $template->assign_block_vars('champions', array(
    'NAME' => implode('<br />', $names),
    'OF' => $champions ? $lang['champion_of'] : '',
    'LIST' => $champions ? implode('<br />', $tournaments) : $lang['None'],
    'LINK' => $links,
  ));
}

function arcade_tournament_join_status($tour, $member, $players)
{
  if ((int) $tour['tour_active'] !== 2) { return 'inactive'; }
  if ($member) { return 'arcade_tournament_already_joined'; }
  return (int) $players >= (int) $tour['tour_max_players'] ? 'arcade_tournament_full' : null;
}

function arcade_tournament_games($db, $tour_id)
{
  global $lang;
  $sql = 'SELECT g.game_id, g.game_name, g.game_path, g.game_desc, g.game_avail, g.game_control,
      g.win_width, g.win_height, g.image_path, td.top_score, td.top_player, u.username AS top_username
    FROM ' . iNA_TOUR_DATA . ' AS td
    INNER JOIN ' . iNA_GAMES . ' AS g ON g.game_id = (
      SELECT MIN(candidate.game_id) FROM ' . iNA_GAMES . ' AS candidate
      WHERE candidate.game_name = td.game_name
    )
    LEFT JOIN ' . USERS_TABLE . ' AS u ON u.user_id = td.top_player
    WHERE td.tour_id = ' . (int) $tour_id . '
    ORDER BY td.game_name';
  $result = $db->sql_query($sql);
  if (!$result)
  {
    message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
  }
  try
  {
    return $db->sql_fetchrowset($result);
  }
  finally
  {
    $db->sql_freeresult($result);
  }
}

function arcade_tournament_overview($db, $user_id)
{
  global $lang;
  $sql = 'SELECT t.tour_id, t.tour_name, t.tour_desc, t.tour_active, t.tour_max_players,
      (SELECT COUNT(*) FROM ' . iNA_TOUR_DATA . ' g WHERE g.tour_id = t.tour_id) AS games,
      (SELECT COUNT(*) FROM ' . iNA_TOUR_PLAY . ' p WHERE p.tour_id = t.tour_id) AS players,
      EXISTS (SELECT 1 FROM ' . iNA_TOUR_PLAY . ' m WHERE m.tour_id = t.tour_id AND m.user_id = ' . (int) $user_id . ') AS is_member
    FROM ' . iNA_TOUR . ' t
    WHERE t.tour_active <> 3
    ORDER BY t.tour_active DESC, t.tour_id ASC';
  $result = $db->sql_query($sql);
  if (!$result)
  {
    message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
  }
  $rows = $db->sql_fetchrowset($result);
  $db->sql_freeresult($result);
  return $rows;
}

function arcade_tournament_detail_options($tour, $member, $players, $admin)
{
  global $lang;
  $id = (int) $tour['tour_id'];
  $options = array();
  if (arcade_tournament_join_status($tour, $member, $players) === null)
  {
    $options[] = '<input type="submit" name="join" value="' . $lang['Join'] .
      '"><input type="hidden" name="join_tour[]" value="' . $id . '">';
  }
  if ($admin && (int) $tour['tour_active'] !== 3)
  {
    $options[] = '<input type="submit" name="end" value="' . $lang['End'] .
      '"><input type="hidden" name="id" value="' . $id . '">';
  }
  return implode('&nbsp;&nbsp;', $options);
}

function arcade_public_tournament_require_token($userdata, $lang)
{
  global $HTTP_POST_VARS;
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($HTTP_POST_VARS['sid']) || !is_scalar($HTTP_POST_VARS['sid']) ||
    !hash_equals((string) $userdata['session_id'], stripslashes((string) $HTTP_POST_VARS['sid'])))
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }
}

function arcade_public_tournament_game_token($tour_id, $game_id, $session_id)
{
  return hash_hmac('sha256', (int) $tour_id . ':' . (int) $game_id, (string) $session_id);
}
//
//  Set the template so we don't get any errors.
//
$template->set_filenames(array('body' => 'arcade_tour_body.tpl') );
//
//  Does the user want to join a tournament?
//
if(!empty($HTTP_POST_VARS['join']))
{
  arcade_public_tournament_require_token($userdata, $lang);
  require_once dirname(__FILE__).'/includes/functions_arcade_tournament_storage.php';
  try
  {
    $results = phpbb_arcade_tournament_join($db, $join_tour);
  }
  catch (PhpbbOwnedStorageFailure $error)
  {
    $key = $error->getMessage();
    message_die(GENERAL_MESSAGE, (isset($lang[$key]) ? $lang[$key] : $lang['no_tour_play_data']).$lang['tour_return']);
  }
  $joining = '';
  foreach ($results as $result)
  {
    $joining .= sprintf($lang[$result['status']], htmlspecialchars($result['name'], ENT_QUOTES, 'UTF-8'));
  }
  message_die(GENERAL_MESSAGE, ($joining !== '' ? $joining : $lang['tour_no_join']).$lang['tour_return']);
}
//
//  Start a new Tournament...
//
else if(!empty($HTTP_POST_VARS['submit']))
{
  arcade_public_tournament_require_token($userdata, $lang);
//
//  Validate the submitted captions before opening the owned writer.
//
	$tour_name = (isset($HTTP_POST_VARS['tour_name']) && is_scalar($HTTP_POST_VARS['tour_name'])) ? trim(stripslashes((string) $HTTP_POST_VARS['tour_name'])) : '';
	$tour_desc = (isset($HTTP_POST_VARS['tour_desc']) && is_scalar($HTTP_POST_VARS['tour_desc'])) ? trim(stripslashes((string) $HTTP_POST_VARS['tour_desc'])) : '';
	if (phpbb_arcade_text_limit($tour_name, 65535) === null || phpbb_arcade_text_limit($tour_desc, 65535) === null)
	{
		message_die(GENERAL_MESSAGE, $lang['error_no_tour_info'].$lang['tour_return']);
	}
	$tour_name = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', strip_tags($tour_name)));
	$tour_desc = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', strip_tags($tour_desc)));
  $tour_name = phpbb_arcade_text_limit($tour_name, 25);
  $tour_desc = phpbb_arcade_text_limit($tour_desc, 255);
  if(strlen($tour_name) < 1 || strlen($tour_desc) < 3)
  {
    message_die(GENERAL_MESSAGE, $lang['error_no_tour_info'].$lang['tour_return']);
  }
  // Save the event and its initial membership together using current limits.
  require_once dirname(__FILE__).'/includes/functions_arcade_tournament_manage.php';
  try
  {
    $values = array('name'=>$tour_name,'desc'=>$tour_desc,
      'players'=>phpbb_arcade_tournament_post_int($HTTP_POST_VARS,'tour_max_players'),
      'turns'=>phpbb_arcade_tournament_post_int($HTTP_POST_VARS,'tour_player_turns'),
      'block'=>phpbb_arcade_tournament_post_int($HTTP_POST_VARS,'block_plays'),'active'=>0);
    $saved = phpbb_arcade_tournament_manage($db,'save',0,$values);
    $tour_id = $saved['id'];
  }
  catch (PhpbbOwnedStorageFailure $error)
  {
    $key = $error->getMessage();
    message_die(GENERAL_MESSAGE,(isset($lang[$key])?$lang[$key]:$lang['no_tour_data']).$lang['tour_return']);
  }
 	$message = $lang['tour_added'];
  $message .= $lang['tour_invite_players'];
  $message .= $lang['tour_add_games'].$lang['tour_return'];
	message_die(GENERAL_MESSAGE, $message);
}
//
//  Does the user want to Start a New Tournament ?
//
else if(!empty($HTTP_POST_VARS['start']))
{
//
//  Can USERS start a Tournament???
//
  if (empty($arcade->arcade_config['games_tournament_user']))
  {
    message_die(GENERAL_MESSAGE, $lang['arcade_admin_only'].$lang['tour_return']);
  }
  $sql = 'SELECT COUNT(*) AS total FROM ' . iNA_TOUR . ' WHERE tour_active <> 3';
  $result = $db->sql_query($sql);
  if (!$result)
  {
    message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
  }
  $count = $db->sql_fetchrow($result);
  $db->sql_freeresult($result);
  if ((int) $count['total'] >= (int) $arcade->arcade_config['games_tournament_max'])
  {
    message_die(GENERAL_MESSAGE, $lang['arcade_tournament_limit'].$lang['tour_return']);
  }

  $s_options = '<input type="Submit" name="submit" value="'. $lang['Submit'] . '">';

  $template->assign_block_vars('tournament_add', array(
      'NAME' => $tour['tour_name'],
      'DESC' => $tour['tour_desc'],
      'PLAYERS' => $tour['tour_max_players'],
      'TURNS' => $tour['tour_player_turns'],
      
      'S_BLOCK_YES' => '',
      'S_BLOCK_NO' => '',
      'S_SELECT_END' => '',
    ));
}
//
//  Admin END Tournament Feature.
//
else if(!empty($HTTP_POST_VARS['end']))
{
  arcade_public_tournament_require_token($userdata, $lang);
  require_once dirname(__FILE__).'/includes/functions_arcade_tournament_actions.php';
  try
  {
    $tour_id = phpbb_arcade_tournament_post_int($HTTP_POST_VARS,'id');
    if (!isset($HTTP_POST_VARS['confirm']))
    {
      $preview = phpbb_arcade_tournament_action($db,'preview-end',$tour_id);
      $end_revision = phpbb_arcade_tournament_revision($preview['tour']);
    }
  }
  catch (PhpbbOwnedStorageFailure $error)
  {
    $key=$error->getMessage();message_die(GENERAL_MESSAGE,(isset($lang[$key])?$lang[$key]:$lang['no_tour_data']).$lang['tour_return']);
  }
  if(!isset($HTTP_POST_VARS['confirm']))
  {
  	 if( isset($HTTP_POST_VARS['cancel']) )
	   {
		    redirect(append_sid("$filename"));
		    exit;
	   }
//
// Start output of page
//
	  $page_title = $lang['arcade_comment_delete'];
	  include($phpbb_root_path . 'includes/page_header.'.$phpEx);

    $template->set_filenames(array(
		  'body' => 'confirm_body.tpl')
  	);

  	$template->assign_vars(array(
	   	'MESSAGE_TITLE' => $lang['Confirm'],

  		'MESSAGE_TEXT' => $lang['arcade_tournament_end_sure'],

  		'L_NO' => $lang['No'],
	   	'L_YES' => $lang['Yes'],

  		'S_CONFIRM_ACTION' => append_sid("$filename"),
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="end" value="1"><input type="hidden" name="id" value="'.(int) $tour_id.'"><input type="hidden" name="tour_revision" value="'.$end_revision.'"><input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '">',
	   	));

	//
	// Generate the page
	//
  	$template->pparse('body');

  	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
    exit;    
  }
  try
  {
    $revision = phpbb_arcade_score_request($HTTP_POST_VARS,'tour_revision','');
    $ended = phpbb_arcade_tournament_action($db,'end',$tour_id,array(),$revision);
  }
  catch (PhpbbOwnedStorageFailure $error)
  {
    $key=$error->getMessage();message_die(GENERAL_MESSAGE,(isset($lang[$key])?$lang[$key]:$lang['no_tour_data']).$lang['tour_return']);
  }
  $tour = $ended['tour'];
  $message = sprintf($lang['arcade_tournament_end'], htmlspecialchars((string)$tour['tour_name'], ENT_QUOTES, 'UTF-8'));
  if ($ended['draw']) { message_die(GENERAL_MESSAGE,$message.$lang['tour_msg_draw'].$lang['tour_return']); }
  $winner_id = $ended['winner'];
  if ($winner_id > 0)
  {
    $message .= sprintf($lang['tour_msg_winner'], arcade_tournament_player_html($winner_id, $arcade->get_username($winner_id), false));
  }
  // Notifications are optional delivery after a confirmed commit. A failed
  // delivery must not turn a completed end operation into an error/replay.
  $notice = $message . "\n\n" . sprintf($lang['tour_msg_message'], phpbb_board_url('arcade_tournament.' . $phpEx . '?mode=tour&id=' . (int) $tour_id));
  foreach ($ended['players'] as $recipient)
  {
    try { ina_send_user_pm($recipient,$lang['tour_msg_subject'],$notice,(int)$userdata['user_id'],'NO',true); }
    catch (Exception $ignored) {} catch (Error $ignored) {}
  }
  message_die(GENERAL_MESSAGE, $message.$lang['tour_return']);
}
//
//	View Tournament Mode.
//
else if ($mode == 'tour')
{
  $played_games = 0;
  $GameData = array();
//
//  Get Tournament details
//
	$sql = "SELECT * FROM " . iNA_TOUR . "
     WHERE tour_id = " . $tour_id;
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
	$tour = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
  if (!$tour)
  {
    message_die(GENERAL_MESSAGE, $lang['no_tour_data'] . $lang['tour_return']);
  }
//
//  Get Tournament Games
//
	$tour_games = arcade_tournament_games($db, $tour_id);
	$total_games = count($tour_games);
//
//  Get Tournament Players
//
	$sql = "SELECT p.*, u.username FROM " . iNA_TOUR_PLAY . " AS p
	  LEFT JOIN " . USERS_TABLE . " AS u
	    ON p.user_id = u.user_id
    WHERE tour_id = " . $tour_id;
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
	$tour_players = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	$total_players = count($tour_players);

  $template->assign_block_vars('player_head', array());
  $player_names = array();

  for($i = 0; $i < $total_players; $i++)
  {
    $player_names[(int) $tour_players[$i]['user_id']] = $tour_players[$i]['username'];
    $temp_gamedata = arcade_tournament_player_games($tour_players[$i]['gamedata']);
//
//  Get Game Info for This user.
//
    if($tour_players[$i]['user_id'] == $userdata['user_id'])
    {
      $in_tour = true;
      $GameData = $temp_gamedata;
      $played_games = count($GameData);
    }
//
//  Now we have that, let's build a list of what games each person has played for some stats
// 
    $played_games_count = count($temp_gamedata);

    $played_games_list = ' [<i>';
    for($count = 0; $count < $played_games_count; $count++)
    {
      if($count > 0)
      {
        $played_games_list .= ', ';
      }
      $played_name = isset($temp_gamedata[$count]['game_name']) && is_string($temp_gamedata[$count]['game_name'])
        ? $temp_gamedata[$count]['game_name'] : '';
      if ($played_name === '')
      {
        continue;
      }
      $played_count = isset($temp_gamedata[$count]['played']) ? (int) $temp_gamedata[$count]['played'] : 0;
      $played_score = isset($temp_gamedata[$count]['score']) ? (int) $temp_gamedata[$count]['score'] : 0;
      $played_games_list .= htmlspecialchars($played_name, ENT_QUOTES, 'UTF-8') . '(P:<b>' . $played_count . '</b>:S:<b>' . $played_score . '</b>)';
      $games_played_list[$played_name][] = (int) $tour_players[$i]['user_id'];
    }
    $played_games_list .= '</i>]';

    $template->assign_block_vars('player', array(
      'ID' => (int) $tour_players[$i]['user_id'],
      'ROW_CLASS' => ( !($i % 2) ) ? 'row1' : 'row2',
      'NAME' => arcade_tournament_player_html($tour_players[$i]['user_id'], $tour_players[$i]['username']),
      'PLAYED_GAMES' => ($played_games_count > 0 && $userdata['user_level'] == ADMIN) ? $played_games_list : '',

       ));

  }
  $s_options = arcade_tournament_detail_options($tour, $in_tour, $total_players, (int) $userdata['user_level'] === ADMIN);
//
//  Thats the Player Info sorted, now get the Games Info
//
  $template->assign_block_vars('game_head', array());

  for($i = 0; $i < $total_games; $i++)
  {
    $game_info = $tour_games[$i];
    $played = 0;
    $played_text = $lang['has'];
//
//  Build a list of those that have played this game
//
    $tour_game_name = (string) $game_info['game_name'];
    $game_players = isset($games_played_list[$tour_game_name]) ? $games_played_list[$tour_game_name] : array();
    $played_players = $game_players ? '' : $lang['No-One'];

    foreach($game_players as $count => $player_id)
    {
      if($count > 0)
      {
        $played_players .= ', ';
        $played_text = $lang['have'];
      }
      $played_players .= arcade_tournament_player_html($player_id, $player_names[$player_id]);
    }
//
//  Check to see what THIS user has played
//
    for($count = 0; $count < $played_games; $count++)
    {
      if(isset($GameData[$count]['game_name']) && $GameData[$count]['game_name'] == $tour_game_name)
      {
        $played = isset($GameData[$count]['played']) ? (int) $GameData[$count]['played'] : 0;
      }
    }
    $image_path = ina_find_image($game_info['game_path'], $tour_game_name, $game_info['image_path'], $phpbb_root_path);
    if($game_info['game_control'] == 1)
    {
      $game_control = '&nbsp;<img src="images/mouse.gif" alt="'. $lang['arcade_mouse'] .'">&nbsp;';
    } 
    else if($game_info['game_control'] == 2)
    {
      $game_control = '&nbsp;<img src="images/keyboard.gif" alt="'.$lang['arcade_keyboard'].'">&nbsp;';
    } 
    else if($game_info['game_control'] == 3)
    {
      $game_control = '&nbsp;<img src="images/mouse.gif" alt="'.$lang['arcade_mouse'].'">&nbsp;<img src="images/keyboard.gif" alt="'.$lang['arcade_keyboard'].'">&nbsp;';
    }
    else
    {
      $game_control = '';
    }
    if($in_tour && (int) $game_info['game_avail'] === 1
      && (int) $tour['tour_player_turns'] > $played && (int) $tour['tour_active'] === 2)
    { 
		  $tour_token = arcade_public_tournament_game_token($tour_id, $game_info['game_id'], $userdata['session_id']);
		  $game_url = append_sid($filename . '?mode=game&amp;id=' . (int) $tour_id . '&amp;game_id=' . (int) $game_info['game_id'] . '&amp;tour_token=' . rawurlencode($tour_token));
		  $game_link = "<a href=\"javascript:Gk_PopTart('" . $game_url . "', 'Game_Window', '" . (int) $game_info['win_width'] . "', '" . (int) $game_info['win_height'] . "', 'no')\" class=\"forumlink\" onClick=\"blur()\">&nbsp;&laquo;&nbsp;" . htmlspecialchars($game_info['game_desc'], ENT_QUOTES, 'UTF-8') . "&nbsp;&raquo;&nbsp;</a>";
    }
    else
    {
      $game_link = '<span class="gensmall">' . htmlspecialchars($game_info['game_desc'], ENT_QUOTES, 'UTF-8') . '</span>';
    }

    $highest_scorer = $lang['No-One'];

    if($game_info['top_score'] !== null)
    {
      $highest_scorer = arcade_tournament_player_html($game_info['top_player'], $game_info['top_username']);
    }
    
        
    $template->assign_block_vars('game', array(
        'ID' => (int) $game_info['game_id'],
        'ROW_CLASS' => ( !($i % 2) ) ? 'row1' : 'row2',
        'DESC' => $game_link,
        'IMAGE' => $image_path ? '<img src="'.phpbb_profile_text($image_path).'" width="'.(int) $arcade->arcade_config['games_image_width'].'" height="'.(int) $arcade->arcade_config['games_image_height'].'" alt="" />' : '',
        'CONTROL' => $game_control,
        'INFO' => ($in_tour == true) ? sprintf($lang['tour_play_stats'], $tour['tour_player_turns']-$played, $highest_scorer, $played_players, $played_text) : sprintf($lang['tour_not_part'], $highest_scorer, $played_players)
      ));
    
    
  }
    
	$page_title = htmlspecialchars($tour['tour_name'], ENT_QUOTES, 'UTF-8') . '&nbsp;' . $lang['tournament'];
	$url		= '&nbsp;&raquo;&nbsp;<a href="'. append_sid("activity.$phpEx") .'" class="nav">' . $lang['games_catagories'] . '</a>&nbsp;&raquo;&nbsp;<a href="' . append_sid($filename) . '" class="nav">' . $lang['tournaments'] . '</a>&nbsp;&raquo;&nbsp;' . htmlspecialchars($tour['tour_name'], ENT_QUOTES, 'UTF-8');
}
//
//  Game Mode!!!!!!!!!
//
else if ($mode == 'game')
{
	$tour_token = (isset($HTTP_GET_VARS['tour_token']) && is_scalar($HTTP_GET_VARS['tour_token'])) ? stripslashes((string) $HTTP_GET_VARS['tour_token']) : '';
  $expected_token = arcade_public_tournament_game_token($tour_id, $game_id, $userdata['session_id']);
  if ($tour_id <= 0 || $game_id <= 0 || !hash_equals($expected_token, $tour_token))
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }

  $sql = "SELECT g.*, p.gamedata, t.tour_player_turns
    FROM " . iNA_TOUR_DATA . " AS td
    INNER JOIN " . iNA_GAMES . " AS g ON td.game_name = g.game_name
    INNER JOIN " . iNA_TOUR . " AS t ON td.tour_id = t.tour_id
    INNER JOIN " . iNA_TOUR_PLAY . " AS p ON p.tour_id = t.tour_id
    WHERE td.tour_id = " . $tour_id . "
      AND g.game_id = " . $game_id . "
      AND g.game_avail = 1
      AND t.tour_active = 2
      AND p.user_id = " . (int) $userdata['user_id'];
	if( !$result = $db->sql_query($sql) )
 	{
 		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
 	}
	$game_info = $db->sql_fetchrow($result);
  $db->sql_freeresult($result);
  if (!$game_info)
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }

  // The launch transaction validates and charges the tournament turn.
  $win='NORM';
  $session = update_ina_session($userdata['user_id'], $user_ip, PAGE_ARCADE_TOUR, $game_info['game_name'], '', 'NORM', $tour_id);

  require("loader.".$phpEx);
  exit;
}
//
//  View completed tournaments with a winner.
//
else if ($mode == 'champions')
{
  $sql = 'SELECT COUNT(*) AS total FROM ' . iNA_TOUR . ' WHERE tour_active = 3 AND champion > 0';
  $result = $db->sql_query($sql);
  if (!$result)
  {
    message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
  }
  $count = $db->sql_fetchrow($result);
  $db->sql_freeresult($result);
  $total_champions = (int) $count['total'];
  $per_page = 25;
  $start = min(max(0, (int) $arcade->pass_var('start', 0, true)), max(0, $total_champions - 1));
  $start -= $start % $per_page;
  $pagination = generate_pagination(append_sid($filename . '?mode=champions'), $total_champions, $per_page, $start);
  $champions_heading = $lang['tournament_champions'];
  arcade_tournament_champions_block($template, arcade_tournament_champions($db, $per_page, $start), $pagination);
  $s_options = '<a class="forumlink" href="' . append_sid($filename) . '">' . $lang['tournaments'] . '</a>';
}
//
//
//
else if($mode == 'add_games')
{
  redirect(append_sid($filename));
  exit;
}
//
//
//
else if($mode == 'invite_players')
{
  redirect(append_sid($filename));
  exit;

}
//
//  Default Tournament View..
//
else
{
  $template->assign_block_vars('tour_head', array(
    'TOURNAMENT' => $lang['tournament'],
    'INFORMATION' => $lang['Information'],
    'ACTION' => $lang['Action'],
    ));
//
//  Fetch one extra result to determine whether to link to the complete list.
//
  $tour_champs = arcade_tournament_champions($db, 6);
  $champions_link = count($tour_champs) > 5
    ? '<a class="forumlink" href="' . append_sid($filename . '?mode=champions') . '">' . $lang['view_champions'] . '</a>'
    : '';
  arcade_tournament_champions_block($template, array_slice($tour_champs, 0, 5), $champions_link);

  $tours = arcade_tournament_overview($db, $userdata['user_id']);
  $can_join = false;
  if (!$tours)
  {
    $template->assign_block_vars('tournament_none', array(
      'NONE' => $lang['None'],
      ));
  }
  foreach ($tours as $i => $entry)
  {
    $join_status = arcade_tournament_join_status($entry, (bool) $entry['is_member'], $entry['players']);
    if ($join_status === null)
    {
      $can_join = true;
    }
    $template->assign_block_vars('tour', array(
        'ROW_CLASS' => ($i % 2) ? 'row2' : 'row1',
        'NAME' => '<a href="'.append_sid($filename.'?mode=tour&amp;id='.(int) $entry['tour_id']).'" class="forumlink">'.htmlspecialchars((string) $entry['tour_name'], ENT_QUOTES, 'UTF-8').'</a>',
        'DESC' => htmlspecialchars((string) $entry['tour_desc'], ENT_QUOTES, 'UTF-8'),
        'TOTAL_GAMES' => sprintf($lang['tournament_games'], $entry['games']),
        'TOTAL_PLAYERS' => sprintf($lang['tournament_players'], $entry['players']),
        'JOIN' => $join_status === null ? '<input type="checkbox" name="join_tour[]" value="'.(int) $entry['tour_id'].'">' : $lang[$join_status],
        'EDIT' => '',
        'DELETE' => '',

      ));
  }
  $options = array();
  if (!empty($arcade->arcade_config['games_tournament_user']) &&
      count($tours) < (int) $arcade->arcade_config['games_tournament_max'])
  {
    $options[] = '<input type="submit" name="start" value="' . $lang['Start'] . '">';
  }
  if ($can_join)
  {
    $options[] = '<input type="submit" name="join" value="' . $lang['Join'] . '">';
  }
  $s_options = implode('&nbsp;&nbsp;', $options);
}
  
$template->assign_vars(array(

    'CHAMPIONS' => $champions_heading,

    'L_NAME' => $lang['tournament_name'],
    'L_NAME_INFO' => $lang['tournament_name_info'],
    'L_DESC' => $lang['tournament_desc'],
    'L_DESC_INFO' => $lang['tournament_desc_info'],
    'L_PLAYERS' => $lang['tournament_max_player'],
    'L_PLAYERS_INFO' => $lang['tournament_max_player_info'],
    'L_TURNS' => $lang['tournament_turns'],
    'L_TURNS_INFO' => $lang['tournament_turns_info'],
    'L_BLOCK' => $lang['tournament_block'],
    'L_BLOCK_INFO' => $lang['tournament_block_info'],
    'L_ACTIVE' => $lang['tournament_active'],
    'L_ACTIVE_INFO' => $lang['tournament_active_info'],
    'L_START' => $lang['tournament_start'],
    'L_START_INFO' => $lang['tournament_start_info'],
    'L_END' => $lang['tournament_end'],
    'L_END_INFO' => $lang['tournament_end_info'],

		'NAME' => $lang['Name'],
		'DATA' => $lang['Data'],
		'JOIN' => $lang['Join'],
		'PLAYERS' => isset($lang['Players']) ? $lang['Players'] : 'Spieler',

		'U_CAT' => $url,
		
		'S_ACTION' => append_sid($filename),
		'S_OPTIONS' => $s_options,
		'S_FORM_TOKEN' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
		
    'L_YES' => $lang['Yes'],
    'L_NO' => $lang['No'],
		
		'ARCADE_MOD' => sprintf($lang['activitiy_mod_info'], $arcade->version)
        ));

//
//  Generate page
//
include($phpbb_root_path . 'includes/page_header.'.$phpEx);
$template->pparse('body');
include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>
