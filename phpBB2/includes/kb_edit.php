<?php
/***************************************************************************
 *                                 kb_edit.php
 *                            -------------------
 *   begin                : Sunday, Mar 31, 2003
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: kb_edit.php,v 1.8 2004/05/02 08:25:02 jonohlsson Exp $
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

if ( !defined('IN_PHPBB') )
{
	die("Hacking attempt");
}
	$article_id = (isset($_GET['k']) && is_scalar($_GET['k'])) ? intval($_GET['k']) : ((isset($_POST['k']) && is_scalar($_POST['k'])) ? intval($_POST['k']) : 0);
	$article_submit = !empty($_POST['article_submit']);
	$preview = !empty($_POST['preview']);
	$sql = 'SELECT * FROM ' . KB_ARTICLES_TABLE . ' WHERE article_id = ' . $article_id;
	if (!($permission_result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not obtain article data', '', __LINE__, __FILE__, $sql);
	}
	$permission_row = $db->sql_fetchrow($permission_result);
	$db->sql_freeresult($permission_result);
	if (!$permission_row)
	{
		message_die(GENERAL_MESSAGE, $lang['Article_not_exsist']);
	}
	if (!$is_admin && (!$userdata['session_logged_in'] || intval($permission_row['article_author_id']) !== intval($userdata['user_id'])))
	{
		$message = $lang['No_edit'] . '<br /><br />' . sprintf($lang['Click_return_kb'], '<a href="' . append_sid(this_kb_mxurl()) . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid($phpbb_root_path . "index.$phpEx") . '">', '</a>');
		message_die(GENERAL_MESSAGE, $message);
	}
	if ($article_submit && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['sid']) || !is_scalar($_POST['sid']) || !hash_equals((string) $userdata['session_id'], (string) $_POST['sid'])))
	{
		message_die(GENERAL_ERROR, $lang['Not_Authorised']);
	}
// main / preview -------------------------------------------------------------------------	
	//show article form
	if (!$article_submit || $preview)
	{
		  
		$sql = "SELECT *
		  FROM " . KB_ARTICLES_TABLE . "
		  WHERE article_id = '$article_id'";
	    if ( !($result = $db->sql_query($sql)) )
		{
	        message_die(GENERAL_ERROR, "Could not obtain article data", '', __LINE__, __FILE__, $sql);
	    }
	
	    $row = $db->sql_fetchrow($result);

		$article_name = stripslashes($row['article_title']); 

    	$article_category = $row['article_category_id']; 
    	$article_desc = stripslashes($row['article_description']); 
    	$article_body = stripslashes($row['article_body']);

		  $article_type = $row['article_type'];
		  $bbcode_uid = $row['bbcode_uid'];
		  $topic = $row['topic_id'];
          $author_id = $row['article_author_id'];
		  	
		  
		  if ( $row['bbcode_uid'] != '' )
		  {
			  $article_body = preg_replace('/\:(([a-z0-9]:)?)' . $row['bbcode_uid'] . '/s', '', $article_body);
		  }
	
	   $page_title = $lang['Edit_article'];
	    if ( !$is_block )
		 {
		   include($phpbb_root_path . 'includes/page_header.'.$phpEx);
		 }
	   make_jumpbox($phpbb_root_path .'viewforum.'.$phpEx,'');

	   //
	   // HTML toggle selection
	   //
	   if ( $board_config['allow_html'] )
	   {
	   	  $html_status = $lang['HTML_is_ON'];
	   }
	   else
	   {
	   	  $html_status = $lang['HTML_is_OFF'];
	   }

	   //
	   // BBCode toggle selection
	   //
	   if ( $board_config['allow_bbcode'] )
	   {
	      $bbcode_status = $lang['BBCode_is_ON'];
	   }
	   else
	   {
	   	  $bbcode_status = $lang['BBCode_is_OFF'];
       }

	   //
	   // Smilies toggle selection
	   //
	   if ( $board_config['allow_smilies'] )
	   {
	   	  $smilies_status = $lang['Smilies_are_ON'];
	   }
	   else
	   {
	   	   $smilies_status = $lang['Smilies_are_OFF'];
	   }
	   
	   // Generate smilies listing for page output
	   generate_smilies('inline', PAGE_POSTING);
	   
	   //load header
	   include ($phpbb_root_path ."includes/kb_header.".$phpEx);
   
	   //set up page
	   $template->set_filenames(array(
		  'body' => 'kb_add_body.tpl')
	   );

	   $template->assign_vars(array(
		  'L_ADD_ARTICLE' => $lang['Edit_article'],
		  'L_ARTICLE_TITLE' => $lang['Article_title'],
		  'L_ARTICLE_DESCRIPTION' => $lang['Article_description'],
		  'L_ARTICLE_TEXT' => $lang['Article_text'],
		  'L_ARTICLE_TYPE' => $lang['Article_type'],
		  'L_ARTICLE_CATEGORY' => $lang['Category'],
		  'L_TOPIC' => $lang['Topic'],
		  'L_SUBMIT' => $lang['Edit'],
		  'L_PREVIEW' => $lang['Preview'],
		  
		  'L_FAQ' => $lang['FAQ'],
		  'L_HOWTO' => $lang['HowTo'],
		  'L_INFO' => $lang['Info'],
		  'L_TUTORIAL' => $lang['Tutorial'],
		  
		  'S_ACTION' => this_kb_mxurl('mode=edit'),
		  'HTML_STATUS' => $html_status,
		  'BBCODE_STATUS' => sprintf($bbcode_status, '<a href="' . append_sid($phpbb_root_path . "faq.$phpEx?mode=bbcode") . '" target="_phpbbcode">', '</a>'), 
		  'SMILIES_STATUS' => $smilies_status,
		  
		  'ARTICLE_TITLE' => $article_name,
		  'ARTICLE_DESC' => $article_desc,
		  'ARTICLE_BODY' => $article_body,
		  'TOPIC' => $topic,
		  'S_HIDDEN_FIELDS' => '<input type="hidden" name="k" value="' . $article_id . '" /><input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
		
      	  'L_BBCODE_B_HELP' => $lang['bbcode_b_help'], 
		  'L_BBCODE_I_HELP' => $lang['bbcode_i_help'], 
		  'L_BBCODE_U_HELP' => $lang['bbcode_u_help'], 
		  'L_BBCODE_Q_HELP' => $lang['bbcode_q_help'], 
		  'L_BBCODE_C_HELP' => $lang['bbcode_c_help'], 
		  'L_BBCODE_L_HELP' => $lang['bbcode_l_help'], 
		  'L_BBCODE_O_HELP' => $lang['bbcode_o_help'], 
		  'L_BBCODE_P_HELP' => $lang['bbcode_p_help'], 
		  'L_BBCODE_W_HELP' => $lang['bbcode_w_help'], 
		  'L_BBCODE_A_HELP' => $lang['bbcode_a_help'], 
		  'L_BBCODE_S_HELP' => $lang['bbcode_s_help'], 
		  'L_BBCODE_F_HELP' => $lang['bbcode_f_help'], 
		  'L_EMPTY_MESSAGE' => $lang['Empty_message'],
		  'L_EMPTY_ARTICLE_NAME' => $lang['Empty_article_name'],
		  'L_EMPTY_ARTICLE_DESC' => $lang['Empty_article_desc'],

		  'L_FONT_COLOR' => $lang['Font_color'], 
		  'L_COLOR_DEFAULT' => $lang['color_default'], 
		  'L_COLOR_DARK_RED' => $lang['color_dark_red'], 
		  'L_COLOR_RED' => $lang['color_red'], 
		  'L_COLOR_ORANGE' => $lang['color_orange'], 
		  'L_COLOR_BROWN' => $lang['color_brown'], 
		  'L_COLOR_YELLOW' => $lang['color_yellow'], 
		  'L_COLOR_GREEN' => $lang['color_green'], 
		  'L_COLOR_OLIVE' => $lang['color_olive'], 
		  'L_COLOR_CYAN' => $lang['color_cyan'], 
		  'L_COLOR_BLUE' => $lang['color_blue'], 
		  'L_COLOR_DARK_BLUE' => $lang['color_dark_blue'], 
		  'L_COLOR_INDIGO' => $lang['color_indigo'], 
		  'L_COLOR_VIOLET' => $lang['color_violet'], 
		  'L_COLOR_WHITE' => $lang['color_white'], 
		  'L_COLOR_BLACK' => $lang['color_black'], 

		  'L_FONT_SIZE' => $lang['Font_size'], 
		  'L_FONT_TINY' => $lang['font_tiny'], 
		  'L_FONT_SMALL' => $lang['font_small'], 
		  'L_FONT_NORMAL' => $lang['font_normal'], 
		  'L_FONT_LARGE' => $lang['font_large'], 
		  'L_FONT_HUGE' => $lang['font_huge'], 
		  'L_SELECT' => $lang['Select'],		  

		  'L_BBCODE_CLOSE_TAGS' => $lang['Close_Tags'], 
		  'L_STYLES_TIP' => $lang['Styles_tip'])
	   );

	   	get_kb_type_list($article_type);


 	   if ( $is_admin || $userdata['user_id'] == $author_id )
	   {
	       	$template->assign_block_vars('switch_edit', array());
		   	get_kb_cat_list($article_category);
	   }
 
	}

// Preview -------------------------------------------------------------------------	
	if( $_POST['preview'] )
	{
		$sql = "SELECT bbcode_uid
		  FROM " . KB_ARTICLES_TABLE . "
		  WHERE article_id = $article_id";
		if ( !($result = $db->sql_query($sql)) )
		{
	        message_die(GENERAL_ERROR, "Could not obtain article data", '', __LINE__, __FILE__, $sql);
		}
	
		$row = $db->sql_fetchrow($result);	
		
		$orig_word = array();
		$replacement_word = array();
		obtain_word_list($orig_word, $replacement_word);

		$message_name = htmlspecialchars(stripslashes($_POST['article_name']));
		$message_desc = htmlspecialchars(stripslashes($_POST['article_desc']));

		$message = ( !empty($_POST['message']) ) ? stripslashes($_POST['message']) : '';
		
		$bbcode_uid = ( $row['bbcode_uid'] ) ? $row['bbcode_uid'] : '';
		$preview_message = stripslashes(prepare_message(addslashes(unprepare_message($message)), $html_on, $bbcode_on, $smilies_on, $bbcode_uid));

		$message = stripslashes($message);
		
		if( $bbcode_on )
		{

			$preview_message = bbencode_second_pass($preview_message, $bbcode_uid);
		}

		$preview_message = make_clickable($preview_message);

		if( $smilies_on )
		{
			$preview_message = smilies_pass($preview_message);
		}

		$preview_message = str_replace("\n", '<br />', $preview_message);

		$template->set_filenames(array(
			'preview' => 'kb_add_preview.tpl')
		);

		$template->assign_vars(array(
			'ARTICLE_TITLE' => $message_name,
			'ARTICLE_DESC' => $message_desc,
			'ARTICLE_BODY' => $message,
			
			'PREVIEW_MESSAGE' => $preview_message)
		);
		$template->assign_var_from_handle('KB_PREVIEW_BOX', 'preview');
	}
	
// update -------------------------------------------------------------------------	
	if ($article_submit)
	{		   
	   	$page_title = $lang['Edit'];
	    
		if ( !$is_block )
		 {
		   include($phpbb_root_path . 'includes/page_header.'.$phpEx);
         }	
	   	
		make_jumpbox($phpbb_root_path .'viewforum.'.$phpEx,'');
	   
	   	//load header
	   	include ($phpbb_root_path ."includes/kb_header.".$phpEx);
	   
		$posted_name = (isset($_POST['article_name']) && is_scalar($_POST['article_name'])) ? trim((string) $_POST['article_name']) : '';
		$posted_desc = (isset($_POST['article_desc']) && is_scalar($_POST['article_desc'])) ? trim((string) $_POST['article_desc']) : '';
		$posted_message = (isset($_POST['message']) && is_scalar($_POST['message'])) ? trim((string) $_POST['message']) : '';
		$category = (isset($_POST['category_id']) && is_scalar($_POST['category_id'])) ? intval($_POST['category_id']) : 0;
		$type_id = (isset($_POST['type_id']) && is_scalar($_POST['type_id'])) ? intval($_POST['type_id']) : 0;
		$topic = (isset($_POST['topic']) && is_scalar($_POST['topic'])) ? intval($_POST['topic']) : 0;
		if ($posted_name === '' || $posted_desc === '' || $posted_message === '' || $category <= 0 || $type_id <= 0)
	   	{
	   		$message = "Please fill out all parts of the form.<br /><br />Click <a href=" . this_kb_mxurl('mode=add').">Here</a> to return to the form";
    		message_die(GENERAL_MESSAGE, $message);
	   	}
   		
		$article_text = $posted_message;
		$title = htmlspecialchars($posted_name);
		$description = htmlspecialchars($posted_desc);
		$date = time();
		$author_id = intval($permission_row['article_author_id']);
		$bbcode_uid = (string) $permission_row['bbcode_uid'];

	   	if ( $type_id == 'select_one' )
	   	{
	   	  	$message = "Please select article type.<br /><br />Click <a href=" . this_kb_mxurl('mode=add').">Here</a> to return to the form";
    		message_die(GENERAL_MESSAGE, $message);
	   	}
	   
	   	$sql = "SELECT article_category_id, approved, topic_id
		  FROM " . KB_ARTICLES_TABLE . "
		  WHERE article_id = $article_id";
	    if ( !($result = $db->sql_query($sql)) )
		{
	        	message_die(GENERAL_ERROR, "Could not obtain article data", '', __LINE__, __FILE__, $sql);
	    }
	
	   	$row = $db->sql_fetchrow($result);
	   
	   	$old_approve = $row['approved'];
	   	$comment_topic_id = $row['topic_id'];
	   	$old_category = $row['article_category_id'];
	   
    	$error_msg = '';	      
		$article_text = prepare_message(trim($article_text), $html_on, $bbcode_on, $smilies_on, $bbcode_uid);
	   
     	if ( $old_category != $category )
	   	{
	       	update_kb_number($old_category, '- 1');
	       	if ( $is_admin || ( !$kb_config['approve_edit'] && $userdata['user_id'] == $author_id ) )
	       	{
	           	update_kb_number($category, '+ 1');
         	}
     	}
	   
	   	if ( $is_admin || ( !$kb_config['approve_edit'] && $userdata['user_id'] == $author_id ) )
	   	{
	   	  	$approve = 1;		  
		  	if ( $old_approve != 1 )
		  	{
		      	update_kb_number($category, '+ 1');
		  	}
	   	}
	   	else
	   	{
	   	   	$approve = 2;
		   
		   	if ( $old_approve == 1 && $old_category == $category )
		   	{		   
		       	update_kb_number($category, '- 1');
		   	}
	   	}

	  	$sql = "UPDATE " . KB_ARTICLES_TABLE . "
			SET article_category_id = $category,
			article_title = '" . $db->sql_escape($title) . "',
			article_description = '" . $db->sql_escape($description) . "',
			article_date = $date,
			article_author_id = $author_id,
			article_body = '" . $db->sql_escape($article_text) . "',
			article_type = $type_id,
			approved = $approve,
			topic_id = $topic,
			bbcode_uid = '" . $db->sql_escape($bbcode_uid) . "'
			WHERE article_id = $article_id";
			  
	   	if ( !($edit_article = $db->sql_query($sql)) )
	   	{
	   	  	message_die(GENERAL_ERROR, "Could not edit aritcle", '', __LINE__, __FILE__, $sql);
	   	}

	   	if ( $approve == 1 && $kb_config['comments'] )
	   	{		  
		  	$sql = "SELECT * FROM " . KB_ARTICLES_TABLE . " WHERE article_id = '" . $article_id . "'";
	   	  	if ( !($results = $db->sql_query($sql)) )
	   	  	{
	       	 	message_die(GENERAL_ERROR, "Could not get aritcle id", '', __LINE__, __FILE__, $sql);
	   	  	}
	   	  	$row = $db->sql_fetchrow($results);

		  	// choose a user
		  	$user_id = $userdata['user_id'];
		  
		  	// initialise the userdata
		  	$sql = "SELECT * FROM " . USERS_TABLE . " WHERE user_id = $user_id";
		  	if ( !($result = $db->sql_query($sql)) )
		  	{
	  	      	message_die(CRITICAL_ERROR, 'Could not obtain lastvisit data from user table', '', __LINE__, __FILE__, $sql);
		  	}
		  	$user = $db->sql_fetchrow($result);
		  	init_userprefs($user);
		  

		  	$kb_cat = get_kb_cat($category);
		  	$type = get_kb_type($type_id);
		  	$author = get_kb_author($author_id);

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
		  
  		  	$message_update_text = "[ [i]" . $lang['Edited_Article_info']. $user['username'] . "[/i] ]" . "\n\n";

		  	$message_update_text = addslashes($message_update_text);
	
		  	$subject = '[ KB ] ' . $row['article_title'];

		  	$subject = str_replace("'", "\'" , $subject);
		  	$message = str_replace("'", "\'" , $message);

		  	$forum_id = $kb_config['forum_id'];
	
		  	$topic_data = insert_post($message, $subject, $forum_id, $user['user_id'], $user['username'], $user['user_attachsig'], $comment_topic_id, $message_update_text, $kb_config['bump_post']);
		}  

	   	if (  !$approve || $approve == 2 )
	   	{	   
	       	email_kb_admin($kb_config['notify']);
	   	}
	   
	   	if ($approve == 1)
	   	{
	       	add_kb_words($article_id, $article_text);
		   	$message = $lang['Article_Edited'] . '<br /><br />' . sprintf($lang['Click_return_kb'], '<a href="' . append_sid(this_kb_mxurl()) . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid($phpbb_root_path . "index.$phpEx") . '">', '</a>');
	   	}
	   	else
	   	{
		   $message = $lang['Article_Edited_Approve'] . '<br /><br />' . sprintf($lang['Click_return_kb'], '<a href="' . append_sid(this_kb_mxurl()) . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid($phpbb_root_path . "index.$phpEx") . '">', '</a>');
	   	}

	  	 message_die(GENERAL_MESSAGE, $message);	   
	}
	
?>
