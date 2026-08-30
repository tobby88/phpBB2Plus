<?php
/*************************************************************************** 
*                            admin_prune_users.php 
*             php Admin Script for prune users mod 
*                       ------------------- 
*   begin                : April 30, 2002 
*   email                : ncr@db9.dk HTTP://mods.db9.dk 
*      ver. 1.0.2. 
* 
* 
*   History:
* 	 0.9.0. - initial BETA
*      0.9.1. - added prune inativated option
*	 0.9.2. - added support for the end user easely can customise the
*			 interface with more options    
*	 0.9.3. - changed $lang['prune'] to $lang['Prune__commands']
*	 0.9.4. - added prune "avarage posts prune
*	 0.9.5. - now support own language file, the complete mod, require litle change in existing files
*	 0.9.6. - change the javascript name, in the template file
*      1.0.0. - considered as final, included a limit about how meny users max can be deleted at once
*      1.0.1. - fixed a HTML tag, in the admin URL
*      1.0.2. - moved to users section in ACP
*
***************************************************************************/ 

/*************************************************************************** 
* 
*   This program is free software; you can redistribute it and/or modify 
*   it under the terms of the GNU General Public License as published by 
*   the Free Software Foundation; either version 2 of the License, or 
*   (at your option) any later version. 
* 
***************************************************************************/ 

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if( !empty($setmodules) )
{
	$filename = basename(__FILE__);
	$module['Users']['Prune_users'] = $filename;
	return;
}
//
// Load default header
//
$no_page_header = TRUE;
$phpbb_root_path = "../";
require($phpbb_root_path . 'extension.inc');
require('pagestart.' . $phpEx);
include($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_prune_users.' . $phpEx);

$sql = array();
$default = array();


// ********************************************************************************
// from here you can define you own delete creterias, if you makes more, then you shall also
// edit the files lang_main.php, and the file delete_users.php, so they hold the same amount
// of options

//
// Initial selection
//

// find zero posters
$sql [0] = ' AND user_posts="0"';
$default [0] = 240;

// find users who have newer logged in
$sql [1] = ' AND user_lastvisit="0"';
$default [1] = 240;

// find not activated users
$sql [2] = ' AND user_lastvisit=0 AND user_active=0';
$default [2] = 240;

// find users not visited since 60 days 
$sql [3] = ' AND user_lastvisit<'.(time()-86400*60); 
$default [3] = 120;
 
// 
// Users with less than 0.1 posts per day avg. 
// 
$sql[4] = ' AND user_posts/((user_lastvisit - user_regdate)/86400) < "0.1"'; 
$default[4] =360;


// ********************************************************************************
// ****************** Do not change any thing below *******************************

$day_options = array(
	1 => $lang['1_Day'],
	7 => $lang['7_Days'],
	14 => $lang['2_Weeks'],
	21 => sprintf($lang['X_Weeks'], 3),
	30 => $lang['1_Month'],
	60 => sprintf($lang['X_Months'], 2),
	90 => $lang['3_Months'],
	180 => $lang['6_Months'],
	365 => $lang['1_Year']
);
//
// Generate page
//

include('page_header_admin.'.$phpEx);
$template->set_filenames(array("body" => "admin/prune_users_body.tpl"));
$n=0;
while ( !empty($sql[$n]) )
{
	$vars='days_'.$n;
	
	$default[$n] = !empty($default[$n]) ? $default[$n] : 10;
	$day_value = (isset($_POST[$vars]) && is_scalar($_POST[$vars])) ? $_POST[$vars] :
		((isset($_GET[$vars]) && is_scalar($_GET[$vars])) ? $_GET[$vars] : $default[$n]);
	$days[$n] = max(1, min(36500, intval($day_value)));
	$current_options = $day_options;
	if (!isset($current_options[$days[$n]]))
	{
		$current_options[$days[$n]] = sprintf($lang['X_Days'], $days[$n]);
		ksort($current_options, SORT_NUMERIC);
	}
	$select[$n] = '<select name="days_'.$n.'" size="1" onchange="SetDays();" class="gensmall">';
	foreach ($current_options as $option_days => $option_label)
	{
		$selected = ((int) $option_days === $days[$n]) ? ' selected="selected"' : '';
		$select[$n] .= '<option value="' . (int) $option_days . '"' . $selected . '>&nbsp;' . phpbb_admin_html($option_label) . '</option>';
	}
	$select[$n] .= '</select>';

	if(!($result = $db->sql_query('SELECT user_id , username, user_level FROM '. USERS_TABLE .' WHERE user_id<>"'.ANONYMOUS.'"'.$sql[$n].' AND user_regdate<"'.(time()-(86400*$days [$n])).'" ORDER BY username LIMIT 800')))
		message_die(GENERAL_ERROR, 'Error obtaining userdata'.$sql[$n], '', __LINE__, __FILE__, $sql[$n]);
	$user_list = $db->sql_fetchrowset($result);
	$user_count=count($user_list);
	$list[$n] = '';
	for($i = 0; $i < $user_count; $i++) 
	{ 
		$style_color = ($user_list[$i]['user_level'] == ADMIN )?'style="color:#' . $theme['fontcolor3'] . '"':(( $user_list[$i]['user_level'] == MOD )?'style="color:#' . $theme['fontcolor2'] . '"':''); 
		$list[$n] .= ' <a href="' . append_sid($phpbb_root_path."profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=" . intval($user_list[$i]['user_id'])) . '"' . $style_color .'><b>' . phpbb_admin_html($user_list[$i]['username']) . '</b></a>';
	}
	$db->sql_freeresult($result);
$template->assign_block_vars('prune_list', array(
		"LIST" => !empty($list[$n]) ? $list[$n] : $lang['None'],
		"USER_COUNT" => $user_count,
		"L_PRUNE" => $lang['Prune_commands'][$n],
		"L_PRUNE_EXPLAIN" => sprintf($lang['Prune_explain'][$n],$days[$n]),
		'S_PRUNE_USERS' => append_sid("admin_prune_users.$phpEx"),
		"S_DAYS" => $select[$n],
		"U_PRUNE" => '<a href="'.append_sid($phpbb_root_path.'delete_users.php?mode=prune_'.$n.'&amp;days='.$days[$n]).'">'.phpbb_admin_html($lang['Prune_commands'][$n]).'</a>',));
	$n++;
}

$template->assign_vars(array(
	"L_PRUNE_ACTION" => $lang['Prune_Action'],
	"L_PRUNE_LIST" =>	$lang['Prune_user_list'],
	"L_DAYS" => $lang['Days'],
	"L_PRUNE_USERS" => $lang['Prune_users'],
	"L_PRUNE_USERS_EXPLAIN" => $lang['Prune_users_explain'],
));

$template->pparse('body');
include('page_footer_admin.'.$phpEx);

?>
