<?php
/** 
*
* @package attachment_mod
* @version $Id: admin_attach_cp.php,v 1.1 2005/11/07 18:55:05 acydburn Exp $
* @copyright (c) 2002 Meik Sievertsen
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/

/**
*/
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if (!empty($setmodules))
{
	$filename = basename(__FILE__);
	$module['Attachments']['Control_Panel'] = $filename;
	return;
}

// Let's set the root dir for phpBB
$phpbb_root_path = './../';
require($phpbb_root_path . 'extension.inc');
require('pagestart.' . $phpEx);
require_once($phpbb_root_path . 'attach_mod/includes/functions_metadata_storage.' . $phpEx);
require_once($phpbb_root_path . 'attach_mod/includes/functions_admin_delete_storage.' . $phpEx);

@include_once($phpbb_root_path . 'attach_mod/includes/constants.'.$phpEx);

if (!intval($attach_config['allow_ftp_upload']))
{
	if ( ($attach_config['upload_dir'][0] == '/') || ( ($attach_config['upload_dir'][0] != '/') && ($attach_config['upload_dir'][1] == ':') ) )
	{
		$upload_dir = $attach_config['upload_dir'];
	}
	else
	{
		$upload_dir = '../' . $attach_config['upload_dir'];
	}
}
else
{
	$upload_dir = $attach_config['download_path'];
}

include($phpbb_root_path . 'attach_mod/includes/functions_selects.' . $phpEx);
include($phpbb_root_path . 'attach_mod/includes/functions_admin.' . $phpEx);

// Check if the language got included
if (!isset($lang['Test_settings_successful']))
{
	// include_once is used within the function
	include_attach_lang();
}

// Init Variables
$start = max(0, min(1000000, get_var('start', 0)));
$page_size = isset($board_config['topics_per_page']) ? $board_config['topics_per_page'] : 25;
$page_size = (is_int($page_size) || is_string($page_size)) && preg_match('/^[1-9][0-9]{0,3}$/D', (string) $page_size) && (int) $page_size <= 1000 ? (int) $page_size : 25;
$sort_order = get_var('order', 'ASC');
$sort_order = ($sort_order == 'ASC') ? 'ASC' : 'DESC';
$mode = get_var('mode', '');
$view = get_var('view', '');
$uid = (isset($HTTP_POST_VARS['u_id'])) ? get_var('u_id', 0) : get_var('uid', 0);

$search_submit = isset($HTTP_POST_VARS['search']) ? $HTTP_POST_VARS['search'] : '';
$search_marker = isset($HTTP_POST_VARS['searching']) ? $HTTP_POST_VARS['searching'] : (isset($HTTP_GET_VARS['searching']) ? $HTTP_GET_VARS['searching'] : '');
if (!is_string($search_submit) || !is_string($search_marker) || !in_array($search_marker, array('', '0', '1'), true)) { attach_admin_search_invalid(); }
$search_submitted = $search_submit !== '' && $search_submit !== '0';
if ($search_submitted) { $view = 'attachments'; }
$search_based = $view === 'attachments' && ($search_submitted || $search_marker === '1');
$search_filters = ($search_based || $view === 'search') ? attach_admin_search_input($search_submitted) : array();
$search_hidden = $search_based ? attach_admin_search_hidden($search_filters) : '';
if ($search_based) { $uid = 0; }

// process modes based on view
if ($view == 'username')
{
	$mode_types_text = array($lang['Sort_Username'], $lang['Sort_Attachments'], $lang['Sort_Size']);
	$mode_types = array('username', 'attachments', 'filesize');

	if (!$mode)
	{
		$mode = 'attachments';
		$sort_order = 'DESC';
	}
}
else if ($view == 'attachments' || $view == 'search')
{
	$mode_types_text = array($lang['Sort_Filename'], $lang['Sort_Comment'], $lang['Sort_Extension'], $lang['Sort_Size'], $lang['Sort_Downloads'], $lang['Sort_Posttime'], /*$lang['Sort_Posts']*/);
	$mode_types = array('real_filename', 'comment', 'extension', 'filesize', 'downloads', 'post_time'/*, 'posts'*/);

	if (!$mode)
	{
		$mode = 'real_filename';
		$sort_order = 'ASC';
	}
}
else
{
	$view = 'stats';
	$mode_types_text = array();
	$sort_order = 'ASC';
}


