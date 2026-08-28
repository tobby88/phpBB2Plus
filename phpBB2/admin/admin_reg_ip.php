<?php
/***************************************************************************
 * Registration IP 1.1.2, adapted for PHP 5.6-8.x and IPv6
 ***************************************************************************/

if (!defined('IN_PHPBB'))
{
	define('IN_PHPBB', true);
}
if (!empty($setmodules))
{
	$file = basename(__FILE__);
	$module['Users']['Registration IP'] = $file;
	return;
}

$phpbb_root_path = '../';
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);

$username = isset($_REQUEST['username']) ? phpbb_clean_username((string) $_REQUEST['username']) : '';
if ($username === '')
{
	$template->set_filenames(array('body' => 'admin/user_select_body.tpl'));
	$template->assign_vars(array(
		'L_USER_TITLE' => $lang['Registration_IP'], 'L_USER_EXPLAIN' => $lang['Registration_IP_explain'],
		'L_USER_SELECT' => $lang['Select_a_User'], 'L_LOOK_UP' => $lang['Look_up_user'], 'L_FIND_USERNAME' => $lang['Find_username'],
		'U_SEARCH_USER' => append_sid('../search.' . $phpEx . '?mode=searchuser'),
		'S_USER_ACTION' => append_sid('admin_reg_ip.' . $phpEx), 'S_USER_SELECT' => ''
	));
	$template->pparse('body');
	include('./page_footer_admin.' . $phpEx);
	exit;
}

$username_sql = str_replace("'", "''", str_replace("\\'", "'", $username));
$sql = "SELECT user_id, username, user_email, user_posts, user_regdate, user_reg_ip, user_reg_host
	FROM " . USERS_TABLE . " WHERE username = '$username_sql'";
if (!($result = $db->sql_query($sql)))
{
	message_die(GENERAL_ERROR, 'Could not read registration IP', '', __LINE__, __FILE__, $sql);
}
if (!($main = $db->sql_fetchrow($result)))
{
	message_die(GENERAL_MESSAGE, $lang['No_user_id_specified']);
}
$db->sql_freeresult($result);

$ip = trim((string) $main['user_reg_ip']);
$host = trim((string) $main['user_reg_host']);
if ($ip !== '' && isset($_GET['resolve']) && $_GET['resolve'] === '1')
{
	$resolved = @gethostbyaddr($ip);
	$host = ($resolved && $resolved !== $ip) ? $resolved : $lang['Registration_IP_no_hostname'];
	if ($resolved && $resolved !== $ip)
	{
		$host_sql = str_replace("'", "''", substr($resolved, 0, 255));
		$sql = "UPDATE " . USERS_TABLE . " SET user_reg_host = '$host_sql' WHERE user_id = " . intval($main['user_id']);
		$db->sql_query($sql);
	}
}
else if ($host === '')
{
	$host = $lang['Registration_IP_not_resolved'];
}

$template->set_filenames(array('body' => 'admin/user_ip_list.tpl'));
$template->assign_vars(array(
	'L_TITLE' => $lang['Registration_IP'], 'L_EXPLAIN' => $lang['Registration_IP_explain'],
	'L_USERNAME' => $lang['Username'], 'L_POSTS' => $lang['Posts'], 'L_JOINED' => $lang['Joined'], 'L_EMAIL' => $lang['Email'],
	'L_IP' => $lang['Registration_IP_address'], 'L_HOST' => $lang['Registration_IP_hostname'],
	'L_SHARED' => $lang['Registration_IP_shared'], 'L_RESOLVE' => $lang['Registration_IP_resolve'],
	'MAIN_USER' => htmlspecialchars($main['username'], ENT_QUOTES, 'UTF-8'),
	'MAIN_EMAIL' => htmlspecialchars($main['user_email'], ENT_QUOTES, 'UTF-8'), 'MAIN_POSTS' => intval($main['user_posts']),
	'MAIN_JOINED' => create_date($lang['DATE_FORMAT'], $main['user_regdate'], $board_config['board_timezone']),
	'MAIN_IP' => ($ip === '') ? $lang['Registration_IP_unknown'] : htmlspecialchars($ip, ENT_QUOTES, 'UTF-8'),
	'MAIN_HOST' => htmlspecialchars($host, ENT_QUOTES, 'UTF-8'),
	'U_RESOLVE' => append_sid("admin_reg_ip.$phpEx?username=" . urlencode($main['username']) . '&amp;resolve=1')
));

if ($ip !== '')
{
	$ip_sql = str_replace("'", "''", $ip);
	$sql = "SELECT user_id, username, user_email, user_posts, user_regdate
		FROM " . USERS_TABLE . " WHERE user_reg_ip = '$ip_sql' ORDER BY user_regdate";
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not find matching registration IPs', '', __LINE__, __FILE__, $sql);
	}
	$i = 0;
	while ($row = $db->sql_fetchrow($result))
	{
		$template->assign_block_vars('userrow', array(
			'ROW_CLASS' => (($i++ % 2) === 0) ? 'row1' : 'row2',
			'USERNAME' => htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'),
			'EMAIL' => htmlspecialchars($row['user_email'], ENT_QUOTES, 'UTF-8'), 'POSTS' => intval($row['user_posts']),
			'JOINED' => create_date($lang['DATE_FORMAT'], $row['user_regdate'], $board_config['board_timezone']),
			'U_USER' => append_sid("admin_users.$phpEx?mode=edit&amp;" . POST_USERS_URL . '=' . intval($row['user_id']))
		));
	}
	$db->sql_freeresult($result);
}

$template->pparse('body');
include('./page_footer_admin.' . $phpEx);
?>
