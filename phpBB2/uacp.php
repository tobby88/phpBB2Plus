<?php
/** 
*
* @package attachment_mod
* @version $Id: uacp.php,v 1.3 2005/11/06 18:35:43 acydburn Exp $
* @copyright (c) 2002 Meik Sievertsen
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/

/**
*/
define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);
require_once($phpbb_root_path . 'includes/functions_uacp_attachments.' . $phpEx);
require_once($phpbb_root_path . 'includes/functions_uacp_listing.' . $phpEx);

// session id check
$sid = get_var('sid', '');

// Start session management
$userdata = session_pagestart($user_ip, PAGE_PROFILE);
init_userprefs($userdata);

// session id check
if (empty($userdata['session_logged_in']) || !is_string($sid) || $sid === '' || !isset($userdata['session_id']) || !is_string($userdata['session_id']) || !hash_equals($userdata['session_id'], $sid))
{
	message_die(GENERAL_ERROR, 'Invalid_session');
}

// Obtain initial var settings
$user_id = get_var(POST_USERS_URL, 0);

if ($user_id < 1 || $user_id > 8388607)
{
	message_die(GENERAL_MESSAGE, $lang['No_user_id_specified']);
}

$profiledata = get_userdata($user_id);
if (!is_array($profiledata) || !isset($profiledata['user_id'])) { message_die(GENERAL_MESSAGE, $lang['No_such_user']); }

if ($profiledata['user_id'] != $userdata['user_id'] && $userdata['user_level'] != ADMIN)
{
	message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}

$page_title = $lang['User_acp_title'];
include($phpbb_root_path . 'includes/page_header.'.$phpEx);

$language = $board_config['default_lang'];

if (!file_exists($phpbb_root_path . 'language/lang_' . $language . '/lang_admin_attach.'.$phpEx))
{
	$language = $attach_config['board_lang'];
}

include($phpbb_root_path . 'language/lang_' . $language . '/lang_admin_attach.' . $phpEx);

$start = max(0, min(1000000, get_var('start', 0)));
$sort_order = get_var('order', 'ASC');
$sort_order = ($sort_order === 'DESC') ? 'DESC' : 'ASC';
$mode = get_var('mode', '');

$mode_types_text = array($lang['Sort_Filename'], $lang['Sort_Comment'], $lang['Sort_Extension'], $lang['Sort_Size'], $lang['Sort_Downloads'], $lang['Sort_Posttime']);
$mode_types = array('real_filename', 'comment', 'extension', 'filesize', 'downloads', 'post_time');
// Retain old bookmarked modes while using one value in controls and pagination.
$sort_aliases = array('filename' => 'real_filename', 'a.real_filename' => 'real_filename', 'download_count' => 'downloads', 'filetime' => 'post_time');
if (isset($sort_aliases[$mode]))
{
	$mode = $sort_aliases[$mode];
}
if (!in_array($mode, $mode_types, true))
{
	$mode = 'real_filename';
}

$do_pagination = true;

// Set select fields
$select_sort_mode = $select_sort_order = '';

if (sizeof($mode_types_text) > 0)
{
	$select_sort_mode = '<select name="mode">';

	for ($i = 0; $i < sizeof($mode_types_text); $i++)
	{
		$selected = ($mode == $mode_types[$i]) ? ' selected="selected"' : '';
		$select_sort_mode .= '<option value="' . $mode_types[$i] . '"' . $selected . '>' . $mode_types_text[$i] . '</option>';
	}
	$select_sort_mode .= '</select>';
}

$select_sort_order = '<select name="order">';
if ($sort_order == 'ASC')
{
	$select_sort_order .= '<option value="ASC" selected="selected">' . $lang['Sort_Ascending'] . '</option><option value="DESC">' . $lang['Sort_Descending'] . '</option>';
}
else
{
	$select_sort_order .= '<option value="ASC">' . $lang['Sort_Ascending'] . '</option><option value="DESC" selected="selected">' . $lang['Sort_Descending'] . '</option>';
}
$select_sort_order .= '</select>';

