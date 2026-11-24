<?php
/** 
*
* @package attachment_mod
* @version $Id: attach_rules.php,v 1.2 2005/11/05 12:23:33 acydburn Exp $
* @copyright (c) 2002 Meik Sievertsen
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/

/**
*/
if (defined('IN_PHPBB'))
{
	die('Hacking attempt');
	exit;
}

define('IN_PHPBB', TRUE);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);

$forum_id = get_var('f', 0);
$privmsg = ($forum_id === 0);

// Start Session Management
$userdata = session_pagestart($user_ip, PAGE_INDEX);
init_userprefs($userdata);

$admin = ($userdata['user_level'] == ADMIN);
if ($privmsg)
{
	$allowed = $userdata['session_logged_in'] && empty($board_config['privmsg_disable'])
		&& !empty($userdata['user_allow_pm']) && !empty($attach_config['allow_pm_attach']);
}
else
{
	$auth = auth(AUTH_ALL, $forum_id, $userdata);
	$allowed = !empty($auth['auth_view']) && !empty($auth['auth_read']) && !empty($auth['auth_attachments']);
}
if (!empty($attach_config['disable_mod']) || !$allowed)
{
	message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}

$template->set_filenames(array(
	'body' => 'posting_attach_rules.tpl')
);

$groups = attach_get_extension_rules($db, $forum_id, $privmsg, $admin, $attach_config['max_filesize']);
foreach ($groups as $group)
{
	$limit = $group['limit'];
	$label = $lang['Unlimited'];
	if ($limit > 0)
	{
		if ($limit >= 1048576) { $label = round($limit / 1048576, 2) . ' ' . $lang['MB']; }
		elseif ($limit >= 1024) { $label = round($limit / 1024, 2) . ' ' . $lang['KB']; }
		else { $label = $limit . ' ' . $lang['Bytes']; }
	}
	$template->assign_block_vars('group_row', array('GROUP_RULE_HEADER' => sprintf(
		$lang['Group_rule_header'], htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8'), $label
	)));
	foreach ($group['extensions'] as $extension)
	{
		$template->assign_block_vars('group_row.extension_row', array(
			'EXTENSION' => htmlspecialchars($extension, ENT_QUOTES, 'UTF-8')
		));
	}
}

$gen_simple_header = TRUE;
$page_title = $lang['Attach_rules_title'];
include($phpbb_root_path . 'includes/page_header.' . $phpEx);

$template->assign_vars(array(
	'L_RULES_TITLE'			=> $lang['Attach_rules_title'],
	'L_CLOSE_WINDOW'		=> $lang['Close_window'],
	'L_EMPTY_GROUP_PERMS'	=> $lang['Note_user_empty_group_permissions'])
);

if (!$groups)
{
	$template->assign_block_vars('switch_nothing', array());
}

$template->pparse('body');
include($phpbb_root_path . 'includes/page_tail.' . $phpEx);

?>