// Pagination ?
$do_pagination = ($view != 'stats' && $view != 'search') ? true : false;

// Set Order
$order_by = '';

if ($view == 'username')
{
	$sort_columns = array('username' => 'u.username', 'attachments' => 'total_attachments', 'filesize' => 'total_size');
	if (!isset($sort_columns[$mode])) { $mode = 'attachments'; $sort_order = 'DESC'; }
	$order_by = 'ORDER BY ' . $sort_columns[$mode] . ' ' . $sort_order . ', u.user_id ' . $sort_order . ' LIMIT ' . $start . ', ' . $page_size;
}
else if ($view == 'attachments' || $view == 'search')
{
	$sort_columns = array('real_filename' => 'real_filename', 'comment' => 'comment', 'extension' => 'extension', 'filesize' => 'filesize', 'downloads' => 'download_count', 'post_time' => 'filetime');
	$sort_aliases = array('filename' => 'real_filename', 'a.real_filename' => 'real_filename', 'download_count' => 'downloads', 'filetime' => 'post_time');
	if (isset($sort_aliases[$mode])) { $mode = $sort_aliases[$mode]; }
	if (!isset($sort_columns[$mode])) { $mode = 'real_filename'; }
	$order_by = 'ORDER BY a.' . $sort_columns[$mode] . ' ' . $sort_order . ', a.attach_id ' . $sort_order . ' LIMIT ' . $start . ', ' . $page_size;
}

// Set select fields
$view_types_text = array($lang['View_Statistic'], $lang['View_Search'], $lang['View_Username'], $lang['View_Attachments']);
$view_types = array('stats', 'search', 'username', 'attachments');

$select_view = '<select name="view">';

for($i = 0; $i < count($view_types_text); $i++)
{
	$selected = ($view == $view_types[$i]) ? ' selected="selected"' : '';
	$select_view .= '<option value="' . $view_types[$i] . '"' . $selected . '>' . $view_types_text[$i] . '</option>';
}
$select_view .= '</select>';

