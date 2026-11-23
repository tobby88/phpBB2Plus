<?php
/***************************************************************************
 *                               viewforum.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: viewforum.php,v 1.139.2.12 2004/03/13 15:08:23 acydburn Exp $
 *
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

if (!defined('IN_PHPBB'))
{
    define( 'IN_PHPBB', true);
}
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);
include_once($phpbb_root_path . 'includes/bbcode.'.$phpEx);
include_once($phpbb_root_path.'includes/functions_color_groups.'.$phpEx);
//-- mod : announces -------------------------------------------------------------------------------
//-- add
include_once($phpbb_root_path . 'includes/functions_announces.'. $phpEx);
//-- fin mod : announces ---------------------------------------------------------------------------
//-- mod : split topic type ------------------------------------------------------------------------
//-- add
include_once($phpbb_root_path . 'includes/functions_topics_list.'. $phpEx);
//-- fin mod : split topic type --------------------------------------------------------------------

//
// Start initial var setup
//
if (phpbb_request_scalar($_GET, POST_FORUM_URL) !== '' || phpbb_request_scalar($_POST, POST_FORUM_URL) !== '')
{
	$forum_id = intval(phpbb_request_scalar($_GET, POST_FORUM_URL, phpbb_request_scalar($_POST, POST_FORUM_URL)));
}
else if (phpbb_request_scalar($_GET, 'forum') !== '')
{
	$forum_id = intval(phpbb_request_scalar($_GET, 'forum'));
}
else
{
	$forum_id = '';
}
//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
define('IN_VIEWFORUM', true);
if (isset($_GET['selected_id']) || isset($_POST['selected_id']))
{
	$selected_id = phpbb_request_scalar($_POST, 'selected_id', phpbb_request_scalar($_GET, 'selected_id'));
	$type	= substr($selected_id, 0, 1);
	$id		= intval(substr($selected_id, 1));
	if ($type == POST_FORUM_URL)
	{
		$forum_id = $id;
	}
	else if (($type == POST_CAT_URL) || ($selected_id == 'Root'))
	{
		$parm = ($id != 0) ? "?" . POST_CAT_URL . "=$id" : '';
		redirect(append_sid("./index.$phpEx" . $parm));
		exit;
	}
}
//-- fin mod : categories hierarchy ----------------------------------------------------------------

$start = max(0, min(1000000, intval(phpbb_request_scalar($_GET, 'start', 0))));

if ( isset($_GET['mark']) || isset($_POST['mark']) )
{
	$mark_read = phpbb_request_scalar($_POST, 'mark', phpbb_request_scalar($_GET, 'mark'));
}
else
{
	$mark_read = '';
}
//
// End initial var setup
//

//
// Check if the user has actually sent a forum ID with his/her request
// If not give them a nice error page.
//
//-- mod : categories hierarchy --------------------------------------------------------------------
//-- deleted
// if ( !empty($forum_id) )
// {
//	$sql = "SELECT *
//		FROM " . FORUMS_TABLE . "
//		WHERE forum_id = $forum_id";
//	if ( !($result = $db->sql_query($sql)) )
//	{
//		message_die(GENERAL_ERROR, 'Could not obtain forums information', '', __LINE__, __FILE__, $sql);
//	}
// }
// else
// {
//	message_die(GENERAL_MESSAGE, 'Forum_not_exist');
// }
//
//
// If the query doesn't return any rows this isn't a valid forum. Inform
// the user.
//
// if ( !($forum_row = $db->sql_fetchrow($result)) )
// {
//	message_die(GENERAL_MESSAGE, 'Forum_not_exist');
// }
//-- fin mod : categories hierarchy ----------------------------------------------------------------


//
// Start session management
//
$userdata = session_pagestart($user_ip, $forum_id);
init_userprefs($userdata);
//
// End session management
//
//-- mod : announces -------------------------------------------------------------------------------
//-- add
include_once($phpbb_root_path . 'includes/mods_settings/mod_announces.' . $phpEx);
//-- fin mod : announces ---------------------------------------------------------------------------

//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
// get the forum row
$forum_key = POST_FORUM_URL . $forum_id;
$forum_index = isset($tree['keys'][$forum_key]) ? $tree['keys'][$forum_key] : -1;
$forum_row = ($forum_index >= 0 && isset($tree['data'][$forum_index]) && is_array($tree['data'][$forum_index])) ? $tree['data'][$forum_index] : array();
if ( empty($forum_row) )
{
	message_die(GENERAL_MESSAGE, 'Forum_not_exist');
}

// handle forum link type
$selected_id = POST_FORUM_URL . $forum_id;
$CH_this = isset($tree['keys'][$selected_id]) ? $tree['keys'][$selected_id] : -1;
if ( ($CH_this > -1) && !empty($tree['data'][$CH_this]['forum_link']))
{
	// add 1 to hit if count ativated
	if ($tree['data'][$CH_this]['forum_link_hit_count'])
	{
		$sql = "UPDATE " . FORUMS_TABLE . "
					SET forum_link_hit = forum_link_hit + 1
					WHERE forum_id=$forum_id";
		if (!$db->sql_query($sql)) message_die(GENERAL_ERROR, 'Could not increment forum hits information', '', __LINE__, __FILE__, $sql);
		cache_tree(true);
	}

	// prepare url
	$url = trim((string) $tree['data'][$CH_this]['forum_link']);
	if ($tree['data'][$CH_this]['forum_link_internal'])
	{
		$part = explode( '?', $url);
		$url .= ((count($part) > 1) ? '&' : '?') . 'sid=' . $userdata['session_id'];
		$url = append_sid($url);

		// redirect to url
		redirect($url);
	}

	// External forum links are administrator-provided, but still validate them
	// before they reach an HTTP header or the fallback HTML document.
	$url = phpbb_normalize_external_url($url);
	if ($url === false)
	{
		message_die(GENERAL_ERROR, 'Invalid external forum URL.');
	}

	// Redirect via an HTML form for PITA webservers
	if (@preg_match('/Microsoft|WebSTAR|Xitami/', getenv('SERVER_SOFTWARE')))
	{
		header('Refresh: 0; URL=' . $url);
		$safe_url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
		echo '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta http-equiv="refresh" content="0; url=' . $safe_url . '"><title>' . htmlspecialchars($lang['Redirect'], ENT_QUOTES, 'UTF-8') . '</title></head><body><div align="center">' . sprintf($lang['Redirect_to'], '<a href="' . $safe_url . '">', '</a>') . '</div></body></html>';
		exit;
	}

	// Behave as per HTTP/1.1 spec for others
	header('Location: ' . $url);
	exit;
}
//-- fin mod : categories hierarchy ----------------------------------------------------------------

//
// Start auth check
//
$is_auth = array();
//-- mod : categories hierarchy --------------------------------------------------------------------
//-- delete
// $is_auth = auth(AUTH_ALL, $forum_id, $userdata, $forum_row);
//-- add
$is_auth = $tree['auth'][POST_FORUM_URL . $forum_id];
//-- fin mod : categories hierarchy ----------------------------------------------------------------


if ( !$is_auth['auth_read'] || !$is_auth['auth_view'] )
{
	if ( !$userdata['session_logged_in'] )
	{
		$redirect = POST_FORUM_URL . "=$forum_id" . ( ( isset($start) ) ? "&start=$start" : '' );
		redirect(append_sid("login.$phpEx?redirect=viewforum.$phpEx&$redirect", true));
	}
	//
	// The user is not authed to read this forum ...
	//
	$message = ( !$is_auth['auth_view'] ) ? $lang['Forum_not_exist'] : sprintf($lang['Sorry_auth_read'], $is_auth['auth_read_type']);

	message_die(GENERAL_MESSAGE, $message);
}
//
// End of auth check
//

//
// Handle marking posts
//
if ( $mark_read == 'topics' )
{
	if ( $userdata['session_logged_in'] )
	{
		$sql = "SELECT MAX(post_time) AS last_post
			FROM " . POSTS_TABLE . "
			WHERE forum_id = $forum_id";
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not obtain forums information', '', __LINE__, __FILE__, $sql);
		}

		if ( $row = $db->sql_fetchrow($result) )
		{
			$tracking_forums = ( isset($HTTP_COOKIE_VARS[$board_config['cookie_name'] . '_f']) ) ? phpbb_tracking_cookie_array($HTTP_COOKIE_VARS[$board_config['cookie_name'] . '_f']) : array();
			$tracking_topics = ( isset($HTTP_COOKIE_VARS[$board_config['cookie_name'] . '_t']) ) ? phpbb_tracking_cookie_array($HTTP_COOKIE_VARS[$board_config['cookie_name'] . '_t']) : array();

			if ( ( count($tracking_forums) + count($tracking_topics) ) >= 150 && empty($tracking_forums[$forum_id]) )
			{
				asort($tracking_forums);
				unset($tracking_forums[key($tracking_forums)]);
			}

			if ( $row['last_post'] > $userdata['user_lastvisit'] )
			{
				$tracking_forums[$forum_id] = time();

				phpbb_setcookie($board_config['cookie_name'] . '_f', serialize($tracking_forums), 0, $board_config['cookie_path'], $board_config['cookie_domain'], $board_config['cookie_secure']);
			}
		}

		$template->assign_vars(array(
			'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid("viewforum.$phpEx?" . POST_FORUM_URL . "=$forum_id") . '">')
		);
	}

	$message = $lang['Topics_marked_read'] . '<br /><br />' . sprintf($lang['Click_return_forum'], '<a href="' . append_sid("viewforum.$phpEx?" . POST_FORUM_URL . "=$forum_id") . '">', '</a> ');
	message_die(GENERAL_MESSAGE, $message);
}
//
// End handle marking posts
//

$tracking_topics = ( isset($HTTP_COOKIE_VARS[$board_config['cookie_name'] . '_t']) ) ? phpbb_tracking_cookie_array($HTTP_COOKIE_VARS[$board_config['cookie_name'] . '_t']) : array();
$tracking_forums = ( isset($HTTP_COOKIE_VARS[$board_config['cookie_name'] . '_f']) ) ? phpbb_tracking_cookie_array($HTTP_COOKIE_VARS[$board_config['cookie_name'] . '_f']) : array();

//
// Do the forum Prune
//
if ( $is_auth['auth_mod'] && $board_config['prune_enable'] )
{
	if ( $forum_row['prune_next'] < time() && $forum_row['prune_enable'] )
	{
		require_once($phpbb_root_path . 'includes/prune.'.$phpEx);
		try
		{
			auto_prune($forum_id);
		}
		catch (PhpbbPruneException $error)
		{
			// Optional maintenance must not hide a readable forum. Keep partial
			// storage failures visible to operators without logging user content.
			error_log('phpBB automatic forum pruning did not complete.');
		}
	}
}
//
// End of forum prune
//

//
// Obtain list of moderators of each forum
// First users, then groups ... broken into two queries
//
//-- mod : categories hierarchy --------------------------------------------------------------------
//-- delete
// $sql = "SELECT u.user_id, u.username
//	FROM " . AUTH_ACCESS_TABLE . " aa, " . USER_GROUP_TABLE . " ug, " . GROUPS_TABLE . " g, " . USERS_TABLE . " u
//	WHERE aa.forum_id = $forum_id
//		AND aa.auth_mod = " . TRUE . "
//		AND g.group_single_user = 1
//		AND ug.group_id = aa.group_id
//		AND g.group_id = aa.group_id
//		AND u.user_id = ug.user_id
//	GROUP BY u.user_id, u.username
//	ORDER BY u.user_id";
// if ( !($result = $db->sql_query($sql)) )
// {
//	message_die(GENERAL_ERROR, 'Could not query forum moderator information', '', __LINE__, __FILE__, $sql);
// }
//
// $moderators = array();
// while( $row = $db->sql_fetchrow($result) )
// {
//	$moderators[] = '<a href="' . append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=" . $row['user_id']) . '">' . $row['username'] . '</a>';
// }
//
// $sql = "SELECT g.group_id, g.group_name
//	FROM " . AUTH_ACCESS_TABLE . " aa, " . USER_GROUP_TABLE . " ug, " . GROUPS_TABLE . " g
//	WHERE aa.forum_id = $forum_id
//		AND aa.auth_mod = " . TRUE . "
//		AND g.group_single_user = 0
//		AND g.group_type <> ". GROUP_HIDDEN ."
//		AND ug.group_id = aa.group_id
//		AND g.group_id = aa.group_id
//	GROUP BY g.group_id, g.group_name
//	ORDER BY g.group_id";
// if ( !($result = $db->sql_query($sql)) )
// {
//	message_die(GENERAL_ERROR, 'Could not query forum moderator information', '', __LINE__, __FILE__, $sql);
// }
//
// while( $row = $db->sql_fetchrow($result) )
// {
//	$moderators[] = '<a href="' . append_sid("groupcp.$phpEx?" . POST_GROUPS_URL . "=" . $row['group_id']) . '">' . $row['group_name'] . '</a>';
// }
//-- add
// moderators list
$moderators = array();
$idx = isset($tree['keys'][POST_FORUM_URL . $forum_id]) ? $tree['keys'][POST_FORUM_URL . $forum_id] : null;
$forum_mods = ($idx !== null && isset($tree['mods'][$idx]) && is_array($tree['mods'][$idx])) ? $tree['mods'][$idx] : array();
$moderator_user_ids = (isset($forum_mods['user_id']) && is_array($forum_mods['user_id'])) ? $forum_mods['user_id'] : array();
$moderator_group_ids = (isset($forum_mods['group_id']) && is_array($forum_mods['group_id'])) ? $forum_mods['group_id'] : array();
$moderator_group_names = (isset($forum_mods['group_name']) && is_array($forum_mods['group_name'])) ? $forum_mods['group_name'] : array();
for ( $i = 0; $i < count($moderator_user_ids); $i++ )
{
	$moderators[] = color_group_colorize_name(intval($moderator_user_ids[$i]));
}
for ( $i = 0; $i < count($moderator_group_ids); $i++ )
{
	$group_id = intval($moderator_group_ids[$i]);
	$group_name = isset($moderator_group_names[$i]) ? htmlspecialchars($moderator_group_names[$i], ENT_QUOTES, 'UTF-8') : '';
	$moderators[] = '<a href="' . append_sid("./groupcp.$phpEx?" . POST_GROUPS_URL . "=" . $group_id) . '">' . $group_name . '</a>';
}
//-- fin mod : categories hierarchy ----------------------------------------------------------------


$l_moderators = ( count($moderators) == 1 ) ? $lang['Moderator'] : $lang['Moderators'];
$forum_moderators = ( count($moderators) ) ? implode(', ', $moderators) : $lang['None'];
unset($moderators);

//
// Generate a 'Show topics in previous x days' select box. If the topicsdays var is sent
// then get it's value, find the number of topics with dates newer than it (to properly
// handle pagination) and alter the main query
//
$previous_days = array(0, 1, 7, 14, 30, 90, 180, 364);
$previous_days_text = array($lang['All_Topics'], $lang['1_Day'], $lang['7_Days'], $lang['2_Weeks'], $lang['1_Month'], $lang['3_Months'], $lang['6_Months'], $lang['1_Year']);

$topic_days_value = phpbb_request_scalar($_POST, 'topicdays', phpbb_request_scalar($_GET, 'topicdays'));
$topic_days = intval($topic_days_value);
if (!in_array($topic_days, $previous_days, true))
{
	$topic_days = 0;
}
if (phpbb_request_scalar($_POST, 'topicdays') !== '')
{
	$start = 0;
}

$select_topic_days = '<select name="topicdays">';
for($i = 0; $i < count($previous_days); $i++)
{
	$selected = ($topic_days == $previous_days[$i]) ? ' selected="selected"' : '';
	$select_topic_days .= '<option value="' . $previous_days[$i] . '"' . $selected . '>' . $previous_days_text[$i] . '</option>';
}
$select_topic_days .= '</select>';


$other_announce_forums = isset($board_config['announcement_display_forum']) && !intval($board_config['announcement_display_forum'])
	? phpbb_announcement_forums($tree, POST_FORUM_URL . $forum_id) : array();
$since = $topic_days > 0 ? time() - $topic_days * 86400 : 0;
$topic_page = phpbb_forum_topics_page($db, $forum_id, $other_announce_forums, $since, $start, $board_config['topics_per_page']);
$topic_rowset = $topic_page['rows'];
$start = $topic_page['start'];

//
// Post URL generation for templating vars
//
$template->assign_vars(array(
	'L_DISPLAY_TOPICS' => $lang['Display_topics'],

	'U_POST_NEW_TOPIC' => append_sid("posting.$phpEx?mode=newtopic&amp;" . POST_FORUM_URL . "=$forum_id"),

	'S_SELECT_TOPIC_DAYS' => $select_topic_days,
	'S_POST_DAYS_ACTION' => append_sid("viewforum.$phpEx?" . POST_FORUM_URL . "=" . $forum_id))
);

//
// User authorisation levels output
//
$s_auth_can = ( ( $is_auth['auth_post'] ) ? $lang['Rules_post_can'] : $lang['Rules_post_cannot'] ) . '<br />';
$s_auth_can .= ( ( $is_auth['auth_reply'] ) ? $lang['Rules_reply_can'] : $lang['Rules_reply_cannot'] ) . '<br />';
$s_auth_can .= ( ( $is_auth['auth_edit'] ) ? $lang['Rules_edit_can'] : $lang['Rules_edit_cannot'] ) . '<br />';
$s_auth_can .= ( ( $is_auth['auth_delete'] ) ? $lang['Rules_delete_can'] : $lang['Rules_delete_cannot'] ) . '<br />';
$s_auth_can .= ( ( $is_auth['auth_vote'] ) ? $lang['Rules_vote_can'] : $lang['Rules_vote_cannot'] ) . '<br />';
$s_auth_can .= ( $is_auth['auth_ban'] ) ? $lang['Rules_ban_can'] . '<br />' : '';
$s_auth_can .= ( $is_auth['auth_greencard'] ) ? $lang['Rules_greencard_can'] . '<br />' : '';
$s_auth_can .= ( $is_auth['auth_bluecard'] ) ? $lang['Rules_bluecard_can'] . '<br />' : '';
//-- mod : calendar --------------------------------------------------------------------------------
//-- add
$s_auth_can .= ( ( $is_auth['auth_cal'] ) ? $lang['Rules_calendar_can'] : $lang['Rules_calendar_cannot'] ) . '<br />';
//-- fin mod : calendar ----------------------------------------------------------------------------

attach_build_auth_levels($is_auth, $s_auth_can);
if ( $is_auth['auth_mod'] )
{
	$s_auth_can .= sprintf($lang['Rules_moderate'], "<a href=\"modcp.$phpEx?" . POST_FORUM_URL . "=$forum_id&amp;start=" . $start . "&amp;sid=" . $userdata['session_id'] . '">', '</a>');
}

//
// Mozilla navigation bar
//
$nav_links['up'] = array(
	'url' => append_sid('index.'.$phpEx),
	'title' => sprintf($lang['Forum_Index'], $board_config['sitename'])
);

//
// Dump out the page header and load viewforum template
//
//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
$forum_row['forum_name'] = get_object_lang(POST_FORUM_URL . $forum_id, 'name');
//-- fin mod : categories hierarchy ----------------------------------------------------------------

if ($board_config['display_viewonline'] == 2) define('SHOW_ONLINE', true);

$page_title = $lang['View_forum'] . ' - ' . $forum_row['forum_name'];
include($phpbb_root_path . 'includes/page_header.'.$phpEx);

$template->set_filenames(array(
	'body' => 'viewforum_body.tpl')
);
make_jumpbox('viewforum.'.$phpEx);
//-- mod : announces -------------------------------------------------------------------------------
//-- add
announces_from_forums(POST_FORUM_URL . $forum_id);
//-- fin mod : announces ---------------------------------------------------------------------------

//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
display_index(POST_FORUM_URL . $forum_id);
//-- fin mod : categories hierarchy ----------------------------------------------------------------

$template->assign_vars(array(
	'FORUM_ID' => $forum_id,
	'FORUM_NAME' => $forum_row['forum_name'],
	'MODERATORS' => $forum_moderators,
	'POST_IMG' => ( $forum_row['forum_status'] == FORUM_LOCKED ) ? $images['post_locked'] : $images['post_new'],

	'FOLDER_IMG' => $images['folder'],
	'FOLDER_NEW_IMG' => $images['folder_new'],
	'FOLDER_HOT_IMG' => $images['folder_hot'],
	'FOLDER_HOT_NEW_IMG' => $images['folder_hot_new'],
	'FOLDER_LOCKED_IMG' => $images['folder_locked'],
	'FOLDER_LOCKED_NEW_IMG' => $images['folder_locked_new'],
	'FOLDER_STICKY_IMG' => $images['folder_sticky'],
	'FOLDER_STICKY_NEW_IMG' => $images['folder_sticky_new'],
	'FOLDER_ANNOUNCE_IMG' => $images['folder_announce'],
	'FOLDER_ANNOUNCE_NEW_IMG' => $images['folder_announce_new'],
	'FOLDER_MOVED_IMG' => $images['folder_moved'],
	//-- mod : announces -------------------------------------------------------------------------------
//-- add
	'FOLDER_GLOBAL_ANNOUNCE_IMG'		=> $images['folder_global_announce'],
	'FOLDER_GLOBAL_ANNOUNCE_NEW_IMG'	=> $images['folder_global_announce_new'],
	'L_GLOBAL_ANNOUNCEMENT'				=> $lang['Post_Global_Announcement'],
//-- fin mod : announces ---------------------------------------------------------------------------

	'L_TOPICS' => $lang['Topics'],
	'L_REPLIES' => $lang['Replies'],
	'L_VIEWS' => $lang['Views'],
	'L_POSTS' => $lang['Posts'],
	'L_LASTPOST' => $lang['Last_Post'],
	'L_MODERATOR' => $l_moderators,
	'L_MARK_TOPICS_READ' => $lang['Mark_all_topics'],
	'L_POST_NEW_TOPIC' => ( $forum_row['forum_status'] == FORUM_LOCKED ) ? $lang['Forum_locked'] : $lang['Post_new_topic'],
	'L_NO_NEW_POSTS' => $lang['No_new_posts'],
	'L_NEW_POSTS' => $lang['New_posts'],
	'L_NO_NEW_POSTS_LOCKED' => $lang['No_new_posts_locked'],
	'L_NEW_POSTS_LOCKED' => $lang['New_posts_locked'],
	'L_NO_NEW_POSTS_HOT' => $lang['No_new_posts_hot'],
	'L_NEW_POSTS_HOT' => $lang['New_posts_hot'],
	'L_ANNOUNCEMENT' => $lang['Post_Announcement'],
	'L_STICKY' => $lang['Post_Sticky'],
	'L_POSTED' => $lang['Posted'],
	'L_MOVED' => $lang['Moved'],
	'L_JOINED' => $lang['Joined'],
	'L_AUTHOR' => $lang['Author'],
	'L_DESCRIPTION' => $lang['Description'],
	'S_AUTH_LIST' => $s_auth_can,

	'U_VIEW_FORUM' => append_sid("viewforum.$phpEx?" . POST_FORUM_URL ."=$forum_id"),

	'U_MARK_READ' => append_sid("viewforum.$phpEx?" . POST_FORUM_URL . "=$forum_id&amp;mark=topics"))
);
//
// End header
//
$template->assign_vars(array(
	'L_SEARCH_FOR' => $lang['Search_for'],
	'L_SUBMIT_SEARCH' => $lang['Submit_search'])
);
//
// Okay, lets dump out the page ...
//
//-- mod : split topic type ------------------------------------------------------------------------
//-- add
// adjust the item id
for ($i=0; $i < count($topic_rowset); $i++)
{
	$topic_rowset[$i]['topic_id'] = POST_TOPIC_URL . $topic_rowset[$i]['topic_id'];
}

// set the bottom sort option
$footer = $lang['Display_topics'] . ':&nbsp;' . $select_topic_days . '&nbsp;' . ( !empty($s_display_order) ? $s_display_order : '') . '<input type="submit" class="liteoption" value="' . $lang['Go'] . '" name="submit" />';

// send the list
$allow_split_type = true;
$display_nav_tree = false;
topic_list('TOPICS_LIST_BOX', 'topics_list_box', $topic_rowset, '', $allow_split_type, $display_nav_tree, $footer);

$template->assign_vars(array(
	'PAGINATION' => generate_pagination("viewforum.$phpEx?" . POST_FORUM_URL . "=$forum_id&amp;topicdays=$topic_days",
		$topic_page['total'], $topic_page['per_page'], $start),
	'PAGE_NUMBER' => sprintf($lang['Page_of'], $topic_page['page'], $topic_page['pages']),
	'L_GOTO_PAGE' => $lang['Goto_page'])
);

//
// Parse the page and print
//
$template->pparse('body');

//
// Page footer
//
include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>
