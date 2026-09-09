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
require_once($phpbb_root_path . 'includes/functions_user_prune.' . $phpEx);

// Shared selection predicates live in functions_user_prune.php.
$default = array(240, 240, 240, 120, 360);

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
while (isset($default[$n]))
{
	$vars='days_'.$n;
	
	$default[$n] = !empty($default[$n]) ? $default[$n] : 10;
	$day_value = isset($_POST[$vars]) ? $_POST[$vars] : (isset($_GET[$vars]) ? $_GET[$vars] : $default[$n]);
	try { $days[$n] = phpbb_prune_days($day_value); }
	catch (PhpbbRemovalException $error) { message_die(GENERAL_MESSAGE, phpbb_prune_html($error->getMessage())); }
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

	$policy = array('mode'=>'prune_' . $n,'days'=>$days[$n],'at'=>time());
	if(!($result = $db->sql_query('SELECT user_id, username, user_level FROM ' . USERS_TABLE . ' WHERE ' . phpbb_prune_where($policy) . ' ORDER BY username,user_id LIMIT 800')))
		message_die(GENERAL_ERROR, 'Error obtaining pruning candidates', '', __LINE__, __FILE__);
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

try { $pending_removals = phpbb_prune_pending_html($db); }
catch (Exception $error) { $pending_removals = '<p>' . phpbb_prune_html($error instanceof PhpbbRemovalException ? $error->getMessage() : $lang['Removal_jobs_unavailable']) . '</p>'; }
catch (Error $error) { $pending_removals = '<p>' . phpbb_prune_html($lang['Removal_jobs_unavailable']) . '</p>'; }
$template->assign_vars(array(
	'REMOVAL_JOBS' => $pending_removals,
	'S_PRUNE_USERS' => append_sid('admin_prune_users.' . $phpEx),
	"L_PRUNE_ACTION" => $lang['Prune_Action'],
	"L_PRUNE_LIST" =>	$lang['Prune_user_list'],
	"L_DAYS" => $lang['Days'],
	"L_PRUNE_USERS" => $lang['Prune_users'],
	"L_PRUNE_USERS_EXPLAIN" => $lang['Prune_users_explain'],
));

$template->pparse('body');
include('page_footer_admin.'.$phpEx);

?>