if (count($mode_types_text) > 0)
{
	$select_sort_mode = '<select name="mode">';

	for($i = 0; $i < count($mode_types_text); $i++)
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

$submit_change = (isset($HTTP_POST_VARS['submit_change'])) ? TRUE : FALSE;
$delete = (isset($HTTP_POST_VARS['delete'])) ? TRUE : FALSE;
$delete_id_list = array();

$confirm = !empty($HTTP_POST_VARS['confirm']);
if ($submit_change || $delete || $confirm)
{
	phpbb_admin_require_post_session();
}
if ($delete || $confirm)
{
	try
	{
		if ((int) $submit_change + (int) $delete + (int) $confirm !== 1) { throw new PhpbbOwnedStorageFailure('Attachment_admin_delete_invalid'); }
		$delete_id_list = phpbb_attach_admin_delete_ids(isset($_POST['delete_id_list']) ? $_POST['delete_id_list'] : null);
	}
	catch (PhpbbOwnedStorageFailure $error) { message_die(GENERAL_MESSAGE, $lang['Attachment_admin_delete_invalid']); }
}

if ($confirm && sizeof($delete_id_list) > 0)
{
	try { $delete_result = phpbb_attach_admin_remove($db, $_POST, true); }
	catch (PhpbbOwnedStorageFailure $error)
	{
		$key = $error->getMessage();
		message_die(GENERAL_MESSAGE, isset($lang[$key]) ? $lang[$key] : $lang['Attachment_admin_delete_failed']);
	}
	$return_url = append_sid('admin_attach_cp.' . $phpEx . '?view=attachments&mode=' . rawurlencode($mode) . '&order=' . $sort_order . '&uid=' . (int) $uid . '&start=' . (int) $start . (!empty($search_based) ? attach_admin_search_query($search_filters, false) : ''), true);
	message_die(GENERAL_MESSAGE, $lang[$delete_result['cleanup_complete'] ? 'Attachment_admin_delete_saved' : 'Attachment_admin_delete_cleanup'] . '<br /><br />' . sprintf($lang['Attachment_metadata_return'], '<a href="' . phpbb_profile_text($return_url) . '">', '</a>'));
}
else if ($delete && sizeof($delete_id_list) > 0)
{
	try { phpbb_attach_admin_remove($db, $_POST, false); }
	catch (PhpbbOwnedStorageFailure $error)
	{
		$key = $error->getMessage();
		message_die(GENERAL_MESSAGE, isset($lang[$key]) ? $lang[$key] : $lang['Attachment_admin_delete_failed']);
	}
	// Not confirmed, show confirmation message
	$hidden_fields = '<input type="hidden" name="view" value="' . $view . '" />';
	$hidden_fields .= '<input type="hidden" name="mode" value="' . $mode . '" />';
	$hidden_fields .= '<input type="hidden" name="order" value="' . $sort_order . '" />';
	$hidden_fields .= '<input type="hidden" name="u_id" value="' . $uid . '" />';
	$hidden_fields .= '<input type="hidden" name="start" value="' . $start . '" />';
	$hidden_fields .= phpbb_admin_session_field();
	$hidden_fields .= $search_hidden;

	for ($i = 0; $i < sizeof($delete_id_list); $i++)
	{
		$hidden_fields .= '<input type="hidden" name="delete_id_list[]" value="' . $delete_id_list[$i] . '" />';
	}

	$template->set_filenames(array(
		'confirm' => 'confirm_body.tpl')
	);

	$template->assign_vars(array(
		'MESSAGE_TITLE'		=> $lang['Confirm'],
		'MESSAGE_TEXT'		=> $lang['Confirm_delete_attachments'],

		'L_YES'				=> $lang['Yes'],
		'L_NO'				=> $lang['No'],

		'S_CONFIRM_ACTION'	=> append_sid('admin_attach_cp.' . $phpEx),
		'S_HIDDEN_FIELDS'	=> $hidden_fields)
	);

	$template->pparse('confirm');
	
	include('page_footer_admin.'.$phpEx);

	exit;
}

// Assign Default Template Vars
$template->assign_vars(array(
	'L_VIEW'					=> $lang['View'],
	'L_SUBMIT'					=> $lang['Submit'],
	'L_CONTROL_PANEL_TITLE'		=> $lang['Control_panel_title'],
	'L_CONTROL_PANEL_EXPLAIN'	=> $lang['Control_panel_explain'],

	'S_VIEW_SELECT'	=> $select_view,
	'S_MODE_ACTION'	=> append_sid('admin_attach_cp.' . $phpEx),
	'S_HIDDEN_FIELDS' => phpbb_admin_session_field() . $search_hidden)
);

if ($submit_change && $view == 'attachments')
{
	try { phpbb_attach_admin_metadata_save($db, $_POST); }
	catch (PhpbbOwnedStorageFailure $error)
	{
		$key = $error->getMessage();
		message_die(GENERAL_MESSAGE, isset($lang[$key]) ? $lang[$key] : $lang['Attachment_metadata_failed']);
	}
	$return_url = append_sid('admin_attach_cp.' . $phpEx . '?view=attachments&mode=' . rawurlencode($mode) . '&order=' . $sort_order . '&uid=' . (int) $uid . '&start=' . (int) $start . (!empty($search_based) ? attach_admin_search_query($search_filters, false) : ''), true);
	message_die(GENERAL_MESSAGE, $lang['Attachment_metadata_saved'] . '<br /><br />' . sprintf($lang['Attachment_metadata_return'], '<a href="' . phpbb_profile_text($return_url) . '">', '</a>'));
}

// Statistics
if ($view == 'stats')
{
	$template->set_filenames(array(
		'body' => 'admin/attach_cp_body.tpl')
	);

	$upload_dir_size = get_formatted_dirsize();

	if ($attach_config['attachment_quota'] >= 1048576)
	{
		$attachment_quota = round($attach_config['attachment_quota'] / 1048576 * 100) / 100 . ' ' . $lang['MB'];
	}
	else if ($attach_config['attachment_quota'] >= 1024)
	{
		$attachment_quota = round($attach_config['attachment_quota'] / 1024 * 100) / 100 . ' ' . $lang['KB'];
	}
	else
	{
		$attachment_quota = $attach_config['attachment_quota'] . ' ' . $lang['Bytes'];
	}

	$sql = "SELECT count(*) AS total
		FROM " . ATTACHMENTS_DESC_TABLE;

	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Error getting total attachments', '', __LINE__, __FILE__, $sql);
	}

	$total = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	$number_of_attachments = $total['total'];

	$sql = "SELECT post_id
		FROM " . ATTACHMENTS_TABLE . "
		WHERE post_id <> 0
		GROUP BY post_id";

	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Error getting total posts', '', __LINE__, __FILE__, $sql);
	}

	$number_of_posts = $db->sql_numrows($result);
	$db->sql_freeresult($result);

	$sql = "SELECT privmsgs_id
		FROM " . ATTACHMENTS_TABLE . "
		WHERE privmsgs_id <> 0
		GROUP BY privmsgs_id";

	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Error getting total private messages', '', __LINE__, __FILE__, $sql);
	}

	$number_of_pms = $db->sql_numrows($result);
	$db->sql_freeresult($result);

	$sql = "SELECT p.topic_id
		FROM " . ATTACHMENTS_TABLE . " a, " . POSTS_TABLE . " p
		WHERE a.post_id = p.post_id
		GROUP BY p.topic_id";

	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Error getting total topics', '', __LINE__, __FILE__, $sql);
	}

	$number_of_topics = $db->sql_numrows($result);
	$db->sql_freeresult($result);

	$sql = "SELECT user_id_1
		FROM " . ATTACHMENTS_TABLE . "
		WHERE (post_id <> 0)
		GROUP BY user_id_1";

	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Error getting total users', '', __LINE__, __FILE__, $sql);
	}

	$number_of_users = $db->sql_numrows($result);
	$db->sql_freeresult($result);

	$template->assign_vars(array(
		'L_STATISTIC'				=> $lang['Statistic'],
		'L_VALUE'					=> $lang['Value'],
		'L_NUMBER_OF_ATTACHMENTS'	=> $lang['Number_of_attachments'],
		'L_TOTAL_FILESIZE'			=> $lang['Total_filesize'],
		'L_ATTACH_QUOTA'			=> $lang['Attach_quota'],
		'L_NUMBER_OF_POSTS'			=> $lang['Number_posts_attach'],
		'L_NUMBER_OF_PMS'			=> $lang['Number_pms_attach'],
		'L_NUMBER_OF_TOPICS'		=> $lang['Number_topics_attach'],
		'L_NUMBER_OF_USERS'			=> $lang['Number_users_attach'],
		
		'TOTAL_FILESIZE'			=> $upload_dir_size,
		'ATTACH_QUOTA'				=> $attachment_quota,
		'NUMBER_OF_ATTACHMENTS'		=> $number_of_attachments,
		'NUMBER_OF_POSTS'			=> $number_of_posts,
		'NUMBER_OF_PMS'				=> $number_of_pms,
		'NUMBER_OF_TOPICS'			=> $number_of_topics,
		'NUMBER_OF_USERS'			=> $number_of_users)
	);

}

