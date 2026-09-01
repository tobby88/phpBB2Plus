<?php
/***************************************************************************
 *                             admin_kb_art.php
 *                            -------------------
 *   begin                : Monday, Mar 31, 2003
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: admin_kb_art.php,v 1.9 2004/05/02 08:25:02 jonohlsson Exp $
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

// MX
if ( !defined('IN_PHPBB') )
{
	die("Hacking attempt");
}

if ( !$is_admin )
{
   $message = $lang['No_add'] . '<br /><br />' . sprintf($lang['Click_return_kb'], '<a href="' . append_sid(this_kb_mxurl()) . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid($mx_root_path . "index.$phpEx") . '">', '</a>');
   message_die(GENERAL_MESSAGE, $message);
}

include($phpbb_root_path . 'includes/functions_admin.'.$phpEx);

$category_id = (isset($_REQUEST['cat']) && is_scalar($_REQUEST['cat'])) ? intval($_REQUEST['cat']) : 0;
$page_id = (isset($_REQUEST['page']) && is_scalar($_REQUEST['page'])) ? intval($_REQUEST['page']) : 0;
$ref_stats = ( isset($_GET['ref']) ) ? true : 0;

if ( isset($_POST['action']) || isset($_GET['action']) )
{
	$action_value = (isset($_POST['action']) && is_scalar($_POST['action'])) ? $_POST['action'] : ((isset($_GET['action']) && is_scalar($_GET['action'])) ? $_GET['action'] : '');
	$action = (string) $action_value;
}

else
{
	if (!empty($approve))
	{
		$action = 'approve';
	}
	else if (!empty($unapprove))
	{
		$action = 'unapprove';
	}
	else if (!empty($delete))
	{
		$action = 'delete';
	}
	else
	{
		$action = '';
	}
}

$article_id = (isset($_REQUEST['a']) && is_scalar($_REQUEST['a'])) ? intval($_REQUEST['a']) : 0;
$confirmed = isset($_POST['confirm_action']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sid']) && is_scalar($_POST['sid']) && hash_equals((string) $userdata['session_id'], (string) $_POST['sid']);
if (in_array($action, array('approve', 'unapprove', 'delete'), true))
{
	if ($article_id <= 0)
	{
		message_die(GENERAL_MESSAGE, $lang['Article_not_exsist']);
	}
	$sql = 'SELECT * FROM ' . KB_ARTICLES_TABLE . ' WHERE article_id = ' . $article_id;
	if (!($moderated_result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not obtain article data', '', __LINE__, __FILE__, $sql);
	}
	$moderated_article = $db->sql_fetchrow($moderated_result);
	$db->sql_freeresult($moderated_result);
	if (!$moderated_article)
	{
		message_die(GENERAL_MESSAGE, $lang['Article_not_exsist']);
	}
	if ($category_id <= 0)
	{
		$category_id = intval($moderated_article['article_category_id']);
	}
	if (!$confirmed)
	{
		$action_labels = array('approve' => $lang['Approve'], 'unapprove' => $lang['Unapprove'], 'delete' => $lang['Delete']);
		$confirm_text = ($action === 'delete') ? $lang['Confirm_art_delete'] : sprintf($lang['Confirm_art_action'], $action_labels[$action]);
		$confirm_url = append_sid($phpbb_root_path . "kb.$phpEx?mode=moderate");
		$confirm_message = $confirm_text . '<br /><br /><form method="post" action="' . htmlspecialchars($confirm_url, ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="action" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" /><input type="hidden" name="a" value="' . $article_id . '" /><input type="hidden" name="cat" value="' . $category_id . '" /><input type="hidden" name="page" value="' . $page_id . '" /><input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" /><input type="submit" name="confirm_action" value="' . htmlspecialchars($lang['Yes'], ENT_QUOTES, 'UTF-8') . '" />&nbsp;&nbsp;<a href="' . append_sid($phpbb_root_path . "kb.$phpEx?mode=cat&cat=$category_id") . '">' . $lang['No'] . '</a></form>';
		message_die(GENERAL_MESSAGE, $confirm_message);
	}
}

switch( $action )
{

 	case 'approve':
	
	$topic_sql = '';
	if ( $kb_config['comments'] )
	{
	    $sql = "SELECT * FROM " . KB_ARTICLES_TABLE . " WHERE article_id = " . $article_id;	
		if ( !($results = $db->sql_query($sql)) )
		{
	        message_die(GENERAL_ERROR, "Could not obtain article data", '', __LINE__, __FILE__, $sql);
		}

		$row = $db->sql_fetchrow($results);
	
		if ( !$row['topic_id'] )
		{		
		    // choose a user
			$user_id = $kb_config['admin_id'];

			// initialise the userdata
			$sql = "SELECT * FROM " . USERS_TABLE . " WHERE user_id = $user_id";
			if ( !($result = $db->sql_query($sql)) )
			{
	   	        message_die(CRITICAL_ERROR, 'Could not obtain lastvisit data from user table', '', __LINE__, __FILE__, $sql);
			}
			$user = $db->sql_fetchrow($result);
			init_userprefs($user);
			
			$kb_cat = get_kb_cat($row['article_category_id']);
		    $type = get_kb_type($row['article_type']);
		  	$author = get_kb_author($row['article_author_id']);

		  	$search = array (
                 "'&(quot|#34);'i",                	// Replace HTML entities
                 "'&(amp|#38);'i",
                 "'&(lt|#60);'i",
                 "'&(gt|#62);'i"
				 );                    
		  	$replace = array (
                 "\"",
                 "&",
                 "<",
                 ">"
				 );

			$temp_url = phpbb_board_url('kb.' . $phpEx . '?mode=article&k=' . $article_id);
		  	$message = "[b]" . $lang['Category'] . ":[/b] "  . $kb_cat['category_name'] . "\n";
		  	$message .= "[b]" . $lang['Article_type'] . ":[/b] " . $type . "\n\n";
		  	$message .= "[b]" . $lang['Article_title'] . ":[/b] " . preg_replace($search, $replace, $row['article_title']) . "\n";
		  	$message .= "[b]" . $lang['Author'] . ":[/b] " . $author . "\n";
		  	$message .= "[b]" . $lang['Article_description'] . ":[/b] " . preg_replace($search, $replace, $row['article_description']) . "\n\n";
		  	$message .= "[b][url=" . $temp_url . "]" . $lang['Read_full_article'] . "[/url][/b]";

		  	$subject = '[ KB ] ' . $row['article_title'];

			$forum_id = $kb_config['forum_id'];
	
			$topic_data = insert_post($message, $subject, $forum_id, $user['user_id'], $user['username'], $user['user_attachsig']);
			$topic_sql = ", topic_id = " . $topic_data['topic_id'];
		}
	}
		
	$sql = "UPDATE " . KB_ARTICLES_TABLE .
		 " SET approved = 1 " . $topic_sql . "
		 WHERE article_id = " . $article_id;
		 
	if ( !($result = $db->sql_query($sql)) )
	{
   	   message_die(GENERAL_ERROR, "Could not update article data", '', __LINE__, __FILE__, $sql);
	}
	
	$sql = "SELECT article_category_id, article_body 
	 FROM " . KB_ARTICLES_TABLE . "
	 WHERE article_id = " . $article_id;

	 if ( !($result = $db->sql_query($sql)) )
	 {
   	  	message_die(GENERAL_ERROR, "Could not obtain article category", '', __LINE__, __FILE__, $sql);
	 }

	 if ( $article = $db->sql_fetchrow($result) )
	 {
	  	$article_category_id = $article['article_category_id'];
		$body = $article['article_body'];
	 }
	 
	 if (empty($moderated_article['approved']))
	 {
		update_kb_number($article_category_id, '+ 1');
		add_kb_words($article_id, $body);
	 }
	
	$message = $lang['Article_approved'] . '<br /><br />' . sprintf($lang['Click_return_article_manager'], '<a href="' . append_sid($phpbb_root_path ."kb.$phpEx?mode=cat&cat=$article_category_id") . '">', '</a>') ;

	message_die(GENERAL_MESSAGE, $message);
	break;

	case 'unapprove':
	
	$sql = "UPDATE " . KB_ARTICLES_TABLE .
		 " SET approved = 0
		 WHERE article_id = " . $article_id;
		 
	if ( !($result = $db->sql_query($sql)) )
	{
   	   message_die(GENERAL_ERROR, "Could not update article data", '', __LINE__, __FILE__, $sql);
	}
	
	$sql = "SELECT article_category_id 
	 FROM " . KB_ARTICLES_TABLE . "
	 WHERE article_id = " . $article_id;

	 if ( !($result = $db->sql_query($sql)) )
	 {
   	  	message_die(GENERAL_ERROR, "Could not obtain article category", '', __LINE__, __FILE__, $sql);
	 }

	 if ( $article = $db->sql_fetchrow($result) )
	 {
	  	$article_category_id = $article['article_category_id'];
	 }
	 
	 if (!empty($moderated_article['approved']))
	 {
		update_kb_number($article_category_id, '- 1');
	 }
	
	$message = $lang['Article_unapproved'] . '<br /><br />' . sprintf($lang['Click_return_article_manager'], '<a href="' . append_sid($phpbb_root_path ."kb.$phpEx?mode=cat&cat=$article_category_id") . '">', '</a>') ;

	message_die(GENERAL_MESSAGE, $message);
	break;
	
	case 'delete':
	
	if ($confirmed)
	{	
	$sql = "SELECT article_category_id, approved, topic_id  
	 FROM " . KB_ARTICLES_TABLE . "
	 WHERE article_id = " . $article_id;

	 if ( !($result = $db->sql_query($sql)) )
	 {
   	  	message_die(GENERAL_ERROR, "Could not obtain article category", '', __LINE__, __FILE__, $sql);
	 }

	 if ( $article = $db->sql_fetchrow($result) )
	 {
	  	$article_category_id = $article['article_category_id'];
	 }
	
	if ($article['approved'] == 1)
	{
	 	update_kb_number($article_category_id, '- 1');
	}
	
	if ( $kb_config['del_topic'] && $article['topic_id'] )
	{
			$topic = $article['topic_id'];

			$sql = "SELECT poster_id, COUNT(post_id) AS posts 
				FROM " . POSTS_TABLE . " 
				WHERE topic_id = " . $topic . "  
				GROUP BY poster_id";
			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Could not get poster id information', '', __LINE__, __FILE__, $sql);
			}

			$count_sql = array();
			while ( $row = $db->sql_fetchrow($result) )
			{
				$count_sql[] = "UPDATE " . USERS_TABLE . " 
					SET user_posts = user_posts - " . $row['posts'] . " 
					WHERE user_id = " . $row['poster_id'];
			}
			$db->sql_freeresult($result);

			if ( sizeof($count_sql) )
			{
				for($i = 0; $i < sizeof($count_sql); $i++)
				{
					if ( !$db->sql_query($count_sql[$i]) )
					{
						message_die(GENERAL_ERROR, 'Could not update user post count information', '', __LINE__, __FILE__, $sql);
					}
				}
			}
			
			$sql = "SELECT forum_id 
			    FROM " . TOPICS_TABLE . "
				WHERE topic_id = $topic";
				
			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Could not get forum id information', '', __LINE__, __FILE__, $sql);
			}

			$forum_id = array();
			while ( $row = $db->sql_fetchrow($result) )
			{
				$forum_id = $row['forum_id'];
			}
			$db->sql_freeresult($result);
			
			$sql = "SELECT post_id 
				FROM " . POSTS_TABLE . " 
				WHERE topic_id = $topic";
			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Could not get post id information', '', __LINE__, __FILE__, $sql);
			}

			$post_array = array();
			$ii = 0;
			$post_id_sql = '';
			while ( $row = $db->sql_fetchrow($result) )
			{
				$post_array[$ii] = $row['post_id'];
				$post_id_sql .= ( ( $post_id_sql != '' ) ? ', ' : '' ) . $row['post_id'];
				$ii++;
			}
			$db->sql_freeresult($result);

			//
			// Got all required info so go ahead and start deleting everything
			//
			$sql = "DELETE 
				FROM " . TOPICS_TABLE . " 
				WHERE topic_id = $topic 
					OR topic_moved_id = $topic";
			if ( !$db->sql_query($sql, BEGIN_TRANSACTION) )
			{
				message_die(GENERAL_ERROR, 'Could not delete topics', '', __LINE__, __FILE__, $sql);
			}

			if ( $post_id_sql != '' )
			{
				$sql = "DELETE 
					FROM " . POSTS_TABLE . " 
					WHERE topic_id = $topic";
				if ( !$db->sql_query($sql) )
				{
					message_die(GENERAL_ERROR, 'Could not delete posts', '', __LINE__, __FILE__, $sql);
				}

				for ($i = 0; $i < count($post_array); $i++)
				{
					$sql = "DELETE 
						FROM " . POSTS_TEXT_TABLE . " 
						WHERE post_id = $post_array[$i]";
					if ( !$db->sql_query($sql) )
					{
						message_die(GENERAL_ERROR, 'Could not delete posts text', '', __LINE__, __FILE__, $sql);
					}
				}

				remove_search_post($post_id_sql);
			}

			$sql = "DELETE 
				FROM " . TOPICS_WATCH_TABLE . " 
				WHERE topic_id = $topic";
			if ( !$db->sql_query($sql, END_TRANSACTION) )
			{
				message_die(GENERAL_ERROR, 'Could not delete watched post list', '', __LINE__, __FILE__, $sql);
			}
			if ( !empty($forum_id) )
			{
				sync('forum', $forum_id);
			}

	}
	
	$sql = "DELETE FROM  " . KB_ARTICLES_TABLE .
		 " WHERE article_id = " . $article_id;
		 
	if ( !($result = $db->sql_query($sql)) )
	{
   	   message_die(GENERAL_ERROR, "Could not delete article data", '', __LINE__, __FILE__, $sql);
	}	

	$sql = "DELETE FROM  " . KB_MATCH_TABLE .
		 " WHERE article_id = " . $article_id;
		 
	if ( !($result = $db->sql_query($sql)) )
	{
   	   message_die(GENERAL_ERROR, "Could not delete article wordmatch data", '', __LINE__, __FILE__, $sql);
	}	

	$sql = "DELETE FROM " . KB_VOTES_TABLE . "
		WHERE votes_file = " . $article_id;
	if (!$db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, "Could not delete article vote data", '', __LINE__, __FILE__, $sql);
	}
	
	$message = $lang['Article_deleted'] . '<br /><br />' . sprintf($lang['Click_return_article_manager'], '<a href="' . append_sid($phpbb_root_path . "kb.$phpEx?mode=cat&cat=$article_category_id") . '">', '</a>') ;

	message_die(GENERAL_MESSAGE, $message);
	}
	break;
}

$template->pparse('body');


?>
