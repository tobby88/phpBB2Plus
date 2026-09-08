<?php
/***************************************************************************
 * admin_users_list.php
 *
 * Admin Userlist 2.1, adapted for phpBB2 Plus and Color Groups.
 ***************************************************************************/

if (!defined('IN_PHPBB'))
{
	define('IN_PHPBB', true);
}

if (!empty($setmodules))
{
	$filename = basename(__FILE__);
	$module['Users']['Users List'] = $filename;
	return;
}

$phpbb_root_path = '../';
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
include_once($phpbb_root_path . 'includes/functions_color_groups.' . $phpEx);

$start = (isset($_GET['start']) && is_scalar($_GET['start'])) ? max(0, intval($_GET['start'])) : 0;
$show_value = (isset($_POST['show']) && is_scalar($_POST['show'])) ? $_POST['show'] :
	((isset($_GET['show']) && is_scalar($_GET['show'])) ? $_GET['show'] : $board_config['topics_per_page']);
$show = intval($show_value);
$show = min(200, max(1, $show));

$allowed_sorts = array(
	'user_id' => 'u.user_id',
	'username' => 'u.username',
	'user_regdate' => 'u.user_regdate',
	'user_lastvisit' => 'u.user_lastvisit',
	'user_posts' => 'u.user_posts',
	'user_email' => 'u.user_email',
	'user_active' => 'u.user_active'
);
$sort = (isset($_POST['sort']) && is_scalar($_POST['sort'])) ? (string) $_POST['sort'] :
	((isset($_GET['sort']) && is_scalar($_GET['sort'])) ? (string) $_GET['sort'] : 'user_regdate');
if (!isset($allowed_sorts[$sort]))
{
	$sort = 'user_regdate';
}
$order_value = (isset($_POST['order']) && is_scalar($_POST['order'])) ? $_POST['order'] :
	((isset($_GET['order']) && is_scalar($_GET['order'])) ? $_GET['order'] : 'DESC');
$order = strtoupper((string) $order_value) === 'ASC' ? 'ASC' : 'DESC';

$alpha = (isset($_POST['alpha']) && is_scalar($_POST['alpha'])) ? trim((string) $_POST['alpha']) :
	((isset($_GET['alpha']) && is_scalar($_GET['alpha'])) ? trim((string) $_GET['alpha']) : '');
if ($alpha !== '' && !preg_match('/^[A-Za-z0-9]$/', $alpha))
{
	$alpha = '';
}
$alpha_sql = ($alpha === '') ? '' : (($alpha === '0')
	? " AND u.username NOT REGEXP '^[A-Za-z]'"
	: " AND u.username LIKE '" . $db->sql_escape($alpha) . "%'");

$selected_users = isset($_POST[POST_USERS_URL]) ? $_POST[POST_USERS_URL] : array();
$action = (isset($_POST['bulk_action']) && is_scalar($_POST['bulk_action'])) ? (string) $_POST['bulk_action'] : '';

