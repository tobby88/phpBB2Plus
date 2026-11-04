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
require_once $phpbb_root_path.'includes/functions_arcade_score_storage.php';
$score_request = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' ? $HTTP_POST_VARS : $HTTP_GET_VARS;
$score_is_post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
$score_action_result = null;
try
{
    phpbb_acp_actor($db, 'admin_arcade_games.'.$phpEx.'?mode=edit_games');
    $mode = phpbb_arcade_score_request($score_request, 'mode', '');
    if (!in_array($mode, array('scores','at_scores','delete','delete_at'), true)) { throw new PhpbbOwnedStorageFailure('arcade_score_changed'); }
    $board = in_array($mode, array('scores','delete'), true) ? 'current' : 'all_time';
    $table = phpbb_arcade_score_table($board);
    $game_info = phpbb_arcade_score_game($db, phpbb_arcade_score_request($score_request, 'game_id', '0'), phpbb_arcade_score_request($score_request, 'game_name', ''));
    $game_id = (int)$game_info['game_id']; $arcade->game_name = $game_info['game_name'];
    $arcade->sort_method($game_info['reverse_list']);
    $edit_value = phpbb_arcade_score_request($score_request, 'edit');
    $edit = $edit_value === null ? null : phpbb_arcade_score_integer($edit_value);
    $s_mode = $lang['admin_edit_games'];
    $s_action = append_sid('admin_arcade_games.'.$phpEx.'?mode=edit_games'); $s_hidden = '';
    if ($score_is_post && isset($HTTP_POST_VARS['cancel'])) { $mode = $board === 'current' ? 'scores' : 'at_scores'; }
    elseif ($score_is_post && isset($HTTP_POST_VARS['submit']))
    {
        phpbb_admin_require_post_session();
        if ($mode !== 'scores' && $mode !== 'at_scores') { throw new PhpbbOwnedStorageFailure('arcade_score_changed'); }
        $score_action_result = phpbb_arcade_score_change($db, $game_id, $board,
            phpbb_arcade_score_request($score_request, 'player_id'), phpbb_arcade_score_request($score_request, 'score_revision'),
            'update', phpbb_arcade_score_request($score_request, 'score'));
        $edit = null;
    }
    elseif ($mode === 'delete' || $mode === 'delete_at')
    {
        $player_id = phpbb_arcade_score_integer(phpbb_arcade_score_request($score_request, 'player_id'));
        $score_revision = phpbb_arcade_score_request($score_request, 'score_revision');
        if ($score_is_post && isset($HTTP_POST_VARS['confirm']))
        {
            phpbb_admin_require_post_session();
            $score_action_result = phpbb_arcade_score_change($db, $game_id, $board, $player_id, $score_revision, 'delete');
            $mode = $board === 'current' ? 'scores' : 'at_scores';
        }
        else
        {
            $target = phpbb_arcade_score_target($db, $game_info, $board, $player_id, $score_revision);
            $hidden = '';
            foreach (array('mode'=>$mode,'game_id'=>$game_id,'player_id'=>$player_id,'score_revision'=>$score_revision) as $key=>$value)
            { $hidden .= '<input type="hidden" name="'.phpbb_admin_html($key).'" value="'.phpbb_admin_html($value).'" />'; }
            $template->set_filenames(array('confirm'=>'confirm_body.tpl'));
            $template->assign_vars(array('MESSAGE_TITLE'=>$lang['Confirm'],
                'MESSAGE_TEXT'=>sprintf($lang['arcade_score_sure'], phpbb_arcade_score_number($target['score']), $player_id.' ('.phpbb_stored_text($arcade->get_username($player_id)).')'),
                'L_YES'=>$lang['Yes'],'L_NO'=>$lang['No'], 'S_CONFIRM_ACTION'=>append_sid($file),
                'S_HIDDEN_FIELDS'=>$hidden.phpbb_admin_session_field()));
            $template->pparse('confirm'); include('page_footer_admin.'.$phpEx); exit;
        }
    }
}
catch (PhpbbOwnedStorageFailure $e) { message_die(GENERAL_MESSAGE, isset($lang[$e->getMessage()]) ? $lang[$e->getMessage()] : $lang['no_score_data']); }
catch (PhpbbAclException $e) { message_die(GENERAL_MESSAGE, isset($lang[$e->getMessage()]) ? $lang[$e->getMessage()] : $lang['Not_Authorised']); }