// Search
if ($view == 'search')
{
	// Forum and category IDs have separate namespaces. A child forum's cat_id
	// can name a parent forum, so a categories join silently omits valid forums.
	$sql = 'SELECT forum_id, forum_name FROM ' . FORUMS_TABLE . ' ORDER BY forum_order, forum_id';
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not obtain attachment search forums', '', __LINE__, __FILE__, $sql);
	}
	$s_forums = '<option value="0"' . ($search_filters['search_forum'] === '' || $search_filters['search_forum'] === '0' ? ' selected="selected"' : '') . '>' . phpbb_profile_text($lang['All_available']) . '</option>';
	while ($row = $db->sql_fetchrow($result))
	{
		$s_forums .= '<option value="' . (int) $row['forum_id'] . '"' . ((string) $row['forum_id'] === $search_filters['search_forum'] ? ' selected="selected"' : '') . '>' . phpbb_stored_text($row['forum_name']) . '</option>';
	}
	$db->sql_freeresult($result);
	foreach (array('search_keyword_fname','search_keyword_comment','search_author','search_size_smaller','search_size_greater','search_count_smaller','search_count_greater','search_days_greater') as $field)
	{
		$template->assign_vars(array(strtoupper($field) . '_VALUE' => phpbb_profile_text($search_filters[$field])));
	}

	$template->set_filenames(array(
		'body' => 'admin/attach_cp_search.tpl')
	);

	$template->assign_vars(array(
		'L_ATTACH_SEARCH_QUERY'		=> $lang['Attach_search_query'],
		'L_FILENAME'				=> $lang['File_name'],
		'L_COMMENT'					=> $lang['File_comment'],
		'L_SEARCH_OPTIONS'			=> $lang['Search_options'],
		'L_SEARCH_AUTHOR'			=> $lang['Search_author'],
		'L_WILDCARD_EXPLAIN'		=> $lang['Search_wildcard_explain'],
		'L_SIZE_SMALLER_THAN'		=> $lang['Size_smaller_than'],		
		'L_SIZE_GREATER_THAN'		=> $lang['Size_greater_than'],
		'L_COUNT_SMALLER_THAN'		=> $lang['Count_smaller_than'],		
		'L_COUNT_GREATER_THAN'		=> $lang['Count_greater_than'],
		'L_MORE_DAYS_OLD'			=> $lang['More_days_old'],
		'L_ORDER'					=> $lang['Order'],
		'L_SORT_BY'					=> $lang['Select_sort_method'],
		'L_FORUM'					=> $lang['Forum'],
		'L_SEARCH'					=> $lang['Search'],

		'S_FORUM_OPTIONS'			=> $s_forums, 
		'S_SORT_OPTIONS'			=> $select_sort_mode,
		'S_SORT_ORDER'				=> $select_sort_order)
	);
}

