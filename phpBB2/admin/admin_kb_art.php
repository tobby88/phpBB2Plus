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

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if( !empty($setmodules) )
{
	$file = basename(__FILE__);
	$module['KB_title']['Art_man'] = $file;
	return;
}

//
// Load default header
//
$phpbb_root_path = "./../";
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
include($phpbb_root_path . 'includes/functions_admin.'.$phpEx);
include($phpbb_root_path . 'includes/kb_constants.'.$phpEx);
include($phpbb_root_path . 'includes/functions_kb.'.$phpEx);
include_once($phpbb_root_path . 'includes/bbcode.'.$phpEx);
include_once($phpbb_root_path . 'includes/functions_post.'.$phpEx);
include_once($phpbb_root_path . 'includes/functions_search.'.$phpEx);

//
// Pull all config data
//
$sql = "SELECT *
	FROM " . KB_CONFIG_TABLE;
if(!$result = $db->sql_query($sql))
{
	message_die(CRITICAL_ERROR, "Could not query config information in kb_config", "", __LINE__, __FILE__, $sql);
}
else
{
	while( $row = $db->sql_fetchrow($result) )
	{
		$config_name = $row['config_name'];
		$config_value = $row['config_value'];
		$kb_config[$config_name] = $config_value;
    }
}

$approve = !empty($_POST['approve']);
$unapprove = !empty($_POST['unapprove']);
$delete = !empty($_POST['delete']);
$article_id = isset($_POST['a']) ? (int) $_POST['a'] : (isset($_GET['a']) ? (int) $_GET['a'] : 0);

if ( isset($_POST['mode']) || isset($_GET['mode']) )
{
	$mode = ( isset($_POST['mode']) ) ? $_POST['mode'] : $_GET['mode'];
}
else
{
	if ( $approve )
	{
		$mode = 'approve';
	}
	else if ( $unapprove )
	{
		$mode = 'unapprove';
	}
	else if ( $delete )
	{
		$mode = 'delete';
	}
	else
	{
		$mode = '';
	}
}