$delete = isset($HTTP_POST_VARS['delete']);
$confirm = !empty($HTTP_POST_VARS['confirm']);
try
{
	if (isset($HTTP_POST_VARS['confirm']) && !is_string($HTTP_POST_VARS['confirm']) && !is_int($HTTP_POST_VARS['confirm'])) { throw new PhpbbOwnedStorageFailure('Uacp_attachment_selection'); }
	$delete_id_list = phpbb_uacp_attachment_ids(isset($HTTP_POST_VARS['delete_id_list']) ? $HTTP_POST_VARS['delete_id_list'] : array());
	if ($delete || $confirm)
	{
		$change = phpbb_uacp_attachments_remove($db, $userdata, $sid, $profiledata['user_id'], $delete_id_list, $confirm);
		if ($confirm)
		{
			$link = append_sid('uacp.' . $phpEx . '?' . POST_USERS_URL . '=' . (int)$profiledata['user_id'] . '&sid=' . urlencode($userdata['session_id']));
			$message = $lang[$change['cleanup_complete'] ? 'Uacp_attachment_deleted' : 'Uacp_attachment_cleanup_pending'];
			message_die(GENERAL_MESSAGE, $message . '<br /><br /><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . $lang['User_acp_title'] . '</a>');
		}
	}
}
catch (PhpbbOwnedStorageFailure $exception)
{
	$key = $exception->getMessage();
	message_die(GENERAL_MESSAGE, isset($lang[$key]) ? $lang[$key] : $lang['Uacp_attachment_failed']);
}
if ($delete && sizeof($delete_id_list) > 0)

{
	// Not confirmed, show confirmation message
	$hidden_fields = '';
	$hidden_fields .= '<input type="hidden" name="mode" value="' . $mode . '" />';
	$hidden_fields .= '<input type="hidden" name="order" value="' . $sort_order . '" />';
	$hidden_fields .= '<input type="hidden" name="' . POST_USERS_URL . '" value="' . intval($profiledata['user_id']) . '" />';
	$hidden_fields .= '<input type="hidden" name="start" value="' . $start . '" />';
	$hidden_fields .= '<input type="hidden" name="sid" value="' . $userdata['session_id'] . '" />';

	for ($i = 0; $i < sizeof($delete_id_list); $i++)
	{
		$hidden_fields .= '<input type="hidden" name="delete_id_list[]" value="' . intval($delete_id_list[$i]) . '" />';
	}

	$template->set_filenames(array(
		'confirm' => 'confirm_body.tpl')
	);

	$template->assign_vars(array(
		'MESSAGE_TITLE'	=> $lang['Confirm'],
		'MESSAGE_TEXT'	=> $lang['Confirm_delete_attachments'],

		'L_YES'			=> $lang['Yes'],
		'L_NO'			=> $lang['No'],

		'S_CONFIRM_ACTION'	=> append_sid($phpbb_root_path . 'uacp.' . $phpEx),
		'S_HIDDEN_FIELDS'	=> $hidden_fields)
	);

	$template->pparse('confirm');
	
	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

	exit;
}

// Read count, page and parent labels from one independent read-only snapshot.
try
{
	$attachment_page = phpbb_uacp_attachment_listing($db, $userdata, $profiledata['user_id'], $mode, $sort_order, $start, isset($board_config['topics_per_page']) ? $board_config['topics_per_page'] : 25);
}
catch (PhpbbOwnedStorageFailure $exception)
{
	$key = $exception->getMessage();
	message_die(GENERAL_MESSAGE, isset($lang[$key]) ? $lang[$key] : $lang['Uacp_attachment_list_failed']);
}
$profiledata = $attachment_page['user'];
$attachments = $attachment_page['rows'];
$total_rows = $attachment_page['total'];
$start = $attachment_page['start'];
$uacp_page_size = $attachment_page['size'];

$hidden_fields = '';
	
$template->set_filenames(array(
	'body' => 'uacp_body.tpl')
);



$s_hidden = '<input type="hidden" name="' . POST_USERS_URL . '" value="' . intval($profiledata['user_id']) . '">';
$s_hidden .= '<input type="hidden" name="sid" value="' . $userdata['session_id'] . '" />';

// Assign Template Vars
$template->assign_vars(array(
	'L_SUBMIT'				=> $lang['Submit'],
	'L_UACP'				=> $lang['UACP'],
	'L_SELECT_SORT_METHOD'	=> $lang['Select_sort_method'],
	'L_ORDER'				=> $lang['Order'],
	'L_FILENAME'			=> $lang['File_name'],
	'L_FILECOMMENT'			=> $lang['File_comment_cp'],
	'L_EXTENSION'			=> $lang['Extension'],
	'L_SIZE'				=> $lang['Size_in_kb'],
	'L_DOWNLOADS'			=> $lang['Downloads'],
	'L_POST_TIME'			=> $lang['Post_time'],
	'L_POSTED_IN_TOPIC'		=> $lang['Posted_in_topic'],
	'L_DELETE'				=> $lang['Delete'],
	'L_DELETE_MARKED'		=> $lang['Delete_marked'],
	'L_MARK_ALL'			=> $lang['Mark_all'],
	'L_UNMARK_ALL'			=> $lang['Unmark_all'],

	'USERNAME'		=> phpbb_stored_text($profiledata['username']),

	'S_USER_HIDDEN'		=> $s_hidden,
	'S_MODE_ACTION'		=> append_sid($phpbb_root_path . 'uacp.' . $phpEx),
	'S_MODE_SELECT'		=> $select_sort_mode,
	'S_ORDER_SELECT'	=> $select_sort_order)
);

if (sizeof($attachments) > 0)
{
	for ($i = 0; $i < sizeof($attachments); $i++)
	{
		$row_color = ( !($i % 2) ) ? $theme['td_color1'] : $theme['td_color2'];
		$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];

		$post_titles = array();
		foreach ($attachments[$i]['parents'] as $parent)
		{
			if ($parent['type'] === 'post')
			{
				$view_topic = append_sid($phpbb_root_path . 'viewtopic.' . $phpEx . '?' . POST_POST_URL . '=' . $parent['post_id'], true) . '#' . $parent['post_id'];
				$post_titles[] = '<a href="' . phpbb_profile_text($view_topic) . '" class="gen" target="_blank" rel="noopener noreferrer">' . phpbb_uacp_topic_label($parent['title']) . '</a>';
			}
			else
			{
				$post_titles[] = phpbb_profile_text($lang['Private_Message'] . ' (' . $lang[$parent['box']] . ')');
			}
		}

		// Iron out those Attachments assigned to us, but not more controlled by us. ;) (PM's)
		if (sizeof($post_titles) > 0)
		{
			$delete_box = '<input type="checkbox" name="delete_id_list[]" value="' . (int) $attachments[$i]['attach_id'] . '" />';

			for ($j = 0; $j < sizeof($delete_id_list); $j++)
			{
				if ($delete_id_list[$j] == $attachments[$i]['attach_id'])
				{
					$delete_box = '<input type="checkbox" name="delete_id_list[]" value="' . (int) $attachments[$i]['attach_id'] . '" checked />';
					break;
				}
			}

			$post_titles = implode('<br />', $post_titles);

			$hidden_field = '<input type="hidden" name="attach_id_list[]" value="' . (int) $attachments[$i]['attach_id'] . '">';
			$hidden_field .= '<input type="hidden" name="sid" value="' . $userdata['session_id'] . '" />';

			$comment = str_replace(array("\r\n", "\r", "\n"), '<br />', phpbb_stored_text($attachments[$i]['comment']));

			$template->assign_block_vars('attachrow', array(
				'ROW_NUMBER'		=> $i + ($start + 1 ),
				'ROW_COLOR'			=> '#' . $row_color,
				'ROW_CLASS'			=> $row_class,

				'FILENAME'			=> phpbb_stored_text($attachments[$i]['real_filename']),
				'COMMENT'			=> $comment,
				'EXTENSION'			=> phpbb_stored_text($attachments[$i]['extension']),
				'SIZE'				=> round(($attachments[$i]['filesize'] / MEGABYTE), 2),
				'DOWNLOAD_COUNT'	=> $attachments[$i]['download_count'],
				'POST_TIME'			=> create_date($board_config['default_dateformat'], $attachments[$i]['filetime'], $board_config['board_timezone']),
				'POST_TITLE'		=> $post_titles,

				'S_DELETE_BOX'		=> $delete_box,
				'S_HIDDEN'			=> $hidden_field,
				'U_VIEW_ATTACHMENT'	=> append_sid($phpbb_root_path . 'download.' . $phpEx . '?id=' . $attachments[$i]['attach_id']))
			);
		}
	}
}

// Generate Pagination
if ($do_pagination && $total_rows > $uacp_page_size)
{
	$pagination = generate_pagination($phpbb_root_path . 'uacp.' . $phpEx . '?mode=' . $mode . '&amp;order=' . $sort_order . '&amp;' . POST_USERS_URL . '=' . $profiledata['user_id'] . '&amp;sid=' . $userdata['session_id'], $total_rows, $uacp_page_size, $start).'&nbsp;';

	$template->assign_vars(array(
		'PAGINATION'	=> $pagination,
		'PAGE_NUMBER'	=> sprintf($lang['Page_of'], (floor($start / $uacp_page_size) + 1), ceil($total_rows / $uacp_page_size)),

		'L_GOTO_PAGE'	=> $lang['Goto_page'])
	);
}

$template->pparse('body');

include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>
