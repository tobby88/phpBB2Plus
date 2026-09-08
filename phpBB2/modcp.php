<?php
/***************************************************************************
 *                                 modcp.php
 *                            -------------------
 *   begin                : July 4, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: modcp.php,v 1.71.2.23 2004/03/13 15:08:22 acydburn Exp $
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

/**
 * Moderator Control Panel
 *
 * From this 'Control Panel' the moderator of a forum will be able to do
 * mass topic operations (locking/unlocking/moving/deleteing), and it will
 * provide an interface to do quick locking/unlocking/moving/deleting of
 * topics via the moderator operations buttons on all of the viewtopic pages.
 */

if (!defined('IN_PHPBB'))
{
    define( 'IN_PHPBB', true);
}
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);
include($phpbb_root_path . 'includes/bbcode.'.$phpEx);
include($phpbb_root_path . 'includes/functions_admin.'.$phpEx);
include_once($phpbb_root_path . 'includes/functions_moderation.'.$phpEx);
include_once($phpbb_root_path . 'includes/functions_log.'.$phpEx);

//
// Obtain initial var settings
//
if ( isset($_GET[POST_FORUM_URL]) || isset($_POST[POST_FORUM_URL]) )
{
	$forum_id = intval(phpbb_request_scalar($_POST, POST_FORUM_URL, phpbb_request_scalar($_GET, POST_FORUM_URL, 0)));
}
else
{
	$forum_id = '';
}

if ( isset($_GET[POST_POST_URL]) || isset($_POST[POST_POST_URL]) )
{
	$post_id = intval(phpbb_request_scalar($_POST, POST_POST_URL, phpbb_request_scalar($_GET, POST_POST_URL, 0)));
}
else
{
	$post_id = '';
}

if ( isset($_GET[POST_TOPIC_URL]) || isset($_POST[POST_TOPIC_URL]) )
{
	$topic_id = intval(phpbb_request_scalar($_POST, POST_TOPIC_URL, phpbb_request_scalar($_GET, POST_TOPIC_URL, 0)));
}
else
{
	$topic_id = '';
}

$confirm = isset($_POST['confirm']);
$topic_id_list = phpbb_request_id_array($_POST, 'topic_id_list');
$post_id_list = phpbb_request_id_array($_POST, 'post_id_list');
//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
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
//
// Continue var definitions
//
$start = intval(phpbb_request_scalar($_GET, 'start', 0));
$start = ($start < 0) ? 0 : $start;

$delete = ( isset($_POST['delete']) ) ? TRUE : FALSE;
$move = ( isset($_POST['move']) ) ? TRUE : FALSE;
$lock = ( isset($_POST['lock']) ) ? TRUE : FALSE;
$unlock = ( isset($_POST['unlock']) ) ? TRUE : FALSE;
// MOD MODCP EXTENSION BEGIN
$sticky = ( isset($_POST['sticky']) ) ? TRUE : FALSE;
$announce = ( isset($_POST['announce']) ) ? TRUE : FALSE;
$normalise = ( isset($_POST['normalise']) ) ? TRUE : FALSE;
// MOD MODCP EXTENSION END
if ( isset($_POST['mode']) || isset($_GET['mode']) )
{
	$mode = phpbb_request_scalar($_POST, 'mode', phpbb_request_scalar($_GET, 'mode'));
	$mode = htmlspecialchars($mode);
}
else
{
	if ( $delete )
	{
		$mode = 'delete';
	}
	else if ( $move )
	{
		$mode = 'move';
	}
	else if ( $lock )
	{
		$mode = 'lock';
	}
	else if ( $unlock )
	{
		$mode = 'unlock';
	}
	// MOD MODCP EXTENSION BEGIN
    else if ( $sticky )
    {
        $mode = 'sticky';
    }
    else if ( $announce )
    {
        $mode = 'announce';
    }
    else if ( $normalise )
    {
        $mode = 'normalise';
    }
    // MOD MODCP EXTENSION END
	else
	{
		$mode = '';
	}
}

// session id check
if (phpbb_request_scalar($_POST, 'sid') !== '' || phpbb_request_scalar($_GET, 'sid') !== '')
{
	$sid = phpbb_request_scalar($_POST, 'sid', phpbb_request_scalar($_GET, 'sid'));
}
else
{
	$sid = '';
}

//
// Obtain relevant data
//
if ( !empty($topic_id) )
{
	$sql = "SELECT f.forum_id, f.forum_name, f.forum_topics
		FROM " . TOPICS_TABLE . " t, " . FORUMS_TABLE . " f
		WHERE t.topic_id = " . $topic_id . "
			AND f.forum_id = t.forum_id";
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_MESSAGE, 'Topic_post_not_exist');
	}
	$topic_row = $db->sql_fetchrow($result);
	if (!$topic_row)
	{
		message_die(GENERAL_MESSAGE, 'Topic_post_not_exist');
	}
	
	$forum_topics = ( $topic_row['forum_topics'] == 0 ) ? 1 : $topic_row['forum_topics'];
	$forum_id = $topic_row['forum_id'];
	$db->sql_freeresult($result);
}
else if ( !empty($forum_id) )
{
	$sql = "SELECT forum_name, forum_topics
		FROM " . FORUMS_TABLE . "
		WHERE forum_id = " . $forum_id;
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_MESSAGE, 'Forum_not_exist');
	}
	$topic_row = $db->sql_fetchrow($result);
	if (!$topic_row)
	{
		message_die(GENERAL_MESSAGE, 'Forum_not_exist');
	}
	
	$forum_topics = ( $topic_row['forum_topics'] == 0 ) ? 1 : $topic_row['forum_topics'];
	$db->sql_freeresult($result);
}
else
{
	message_die(GENERAL_MESSAGE, 'Forum_not_exist');
}

