<?php
/***************************************************************************
 * ACP moderation log (Log Actions MOD 1.1.6 + Enhanced Log Actions)
 ***************************************************************************/

if (!defined('IN_PHPBB'))
{
	define('IN_PHPBB', true);
}
if (!empty($setmodules))
{
	$file = basename(__FILE__);
	$module['Logs']['Logs Actions'] = $file;
	return;
}

$phpbb_root_path = '../';
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);

$start = (isset($_GET['start']) && is_scalar($_GET['start'])) ? max(0, intval($_GET['start'])) : 0;
$per_page = max(10, intval($board_config['topics_per_page']));
$sort_map = array('time' => 'l.log_time', 'user' => 'l.username', 'action' => 'l.mode', 'topic' => 'l.topic_id');
$sort = (isset($_GET['sort']) && is_scalar($_GET['sort'])) ? (string) $_GET['sort'] : 'time';
if (!isset($sort_map[$sort])) { $sort = 'time'; }
$order = (isset($_GET['order']) && is_scalar($_GET['order']) && strtoupper((string) $_GET['order']) === 'ASC') ? 'ASC' : 'DESC';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST')
{
	phpbb_admin_require_post_session();
	if (isset($_POST['delete_selected']) && isset($_POST['log_id']) && is_array($_POST['log_id']))
	{
		$ids = array();
		foreach ($_POST['log_id'] as $log_id)
		{
			if (is_scalar($log_id) && intval($log_id) > 0)
			{
				$ids[intval($log_id)] = intval($log_id);
			}
		}
		$ids = array_values($ids);
		if (!empty($ids))
		{
			$sql = "DELETE FROM " . LOGS_TABLE . " WHERE id_log IN (" . implode(', ', $ids) . ")";
			if (!$db->sql_query($sql)) { message_die(GENERAL_ERROR, 'Could not delete log entries', '', __LINE__, __FILE__, $sql); }
		}
	}
	if (isset($_POST['prune']))
	{
		$days = (isset($_POST['prune_days']) && is_scalar($_POST['prune_days'])) ? intval($_POST['prune_days']) : 1;
		$days = max(1, min(36500, $days));
		$before = time() - ($days * 86400);
		$sql = "DELETE FROM " . LOGS_TABLE . " WHERE log_time < $before";
		if (!$db->sql_query($sql)) { message_die(GENERAL_ERROR, 'Could not prune log entries', '', __LINE__, __FILE__, $sql); }
	}
	redirect(append_sid("admin_logs.$phpEx"));
}

$template->set_filenames(array('body' => 'admin/logs_body.tpl'));

$sql = "SELECT COUNT(*) AS total FROM " . LOGS_TABLE;
if (!($result = $db->sql_query($sql))) { message_die(GENERAL_ERROR, 'Could not count log entries', '', __LINE__, __FILE__, $sql); }
$row = $db->sql_fetchrow($result);
$total = intval($row['total']);
$db->sql_freeresult($result);

$sort_options = '';
foreach (array('time' => $lang['Log_date'], 'user' => $lang['Log_user'], 'action' => $lang['Log_action'], 'topic' => $lang['Log_topic']) as $value => $label)
{
	$sort_options .= '<option value="' . $value . '"' . (($sort === $value) ? ' selected="selected"' : '') . '>' . $label . '</option>';
}

$template->assign_vars(array(
	'L_LOG_TITLE' => $lang['Log_action_title'], 'L_LOG_EXPLAIN' => $lang['Log_action_explain'],
	'L_SORT_BY' => $lang['Select_sort_method'], 'L_ORDER' => $lang['Order'], 'L_SORT' => $lang['Sort'],
	'L_ASC' => $lang['Sort_Ascending'], 'L_DESC' => $lang['Sort_Descending'],
	'L_ACTION' => $lang['Log_action'], 'L_TOPIC' => $lang['Log_topic'], 'L_USER' => $lang['Log_user'],
	'L_IP' => $lang['Log_ip'], 'L_DATE' => $lang['Log_date'], 'L_DELETE_SELECTED' => $lang['Log_delete_selected'],
	'L_PRUNE' => $lang['Log_prune'], 'L_DAYS' => $lang['Log_days'],
	'S_SORT_OPTIONS' => $sort_options, 'ASC_SELECTED' => ($order === 'ASC') ? ' selected="selected"' : '',
	'DESC_SELECTED' => ($order === 'DESC') ? ' selected="selected"' : '', 'S_SID' => htmlspecialchars($userdata['session_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
	'U_ACTION' => append_sid("admin_logs.$phpEx"),
	'PAGINATION' => generate_pagination(append_sid("admin_logs.$phpEx?sort=$sort&amp;order=$order"), $total, $per_page, $start),
	'PAGE_NUMBER' => sprintf($lang['Page_of'], floor($start / $per_page) + 1, max(1, ceil($total / $per_page)))
));

$sql = "SELECT l.* FROM " . LOGS_TABLE . " l ORDER BY " . $sort_map[$sort] . " $order LIMIT $start, $per_page";
if (!($result = $db->sql_query($sql))) { message_die(GENERAL_ERROR, 'Could not read log entries', '', __LINE__, __FILE__, $sql); }
$i = 0;
while ($row = $db->sql_fetchrow($result))
{
	$action_key = 'Log_action_' . $row['mode'];
	$action_label = isset($lang[$action_key]) ? $lang[$action_key] : ucfirst($row['mode']);
	$topic_id = intval($row['topic_id']);
	$user_id = intval($row['user_id']);
	$template->assign_block_vars('logrow', array(
		'ROW_CLASS' => (($i++ % 2) === 0) ? 'row1' : 'row2', 'ID' => intval($row['id_log']),
		'ACTION' => htmlspecialchars($action_label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'TOPIC' => $topic_id,
		'USERNAME' => htmlspecialchars($row['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'IP' => htmlspecialchars($row['user_ip'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
		'DATE' => htmlspecialchars(create_date($lang['DATE_FORMAT'], $row['log_time'], $board_config['board_timezone']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
		'U_TOPIC' => append_sid($phpbb_root_path . "viewtopic.$phpEx?" . POST_TOPIC_URL . "=$topic_id"),
		'U_USER' => append_sid("admin_users.$phpEx?mode=edit&amp;" . POST_USERS_URL . "=$user_id")
	));
}
$db->sql_freeresult($result);

$template->pparse('body');
include('./page_footer_admin.' . $phpEx);
?>