$managed_article = false;
if (in_array($mode, array('approve', 'unapprove', 'delete'), true))
{
	if ($article_id <= 0)
	{
		message_die(GENERAL_MESSAGE, $lang['No_Articles']);
	}
	$sql = 'SELECT * FROM ' . KB_ARTICLES_TABLE . ' WHERE article_id = ' . $article_id;
	if (!($managed_result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not obtain article data', '', __LINE__, __FILE__, $sql);
	}
	$managed_article = $db->sql_fetchrow($managed_result);
	$db->sql_freeresult($managed_result);
	if (!$managed_article)
	{
		message_die(GENERAL_MESSAGE, $lang['No_Articles']);
	}
}

switch( $mode )
{

	case 'approve':
	phpbb_admin_require_post_session();
	
	$topic_sql = '';
	if ( $kb_config['comments'] )
	{
		$row = $managed_article;
	
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
	
	$article_category_id = (int) $managed_article['article_category_id'];
	if ((int) $managed_article['approved'] !== 1)
	{
		update_kb_number($article_category_id, '+ 1');
		add_kb_words($article_id, $managed_article['article_body'], $managed_article['article_title']);
	}
	
	$message = $lang['Article_approved'] . '<br /><br />' . sprintf($lang['Click_return_article_manager'], '<a href="' . append_sid("admin_kb_art.$phpEx") . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid($phpbb_root_path . "admin/index.$phpEx?pane=right") . '">', '</a>');

	message_die(GENERAL_MESSAGE, $message);
	break;

	case 'unapprove':
	phpbb_admin_require_post_session();
	
	$sql = "UPDATE " . KB_ARTICLES_TABLE .
		 " SET approved = 0
		 WHERE article_id = " . $article_id;
		 
	if ( !($result = $db->sql_query($sql)) )
	{
   	   message_die(GENERAL_ERROR, "Could not update article data", '', __LINE__, __FILE__, $sql);
	}
	
	$article_category_id = (int) $managed_article['article_category_id'];
	if ((int) $managed_article['approved'] === 1)
	{
		update_kb_number($article_category_id, '- 1');
	}
	kb_remove_article_words($article_id);
	
	$message = $lang['Article_unapproved'] . '<br /><br />' . sprintf($lang['Click_return_article_manager'], '<a href="' . append_sid("admin_kb_art.$phpEx") . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid($phpbb_root_path . "admin/index.$phpEx?pane=right") . '">', '</a>');

	message_die(GENERAL_MESSAGE, $message);
	break;
	
	case 'delete':
	
	if (isset($_POST['c']) && $_POST['c'] == "yes")
	{	
	phpbb_admin_require_post_session();
	$article = $managed_article;
	$article_category_id = (int) $article['article_category_id'];
	
	if ($article['approved'] == 1)
	{
	 	update_kb_number($article_category_id, '- 1');
	}
	
	if (!empty($kb_config['del_topic']) && !empty($article['topic_id']))
	{
		kb_delete_discussion_topic((int) $article['topic_id']);
	}
	
	$sql = "DELETE FROM  " . KB_ARTICLES_TABLE .
		 " WHERE article_id = " . $article_id;
		 
	if ( !($result = $db->sql_query($sql)) )
	{
   	   message_die(GENERAL_ERROR, "Could not delete article data", '', __LINE__, __FILE__, $sql);
	}	

	kb_remove_article_words($article_id);

	$sql = "DELETE FROM " . KB_VOTES_TABLE . "
		WHERE votes_file = " . $article_id;
	if (!$db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, "Could not delete article vote data", '', __LINE__, __FILE__, $sql);
	}
	
	$message = $lang['Article_deleted'] . '<br /><br />' . sprintf($lang['Click_return_article_manager'], '<a href="' . append_sid("admin_kb_art.$phpEx") . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid($phpbb_root_path . "admin/index.$phpEx?pane=right") . '">', '</a>');

	message_die(GENERAL_MESSAGE, $message);
	}
	else
	{
		$confirm_action = append_sid("admin_kb_art.$phpEx");
		$message = $lang['Confirm_art_delete'] . '<br /><br /><form method="post" action="' . htmlspecialchars($confirm_action, ENT_QUOTES, 'UTF-8') . '">'
			. phpbb_admin_session_field()
			. '<input type="hidden" name="mode" value="delete" />'
			. '<input type="hidden" name="c" value="yes" />'
			. '<input type="hidden" name="a" value="' . $article_id . '" />'
			. '<input type="submit" value="' . htmlspecialchars($lang['Yes'], ENT_QUOTES, 'UTF-8') . '" />'
			. '&nbsp;&nbsp;<a href="' . append_sid("admin_kb_art.$phpEx") . '">' . htmlspecialchars($lang['No'], ENT_QUOTES, 'UTF-8') . '</a></form>';

		message_die(GENERAL_MESSAGE, $message);
	}
	break;
	
	default:
			//
			// Generate page
			//
			$template->set_filenames(array(
			    'body' => 'admin/kb_art_body.tpl')
			);

			$template->assign_vars(array(
			    'L_ARTICLE' => $lang['Article'],
				'L_ARTICLE_CAT' => $lang['Category'],
				'L_ARTICLE_TYPE' => $lang['Article_type'],
				'L_ARTICLE_AUTHOR' => $lang['Author'],
				'L_ACTION' => $lang['Art_action'],
	
				'L_APPROVED' => $lang['Art_approved'],
				'L_NOT_APPROVED' => $lang['Art_not_approved'],
				'L_EDITED' => $lang['Art_edit'],
	
				'L_KB_ART_TITLE' => $lang['Art_man'],
				'L_KB_ART_DESCRIPTION' => $lang['KB_art_description'])
			);

			//edited articles
			get_kb_articles('', 2, 'editrow');
			//need to be approved
			get_kb_articles('', 0, 'notrow');
			//Articles that are approved
			get_kb_articles('', 1, 'approverow');
			
			break;
}

$template->pparse('body');


include('./page_footer_admin.'.$phpEx);

?>