//
// Start session management
//
$userdata = session_pagestart($user_ip, $forum_id);
init_userprefs($userdata);
//
// End session management
//

// session id check
if ($sid === '' || !hash_equals((string) $userdata['session_id'], $sid))
{
	message_die(GENERAL_ERROR, $lang['Session_invalid']);
}

// The legacy topic toolbar uses GET links as a non-JavaScript fallback.
// Bind direct mutations to the exact session, action and topic so a copied or
// cross-site URL cannot change moderator state.
$direct_topic_modes = array('lock', 'unlock', 'sticky', 'announce', 'normalise');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && in_array($mode, $direct_topic_modes, true))
{
	$submitted_token = phpbb_request_scalar($_GET, 'mod_token');
	$expected_token = phpbb_session_action_token('moderate-topic', $mode, $topic_id, $userdata['session_id']);
	if ($topic_id <= 0 || $submitted_token === '' || !hash_equals($expected_token, $submitted_token))
	{
		message_die(GENERAL_ERROR, $lang['Session_invalid']);
	}
}

//
// Check if user did or did not confirm
// If they did not, forward them to the last page they were on
//
if ( isset($_POST['cancel']) )
{
	if ( $topic_id )
	{
		$redirect = "viewtopic.$phpEx?" . POST_TOPIC_URL . "=$topic_id";
	}
	else if ( $forum_id )
	{
		$redirect = "viewforum.$phpEx?" . POST_FORUM_URL . "=$forum_id";
	}
	else
	{
		$redirect = "index.$phpEx";
	}

	redirect(append_sid($redirect, true));
}

//
// Start auth check
//
$is_auth = auth(AUTH_ALL, $forum_id, $userdata);

if ( !$is_auth['auth_mod'] )
{
	message_die(GENERAL_MESSAGE, $lang['Not_Moderator'], $lang['Not_Authorised']);
}
// Language and hierarchy caches are initialized by init_userprefs(). Resolve
// only after authorization, using the canonical forum ID from either route.
$forum_name = get_object_lang(POST_FORUM_URL . $forum_id, 'name');
//
// End Auth Check
//

