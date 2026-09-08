<?php
/***************************************************************************
 *                            functions_post.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: functions_post.php,v 1.9.2.35 2003/06/09 19:35:56 psotfx Exp $
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
	die('Hacking attempt');
}

$html_entities_match = array('#&(?!(\#[0-9]+;))#', '#<#', '#>#', '#"#');
$html_entities_replace = array('&amp;', '&lt;', '&gt;', '&quot;');

$unhtml_specialchars_match = array('#&gt;#', '#&lt;#', '#&quot;#', '#&amp;#');
$unhtml_specialchars_replace = array('>', '<', '"', '&');

//
// This function will prepare a posted message for
// entry into the database.
//
function phpbb_post_escape_html($text)
{
	global $html_entities_match, $html_entities_replace;
	$text = (string) $text;
	$escaped = preg_replace($html_entities_match, $html_entities_replace, $text);
	if ($escaped === null || preg_last_error() !== PREG_NO_ERROR)
	{
		// If PCRE cannot finish, escape the full input with the non-regex
		// primitive. Never preserve raw HTML or return an empty partial result.
		return htmlspecialchars($text, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');
	}
	return $escaped;
}

function prepare_message($message, $html_on, $bbcode_on, $smile_on, $bbcode_uid = 0)
{
	global $board_config, $html_entities_match, $html_entities_replace;

	//
	// Clean up the message
	//
	$message = trim($message);

	if ($html_on)
	{
		// If HTML is on, we try to make it safe
		// This approach is quite agressive and anything that does not look like a valid tag
		// is going to get converted to HTML entities
		$message = stripslashes($message);
		$html_match = '#<[^\w<]*(\w+)((?:"[^"]*"|\'[^\']*\'|[^<>\'"])+)?>#';
		$matches = array();

		$message_split = preg_split($html_match, $message);
		$split_ok = is_array($message_split) && preg_last_error() === PREG_NO_ERROR;
		$match_count = preg_match_all($html_match, $message, $matches);
		if (!$split_ok || $match_count === false || preg_last_error() !== PREG_NO_ERROR)
		{
			$message = phpbb_post_escape_html($message);
		}
		else
		{
			$message = '';
			foreach ($message_split as $part)
			{
				$tag = array(array_shift($matches[0]), array_shift($matches[1]), array_shift($matches[2]));
				$message .= phpbb_post_escape_html($part) . clean_html($tag);
			}
		}

		$message = addslashes($message);
		$message = str_replace('&quot;', '\&quot;', $message);
	}
	else
	{
		$message = phpbb_post_escape_html($message);
	}

	if($bbcode_on && $bbcode_uid != '')
	{
		$message = bbencode_first_pass($message, $bbcode_uid);
	}

	return $message;
}

function unprepare_message($message)
{
	global $unhtml_specialchars_match, $unhtml_specialchars_replace;

	return preg_replace($unhtml_specialchars_match, $unhtml_specialchars_replace, $message);
}

//
// Prepare a message for posting
// 
//-- mod : calendar --------------------------------------------------------------------------------
// here we have added
//	, $topic_calendar_time = 0, $topic_calendar_duration = 0
//-- modify

function prepare_post(&$mode, &$post_data, &$bbcode_on, &$html_on, &$smilies_on, &$error_msg, &$username, &$bbcode_uid, &$subject, &$message, &$poll_title, &$poll_options, &$poll_length, &$topic_desc, $topic_calendar_time = 0, $topic_calendar_duration = 0)
{
	global $board_config, $userdata, $lang, $phpEx, $phpbb_root_path;

	// Check username
	if (!empty($username))
	{
		$username = phpbb_clean_username($username); 

		if (!$userdata['session_logged_in'] || ($userdata['session_logged_in'] && $username != $userdata['username']))
		{
			include($phpbb_root_path . 'includes/functions_validate.'.$phpEx);

			$result = validate_username($username);
			if ($result['error'])
			{
				$error_msg .= (!empty($error_msg)) ? '<br />' . $result['error_msg'] : $result['error_msg'];
			}
		}
		else
		{
			$username = '';
		}
	}

	// Check subject
	if (!empty($subject))
	{
		$subject = htmlspecialchars(trim($subject));
	}
	else if ($mode == 'newtopic' || ($mode == 'editpost' && $post_data['first_post']))
	{
		$error_msg .= (!empty($error_msg)) ? '<br />' . $lang['Empty_subject'] : $lang['Empty_subject'];
	}
	// Check Topic Desciption
	if ( !empty($topic_desc) )
   {
      $topic_desc = htmlspecialchars(trim($topic_desc));
   }

	// Check message
	if (!empty($message))
	{
		$bbcode_uid = ($bbcode_on) ? make_bbcode_uid() : '';
		$message = prepare_message(trim($message), $html_on, $bbcode_on, $smilies_on, $bbcode_uid);
	}
	else if ($mode != 'delete' && $mode != 'poll_delete') 
	{
		$error_msg .= (!empty($error_msg)) ? '<br />' . $lang['Empty_message'] : $lang['Empty_message'];
	}
	//-- mod : calendar --------------------------------------------------------------------------------
//-- add
	//
	// check calendar date
	//
	if ((!empty($topic_calendar_time)) && ($mode == 'newtopic' || ($mode == 'editpost' && $post_data['first_post'])))
	{
		$year	= intval(date( 'Y', $topic_calendar_time));
		$month	= intval(date( 'm', $topic_calendar_time));
		$day	= intval(date( 'd', $topic_calendar_time));
		if (!checkdate($month, $day, $year))
		{
			$error_msg .= (!empty($error_msg) ? '<br />' : '') . sprintf($lang['Date_error'], $day, $month, $year);
		}
	}
//-- fin mod : calendar ----------------------------------------------------------------------------

	//
	// Handle poll stuff
	//
	if ($mode == 'newtopic' || ($mode == 'editpost' && $post_data['first_post']))
	{
		$poll_length = (isset($poll_length)) ? max(0, intval($poll_length)) : 0;

		if (!empty($poll_title))
		{
			$poll_title = htmlspecialchars(trim($poll_title));
		}

		if(!empty($poll_options))
		{
			$temp_option_text = array();
			foreach ($poll_options as $option_id => $option_text)
			{
				$option_text = trim($option_text);
				if (!empty($option_text))
				{
					$temp_option_text[intval($option_id)] = htmlspecialchars($option_text);
				}
			}
			$poll_options = $temp_option_text;

			if (count($poll_options) < 2)
			{
				$error_msg .= (!empty($error_msg)) ? '<br />' . $lang['To_few_poll_options'] : $lang['To_few_poll_options'];
			}
			else if (count($poll_options) > $board_config['max_poll_options']) 
			{
				$error_msg .= (!empty($error_msg)) ? '<br />' . $lang['To_many_poll_options'] : $lang['To_many_poll_options'];
			}
			else if ($poll_title == '')
			{
				$error_msg .= (!empty($error_msg)) ? '<br />' . $lang['Empty_poll_title'] : $lang['Empty_poll_title'];
			}
		}
	}

	return;
}

//
// Post a new topic/reply/poll or edit existing post/poll
//
//-- mod : announces -------------------------------------------------------------------------------
// here we have added
//	, $topic_announce_duration = 0
//-- modify
//-- mod : post icon -------------------------------------------------------------------------------
// here we added
//	, $post_icon = 0
//-- modify
//-- mod : calendar --------------------------------------------------------------------------------
// here we have added
//	, $topic_calendar_time = 0, $topic_calendar_duration = 0
//-- modify

function submit_post($mode, &$post_data, &$message, &$meta, &$forum_id, &$topic_id, &$post_id, &$poll_id, &$topic_type, &$bbcode_on, &$html_on, &$smilies_on, &$attach_sig, &$bbcode_uid, $post_username, $post_subject, $post_message, $poll_title, &$poll_options, &$poll_length, &$topic_desc, $topic_announce_duration = 0, $post_icon = 0, $topic_calendar_time = 0, $topic_calendar_duration = 0, &$news_category = 0)
{
	global $board_config, $lang, $phpbb_root_path, $phpEx;
	global $userdata, $user_ip;
	global $ctracker_config;

	if (!in_array($mode, array('newtopic', 'reply', 'editpost'), true)) { message_die(GENERAL_MESSAGE, $lang['No_valid_mode']); }
	// CrackerTracker v5.x
	if ( ($mode == 'newtopic' || $mode == 'reply') && ($ctracker_config->settings['spammer_blockmode'] > 0 || $ctracker_config->settings['spam_attack_boost'] == 1) && $userdata['user_id'] != ANONYMOUS )
	{
		include_once($phpbb_root_path . 'ctracker/classes/class_ct_userfunctions.' . $phpEx);
		$login_functions = new ct_userfunctions();
		$login_functions->handle_postings();
		unset($login_functions);
	}
	require_once dirname(__FILE__) . '/functions_posting_storage.php';
	$lock = attach_require_mutation_lock($GLOBALS['db']);
	try
	{
		$db = $lock->connection;
		phpbb_posting_revalidate($db, $mode, $post_data, $forum_id, $topic_id, $post_id, $poll_id);
		// Request data is slash-normalized by common.php for legacy callers. Undo
		// that representation once, then let the active driver quote SQL values.
		$post_username_sql = $db->sql_escape(stripslashes((string) $post_username));
		$post_subject_sql = $db->sql_escape(stripslashes((string) $post_subject));
		$post_message_sql = $db->sql_escape(stripslashes((string) $post_message));
		$poll_title_sql = $db->sql_escape(stripslashes((string) $poll_title));
		$topic_desc_sql = $db->sql_escape(stripslashes((string) $topic_desc));
		$bbcode_uid_sql = $db->sql_escape((string) $bbcode_uid);
		$user_ip_sql = $db->sql_escape((string) $user_ip);

		// BEGIN cmx_slash_news_mod
		if( isset( $news_category ) && is_numeric( $news_category ) )
		{
			$news_id = intval( $news_category );
			$topic_type = POST_NEWS;
		}
		else
		{
			$news_id = 0;
		}
	// END cmx_slash_news_mod
		require_once($phpbb_root_path . 'includes/functions_search.'.$phpEx);

		$current_time = time();

		if ($mode == 'newtopic' || $mode == 'reply' || $mode == 'editpost')
		{
			//
			// Flood control
			//
			$where_sql = ($userdata['user_id'] == ANONYMOUS) ? "poster_ip = '$user_ip_sql'" : 'poster_id = ' . $userdata['user_id'];
			$sql = "SELECT MAX(post_time) AS last_post_time
				FROM " . POSTS_TABLE . "
				WHERE $where_sql";
			if (!($result = $db->sql_query($sql)))
			{
				message_die(GENERAL_ERROR, $lang['Posting_storage_failed'], '', __LINE__, __FILE__, $sql);
			}
			if ($row = $db->sql_fetchrow($result))
			{
				if (intval($row['last_post_time']) > 0 && ($current_time - intval($row['last_post_time'])) < intval($board_config['flood_interval']))
				{
					message_die(GENERAL_MESSAGE, $lang['Flood_Error']);
				}
			}
			$db->sql_freeresult($result);
		}

		if ($mode == 'newtopic' || ($mode == 'editpost' && $post_data['first_post']))
		{
			$topic_vote = (!empty($poll_title) && count($poll_options) >= 2) ? 1 : 0;
			//-- mod : announces -------------------------------------------------------------------------------
	// here we added
	//	topic_announce_duration,
	//	$topic_announce_duration,
	//
	// and
	//	, topic_announce_duration = $topic_announce_duration
	//-- modify
	//-- mod : post icon -------------------------------------------------------------------------------
	// here we added
	//	, topic_icon
	//	, $post_icon
	//
	// and
	//	, topic_icon = $post_icon
	//-- modify
	//-- mod : calendar --------------------------------------------------------------------------------
	// here we have added
	//	, topic_calendar_time, topic_calendar_duration
	//	, $topic_calendar_time, $topic_calendar_duration
	// and
	//	, topic_calendar_time = $topic_calendar_time, topic_calendar_duration = $topic_calendar_duration
	//-- modify

			$sql  = ($mode != "editpost") ? "INSERT INTO " . TOPICS_TABLE . " (topic_title, topic_desc, topic_poster, topic_time, forum_id, news_id, topic_status, topic_type, topic_calendar_time, topic_calendar_duration, topic_icon, topic_announce_duration, topic_vote) SELECT '$post_subject_sql', '$topic_desc_sql', " . $userdata['user_id'] . ", $current_time, $forum_id, $news_id, " . TOPIC_UNLOCKED . ", $topic_type, $topic_calendar_time, $topic_calendar_duration, $post_icon, $topic_announce_duration, $topic_vote FROM " . FORUMS_TABLE . " WHERE forum_id = $forum_id" : "UPDATE " . TOPICS_TABLE . " SET topic_title = '$post_subject_sql', topic_desc = '$topic_desc_sql', news_id = $news_id, topic_type = $topic_type, topic_calendar_time = $topic_calendar_time, topic_calendar_duration = $topic_calendar_duration, topic_icon=$post_icon, topic_announce_duration = $topic_announce_duration " . ((!empty($post_data['edit_poll']) || !empty($poll_title)) ? ", topic_vote = " . $topic_vote : "") . " WHERE topic_id = $topic_id AND forum_id = $forum_id";
			if (!$db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Error in posting', '', __LINE__, __FILE__, $sql);
			}

			if ($mode == 'newtopic')
			{
				if ((int) $db->sql_affectedrows() !== 1) { message_die(GENERAL_MESSAGE, $lang['Posting_target_changed']); }
				$topic_id = $db->sql_nextid();
			}
		}

		$edited_sql = ($mode == 'editpost' && !$post_data['last_post'] && $post_data['poster_post']) ? ", post_edit_time = $current_time, post_edit_count = post_edit_count + 1 " : "";
		//-- mod : post icon -------------------------------------------------------------------------------
	// here we added
	// , post_icon
	// , $post_icon
	//
	// and
	//  , post_icon = $post_icon
	//-- modify

		$sql = ($mode != "editpost") ? "INSERT INTO " . POSTS_TABLE . " (topic_id, forum_id, poster_id, post_username, post_time, poster_ip, enable_bbcode, enable_html, enable_smilies, enable_sig, post_icon) SELECT $topic_id, $forum_id, " . $userdata['user_id'] . ", '$post_username_sql', $current_time, '$user_ip_sql', $bbcode_on, $html_on, $smilies_on, $attach_sig, $post_icon FROM " . TOPICS_TABLE . " t JOIN " . FORUMS_TABLE . " f ON f.forum_id = t.forum_id WHERE t.topic_id = $topic_id AND t.forum_id = $forum_id AND t.topic_moved_id = 0" : "UPDATE " . POSTS_TABLE . " SET post_username = '$post_username_sql', enable_bbcode = $bbcode_on, enable_html = $html_on, enable_smilies = $smilies_on, enable_sig = $attach_sig, post_icon = $post_icon" . $edited_sql . " WHERE post_id = $post_id AND topic_id = $topic_id AND forum_id = $forum_id AND poster_id = " . (int) $post_data['poster_id'];
		if (!$db->sql_query($sql, BEGIN_TRANSACTION))
		{
			message_die(GENERAL_ERROR, 'Error in posting', '', __LINE__, __FILE__, $sql);
		}

		if ($mode != 'editpost')
		{
			if ((int) $db->sql_affectedrows() !== 1) { message_die(GENERAL_MESSAGE, $lang['Posting_target_changed']); }
			$post_id = $db->sql_nextid();
		}

		$storage_poster = $mode === 'editpost' ? (int) $post_data['poster_id'] : (int) $userdata['user_id'];
		$post_scope_sql = ' FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = p.topic_id AND t.forum_id = p.forum_id'
			. " WHERE p.post_id = $post_id AND p.topic_id = $topic_id AND p.forum_id = $forum_id AND p.poster_id = $storage_poster AND t.topic_moved_id = 0";
		$sql = ($mode != 'editpost') ? "INSERT INTO " . POSTS_TEXT_TABLE . " (post_id, post_subject, bbcode_uid, post_text) SELECT $post_id, '$post_subject_sql', '$bbcode_uid_sql', '$post_message_sql'" . $post_scope_sql : "UPDATE " . POSTS_TEXT_TABLE . " SET post_text = '$post_message_sql', bbcode_uid = '$bbcode_uid_sql', post_subject = '$post_subject_sql' WHERE post_id = $post_id AND EXISTS (SELECT 1" . $post_scope_sql . ')';
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Error in posting', '', __LINE__, __FILE__, $sql);
		}
		if ((int) $db->sql_affectedrows() === 0)
		{
			// An unchanged edit is valid; a vanished/changed target is not.
			$result = phpbb_posting_query($db, 'SELECT p.post_id' . $post_scope_sql . ' AND EXISTS (SELECT 1 FROM ' . POSTS_TEXT_TABLE . ' WHERE post_id = ' . $post_id . ')');
			$current_post = $db->sql_fetchrow($result); $db->sql_freeresult($result);
			if ($mode !== 'editpost' || !$current_post) { message_die(GENERAL_MESSAGE, $lang['Posting_target_changed']); }
		}

		if ($mode == 'editpost') { remove_search_post($post_id, true, true, $db); }
		add_search_words('single', $post_id, stripslashes($post_message), stripslashes($post_subject), $db);

		//
		// Add poll
		//
		if (($mode == 'newtopic' || ($mode == 'editpost' && $post_data['edit_poll'])) && !empty($poll_title) && count($poll_options) >= 2)
		{
			$sql = (!$post_data['has_poll']) ? "INSERT INTO " . VOTE_DESC_TABLE . " (topic_id, vote_text, vote_start, vote_length) VALUES ($topic_id, '$poll_title_sql', $current_time, " . ($poll_length * 86400) . ")" : "UPDATE " . VOTE_DESC_TABLE . " SET vote_text = '$poll_title_sql', vote_length = " . ($poll_length * 86400) . " WHERE topic_id = $topic_id";
			if (!$db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Error in posting', '', __LINE__, __FILE__, $sql);
			}

			$delete_option_sql = '';
			$old_poll_result = array();
			if ($mode == 'editpost' && $post_data['has_poll'])
			{
				$sql = "SELECT vote_option_id, vote_result
					FROM " . VOTE_RESULTS_TABLE . "
					WHERE vote_id = $poll_id
					ORDER BY vote_option_id ASC";
				if (!($result = $db->sql_query($sql)))
				{
					message_die(GENERAL_ERROR, 'Could not obtain vote data results for this topic', '', __LINE__, __FILE__, $sql);
				}

				while ($row = $db->sql_fetchrow($result))
				{
					$old_poll_result[$row['vote_option_id']] = $row['vote_result'];

					if (!isset($poll_options[$row['vote_option_id']]))
					{
						$delete_option_sql .= ($delete_option_sql != '') ? ', ' . $row['vote_option_id'] : $row['vote_option_id'];
					}
				}
			}
			else
			{
				$poll_id = $db->sql_nextid();
			}

			$poll_option_id = 1;
			foreach ($poll_options as $option_id => $option_text)
			{
				if (!empty($option_text))
				{
					$option_text = $db->sql_escape(stripslashes((string) $option_text));
					$poll_result = ($mode == "editpost" && isset($old_poll_result[$option_id])) ? $old_poll_result[$option_id] : 0;

					$sql = ($mode != "editpost" || !isset($old_poll_result[$option_id])) ? "INSERT INTO " . VOTE_RESULTS_TABLE . " (vote_id, vote_option_id, vote_option_text, vote_result) VALUES ($poll_id, $poll_option_id, '$option_text', $poll_result)" : "UPDATE " . VOTE_RESULTS_TABLE . " SET vote_option_text = '$option_text' WHERE vote_option_id = $option_id AND vote_id = $poll_id";
					if (!$db->sql_query($sql))
					{
						message_die(GENERAL_ERROR, 'Error in posting', '', __LINE__, __FILE__, $sql);
					}
					$poll_option_id++;
				}
			}

			if ($delete_option_sql != '')
			{
				$sql = "DELETE FROM " . VOTE_RESULTS_TABLE . "
					WHERE vote_option_id IN ($delete_option_sql)
						AND vote_id = $poll_id";
				if (!$db->sql_query($sql))
				{
					message_die(GENERAL_ERROR, 'Error deleting pruned poll options', '', __LINE__, __FILE__, $sql);
				}
			}
		}
		if ($mode !== 'editpost')
		{
			$user_id = (int) $userdata['user_id'];
			update_post_stats($mode, $post_data, $forum_id, $topic_id, $post_id, $user_id, $db, false);
		}
	}
	finally { $lock->release(); }
//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
	board_stats();
	cache_tree(true);
//-- fin mod : categories hierarchy ----------------------------------------------------------------
	$meta = '<meta http-equiv="refresh" content="3;url=' . append_sid("viewtopic.$phpEx?" . POST_POST_URL . "=" . $post_id) . '#' . $post_id . '">';
	$message = $lang['Stored'] . '<br /><br />' . sprintf($lang['Click_view_message'], '<a href="' . append_sid("viewtopic.$phpEx?" . POST_POST_URL . "=" . $post_id) . '#' . $post_id . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_forum'], '<a href="' . append_sid("viewforum.$phpEx?" . POST_FORUM_URL . "=$forum_id") . '">', '</a>');

	return false;
}

//
// Update post stats and details
//
function update_post_stats(&$mode, &$post_data, &$forum_id, &$topic_id, &$post_id, &$user_id, $database = null, $refresh_cache = true)
{
	// Server-created request state, never a form field. This also prevents an
	// older controller from recounting during per-file deployment overlap.
	$stats_signature = $mode . ':' . (int) $post_id;
	if (isset($post_data['_stats_completed']) && $post_data['_stats_completed'] === $stats_signature) { return; }
	$db = $database !== null ? $database : $GLOBALS['db'];

	$sql = 'SELECT count_posts FROM ' . FORUMS_TABLE . " WHERE forum_id = $forum_id";
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not obtain forum post-count setting', '', __LINE__, __FILE__, $sql);
	}
	$forum_information = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$forum_information)
	{
		message_die(GENERAL_ERROR, 'Could not obtain forum post-count setting');
	}
	// This option controls personal/rank counts, not the actual totals used
	// for forum/topic display and pagination.
	$count_posts = !empty($forum_information['count_posts']);
	$sign = ($mode == 'delete') ? '- 1' : '+ 1';
	$forum_update_sql = ($mode == 'delete') ? 'forum_posts = CASE WHEN forum_posts > 0 THEN forum_posts - 1 ELSE 0 END' : 'forum_posts = forum_posts + 1';
	$topic_update_sql = '';

	if ($mode == 'delete')
	{
		if ($post_data['last_post'])
		{
			if ($post_data['first_post'])
			{
				$forum_update_sql .= ', forum_topics = CASE WHEN forum_topics > 0 THEN forum_topics - 1 ELSE 0 END';
			}
			else
			{

				$topic_update_sql .= 'topic_replies = CASE WHEN topic_replies > 0 THEN topic_replies - 1 ELSE 0 END';

				$sql = "SELECT MAX(post_id) AS last_post_id
					FROM " . POSTS_TABLE . " 
					WHERE topic_id = $topic_id";
				if (!($result = $db->sql_query($sql)))
				{
					message_die(GENERAL_ERROR, 'Error in deleting post', '', __LINE__, __FILE__, $sql);
				}
				if ($row = $db->sql_fetchrow($result))
				{
					$topic_update_sql .= ', topic_last_post_id = ' . (int) $row['last_post_id'];
				}
			}

			if ($post_data['last_topic'])
			{
				$sql = "SELECT MAX(post_id) AS last_post_id
					FROM " . POSTS_TABLE . " 
					WHERE forum_id = $forum_id"; 
				if (!($result = $db->sql_query($sql)))
				{
					message_die(GENERAL_ERROR, 'Error in deleting post', '', __LINE__, __FILE__, $sql);
				}

				if ($row = $db->sql_fetchrow($result))
				{
					$forum_update_sql .= ($row['last_post_id']) ? ', forum_last_post_id = ' . $row['last_post_id'] : ', forum_last_post_id = 0';
				}
			}
		}
		else if ($post_data['first_post']) 
		{
			$sql = "SELECT MIN(post_id) AS first_post_id
				FROM " . POSTS_TABLE . " 
				WHERE topic_id = $topic_id";
			if (!($result = $db->sql_query($sql)))
			{
				message_die(GENERAL_ERROR, 'Error in deleting post', '', __LINE__, __FILE__, $sql);
			}

			if ($row = $db->sql_fetchrow($result))
			{
				$topic_update_sql .= 'topic_replies = CASE WHEN topic_replies > 0 THEN topic_replies - 1 ELSE 0 END, topic_first_post_id = ' . (int) $row['first_post_id'];
			}
		}
		else
		{
			$topic_update_sql .= 'topic_replies = CASE WHEN topic_replies > 0 THEN topic_replies - 1 ELSE 0 END';
		}
	}
	else if ($mode != 'poll_delete')
	{
		$forum_update_sql .= ", forum_last_post_id = $post_id" . (($mode == 'newtopic') ? ", forum_topics = forum_topics $sign" : ""); 
		$topic_update_sql = "topic_last_post_id = $post_id" . (($mode == 'reply') ? ", topic_replies = topic_replies $sign" : ", topic_first_post_id = $post_id");
	}
	else 
	{
		$topic_update_sql .= 'topic_vote = 0';
	}

	if ($mode != 'poll_delete')
	{
		$sql = "UPDATE " . FORUMS_TABLE . " SET 
			$forum_update_sql 
			WHERE forum_id = $forum_id";
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Error in posting', '', __LINE__, __FILE__, $sql);
		}
	}

	if ($topic_update_sql != '')
	{
		$sql = "UPDATE " . TOPICS_TABLE . " SET 
			$topic_update_sql 
			WHERE topic_id = $topic_id";
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Error in posting', '', __LINE__, __FILE__, $sql);
		}
	}

	if ($mode != 'poll_delete' && $count_posts && (int) $user_id > 0)
	{
		$user_update_sql = ($mode == 'delete') ? 'CASE WHEN user_posts > 0 THEN user_posts - 1 ELSE 0 END' : 'user_posts + 1';
		$sql = "UPDATE " . USERS_TABLE . "
			SET user_posts = $user_update_sql
			WHERE user_id = $user_id";
		if (!$db->sql_query($sql, END_TRANSACTION))
		{
			message_die(GENERAL_ERROR, 'Error in posting', '', __LINE__, __FILE__, $sql);
		}
	}
	//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
	$post_data['_stats_completed'] = $stats_signature;
	if ($refresh_cache) { board_stats(); cache_tree(true); }
//-- fin mod : categories hierarchy ----------------------------------------------------------------

	return;
}

//
// Delete a post/poll
//
// Delete storage only after the authorized parent selection succeeds. All
// queries here share the attachment publisher's owning connection, so a late
// upload cannot create a reference between parent deletion and file cleanup.
// This is not a transaction for the entire topic/search/statistics lifecycle.
function phpbb_delete_post_storage($database, $post_id, $topic_id, $forum_id)
{
	global $lang;
	$scope = array();
	foreach (array($post_id, $topic_id, $forum_id) as $id)
	{
		if ((!is_int($id) && !is_string($id)) || !preg_match('/^[0-9]+$/D', (string) $id))
		{
			message_die(GENERAL_MESSAGE, $lang['Topic_post_not_exist']);
		}
		$ids = attach_delete_id_array($id);
		if ($ids === false || count($ids) !== 1)
		{
			message_die(GENERAL_MESSAGE, $lang['Topic_post_not_exist']);
		}
		$scope[] = $ids[0];
	}

	$lock = attach_require_mutation_lock($database);
	try
	{
		phpbb_delete_post_storage_owned($lock->connection, $scope[0], $scope[1], $scope[2]);
	}
	finally { $lock->release(); }
}

// Internal worker: the caller has validated the IDs and owns the mutation lock.
function phpbb_delete_post_storage_owned($storage_db, $post_id, $topic_id, $forum_id, $poster_id = null)
{
	global $lang;
	$sql = 'DELETE FROM ' . POSTS_TABLE . ' WHERE post_id = ' . $post_id .
		' AND topic_id = ' . $topic_id . ' AND forum_id = ' . $forum_id . ($poster_id !== null ? ' AND poster_id = ' . (int) $poster_id : '');
	if (!$storage_db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Error in deleting post', '', __LINE__, __FILE__, $sql);
	}
	if ((int) $storage_db->sql_affectedrows() !== 1)
	{
		message_die(GENERAL_MESSAGE, $lang['Topic_post_not_exist']);
	}

	$sql = 'DELETE FROM ' . POSTS_TEXT_TABLE . ' WHERE post_id = ' . $post_id;
	if (!$storage_db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Error in deleting post', '', __LINE__, __FILE__, $sql);
	}
	attach_delete_selected($storage_db, array($post_id), array(), 0, 0, false, true);
	// The deleted parent can no longer identify its topic during attachment sync.
	attachment_sync_topic($topic_id, $storage_db);
}

// Topic preferences belong to the topic, not to its most recent reply.
// Each write verifies actual topic absence instead of trusting form flags.
function phpbb_cleanup_removed_topic_preferences($database, $topic_id)
{
	global $lang;
	if ((!is_int($topic_id) && !is_string($topic_id)) || !preg_match('/^[0-9]+$/D', (string) $topic_id))
	{
		message_die(GENERAL_MESSAGE, $lang['Topic_post_not_exist']);
	}
	$ids = attach_delete_id_array($topic_id);
	if ($ids === false || count($ids) !== 1)
	{
		message_die(GENERAL_MESSAGE, $lang['Topic_post_not_exist']);
	}
	foreach (array(TOPICS_WATCH_TABLE, BOOKMARK_TABLE, TOPIC_VIEW_TABLE) as $table)
	{
		$sql = 'DELETE FROM ' . $table . ' WHERE topic_id = ' . $ids[0] .
			' AND NOT EXISTS (SELECT 1 FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $ids[0] . ')';
		if (!$database->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Error in deleting topic preferences', '', __LINE__, __FILE__, $sql);
		}
	}
}

function delete_post($mode, &$post_data, &$message, &$meta, &$forum_id, &$topic_id, &$post_id, &$poll_id)
{
	global $board_config, $lang, $phpbb_root_path, $phpEx;
	global $userdata, $user_ip;

	if (!in_array($mode, array('delete', 'poll_delete'), true)) { message_die(GENERAL_MESSAGE, $lang['No_valid_mode']); }
	require_once dirname(__FILE__) . '/functions_posting_storage.php';
	$lock = attach_require_mutation_lock($GLOBALS['db']);
	try
	{
		$db = $lock->connection;
		phpbb_posting_revalidate($db, $mode, $post_data, $forum_id, $topic_id, $post_id, $poll_id);
		if ($mode != 'poll_delete')
		{
			require_once($phpbb_root_path . 'includes/functions_search.'.$phpEx);

			phpbb_delete_post_storage_owned($db, $post_id, $topic_id, $forum_id, $post_data['poster_id']);

			if ($post_data['last_post'])
			{
				if ($post_data['first_post'])
				{
					$sql = "DELETE FROM " . TOPICS_TABLE . "
						WHERE topic_id = $topic_id AND forum_id = $forum_id
							AND NOT EXISTS (SELECT 1 FROM " . POSTS_TABLE . " WHERE " . POSTS_TABLE . ".topic_id = " . TOPICS_TABLE . ".topic_id)";
					if (!$db->sql_query($sql))
					{
						message_die(GENERAL_ERROR, 'Error in deleting post', '', __LINE__, __FILE__, $sql);
					}
					if ((int) $db->sql_affectedrows() !== 1) { message_die(GENERAL_MESSAGE, $lang['Posting_target_changed']); }

					phpbb_cleanup_removed_topic_preferences($db, $topic_id);
					phpbb_posting_cleanup_empty_redirects($db, $topic_id);
				}
			}

			remove_search_post($post_id, true, true, $db);
		}

		if ($mode == 'poll_delete' || ($mode == 'delete' && $post_data['first_post'] && $post_data['last_post']) && $post_data['has_poll'])
		{
			$sql = "DELETE FROM " . VOTE_DESC_TABLE . "
				WHERE topic_id = $topic_id";
			if (!$db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Error in deleting poll', '', __LINE__, __FILE__, $sql);
			}

			$sql = "DELETE FROM " . VOTE_RESULTS_TABLE . "
				WHERE vote_id = $poll_id";
			if (!$db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Error in deleting poll', '', __LINE__, __FILE__, $sql);
			}

			$sql = "DELETE FROM " . VOTE_USERS_TABLE . "
				WHERE vote_id = $poll_id";
			if (!$db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Error in deleting poll', '', __LINE__, __FILE__, $sql);
			}
		}

		$user_id = (int) $post_data['poster_id'];
		update_post_stats($mode, $post_data, $forum_id, $topic_id, $post_id, $user_id, $db, false);
		if ($mode === 'delete') { phpbb_posting_sync_forum($db, $forum_id); }
	}
	finally { $lock->release(); }

	if ($mode == 'delete' && $post_data['first_post'] && $post_data['last_post'])
	{
		$meta = '<meta http-equiv="refresh" content="3;url=' . append_sid("viewforum.$phpEx?" . POST_FORUM_URL . '=' . $forum_id) . '">';
		$message = $lang['Deleted'];
	}
	else
	{
		$meta = '<meta http-equiv="refresh" content="3;url=' . append_sid("viewtopic.$phpEx?" . POST_TOPIC_URL . '=' . $topic_id) . '">';
		$message = (($mode == 'poll_delete') ? $lang['Poll_delete'] : $lang['Deleted']) . '<br /><br />' . sprintf($lang['Click_return_topic'], '<a href="' . append_sid("viewtopic.$phpEx?" . POST_TOPIC_URL . "=$topic_id") . '">', '</a>');
	}

	$message .=  '<br /><br />' . sprintf($lang['Click_return_forum'], '<a href="' . append_sid("viewforum.$phpEx?" . POST_FORUM_URL . "=$forum_id") . '">', '</a>');
	//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
	board_stats();
	cache_tree(true);
//-- fin mod : categories hierarchy ----------------------------------------------------------------

	return;
}

//
// Handle user notification on new post
//
function user_notification($mode, &$post_data, &$topic_title, &$forum_id, &$topic_id, &$post_id, &$notify_user, $bookmark = false)
{
	global $db, $userdata, $lang;
	if ($mode === 'delete') { return; }
	require_once dirname(__FILE__) . '/functions_topic_notifications.php';
	try
	{
		if (!empty($userdata['session_logged_in']) && (int) $userdata['user_id'] > 0)
		{
			if ($bookmark) { phpbb_topic_preference($db, $topic_id, 'bookmark', true); }
			phpbb_topic_preference($db, $topic_id, 'watch', (bool) $notify_user);
		}
		if ($mode === 'reply') { phpbb_send_topic_notifications($db, $post_id); }
	}
	catch (PhpbbTopicPreferenceException $exception)
	{
		// The post is already stored. Do not invite duplicate resubmission by
		// claiming publication failed because its optional notification failed.
		error_log('phpBB topic notification/preference processing failed.');
	}
}

//
// Fill smiley templates (or just the variables) with smileys
// Either in a window or inline
//
function generate_smilies($mode, $page_id)
{
	global $db, $board_config, $template, $lang, $images, $theme, $phpEx, $phpbb_root_path;
	global $user_ip, $session_length, $starttime;
	global $userdata;

	$inline_columns = 4;
	$inline_rows = 5;
	$window_columns = 8;

	if ($mode == 'window')
	{
		$userdata = session_pagestart($user_ip, $page_id);
		init_userprefs($userdata);

		$gen_simple_header = TRUE;

		$page_title = $lang['Emoticons'];
		include($phpbb_root_path . 'includes/page_header.'.$phpEx);

		$template->set_filenames(array(
			'smiliesbody' => 'posting_smilies.tpl')
		);
	}

	$sql = "SELECT emoticon, code, smile_url   
		FROM " . SMILIES_TABLE . " 
		ORDER BY smilies_id";
	if ($result = $db->sql_query($sql))
	{
		$num_smilies = 0;
		$rowset = array();
		while ($row = $db->sql_fetchrow($result))
		{
			if (empty($rowset[$row['smile_url']]))
			{
				$rowset[$row['smile_url']]['code'] = str_replace("'", "\\'", str_replace('\\', '\\\\', $row['code']));
				$rowset[$row['smile_url']]['emoticon'] = $row['emoticon'];
				$num_smilies++;
			}
		}

		if ($num_smilies)
		{
			$smilies_count = ($mode == 'inline') ? min(19, $num_smilies) : $num_smilies;
			$smilies_split_row = ($mode == 'inline') ? $inline_columns - 1 : $window_columns - 1;

			$s_colspan = 0;
			$row = 0;
			$col = 0;

			foreach ($rowset as $smile_url => $data)
			{
				if (!$col)
				{
					$template->assign_block_vars('smilies_row', array());
				}

				$template->assign_block_vars('smilies_row.smilies_col', array(
					'SMILEY_CODE' => $data['code'],
					'SMILEY_IMG' => $board_config['smilies_path'] . '/' . $smile_url,
					'SMILEY_DESC' => $data['emoticon'])
				);

				$s_colspan = max($s_colspan, $col + 1);

				if ($col == $smilies_split_row)
				{
					if ($mode == 'inline' && $row == $inline_rows - 1)
					{
						break;
					}
					$col = 0;
					$row++;
				}
				else
				{
					$col++;
				}
			}

			if ($mode == 'inline' && $num_smilies > $inline_rows * $inline_columns)
			{
				$template->assign_block_vars('switch_smilies_extra', array());

				$template->assign_vars(array(
					'L_MORE_SMILIES' => $lang['More_emoticons'], 
					'U_MORE_SMILIES' => append_sid("posting.$phpEx?mode=smilies"))
				);
			}

			$template->assign_vars(array(
				'L_EMOTICONS' => $lang['Emoticons'], 
				'L_CLOSE_WINDOW' => $lang['Close_window'], 
				'S_SMILIES_COLSPAN' => $s_colspan)
			);
		}
	}

	if ($mode == 'window')
	{
		$template->pparse('smiliesbody');

		include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
	}
}

/**
* Called from within prepare_message to clean included HTML tags if HTML is
* turned on for that post
* @param array $tag Matching text from the message to parse
*/
function clean_html($tag)
{
	global $board_config;

	if (empty($tag[0]))
	{
		return '';
	}

	$allowed_html_tags = preg_split('/, */', strtolower($board_config['allow_html_tags']));
	$unsafe_html_tags = array('script', 'style', 'iframe', 'object', 'embed', 'applet', 'link', 'meta', 'base', 'form', 'input', 'button', 'textarea', 'select', 'option', 'svg', 'math');
	$disallowed_attributes = '/^(?:style|on|srcdoc$|formaction$)/i';
	$tag_name = strtolower($tag[1]);

	// Check if this is an end tag
	preg_match('/<[^\w\/]*\/[\W]*(\w+)/', $tag[0], $matches);
	if (sizeof($matches))
	{
		$end_tag_name = strtolower($matches[1]);
		if (in_array($end_tag_name, $allowed_html_tags) && !in_array($end_tag_name, $unsafe_html_tags))
		{
			return  '</' . $matches[1] . '>';
		}
		else
		{
			return  htmlspecialchars('</' . $matches[1] . '>');
		}
	}

	// Check if this is an allowed tag
	if (in_array($tag_name, $allowed_html_tags) && !in_array($tag_name, $unsafe_html_tags))
	{
		$attributes = '';
		if (!empty($tag[2]))
		{
			preg_match_all('/[\W]*?(\w+)[\W]*?=[\W]*?(["\'])((?:(?!\2).)*)\2/', $tag[2], $test);
			for ($i = 0; $i < sizeof($test[0]); $i++)
			{
				$attribute_name = strtolower($test[1][$i]);
				if (preg_match($disallowed_attributes, $attribute_name))
				{
					continue;
				}

				if (in_array($attribute_name, array('href', 'src', 'action', 'background', 'dynsrc', 'lowsrc', 'poster'), true))
				{
					$decoded_url = html_entity_decode($test[3][$i], ENT_QUOTES, 'UTF-8');
					$scheme_test = strtolower(preg_replace('/[\x00-\x20\x7f]+/', '', $decoded_url));
					if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $scheme_test) && !preg_match('/^(?:https?|ftp|mailto):/i', $scheme_test))
					{
						continue;
					}
				}

				$attributes .= ' ' . $attribute_name . '=' . $test[2][$i] . str_replace(array('[', ']'), array('&#91;', '&#93;'), htmlspecialchars($test[3][$i])) . $test[2][$i];
			}
		}
		if (in_array($tag_name, $allowed_html_tags) && !in_array($tag_name, $unsafe_html_tags))
		{
			return '<' . $tag[1] . $attributes . '>';
		}
		else
		{
			return htmlspecialchars('<' . $tag[1] . $attributes . '>');
		}
	}
	// Finally, this is not an allowed tag so strip all the attibutes and escape it
	else
	{
		return htmlspecialchars('<' .   $tag[1] . '>');
	}
}

?>
