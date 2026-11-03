<?php
/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/
if ( !defined('IN_PHPBB') )
{
	die("Hacking attempt");
} 
//
// Top Posting Users This Month (History Mod adaption)
//
$statistics->init_bars();

$current_time = time();
list($time_thismonth, $time_nextmonth) = statistics_period_window('month', $current_time, $board_config['board_timezone']);

$l_this_month = create_date('F', $time_thismonth, $board_config['board_timezone']);

$template->assign_vars(array(
	'L_MODULE_NAME' => $lang['module_name'],
	'MONTH' => sprintf($lang['Month'], ($l_this_month . ' ' . create_date('Y', $time_thismonth, $board_config['board_timezone'])) ),
	'L_RANK' => $lang['Rank'],
	'L_PERCENTAGE' => $lang['Percent'],
	'L_GRAPH' => $lang['Graph'],
	'L_USERNAME' => $lang['Username'],
	'L_POSTS' => $lang['Posts'])
);

$sql = "select u.user_id, u.username, count(u.user_id) as user_posts  
FROM " . USERS_TABLE . " u, " . POSTS_TABLE . " p 
WHERE (u.user_id = p.poster_id) AND (p.post_time >= '" . $time_thismonth . "') AND (p.post_time < '" . $time_nextmonth . "') AND (u.user_id <> " . ANONYMOUS . ")
GROUP BY user_id, username
ORDER BY user_posts DESC
LIMIT " . $return_limit;

if ( !($result = $stat_db->sql_query($sql)) )
{
	message_die(GENERAL_ERROR, "Couldn't retrieve topposters data", "", __LINE__, __FILE__, $sql);
}

$total_posts_thismonth = 0;
$user_count = $stat_db->sql_numrows($result);
$user_data = $stat_db->sql_fetchrowset($result);
$firstcount = !empty($user_data) ? max(1, intval($user_data[0]['user_posts'])) : 1;

for ($i = 0; $i < $user_count; $i++)
{
	$total_posts_thismonth += $user_data[$i]['user_posts'];
}

for ($i = 0; $i < $user_count; $i++)
{
	$class = ($i % 2) ? $theme['td_class2'] : $theme['td_class1'];
	
	$statistics->do_math($firstcount, $user_data[$i]['user_posts'], $total_posts_thismonth);

	$template->assign_block_vars('top_posters', array(
		'RANK' => $i+1,
		'CLASS' => $class,
		'USERNAME' => htmlspecialchars((string) $user_data[$i]['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
		'PERCENTAGE' => $statistics->percentage,
		'BAR' => $statistics->bar_percent,
		'URL' => append_sid($phpbb_root_path . 'profile.php?mode=viewprofile&u=' . intval($user_data[$i]['user_id'])),
		'POSTS' => max(0, intval($user_data[$i]['user_posts'])))
	);
}

?>