// Username
if ($view == 'username')
{
	$template->set_filenames(array(
		'body' => 'admin/attach_cp_user.tpl')
	);

	$template->assign_vars(array(
		'L_SELECT_SORT_METHOD'	=> $lang['Select_sort_method'],
		'L_ORDER'				=> $lang['Order'],
		'L_USERNAME'			=> $lang['Username'],
		'L_TOTAL_SIZE'			=> $lang['Size_in_kb'],
		'L_ATTACHMENTS'			=> $lang['Attachments'],

		'S_MODE_SELECT'			=> $select_sort_mode,
		'S_ORDER_SELECT'		=> $select_sort_order)
	);


	// Count the same registered uploader/file pairs used by the page query.
	// Multiple post/PM references to one file must not inflate its count or size.
	$owner_files = '(SELECT DISTINCT user_id_1, attach_id FROM ' . ATTACHMENTS_TABLE . ') a';
	$owner_from = $owner_files . ' JOIN ' . USERS_TABLE . ' u ON u.user_id = a.user_id_1 JOIN ' . ATTACHMENTS_DESC_TABLE . ' d ON d.attach_id = a.attach_id';
	$sql = 'SELECT COUNT(DISTINCT u.user_id) AS total FROM ' . $owner_from;
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Error getting total attachment users', '', __LINE__, __FILE__, $sql);
	}
	$count = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	$total_rows = (int) $count['total'];
	if (!$total_rows) { $start = 0; }
	else if ($start >= $total_rows) { $start = (int) (floor(($total_rows - 1) / $page_size) * $page_size); }
	// Rebuild LIMIT after clamping a stale page offset.
	$order_by = 'ORDER BY ' . $sort_columns[$mode] . ' ' . $sort_order . ', u.user_id ' . $sort_order . ' LIMIT ' . $start . ', ' . $page_size;
	$sql = 'SELECT u.user_id, u.username, COUNT(*) AS total_attachments, SUM(d.filesize) AS total_size FROM ' . $owner_from . ' GROUP BY u.user_id, u.username ' . $order_by;
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Couldn\'t query attachment users', '', __LINE__, __FILE__, $sql);
	}
	$members = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	for ($i = 0; $i < count($members); $i++)
	{
		$row_color = !($i % 2) ? $theme['td_color1'] : $theme['td_color2'];
		$row_class = !($i % 2) ? $theme['td_class1'] : $theme['td_class2'];
		$row_color = preg_match('/^[A-Fa-f0-9]{6}$/D', (string) $row_color) ? $row_color : 'FFFFFF';
		$row_class = preg_match('/^[A-Za-z0-9_-]+$/D', (string) $row_class) ? $row_class : 'row1';
		$template->assign_block_vars('memberrow', array(
			'ROW_NUMBER' => $i + $start + 1,
			'ROW_COLOR' => '#' . $row_color,
			'ROW_CLASS' => $row_class,
			'USERNAME' => phpbb_stored_text($members[$i]['username']),
			'TOTAL_ATTACHMENTS' => $members[$i]['total_attachments'],
			'TOTAL_SIZE' => round($members[$i]['total_size'] / MEGABYTE, 2),
			'U_VIEW_MEMBER' => append_sid('admin_attach_cp.' . $phpEx . '?view=attachments&amp;uid=' . (int) $members[$i]['user_id']))
		);
	}
}