// Score listing. Raw database identities stay raw until their output boundary.
$sql = 'SELECT s.*,u.username FROM '.$table.' s LEFT JOIN '.USERS_TABLE.' u ON u.user_id=s.player_id'.
    " WHERE s.game_name='".$db->sql_escape($game_info['game_name'])."' ORDER BY s.score ".$arcade->sort.',s.date ASC,s.player_id ASC';
try { $score_info = phpbb_arcade_score_rows($db, $sql); }
catch (PhpbbOwnedStorageFailure $e) { message_die(GENERAL_MESSAGE, $lang['no_score_data']); }
foreach ($score_info as $row)
{
    if ($row['player_id'] === null) { continue; }
    try { $row_revision = phpbb_arcade_score_revision($game_info, $board, $row); }
    catch (PhpbbOwnedStorageFailure $e) { continue; }
    $row_player = (int)$row['player_id'];
    if ($edit !== null && $edit === $row_player)
    {
        $score = '<input class="post" type="text" size="16" name="score" value="'.phpbb_admin_html($row['score']).'" />';
        $s_mode = $lang['Submit']; $s_action = append_sid($file);
        $s_hidden = '';
        foreach (array('game_id'=>$game_id,'mode'=>$mode,'player_id'=>$row_player,'score_revision'=>$row_revision) as $key=>$value)
        { $s_hidden .= '<input type="hidden" name="'.phpbb_admin_html($key).'" value="'.phpbb_admin_html($value).'" />'; }
    }
    else { $score = $arcade->convert_score($row['score']); }
    $score_ip = isset($row['player_ip']) && filter_var($row['player_ip'], FILTER_VALIDATE_IP) !== false ? phpbb_admin_html($row['player_ip']) : $lang['Not_available'];
    $action = $board === 'current' ? 'delete' : 'delete_at';
    $template->assign_block_vars('highscores', array(
        'PLAYER'=>phpbb_admin_html(isset($row['username'])?$row['username']:''), 'SCORE'=>$score,
        'DATE'=>create_date($board_config['default_dateformat'], $row['date'], $board_config['board_timezone']),
        'TIME'=>$row['time_taken']?$arcade->convert_time($row['time_taken']):'',
        'EDIT_IMG'=>'<a href="'.append_sid($file.'?mode='.$mode.'&amp;edit='.$row_player.'&amp;game_id='.$game_id).'">'.
            '<img src="./../'.$images['icon_edit'].'" alt="'.$lang['Edit'].'" title="'.$lang['Edit'].'" border="0" /></a>',
        'DELETE_IMG'=>'<a href="'.append_sid($file.'?mode='.$action.'&amp;game_id='.$game_id.'&amp;player_id='.$row_player.'&amp;score_revision='.$row_revision).'">'.
            '<img src="./../'.$images['icon_delpost'].'" alt="'.$lang['Delete'].'" title="'.$lang['Delete'].'" border="0" /></a>',
        'IP_IMG'=>'<span class="gensmall">'.$score_ip.'</span>'
    ));
}
$template->assign_vars(array('L_SCORE_HEADER'=>$lang['admin_score_header'],'L_SCORE_INFO'=>$lang['admin_score_info'],
    'L_SCORE_EDITOR'=>$lang['admin_score_editor'],'GAME_DESC'=>htmlspecialchars($game_info['game_desc'],ENT_QUOTES,'UTF-8'),
    'VERSION'=>$arcade->version,'S_MODE'=>'<input type="submit" name="submit" value="'.$s_mode.'" class="mainoption" />',
    'S_HIDDEN_FIELDS'=>$s_hidden.phpbb_admin_session_field(),'S_ACTION'=>$s_action));
$template->set_filenames(array('body'=>'admin/arcade_scores_body.tpl'));
$template->pparse('body'); include('page_footer_admin.'.$phpEx);
