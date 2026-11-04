<?php
/*************************************************************************** 
 * 
 *                             arcade_modcp.php 
 *                             ---------------- 
 *   begin                : Tuesday, June 21, 2005 
 *   copyright            : (c)2002/2006 www.phpbb-amod.co.uk 
 *   email                : defenders_realm@yahoo.com 
 * 
 *   $Id: arcade_modcp.php, v3.0.0 2005/06/21 12:59:59 dEfEndEr Exp $ 
 * 
 *************************************************************************** 
 * 
 *   This program is free software; you can redistribute it and/or modify 
 *   it under the terms of the GNU General Public License as published by 
 *   the Free Software Foundation; either version 2 of the License, or 
 *   (at your option) any later version. 
 * 
 ***************************************************************************/

define('IN_PHPBB', true); 
$phpbb_root_path = './'; 
include($phpbb_root_path . 'extension.inc'); 
include($phpbb_root_path . 'common.'.$phpEx); 
include_once($phpbb_root_path . 'includes/constants_arcade.'.$phpEx);

// Start session management 
$userdata = session_pagestart($user_ip, PAGE_ARCADE_MOD); 
init_userprefs($userdata); 
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
// End session management 

function arcade_modcp_html($value)
{
	return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function arcade_modcp_ip($value, $label)
{
	$ip = trim(is_scalar($value) ? (string) $value : '');
	if (filter_var($ip, FILTER_VALIDATE_IP) === false)
	{
		return '&ndash;';
	}

	return '<span class="gensmall" title="' . arcade_modcp_html($label) . '">' . arcade_modcp_html($ip) . '</span>';
}

function arcade_modcp_fields($values)
{
    $html = '';
    foreach ($values as $name=>$value) { $html .= '<input type="hidden" name="'.arcade_modcp_html($name).'" value="'.arcade_modcp_html($value).'" />'; }
    return $html;
}
require_once $phpbb_root_path.'includes/functions_arcade_moderation_storage.php';
$moderation_request = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' ? $HTTP_POST_VARS : $HTTP_GET_VARS;
$moderation_is_post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
try
{
    $mode = phpbb_arcade_score_request($moderation_request, 'mode', '');
    if ($mode !== 'mod') { throw new PhpbbOwnedStorageFailure('Not_Moderator'); }
    $cat_id = phpbb_arcade_score_integer(phpbb_arcade_score_request($moderation_request, 'cat_id', '0'), -1);
    $moderator = phpbb_arcade_moderator_actor($db, $cat_id);
    $catagory_name = $moderator['category']['cat_name'];
    $action = phpbb_arcade_score_request($moderation_request, 'action', '');
    if (!in_array($action, array('', 'scores', 'at_scores', 'delete_score', 'delete_at_score'), true)) { throw new PhpbbOwnedStorageFailure('arcade_score_changed'); }
    $game_id = phpbb_arcade_score_integer(phpbb_arcade_score_request($moderation_request, 'game_id', '0'), 0);
    $hidden_options = arcade_modcp_fields(array('mode'=>'mod','cat_id'=>$cat_id));
    $url = ' &raquo; <a href="'.append_sid('activity.'.$phpEx).'" class="nav">'.arcade_modcp_html($lang['games_catagories']).'</a> &raquo; <a href="'.append_sid('activity.'.$phpEx.'?mode=cat&amp;cat_id='.$cat_id).'" class="nav">'.arcade_modcp_html($catagory_name).'</a>';
    if ($action === 'delete_score' || $action === 'delete_at_score')
    {
        if ($moderation_is_post && isset($HTTP_POST_VARS['cancel']))
        { redirect(append_sid('arcade_modcp.'.$phpEx.'?mode=mod&cat_id='.$cat_id)); exit; }
        $board = $action === 'delete_score' ? 'current' : 'all_time';
        $player_id = phpbb_arcade_score_integer(phpbb_arcade_score_request($moderation_request, 'player_id'));
        $revision = phpbb_arcade_score_request($moderation_request, 'score_revision');
        $game_info = phpbb_arcade_moderated_game($db, $game_id, $cat_id);
        if ($moderation_is_post && isset($HTTP_POST_VARS['confirm']))
        {
            phpbb_attach_quota_post($_POST);
            phpbb_arcade_moderator_score_delete($db, $game_id, $cat_id, $board, $player_id, $revision);
            $return_action = $board === 'current' ? 'scores' : 'at_scores';
            redirect(append_sid('arcade_modcp.'.$phpEx.'?mode=mod&cat_id='.$cat_id.'&action='.$return_action.'&game_id='.$game_id, true)); exit;
        }
        $target = phpbb_arcade_score_target($db, $game_info, $board, $player_id, $revision);
        $score = phpbb_arcade_score_number($target['score']);
        $page_title = $lang['arcade_comment_delete'];
        include($phpbb_root_path . 'includes/page_header.'.$phpEx);
        $template->set_filenames(array('body'=>'confirm_body.tpl'));
        $template->assign_vars(array(
            'MESSAGE_TITLE'=>$lang['Confirm'],
            'MESSAGE_TEXT' => sprintf($lang['arcade_score_sure'], $score, $player_id . ' (' . phpbb_stored_text($arcade->get_username($player_id)) . ')'),
            'L_NO'=>$lang['No'], 'L_YES'=>$lang['Yes'],
            'S_CONFIRM_ACTION'=>append_sid('arcade_modcp.'.$phpEx),
            'S_HIDDEN_FIELDS'=>arcade_modcp_fields(array('mode'=>'mod','cat_id'=>$cat_id,'action'=>$action,'game_id'=>$game_id,
                'player_id'=>$player_id,'score_revision'=>$revision,'sid'=>$userdata['session_id']))
        ));
        $template->pparse('body'); include($phpbb_root_path . 'includes/page_tail.'.$phpEx); exit;
    }
    elseif ($action === '' || $game_id === 0)
    {
        $template->assign_block_vars('game_menu', array());
        $games = phpbb_arcade_score_rows($db, 'SELECT * FROM '.iNA_GAMES.' WHERE cat_id='.$cat_id.' ORDER BY game_name ASC');
        foreach ($games as $game_info)
        {
            $img = ''; $image_src = ina_find_image($game_info['game_path'], $game_info['game_name'], $game_info['image_path'], $phpbb_root_path);
            if ($image_src !== false) { $img = '<img src="'.arcade_modcp_html($image_src).'" width="'.(int)$board_config['games_image_width'].'" height="'.(int)$board_config['games_image_height'].'" alt="" />'; }
            $template->assign_block_vars('game', array(
                'GAME_ID'=>(int)$game_info['game_id'], 'NAME'=>arcade_modcp_html($game_info['game_name']),
                'FLASH'=>$game_info['game_flash']?$lang['ON']:$lang['OFF'], 'DESC'=>arcade_modcp_html($game_info['game_desc']),
                'PATH'=>arcade_modcp_html($game_info['game_path']), 'IMAGE'=>$img,
                'IMAGE_PATH'=>arcade_modcp_html($game_info['image_path']?$game_info['image_path']:$lang['games_image_default']),
                'INST'=>arcade_modcp_html($game_info['instructions']?$game_info['instructions']:$lang['No_Instructions']),
                'WIDTH'=>(int)$game_info['win_width'], 'HEIGHT'=>(int)$game_info['win_height'],
                'AUTO'=>($board_config['games_auto_size']&&!$game_info['game_autosize'])?$lang['ON']:$lang['OFF']
            ));
        }
    }
    else
    {
        $board = $action === 'scores' ? 'current' : 'all_time'; $table = phpbb_arcade_score_table($board);
        $game_info = phpbb_arcade_moderated_game($db, $game_id, $cat_id);
        $order = $game_info['reverse_list'] ? 'ASC' : 'DESC';
        $template->assign_block_vars('scores_edit_menu', array());
        $scores = phpbb_arcade_score_rows($db, 'SELECT s.*,u.username FROM '.$table.' s LEFT JOIN '.USERS_TABLE.
            " u ON u.user_id=s.player_id WHERE s.game_name='".$db->sql_escape($game_info['game_name'])."' ORDER BY s.score ".$order.',s.date ASC,s.player_id ASC');
        foreach ($scores as $score_info)
        {
            if ($score_info['player_id'] === null) { continue; }
            $revision = phpbb_arcade_score_revision($game_info, $board, $score_info);
            $delete_action = $board === 'current' ? 'delete_score' : 'delete_at_score';
            $delete_url = append_sid('arcade_modcp.'.$phpEx.'?mode=mod&amp;cat_id='.$cat_id.'&amp;action='.$delete_action.'&amp;game_id='.$game_id.'&amp;player_id='.(int)$score_info['player_id'].'&amp;score_revision='.$revision);
            $template->assign_block_vars('highscores', array(
                'PLAYER'=>arcade_modcp_html($score_info['username']!==null?$score_info['username']:$lang['Guest']),
                'SCORE'=>arcade_modcp_html($arcade->convert_score($score_info['score'])),
                'DATE'=>arcade_modcp_html(create_date($board_config['default_dateformat'], $score_info['date'], $board_config['board_timezone'])),
                'TIME'=>arcade_modcp_html($score_info['time_taken']?$arcade->convert_time($score_info['time_taken']):''),
                'DELETE_IMG'=>'<a href="'.$delete_url.'"><img src="'.arcade_modcp_html($images['icon_delpost']).'" alt="'.arcade_modcp_html($lang['arcade_mod_delete_score']).'" title="'.arcade_modcp_html($lang['arcade_mod_delete_score']).'" /></a>',
                'IP_IMG'=>arcade_modcp_ip($score_info['player_ip'], $lang['View_IP'])
            ));
        }
        $hidden_options = arcade_modcp_fields(array('mode'=>'mod','cat_id'=>$cat_id,'game_id'=>$game_id));
    }
}
catch (PhpbbOwnedStorageFailure $e) {
    $message_key = $e->getMessage() === 'arcade_game_changed' ? 'arcade_score_changed' : $e->getMessage();
    message_die(GENERAL_MESSAGE, isset($lang[$message_key])?$lang[$message_key]:$lang['Not_Authorised']);
}
catch (PhpbbAclException $e) { message_die(GENERAL_MESSAGE, isset($lang[$e->getMessage()])?$lang[$e->getMessage()]:$lang['Not_Authorised']); }

$moderators_buttons = '<label><input type="radio" name="action" value="scores"'.($action!=='at_scores'?' checked="checked"':'').' /> '.arcade_modcp_html($lang['arcade_mod_current_scores']).'</label> &nbsp; '.
    '<label><input type="radio" name="action" value="at_scores"'.($action==='at_scores'?' checked="checked"':'').' /> '.arcade_modcp_html($lang['arcade_mod_all_time_scores']).'</label>';
$template->set_filenames(array('body'=>'arcade_mod_body.tpl'));
$template->assign_vars(array(
    'MOD_MENU'=>$lang['arcade_mod_menu'], 'MOD_BUTTONS'=>$moderators_buttons, 'L_SUBMIT'=>$lang['Submit'],
    'L_ARCADE_MOD_IMAGE'=>$lang['arcade_mod_image'],'L_ARCADE_MOD_FILENAME'=>$lang['arcade_mod_filename'],
    'L_ARCADE_MOD_DESCRIPTION'=>$lang['arcade_mod_description'],'L_ARCADE_MOD_PATH'=>$lang['arcade_mod_path'],
    'L_ARCADE_MOD_STATS'=>$lang['arcade_mod_stats'],'L_ARCADE_MOD_PLAYER'=>$lang['arcade_mod_player'],
    'L_ARCADE_MOD_DATE'=>$lang['arcade_mod_date'],'L_ARCADE_MOD_TIME'=>$lang['arcade_mod_time'],
    'L_ARCADE_MOD_SCORE'=>$lang['arcade_mod_score'],'L_ARCADE_MOD_ACTIONS'=>$lang['arcade_mod_actions'],
    'L_ARCADE_MOD_FLASH'=>$lang['arcade_mod_flash'],'L_ARCADE_MOD_AUTOSIZE'=>$lang['arcade_mod_autosize'],
    'L_ARCADE_MOD_SCORE_EDITOR'=>$action==='at_scores'?$lang['arcade_mod_all_time_scores']:$lang['arcade_mod_current_scores'],
    'S_ACTION'=>append_sid('arcade_modcp.'.$phpEx),'ARCADE_MOD'=>sprintf($lang['activitiy_mod_info'], $arcade->version()),
    'S_HIDDEN_OPTIONS'=>$hidden_options,'U_CAT'=>$url
));
$page_title = arcade_modcp_html($lang['arcade_mod_menu'].' - '.$catagory_name);
include($phpbb_root_path . 'includes/page_header.'.$phpEx);
$template->pparse('body'); include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