//
// Do major work ...
//
switch( $mode )
{
	case 'delete':
		if (!$is_auth['auth_delete'])
		{
			message_die(GENERAL_MESSAGE, sprintf($lang['Sorry_auth_delete'], $is_auth['auth_delete_type']));
		}

		$page_title = $lang['Mod_CP'];
		include($phpbb_root_path . 'includes/page_header.'.$phpEx);

		if ( $confirm )
		{
			if ( empty($topic_id_list) && empty($topic_id) )
			{
				message_die(GENERAL_MESSAGE, $lang['None_selected']);
			}

			include($phpbb_root_path . 'includes/functions_search.'.$phpEx);

			$topics = !empty($topic_id_list) ? $topic_id_list : array($topic_id);

			$removed = phpbb_delete_moderated_topics($db, $forum_id, $topics);
			if (!$removed['topic_ids'])
			{
				message_die(GENERAL_MESSAGE, $lang['None_selected']);
			}
			$topic_id_sql = implode(', ', $removed['topic_ids']);
			$post_id_sql = implode(', ', $removed['post_ids']);
			// Index and forum totals were synchronized before releasing the writer.
			board_stats();
			cache_tree(true);
			log_action('delete', $topic_id_sql, $userdata['user_id'], $userdata['username']);

			if ( !empty($topic_id) )
			{
				$redirect_page = "viewforum.$phpEx?" . POST_FORUM_URL . "=$forum_id&amp;sid=" . $userdata['session_id'];
				$l_redirect = sprintf($lang['Click_return_forum'], '<a href="' . $redirect_page . '">', '</a>');
			}
			else
			{
				$redirect_page = "modcp.$phpEx?" . POST_FORUM_URL . "=$forum_id&amp;sid=" . $userdata['session_id'];
				$l_redirect = sprintf($lang['Click_return_modcp'], '<a href="' . $redirect_page . '">', '</a>');
			}

			$template->assign_vars(array(
				'META' => '<meta http-equiv="refresh" content="3;url=' . $redirect_page . '">')
			);

			message_die(GENERAL_MESSAGE, $lang['Topics_Removed'] . '<br /><br />' . $l_redirect);
		}
		else
		{
			// Not confirmed, show confirmation message
			if ( empty($topic_id_list) && empty($topic_id) )
			{
				message_die(GENERAL_MESSAGE, $lang['None_selected']);
			}

			$hidden_fields = '<input type="hidden" name="sid" value="' . $userdata['session_id'] . '" /><input type="hidden" name="mode" value="' . $mode . '" /><input type="hidden" name="' . POST_FORUM_URL . '" value="' . $forum_id . '" />';

			if ( !empty($topic_id_list) )
			{
				$topics = $topic_id_list;
				for($i = 0; $i < count($topics); $i++)
				{
					$hidden_fields .= '<input type="hidden" name="topic_id_list[]" value="' . intval($topics[$i]) . '" />';
				}
			}
			else
			{
				$hidden_fields .= '<input type="hidden" name="' . POST_TOPIC_URL . '" value="' . $topic_id . '" />';
			}

			//
			// Set template files
			//
			$template->set_filenames(array(
				'confirm' => 'confirm_body.tpl')
			);

			$template->assign_vars(array(
				'MESSAGE_TITLE' => $lang['Confirm'],
				'MESSAGE_TEXT' => $lang['Confirm_delete_topic'],

				'L_YES' => $lang['Yes'],
				'L_NO' => $lang['No'],

				'S_CONFIRM_ACTION' => append_sid("modcp.$phpEx"),
				'S_HIDDEN_FIELDS' => $hidden_fields)
			);

			$template->pparse('confirm');

			include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
		}
		break;

	case 'move':
		$page_title = $lang['Mod_CP'];
		include($phpbb_root_path . 'includes/page_header.'.$phpEx);

		if ( $confirm )
		{
			if ( empty($topic_id_list) && empty($topic_id) )
			{
				message_die(GENERAL_MESSAGE, $lang['None_selected']);
			}

			$new_forum_id = intval(phpbb_request_scalar($_POST, 'new_forum', 0));
			//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
			$fid = phpbb_request_scalar($_POST, 'new_forum');
			if ($fid == 'Root')
			{
				$type = POST_CAT_URL;
				$new_forum_id = 0;
			}
			else
			{
				$type = substr($fid, 0, 1);
				$new_forum_id = ($type == POST_FORUM_URL) ? intval(substr($fid, 1)) : 0;
			}
			if ($new_forum_id <= 0 ) message_die(GENERAL_MESSAGE, $lang['Forum_not_exist']);
//-- fin mod : categories hierarchy ----------------------------------------------------------------
			$old_forum_id = $forum_id;
			$topics = !empty($topic_id_list) ? $topic_id_list : array($topic_id);
			require_once $phpbb_root_path . 'includes/functions_topic_move.' . $phpEx;
			try
			{
				$moved = phpbb_move_topics($db, $old_forum_id, $new_forum_id, $topics, isset($_POST['move_leave_shadow']));
			}
			catch (PhpbbTopicMoveException $error)
			{
				message_die(GENERAL_MESSAGE, $error->getMessage());
			}
			if ($moved)
			{
				board_stats();
				cache_tree(true);
			}
			$message = $lang[$moved ? 'Topics_Moved' : 'No_Topics_Moved'] . '<br /><br />';

			if ( !empty($topic_id) )
			{
				$redirect_page = "viewtopic.$phpEx?" . POST_TOPIC_URL . "=$topic_id&amp;sid=" . $userdata['session_id'];
				$message .= sprintf($lang['Click_return_topic'], '<a href="' . $redirect_page . '">', '</a>');
			}
			else
			{
				$redirect_page = "modcp.$phpEx?" . POST_FORUM_URL . "=$forum_id&amp;sid=" . $userdata['session_id'];
				$message .= sprintf($lang['Click_return_modcp'], '<a href="' . $redirect_page . '">', '</a>');
			}

			$message = $message . '<br \><br \>' . sprintf($lang['Click_return_forum'], '<a href="' . "viewforum.$phpEx?" . POST_FORUM_URL . "=$old_forum_id&amp;sid=" . $userdata['session_id'] . '">', '</a>');

			$template->assign_vars(array(
				'META' => '<meta http-equiv="refresh" content="3;url=' . $redirect_page . '">')
			);

			message_die(GENERAL_MESSAGE, $message);
		}
		else
		{
			if ( empty($topic_id_list) && empty($topic_id) )
			{
				message_die(GENERAL_MESSAGE, $lang['None_selected']);
			}

			$hidden_fields = '<input type="hidden" name="sid" value="' . $userdata['session_id'] . '" /><input type="hidden" name="mode" value="' . $mode . '" /><input type="hidden" name="' . POST_FORUM_URL . '" value="' . $forum_id . '" />';

			if ( !empty($topic_id_list) )
			{
				$topics = $topic_id_list;

				for($i = 0; $i < count($topics); $i++)
				{
					$hidden_fields .= '<input type="hidden" name="topic_id_list[]" value="' . intval($topics[$i]) . '" />';
				}
			}
			else
			{
				$hidden_fields .= '<input type="hidden" name="' . POST_TOPIC_URL . '" value="' . $topic_id . '" />';
			}

			//
			// Set template files
			//
			$template->set_filenames(array(
				'movetopic' => 'modcp_move.tpl')
			);

			$template->assign_vars(array(
				'MESSAGE_TITLE' => $lang['Confirm'],
				'MESSAGE_TEXT' => $lang['Confirm_move_topic'],

				'L_MOVE_TO_FORUM' => $lang['Move_to_forum'], 
				'L_LEAVESHADOW' => $lang['Leave_shadow_topic'], 
				'L_YES' => $lang['Yes'],
				'L_NO' => $lang['No'],

				//-- mod : categories hierarchy --------------------------------------------------------------------
//-- delete
//				'S_FORUM_SELECT' => make_forum_select('new_forum', $forum_id), 
//-- add
				'S_FORUM_SELECT' => selectbox('new_forum', $forum_id),
//-- fin mod : categories hierarchy ----------------------------------------------------------------

				'S_MODCP_ACTION' => append_sid("modcp.$phpEx"),
				'S_HIDDEN_FIELDS' => $hidden_fields)
			);

			$template->pparse('movetopic');

			include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
		}
		break;

	case 'lock':
		if ( empty($topic_id_list) && empty($topic_id) )
		{
			message_die(GENERAL_MESSAGE, $lang['None_selected']);
		}

		$topics = !empty($topic_id_list) ? $topic_id_list : array($topic_id);

		require_once($phpbb_root_path . 'includes/functions_topic_state.' . $phpEx);
		try { phpbb_moderate_topic_state($db, $forum_id, $topics, $mode); }
		catch (PhpbbTopicStateException $error) { message_die(GENERAL_MESSAGE, $error->getMessage()); }

		if ( !empty($topic_id) )
		{
			$redirect_page = "viewtopic.$phpEx?" . POST_TOPIC_URL . "=$topic_id&amp;sid=" . $userdata['session_id'];
			$message = sprintf($lang['Click_return_topic'], '<a href="' . $redirect_page . '">', '</a>');
		}
		else
		{
			$redirect_page = "modcp.$phpEx?" . POST_FORUM_URL . "=$forum_id&amp;sid=" . $userdata['session_id'];
			$message = sprintf($lang['Click_return_modcp'], '<a href="' . $redirect_page . '">', '</a>');
		}

		$message = $message . '<br \><br \>' . sprintf($lang['Click_return_forum'], '<a href="' . "viewforum.$phpEx?" . POST_FORUM_URL . "=$forum_id&amp;sid=" . $userdata['session_id'] . '">', '</a>');

		$template->assign_vars(array(
			'META' => '<meta http-equiv="refresh" content="3;url=' . $redirect_page . '">')
		);

		message_die(GENERAL_MESSAGE, $lang['Topics_Locked'] . '<br /><br />' . $message);

		break;

	case 'unlock':
		if ( empty($topic_id_list) && empty($topic_id) )
		{
			message_die(GENERAL_MESSAGE, $lang['None_selected']);
		}

		$topics = !empty($topic_id_list) ? $topic_id_list : array($topic_id);

		require_once($phpbb_root_path . 'includes/functions_topic_state.' . $phpEx);
		try { phpbb_moderate_topic_state($db, $forum_id, $topics, $mode); }
		catch (PhpbbTopicStateException $error) { message_die(GENERAL_MESSAGE, $error->getMessage()); }

		if ( !empty($topic_id) )
		{
			$redirect_page = "viewtopic.$phpEx?" . POST_TOPIC_URL . "=$topic_id&amp;sid=" . $userdata['session_id'];
			$message = sprintf($lang['Click_return_topic'], '<a href="' . $redirect_page . '">', '</a>');
		}
		else
		{
			$redirect_page = "modcp.$phpEx?" . POST_FORUM_URL . "=$forum_id&amp;sid=" . $userdata['session_id'];
			$message = sprintf($lang['Click_return_modcp'], '<a href="' . $redirect_page . '">', '</a>');
		}

		$message = $message . '<br \><br \>' . sprintf($lang['Click_return_forum'], '<a href="' . "viewforum.$phpEx?" . POST_FORUM_URL . "=$forum_id&amp;sid=" . $userdata['session_id'] . '">', '</a>');

		$template->assign_vars(array(
			'META' => '<meta http-equiv="refresh" content="3;url=' . $redirect_page . '">')
		);

		message_die(GENERAL_MESSAGE, $lang['Topics_Unlocked'] . '<br /><br />' . $message);

		break;
		// MOD MODCP EXTENSION BEGIN
	case 'sticky':
	case 'announce':
	case 'normalise':
        if ($mode == 'sticky' && !$is_auth['auth_sticky']) 
        { 
           $message = sprintf($lang['Sorry_auth_sticky'], $is_auth['auth_sticky_type']); 
           message_die(GENERAL_MESSAGE, $message); 
        } 
        if ($mode == 'announce' && !$is_auth['auth_announce']) 
        { 
           $message = sprintf($lang['Sorry_auth_announce'], $is_auth['auth_announce_type']); 
           message_die(GENERAL_MESSAGE, $message); 
        } 
		if ( empty($topic_id_list) && empty($topic_id) )
		{
			message_die(GENERAL_MESSAGE, $lang['None_selected']);
		}

		$topics = !empty($topic_id_list) ? $topic_id_list : array($topic_id);

		require_once($phpbb_root_path . 'includes/functions_topic_state.' . $phpEx);
		try { phpbb_moderate_topic_state($db, $forum_id, $topics, $mode); }
		catch (PhpbbTopicStateException $error) { message_die(GENERAL_MESSAGE, $error->getMessage()); }

		if ( !empty($topic_id) )
		{
			$redirect_page = append_sid("viewtopic.$phpEx?" . POST_TOPIC_URL . "=$topic_id");
			$message = sprintf($lang['Click_return_topic'], '<a href="' . $redirect_page . '">', '</a>');
		}
		else
		{
			$redirect_page = "modcp.$phpEx?" . POST_FORUM_URL . "=$forum_id&sid=" . $userdata['session_id'];
			$message = sprintf($lang['Click_return_modcp'], '<a href="' . $redirect_page . '">', '</a>');
		}

		$message = $message . '<br \><br \>' . sprintf($lang['Click_return_forum'], '<a href="' . append_sid("viewforum.$phpEx?" . POST_FORUM_URL . "=$forum_id") . '">', '</a>');

		$template->assign_vars(array(
			'META' => '<meta http-equiv="refresh" content="3;url=' . $redirect_page . '">')
		);

        if ($mode == 'sticky') 
        { 
            $message = $lang['Topics_Stickyd'] . '<br /><br />' . $message;
        }
        else if ($mode == 'announce')
        {
            $message = $lang['Topics_Announced'] . '<br /><br />' . $message;
        }
        else if ($mode == 'normalise')
        {
            $message = $lang['Topics_Normalised'] . '<br /><br />' . $message;
        }

		message_die(GENERAL_MESSAGE, $message);
        break;
    // MOD MODCP EXTENSION END        

	case 'split':
		$page_title = $lang['Mod_CP'];
		include($phpbb_root_path . 'includes/page_header.'.$phpEx);

		if (isset($_POST['split_type_all']) || isset($_POST['split_type_beyond']))
		{
			require_once $phpbb_root_path . 'includes/functions_topic_split.' . $phpEx;
			$fid = phpbb_request_scalar($_POST, 'new_forum_id');
			if (!preg_match('/^' . POST_FORUM_URL . '[1-9][0-9]*$/D', $fid))
			{
				message_die(GENERAL_MESSAGE, $lang['Forum_not_exist']);
			}
			$split_mode = isset($_POST['split_type_beyond']) ? 'after' : 'selected';
			if (isset($_POST['split_type_beyond'], $_POST['split_type_all'])) { $split_mode = ''; }
			$selected_posts = isset($_POST['post_id_list']) ? $_POST['post_id_list'] : array();
			try
			{
				$split = phpbb_split_topic($db, $forum_id, $topic_id, substr($fid, 1), $selected_posts, $split_mode, stripslashes(phpbb_request_scalar($_POST, 'subject')));
			}
			catch (PhpbbTopicSplitException $error)
			{
				message_die(GENERAL_MESSAGE, $error->getMessage());
			}
			board_stats();
			cache_tree(true);
			$redirect_page = "viewtopic.$phpEx?" . POST_TOPIC_URL . "=$topic_id&amp;sid=" . $userdata['session_id'];
			$template->assign_vars(array('META' => '<meta http-equiv="refresh" content="3;url=' . $redirect_page . '">'));
			$message = $lang['Topic_split'] . '<br /><br />'
				. sprintf($lang['Click_return_topic'], '<a href="' . $redirect_page . '">', '</a>') . '<br /><br />'
				. sprintf($lang['Click_view_split_topic'], '<a href="' . append_sid("viewtopic.$phpEx?" . POST_TOPIC_URL . '=' . $split['topic_id']) . '">', '</a>');
			message_die(GENERAL_MESSAGE, $message);
		}
		else
		{
			//
			// Set template files
			//
			$template->set_filenames(array(
				'split_body' => 'modcp_split.tpl')
			);

			$sql = "SELECT u.username, p.*, pt.post_text, pt.bbcode_uid, pt.post_subject, p.post_username
				FROM " . POSTS_TABLE . " p, " . USERS_TABLE . " u, " . POSTS_TEXT_TABLE . " pt
					WHERE p.topic_id = $topic_id
						AND p.forum_id = $forum_id
						AND p.poster_id = u.user_id
						AND p.post_id = pt.post_id
					ORDER BY p.post_time ASC, p.post_id ASC";
			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Could not get topic/post information', '', __LINE__, __FILE__, $sql);
			}

			$s_hidden_fields = '<input type="hidden" name="sid" value="' . $userdata['session_id'] . '" /><input type="hidden" name="' . POST_FORUM_URL . '" value="' . $forum_id . '" /><input type="hidden" name="' . POST_TOPIC_URL . '" value="' . $topic_id . '" /><input type="hidden" name="mode" value="split" />';

			if( ( $total_posts = $db->sql_numrows($result) ) > 0 )
			{
				$postrow = $db->sql_fetchrowset($result);
				$split_first_post_id = min(array_map('intval', array_column($postrow, 'post_id')));

				$template->assign_vars(array(
					'L_SPLIT_TOPIC' => $lang['Split_Topic'],
					'L_SPLIT_TOPIC_EXPLAIN' => $lang['Split_Topic_explain'],
					'L_AUTHOR' => $lang['Author'],
					'L_MESSAGE' => $lang['Message'],
					'L_SELECT' => $lang['Select'],
					'L_SAVE_CHANGES' => $lang['Save_changes'],
					'L_CANCEL' => $lang['Cancel'],
					'L_SPLIT_SUBJECT' => $lang['Split_title'],
					'L_SPLIT_FORUM' => $lang['Split_forum'],
					'L_POSTED' => $lang['Posted'],
					'L_SPLIT_POSTS' => $lang['Split_posts'],
					'L_SUBMIT' => $lang['Submit'],
					'L_SPLIT_AFTER' => $lang['Split_after'], 
					'L_POST_SUBJECT' => $lang['Post_subject'], 
					'L_MARK_ALL' => $lang['Mark_all'], 
					'L_UNMARK_ALL' => $lang['Unmark_all'], 
					'L_POST' => $lang['Post'], 

					'FORUM_NAME' => $forum_name, 

					'U_VIEW_FORUM' => append_sid("viewforum.$phpEx?" . POST_FORUM_URL . "=$forum_id"), 

					'S_SPLIT_ACTION' => append_sid("modcp.$phpEx"),
					'S_HIDDEN_FIELDS' => $s_hidden_fields,
					//-- mod : categories hierarchy --------------------------------------------------------------------
//-- delete
//					'S_FORUM_SELECT' => make_forum_select("new_forum_id", false, $forum_id))
//-- add
					'S_FORUM_SELECT' => selectbox("new_forum_id", false, $forum_id))
//-- fin mod : categories hierarchy ----------------------------------------------------------------

				);
				
				//
				// Define censored word matches
				//
				$orig_word = array();
				$replacement_word = array();
				obtain_word_list($orig_word, $replacement_word);
				
				for($i = 0; $i < $total_posts; $i++)
				{
					$post_id = $postrow[$i]['post_id'];
					$poster_id = $postrow[$i]['poster_id'];
					$poster = $postrow[$i]['username'];

					//-- mod : today at  yesterday at ------------------------------------------------------------------------ 
//-- add 
               $post_date = create_date_day($board_config['default_dateformat'], $postrow[$i]['post_time'], $board_config['board_timezone']); 
//-- end mod : today at  yesterday at ------------------------------------------------------------------------ 


					$bbcode_uid = $postrow[$i]['bbcode_uid'];
					$message = $postrow[$i]['post_text'];
					$post_subject = ( $postrow[$i]['post_subject'] != '' ) ? $postrow[$i]['post_subject'] : $topic_title;

					//
					// If the board has HTML off but the post has HTML
					// on then we process it, else leave it alone
					//
					if ( !$board_config['allow_html'] )
					{
						if ( $postrow[$i]['enable_html'] )
						{
							$message = preg_replace('#(<)([\/]?.*?)(>)#is', '&lt;\\2&gt;', $message);
						}
					}

					if ( $bbcode_uid != '' )
					{
						$message = ( $board_config['allow_bbcode'] ) ? bbencode_second_pass($message, $bbcode_uid) : preg_replace('/\:[0-9a-z\:]+\]/si', ']', $message);
					}

					if ( count($orig_word) )
					{
						$post_subject = preg_replace($orig_word, $replacement_word, $post_subject);
						$message = preg_replace($orig_word, $replacement_word, $message);
					}

					$message = make_clickable($message);

					if ( $board_config['allow_smilies'] && $postrow[$i]['enable_smilies'] )
					{
						$message = smilies_pass($message);
					}

					$message = str_replace("\n", '<br />', $message);
					
					$row_color = ( !($i % 2) ) ? $theme['td_color1'] : $theme['td_color2'];
					$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];

					$checkbox = ( (int) $post_id !== $split_first_post_id ) ? '<input type="checkbox" name="post_id_list[]" value="' . $post_id . '" />' : '&nbsp;';
					
					$template->assign_block_vars('postrow', array(
						'ROW_COLOR' => '#' . $row_color,
						'ROW_CLASS' => $row_class,
						'POSTER_NAME' => $poster,
						'POST_DATE' => $post_date,
						'POST_SUBJECT' => $post_subject,
						'MESSAGE' => $message,
						'POST_ID' => $post_id,
						
						'S_SPLIT_CHECKBOX' => $checkbox)
					);
				}

				$template->pparse('split_body');
			}
		}
		break;

	case 'ip':
		$page_title = $lang['Mod_CP'];
		include($phpbb_root_path . 'includes/page_header.'.$phpEx);

		$rdns_ip_num = phpbb_request_scalar($_GET, 'rdns');

		if ( !$post_id )
		{
			message_die(GENERAL_MESSAGE, $lang['No_such_post']);
		}

		//
		// Set template files
		//
		$template->set_filenames(array(
			'viewip' => 'modcp_viewip.tpl')
		);

		// Look up relevent data for this post
		$sql = "SELECT poster_ip, poster_id 
			FROM " . POSTS_TABLE . " 
			WHERE post_id = $post_id
				AND forum_id = $forum_id";
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not get poster IP information', '', __LINE__, __FILE__, $sql);
		}
		
		if ( !($post_row = $db->sql_fetchrow($result)) )
		{
			message_die(GENERAL_MESSAGE, $lang['No_such_post']);
		}

		$ip_this_post = decode_ip($post_row['poster_ip']);
		$ip_this_post = ( $rdns_ip_num == $ip_this_post ) ? htmlspecialchars(gethostbyaddr($ip_this_post)) : $ip_this_post;

		$poster_id = $post_row['poster_id'];

		$template->assign_vars(array(
			'L_IP_INFO' => $lang['IP_info'],
			'L_THIS_POST_IP' => $lang['This_posts_IP'],
			'L_OTHER_IPS' => $lang['Other_IP_this_user'],
			'L_OTHER_USERS' => $lang['Users_this_IP'],
			'L_LOOKUP_IP' => $lang['Lookup_IP'], 
			'L_SEARCH' => $lang['Search'],

			'SEARCH_IMG' => $images['icon_search'], 

			'IP' => $ip_this_post, 
				
			'U_LOOKUP_IP' => "modcp.$phpEx?mode=ip&amp;" . POST_POST_URL . "=$post_id&amp;" . POST_TOPIC_URL . "=$topic_id&amp;rdns=$ip_this_post&amp;sid=" . $userdata['session_id'])
		);

		//
		// Get other IP's this user has posted under
		//
		$sql = "SELECT poster_ip, COUNT(*) AS postings 
			FROM " . POSTS_TABLE . " 
			WHERE poster_id = $poster_id 
			GROUP BY poster_ip 
			ORDER BY " . (( SQL_LAYER == 'msaccess' ) ? 'COUNT(*)' : 'postings' ) . " DESC";
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not get IP information for this user', '', __LINE__, __FILE__, $sql);
		}

		if ( $row = $db->sql_fetchrow($result) )
		{
			$i = 0;
			do
			{
				if ( $row['poster_ip'] == $post_row['poster_ip'] )
				{
					$template->assign_vars(array(
						'POSTS' => $row['postings'] . ' ' . ( ( $row['postings'] == 1 ) ? $lang['Post'] : $lang['Posts'] ))
					);
					continue;
				}

				$ip = decode_ip($row['poster_ip']);
				$ip = ( $rdns_ip_num == $row['poster_ip'] || $rdns_ip_num == 'all') ? htmlspecialchars(gethostbyaddr($ip)) : $ip;

				$row_color = ( !($i % 2) ) ? $theme['td_color1'] : $theme['td_color2'];
				$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];

				$template->assign_block_vars('iprow', array(
					'ROW_COLOR' => '#' . $row_color, 
					'ROW_CLASS' => $row_class, 
					'IP' => $ip,
					'POSTS' => $row['postings'] . ' ' . ( ( $row['postings'] == 1 ) ? $lang['Post'] : $lang['Posts'] ),

					'U_LOOKUP_IP' => "modcp.$phpEx?mode=ip&amp;" . POST_POST_URL . "=$post_id&amp;" . POST_TOPIC_URL . "=$topic_id&amp;rdns=" . $row['poster_ip'] . "&amp;sid=" . $userdata['session_id'])
				);

				$i++; 
			}
			while ( $row = $db->sql_fetchrow($result) );
		}

		//
		// Get other users who've posted under this IP
		//
		$sql = "SELECT u.user_id, u.username, COUNT(*) as postings 
			FROM " . USERS_TABLE ." u, " . POSTS_TABLE . " p 
			WHERE p.poster_id = u.user_id 
				AND p.poster_ip = '" . $post_row['poster_ip'] . "'
			GROUP BY u.user_id, u.username
			ORDER BY " . (( SQL_LAYER == 'msaccess' ) ? 'COUNT(*)' : 'postings' ) . " DESC";
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not get posters information based on IP', '', __LINE__, __FILE__, $sql);
		}

		if ( $row = $db->sql_fetchrow($result) )
		{
			$i = 0;
			do
			{
				$id = $row['user_id'];
				$username = ( $id == ANONYMOUS ) ? $lang['Guest'] : $row['username'];

				$row_color = ( !($i % 2) ) ? $theme['td_color1'] : $theme['td_color2'];
				$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];

				$template->assign_block_vars('userrow', array(
					'ROW_COLOR' => '#' . $row_color, 
					'ROW_CLASS' => $row_class, 
					'USERNAME' => $username,
					'POSTS' => $row['postings'] . ' ' . ( ( $row['postings'] == 1 ) ? $lang['Post'] : $lang['Posts'] ),
					'L_SEARCH_POSTS' => sprintf($lang['Search_user_posts'], $username), 

					'U_PROFILE' => ($id == ANONYMOUS) ? "modcp.$phpEx?mode=ip&amp;" . POST_POST_URL . "=" . $post_id . "&amp;" . POST_TOPIC_URL . "=" . $topic_id . "&amp;sid=" . $userdata['session_id'] : append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=$id"),
					'U_SEARCHPOSTS' => append_sid("search.$phpEx?search_author=" . (($id == ANONYMOUS) ? 'Anonymous' : urlencode($username)) . "&amp;showresults=topics"))
				);

				$i++; 
			}
			while ( $row = $db->sql_fetchrow($result) );
		}

		$template->pparse('viewip');

		break;

	default:
		$page_title = $lang['Mod_CP'];
		include($phpbb_root_path . 'includes/page_header.'.$phpEx);

		$template->assign_vars(array(
			'FORUM_NAME' => $forum_name,

			'L_MOD_CP' => $lang['Mod_CP'],
			'L_MOD_CP_EXPLAIN' => $lang['Mod_CP_explain'],
			'L_SELECT' => $lang['Select'],
			'L_DELETE' => $lang['Delete'],
			'L_MOVE' => $lang['Move'],
			'L_LOCK' => $lang['Lock'],
			'L_UNLOCK' => $lang['Unlock'],
			// MOD MODCP EXTENSTION BEGIN
			'L_STICKY' => $lang['Sticky'],
			'L_ANNOUNCE' => $lang['Announce'],
			'L_NORMALISE' => $lang['Normalise'],
            		'L_CHECK_ALL' => $lang['Check_All'], 
            		'L_UNCHECK_ALL' => $lang['Uncheck_All'], 
            		// MOD MODCP EXTENSTION END
			'L_TOPICS' => $lang['Topics'], 
			'L_REPLIES' => $lang['Replies'], 
			'L_LASTPOST' => $lang['Last_Post'], 
			'L_SELECT' => $lang['Select'], 

			'U_VIEW_FORUM' => append_sid("viewforum.$phpEx?" . POST_FORUM_URL . "=$forum_id"), 
			'S_HIDDEN_FIELDS' => '<input type="hidden" name="sid" value="' . $userdata['session_id'] . '" /><input type="hidden" name="' . POST_FORUM_URL . '" value="' . $forum_id . '" />',
			'S_MODCP_ACTION' => append_sid("modcp.$phpEx"))
		);
		if ($is_auth['auth_delete']) 
        {
    		$template->assign_block_vars('switch_auth_delete', array());
        }
        if ($is_auth['auth_sticky']) 
        {
    		$template->assign_block_vars('switch_auth_sticky', array());
        }
        if ($is_auth['auth_announce']) 
        {
    		$template->assign_block_vars('switch_auth_announce', array());
        }

		$template->set_filenames(array(
			'body' => 'modcp_body.tpl')
		);
		make_jumpbox('modcp.'.$phpEx);

		//
		// Define censored word matches
		//
		$orig_word = array();
		$replacement_word = array();
		obtain_word_list($orig_word, $replacement_word);

		$sql = "SELECT t.*, u.username, u.user_id, p.post_time
			FROM " . TOPICS_TABLE . " t, " . USERS_TABLE . " u, " . POSTS_TABLE . " p
			WHERE t.forum_id = $forum_id
				AND t.topic_poster = u.user_id
				AND p.post_id = t.topic_last_post_id
			ORDER BY t.topic_type DESC, p.post_time DESC
			LIMIT $start, " . $board_config['topics_per_page'];
		if ( !($result = $db->sql_query($sql)) )
		{
	   		message_die(GENERAL_ERROR, 'Could not obtain topic information', '', __LINE__, __FILE__, $sql);
		}

		while ( $row = $db->sql_fetchrow($result) )
		{
			$topic_title = '';

			if ( $row['topic_status'] == TOPIC_LOCKED )
			{
				$folder_img = $images['folder_locked'];
				$folder_alt = $lang['Topic_locked'];
			}
			else
			{
				if ( $row['topic_type'] == POST_ANNOUNCE )
				{
					$folder_img = $images['folder_announce'];
					$folder_alt = $lang['Topic_Announcement'];
				}
				else if ( $row['topic_type'] == POST_STICKY )
				{
					$folder_img = $images['folder_sticky'];
					$folder_alt = $lang['Topic_Sticky'];
				}
				else 
				{
					$folder_img = $images['folder'];
					$folder_alt = $lang['No_new_posts'];
				}
			}

			$topic_id = $row['topic_id'];
			$topic_type = $row['topic_type'];
			$topic_status = $row['topic_status'];
			
			if ( $topic_type == POST_ANNOUNCE )
			{
				$topic_type = $lang['Topic_Announcement'] . ' ';
			}
			else if ( $topic_type == POST_STICKY )
			{
				$topic_type = $lang['Topic_Sticky'] . ' ';
			}
			else if ( $topic_status == TOPIC_MOVED )
			{
				$topic_type = $lang['Topic_Moved'] . ' ';
			}
			else
			{
				$topic_type = '';		
			}
	
			if ( $row['topic_vote'] )
			{
				$topic_type .= $lang['Topic_Poll'] . ' ';
			}
	
			$topic_title = $row['topic_title'];
			if ( count($orig_word) )
			{
				$topic_title = preg_replace($orig_word, $replacement_word, $topic_title);
			}

			$u_view_topic = "modcp.$phpEx?mode=split&amp;" . POST_TOPIC_URL . "=$topic_id&amp;sid=" . $userdata['session_id'];
			$topic_replies = $row['topic_replies'];

			//-- mod : today at  yesterday at ------------------------------------------------------------------------ 
//-- add 
            $last_post_time = create_date_day($board_config['default_dateformat'], $row['post_time'], $board_config['board_timezone']); 
//-- end mod : today at  yesterday at ------------------------------------------------------------------------ 

			$template->assign_block_vars('topicrow', array(
				'U_VIEW_TOPIC' => $u_view_topic,

				'TOPIC_FOLDER_IMG' => $folder_img, 
				'TOPIC_TYPE' => $topic_type, 
				'TOPIC_TITLE' => $topic_title,
				'REPLIES' => $topic_replies,
				'LAST_POST_TIME' => $last_post_time,
				'TOPIC_ID' => $topic_id,
				'TOPIC_FIRST_POST_ID' => $row['topic_first_post_id'],
				'FORUM_ID' => $forum_id,
				'TOPIC_ATTACHMENT_IMG' => topic_attachment_image($row['topic_attachment']),
					
				'L_TOPIC_FOLDER_ALT' => $folder_alt)
			);
		}

		$template->assign_vars(array(
			'PAGINATION' => generate_pagination("modcp.$phpEx?" . POST_FORUM_URL . "=$forum_id&amp;sid=" . $userdata['session_id'], $forum_topics, $board_config['topics_per_page'], $start),
			'PAGE_NUMBER' => sprintf($lang['Page_of'], ( floor( $start / $board_config['topics_per_page'] ) + 1 ), ceil( $forum_topics / $board_config['topics_per_page'] )), 
			'L_GOTO_PAGE' => $lang['Goto_page'])
		);

		$template->pparse('body');

		break;
}

include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>