if (!empty($selected_users) && in_array($action, array('activate', 'deactivate', 'ban', 'unban', 'group'), true))
{
	phpbb_admin_require_post_session();
	require_once($phpbb_root_path . 'includes/functions_userlist_storage.' . $phpEx);
	try
	{
		$summary = phpbb_userlist_apply($db, $action, $selected_users, isset($_POST['group_id']) ? $_POST['group_id'] : null);
	}
	catch (PhpbbUserlistException $error)
	{
		message_die(GENERAL_MESSAGE, htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8'));
	}
	message_die(GENERAL_MESSAGE, sprintf($lang['Admin_userlist_result'], $summary['changed'], $summary['unchanged']) . '<br /><br />' . sprintf($lang['Click_return_userlist'], '<a href="' . append_sid("admin_users_list.$phpEx") . '">', '</a>'));
}

$template->set_filenames(array('body' => 'admin/admin_users_list_body.tpl'));

$sql = "SELECT COUNT(u.user_id) AS total FROM " . USERS_TABLE . " u WHERE u.user_id > 0$alpha_sql";
if (!($result = $db->sql_query($sql)))
{
	message_die(GENERAL_ERROR, 'Could not count users', '', __LINE__, __FILE__, $sql);
}
$row = $db->sql_fetchrow($result);
$total_users = intval($row['total']);
$db->sql_freeresult($result);

$banned = array();
$sql = "SELECT ban_userid FROM " . BANLIST_TABLE . " WHERE ban_userid <> 0";
if ($result = $db->sql_query($sql))
{
	while ($row = $db->sql_fetchrow($result)) { $banned[intval($row['ban_userid'])] = true; }
	$db->sql_freeresult($result);
}

$groups_select = '<option value="0">' . $lang['Admin_userlist_select_group'] . '</option>';
$sql = "SELECT group_id, group_name FROM " . GROUPS_TABLE . " WHERE group_single_user = 0 ORDER BY group_name";
if ($result = $db->sql_query($sql))
{
	while ($row = $db->sql_fetchrow($result))
	{
		$groups_select .= '<option value="' . intval($row['group_id']) . '">' . htmlspecialchars($row['group_name'], ENT_QUOTES, 'UTF-8') . '</option>';
	}
	$db->sql_freeresult($result);
}

$sort_options = '';
foreach (array('user_id' => $lang['ID'], 'username' => $lang['Username'], 'user_regdate' => $lang['Joined'], 'user_lastvisit' => $lang['Last_Visit'], 'user_posts' => $lang['Posts'], 'user_email' => $lang['Email'], 'user_active' => $lang['Active']) as $value => $label)
{
	$sort_options .= '<option value="' . $value . '"' . (($sort === $value) ? ' selected="selected"' : '') . '>' . $label . '</option>';
}

$template->assign_vars(array(
	'L_ADMIN_USERS_LIST' => $lang['Admin_Users_List'],
	'L_ADMIN_USERS_LIST_EXPLAIN' => $lang['Admin_Users_List_explain'],
	'L_THERE_ARE' => $lang['There_are'], 'TOTAL_USERS' => $total_users, 'L_MEMBERS' => $lang['Boardmembers'],
	'L_SORT_BY' => $lang['Select_sort_method'], 'L_ORDER' => $lang['Order'], 'L_SHOW' => $lang['Admin_userlist_show'], 'L_SORT' => $lang['Sort'],
	'L_SORT_ASCENDING' => $lang['Sort_Ascending'], 'L_SORT_DESCENDING' => $lang['Sort_Descending'],
	'L_ID' => $lang['ID'], 'L_ACTION' => $lang['Action'], 'L_USERNAME' => $lang['Username'], 'L_EMAIL' => $lang['Email'],
	'L_POSTS' => $lang['Posts'], 'L_JOINED' => $lang['Joined'], 'L_LAST_VISIT' => $lang['Last_Visit'], 'L_ACTIVE' => $lang['Active'],
	'L_EDIT' => $lang['Edit'], 'L_PERMISSION' => $lang['Permission'],
	'L_BULK_ACTION' => $lang['Admin_userlist_bulk_action'], 'L_ACTIVATE' => $lang['Admin_userlist_activate'],
	'L_DEACTIVATE' => $lang['Admin_userlist_deactivate'], 'L_BAN' => $lang['Ban'], 'L_UNBAN' => $lang['Unban'],
	'L_ADD_GROUP' => $lang['Admin_userlist_add_group'], 'L_APPLY' => $lang['Submit'], 'L_BANNED' => $lang['Admin_userlist_banned'],
	'S_SORT_OPTIONS' => $sort_options, 'S_GROUP_OPTIONS' => $groups_select, 'S_SHOW' => $show,
	'ASC_SELECTED' => ($order === 'ASC') ? ' selected="selected"' : '', 'DESC_SELECTED' => ($order === 'DESC') ? ' selected="selected"' : '',
	'U_LIST_ACTION' => append_sid("admin_users_list.$phpEx"),
	'S_HIDDEN_FIELDS' => phpbb_admin_session_field(),
	'PAGINATION' => generate_pagination(append_sid("admin_users_list.$phpEx?sort=$sort&amp;order=$order&amp;show=$show&amp;alpha=$alpha"), $total_users, $show, $start),
	'PAGE_NUMBER' => sprintf($lang['Page_of'], floor($start / $show) + 1, max(1, ceil($total_users / $show)))
));

foreach (array_merge(range('A', 'Z'), array('0')) as $letter)
{
	$template->assign_block_vars('alpha', array('LETTER' => ($letter === '0') ? '0–9' : $letter, 'U_LETTER' => append_sid("admin_users_list.$phpEx?alpha=$letter&amp;sort=$sort&amp;order=$order&amp;show=$show")));
}

$sql = "SELECT u.user_id, u.username, u.user_email, u.user_regdate, u.user_lastvisit, u.user_posts, u.user_active
	FROM " . USERS_TABLE . " u WHERE u.user_id > 0$alpha_sql
	ORDER BY " . $allowed_sorts[$sort] . " $order LIMIT $start, $show";
if (!($result = $db->sql_query($sql)))
{
	message_die(GENERAL_ERROR, 'Could not query user information', '', __LINE__, __FILE__, $sql);
}
$i = 0;
while ($row = $db->sql_fetchrow($result))
{
	$user_id = intval($row['user_id']);
	$template->assign_block_vars('userrow', array(
		'COLOR' => (($i++ % 2) === 0) ? 'row1' : 'row2', 'NUMBER' => $user_id,
		'USERNAME' => color_group_colorize_name($user_id, true) . (isset($banned[$user_id]) ? ' <strong>(' . $lang['Admin_userlist_banned'] . ')</strong>' : ''),
		'EMAIL' => htmlspecialchars($row['user_email'], ENT_QUOTES, 'UTF-8'), 'POSTS' => intval($row['user_posts']),
		'JOINED' => create_date($lang['DATE_FORMAT'], $row['user_regdate'], $board_config['board_timezone']),
		'LAST_VISIT' => empty($row['user_lastvisit']) ? '&ndash;' : create_date($lang['DATE_FORMAT'], $row['user_lastvisit'], $board_config['board_timezone']),
		'ACTIVE' => $row['user_active'] ? $lang['Yes'] : $lang['No'],
		'U_ADMIN_USER' => append_sid("admin_users.$phpEx?mode=edit&amp;" . POST_USERS_URL . "=$user_id"),
		'U_ADMIN_USER_AUTH' => append_sid("admin_ug_auth.$phpEx?mode=user&amp;" . POST_USERS_URL . "=$user_id")
	));
}
$db->sql_freeresult($result);

$template->pparse('body');
include('./page_footer_admin.' . $phpEx);
?>