// Attachments
if ($view == 'attachments')
{
	$user_based = ($uid) ? TRUE : FALSE;
	
	$hidden_fields = '';
	
	$template->set_filenames(array(
		'body' => 'admin/attach_cp_attachments.tpl')
	);

	$template->assign_vars(array(
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
		'L_SUBMIT_CHANGES'		=> $lang['Submit_changes'],
		'L_MARK_ALL'			=> $lang['Mark_all'],
		'L_UNMARK_ALL'			=> $lang['Unmark_all'],

		'S_MODE_SELECT'			=> $select_sort_mode,
		'S_ORDER_SELECT'		=> $select_sort_order)
	);

	$total_rows = 0;
	$attachment_from = ' FROM ' . ATTACHMENTS_DESC_TABLE . ' a';
	
	// Are we called from Username ?
	if ($user_based)
	{
		$sql = "SELECT username 
			FROM " . USERS_TABLE . " 
			WHERE user_id = " . intval($uid);

		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, 'Error getting username', '', __LINE__, __FILE__, $sql);
		}

		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		if (!$row) { message_die(GENERAL_MESSAGE, $lang['No_such_user']); }
		$username = phpbb_stored_text($row['username']);

		$s_hidden = '<input type="hidden" name="u_id" value="' . intval($uid) . '" />';
	
		$template->assign_block_vars('switch_user_based', array());

		$template->assign_vars(array(
			'S_USER_HIDDEN'			=> $s_hidden,
			'L_STATISTICS_FOR_USER'	=> sprintf($lang['Statistics_for_user'], $username))
		);

		// Count and page descriptions in the same scope. Shared links must not
		// duplicate a file; links with no description must not invent a page.
		$attachment_from .= ' WHERE EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' t WHERE t.attach_id=a.attach_id AND t.user_id_1=' . intval($uid) . ')';
	}
	else if ($search_based)
	{
		// we are called from search
		$attachments = search_attachments($order_by, $total_rows, $search_filters, $start);
	}
	if (!$search_based)
	{
		$sql = 'SELECT COUNT(*) AS total' . $attachment_from;
		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, 'Could not count attachment descriptions', '', __LINE__, __FILE__, $sql);
		}
		$count = $db->sql_fetchrow($result);
		$total_rows = (int) $count['total'];
		$db->sql_freeresult($result);
		if (!$total_rows) { $start = 0; }
		else if ($start >= $total_rows) { $start = (int) (floor(($total_rows - 1) / $page_size) * $page_size); }
		$order_by = 'ORDER BY a.' . $sort_columns[$mode] . ' ' . $sort_order . ', a.attach_id ' . $sort_order . ' LIMIT ' . $start . ', ' . $page_size;
		$sql = 'SELECT a.*' . $attachment_from . ' ' . $order_by;
		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, 'Couldn\'t query attachments', '', __LINE__, __FILE__, $sql);
		}

		$attachments = $db->sql_fetchrowset($result);
		$num_attach = $db->sql_numrows($result);
		$db->sql_freeresult($result);
	}
	
	if (sizeof($attachments) > 0)
	{
		for ($i = 0; $i < sizeof($attachments); $i++)
		{
			$delete_box = '<input type="checkbox" name="delete_id_list[]" value="' . intval($attachments[$i]['attach_id']) . '" />';

			for ($j = 0; $j < count($delete_id_list); $j++)
			{
				if ($delete_id_list[$j] == $attachments[$i]['attach_id'])
				{
					$delete_box = '<input type="checkbox" name="delete_id_list[]" value="' . intval($attachments[$i]['attach_id']) . '" checked="checked" />';
					break;
				}
			}

			$row_color = ( !($i % 2) ) ? $theme['td_color1'] : $theme['td_color2'];
			$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];
			$row_color = preg_match('/^[A-Fa-f0-9]{6}$/D', (string) $row_color) ? $row_color : 'FFFFFF';
			$row_class = preg_match('/^[A-Za-z0-9_-]+$/D', (string) $row_class) ? $row_class : 'row1';

			// Is the Attachment assigned to more than one post ?
			// If it's not assigned to any post, it's an private message thingy. ;)
			$post_titles = array();

			$sql = "SELECT *
				FROM " . ATTACHMENTS_TABLE . "
				WHERE attach_id = " . intval($attachments[$i]['attach_id']);

			if (!($result = $db->sql_query($sql)))
			{
				message_die(GENERAL_ERROR, 'Couldn\'t query attachments', '', __LINE__, __FILE__, $sql);
			}

			$ids = $db->sql_fetchrowset($result);
			$num_ids = $db->sql_numrows($result);
			$db->sql_freeresult($result);

			for ($j = 0; $j < $num_ids; $j++)
			{
				if ($ids[$j]['post_id'] != 0)
				{
					$sql = "SELECT t.topic_title
						FROM " . TOPICS_TABLE . " t, " . POSTS_TABLE . " p
						WHERE p.post_id = " . intval($ids[$j]['post_id']) . " AND p.topic_id = t.topic_id
						GROUP BY t.topic_id, t.topic_title";

					if (!($result = $db->sql_query($sql)))
					{
						message_die(GENERAL_ERROR, 'Couldn\'t query topic', '', __LINE__, __FILE__, $sql);
					}

					$row = $db->sql_fetchrow($result);
					$db->sql_freeresult($result);
			
					if (!$row) { continue; }
					$post_title = html_entity_decode((string) $row['topic_title'], ENT_QUOTES, 'UTF-8');

					if (preg_match('//u', $post_title) === 1 && preg_match('/^.{0,32}$/usD', $post_title) !== 1)
					{
						$post_title = phpbb_text_excerpt($post_title, 30);
					}

					$view_topic = append_sid($phpbb_root_path . 'viewtopic.' . $phpEx . '?' . POST_POST_URL . '=' . $ids[$j]['post_id'], true) . '#' . $ids[$j]['post_id'];

					$post_titles[] = '<a href="' . phpbb_profile_text($view_topic) . '" class="gen" target="_blank" rel="noopener noreferrer">' . phpbb_profile_text($post_title) . '</a>';
				}
				else
				{
					$post_titles[] = $lang['Private_Message'];
				}
			}

			$post_titles = implode('<br />', $post_titles);

			$hidden_field = '<input type="hidden" name="attach_id_list[]" value="' . intval($attachments[$i]['attach_id']) . '" />';
			$hidden_field .= '<input type="hidden" name="attach_original_comment_list[]" value="' . phpbb_attach_admin_comment_digest($attachments[$i]['comment']) . '" />';
			$hidden_field .= '<input type="hidden" name="attach_original_count_list[]" value="' . phpbb_profile_text($attachments[$i]['download_count']) . '" />';

			$template->assign_block_vars('attachrow', array(
				'ROW_NUMBER'	=> $i + $start + 1,
				'ROW_COLOR'		=> '#' . $row_color,
				'ROW_CLASS'		=> $row_class,

				'FILENAME'		=> phpbb_stored_text($attachments[$i]['real_filename']),
				'COMMENT'		=> phpbb_stored_text($attachments[$i]['comment']),
				'EXTENSION'		=> phpbb_stored_text($attachments[$i]['extension']),
				'SIZE'			=> round(($attachments[$i]['filesize'] / MEGABYTE), 2),
				'DOWNLOAD_COUNT'=> $attachments[$i]['download_count'],
				'POST_TIME'		=> create_date($board_config['default_dateformat'], $attachments[$i]['filetime'], $board_config['board_timezone']),
				'POST_TITLE'	=> $post_titles,

				'S_DELETE_BOX'	=> $delete_box,
				'S_HIDDEN'		=> $hidden_field,
				'U_VIEW_ATTACHMENT'	=> append_sid($phpbb_root_path . 'download.' . $phpEx . '?id=' . $attachments[$i]['attach_id']))
//				'U_VIEW_POST' => ($attachments[$i]['post_id'] != 0) ? append_sid("../viewtopic." . $phpEx . "?" . POST_POST_URL . "=" . $attachments[$i]['post_id'] . "#" . $attachments[$i]['post_id']) : '')
			);
			
		}
	}

}

// Generate Pagination
if ($do_pagination && $total_rows > $page_size)
{
	$pagination = generate_pagination('admin_attach_cp.' . $phpEx . '?view=' . $view . '&amp;mode=' . $mode . '&amp;order=' . $sort_order . '&amp;uid=' . $uid . (!empty($search_based) ? attach_admin_search_query($search_filters) : ''), $total_rows, $page_size, $start).'&nbsp;';

	$template->assign_vars(array(
		'PAGINATION'	=> $pagination,
		'PAGE_NUMBER'	=> sprintf($lang['Page_of'], ( floor( $start / $page_size ) + 1 ), ceil( $total_rows / $page_size )),

		'L_GOTO_PAGE'	=> $lang['Goto_page'])
	);
}

$template->assign_vars(array(
	'ATTACH_VERSION' => sprintf($lang['Attachment_version'], $attach_config['attach_version']))
);

$template->pparse('body');

include('page_footer_admin.'.$phpEx);

?>
