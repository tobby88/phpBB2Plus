<?php
/***************************************************************************
 *                                 kb_add.php
 *                            -------------------
 *   begin                : Sunday, Mar 31, 2003
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: kb_add.php,v 1.8 2004/05/02 08:25:02 jonohlsson Exp $
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

  $category_id = (isset($_GET['cat']) && is_scalar($_GET['cat'])) ? intval($_GET['cat']) : ((isset($_POST['cat']) && is_scalar($_POST['cat'])) ? intval($_POST['cat']) : 0);
  $article_submit = phpbb_request_scalar($_POST, 'article_submit') !== '';
  $preview = phpbb_request_scalar($_POST, 'preview') !== '';

  if (!$is_admin && ((!$userdata['session_logged_in'] && $kb_config['allow_anon'] != ALLOW_ANON) || $kb_config['allow_new'] == 0))
  {
	  $message = $lang['No_add'] . '<br /><br />' . sprintf($lang['Click_return_kb'], '<a href="' . append_sid(this_kb_mxurl()) . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid($phpbb_root_path . "index.$phpEx") . '">', '</a>');
	  message_die(GENERAL_MESSAGE, $message);
  }

  if (($article_submit || $preview) && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['sid']) || !is_scalar($_POST['sid']) || !hash_equals((string) $userdata['session_id'], (string) $_POST['sid'])))
  {
	  message_die(GENERAL_ERROR, $lang['Not_Authorised']);
  }

  //show article form
	if (!$article_submit || $preview)
	{
	   $page_title = $lang['Add_article'];
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
	   
	   if ( !$userdata['session_logged_in'] && $kb_config['allow_anon'] == ALLOW_ANON )
	   {
	       $template->assign_block_vars('switch_name',array());
	   }
	
	   $template->assign_vars(array(
		  'L_ADD_ARTICLE' => $lang['Add_article'],
		  'L_ARTICLE_TITLE' => $lang['Article_title'],
		  'L_ARTICLE_DESCRIPTION' => $lang['Article_description'],
		  'L_ARTICLE_TEXT' => $lang['Article_text'],
		  'L_ARTICLE_TYPE' => $lang['Article_type'],
		  'L_SUBMIT' => $lang['Submit'],
		  'L_PREVIEW' => $lang['Preview'],
		  'L_SELECT' => $lang['Select'],		  
		  'L_NAME' => $lang['Username'],
		  
		  'S_ACTION' => this_kb_mxurl('mode=add'),
		  'HTML_STATUS' => $html_status,
		  'BBCODE_STATUS' => sprintf($bbcode_status, '<a href="' . append_sid("faq.$phpEx?mode=bbcode") . '" target="_phpbbcode">', '</a>'), 
		  'SMILIES_STATUS' => $smilies_status,
		  'S_HIDDEN_FIELDS' => '<input type="hidden" name="cat" value="' . $category_id . '" /><input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',

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
		  'L_EMPTY_CAT' => $lang['Empty_category'],
		  'L_EMPTY_TYPE' => $lang['Empty_type'],

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

		  'L_BBCODE_CLOSE_TAGS' => $lang['Close_Tags'], 
		  'L_STYLES_TIP' => $lang['Styles_tip'])
	   );
	   $type_id = phpbb_request_scalar($_POST, 'type_id');
		if ( $type_id == 'select_one' )
		{
			$message = "Please select article type.<br /><br />Click <a href=" . append_sid(this_kb_mxurl()) .">Here</a> to return to the form";
			message_die(GENERAL_MESSAGE, $message);
		}
	   get_kb_type_list($type_id);	
	}
	
	//BEGIN - PreText HIDE/SHOW
	if ( $kb_config['show_pretext'] ) 
	{
		// Pull Header/Body info.		
       	$pt_header = $kb_config['pt_header'];		
		$pt_body = $kb_config['pt_body'];		
		$template->set_filenames(array('pretext' => 'kb_add_pretext.tpl'));
		$template->assign_vars(array(
			'PRETEXT_HEADER' => $pt_header,
			'PRETEXT_BODY' => $pt_body ));
		$template->assign_var_from_handle('KB_PRETEXT_BOX', 'pretext');
	}
	//END - PreText HIDE/SHOW
	
	if ($preview)
	{
		$orig_word = array();
		$replacement_word = array();
		obtain_word_list($orig_word, $replacement_word);

		$preview_title = stripslashes(phpbb_request_scalar($_POST, 'article_name'));
		$preview_desc = stripslashes(phpbb_request_scalar($_POST, 'article_desc'));
		$preview_username = stripslashes(phpbb_request_scalar($_POST, 'username'));
		$message = stripslashes(phpbb_request_scalar($_POST, 'message'));

		$bbcode_uid = make_bbcode_uid();

		$preview_message = stripslashes(prepare_message(addslashes($message), $html_on, $bbcode_on, $smilies_on, $bbcode_uid));

		if ($bbcode_on)
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
			'ARTICLE_TITLE' => htmlspecialchars($preview_title),
			'ARTICLE_DESC' => htmlspecialchars($preview_desc),
			'ARTICLE_BODY' => htmlspecialchars($message),
			'USERNAME' => htmlspecialchars($preview_username),
			
			'PREVIEW_MESSAGE' => $preview_message)
		);
		$template->assign_var_from_handle('KB_PREVIEW_BOX', 'preview');
	}
	
	
//post article ----------------------------------------------------------------------------ADD
if ($article_submit)
{

	$page_title = $lang['Add_article'];
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
	$type = (isset($_POST['type_id']) && is_scalar($_POST['type_id'])) ? intval($_POST['type_id']) : 0;
	$posted_username = (isset($_POST['username']) && is_scalar($_POST['username'])) ? trim((string) $_POST['username']) : '';
	if ($posted_name === '' || $posted_desc === '' || $posted_message === '' || $category_id <= 0 || $type <= 0)
	{
	  	echo "<br /><br /><center>Please fill out all parts of the form.  <a href=".this_kb_mxurl('mode=add').">Click Here</a> to go back to the form.</center>";
		exit;
	}

	$article_text = $posted_message;
	$title = htmlspecialchars($posted_name);
	$description = htmlspecialchars($posted_desc);
	$date = time();
	$author_id = $userdata['user_id'];	   
	$username = $userdata['session_logged_in'] ? $userdata['username'] : $posted_username;
   	$category = $category_id;
	   
	// Check message
	if (!empty($article_text))
	{
		$bbcode_uid = ($bbcode_on) ? make_bbcode_uid() : '';
		$article_text = prepare_message(trim($article_text), $html_on, $bbcode_on, $smilies_on, $bbcode_uid);
	}


	if ( ( !$kb_config['approve_new'] ) || ( $is_admin ) || ( $userdata['user_level'] == MOD ) )
	{
	  	$approve = 1;
		update_kb_number($category, '+ 1');
	}
	else
	{
	  	$approve = 0;	   
	}	   

	   
	$sql = "INSERT INTO " . KB_ARTICLES_TABLE . " ( article_category_id , article_title , article_description , article_date , article_author_id , username , bbcode_uid , article_body , article_type , approved, views )
	VALUES ($category, '" . $db->sql_escape($title) . "', '" . $db->sql_escape($description) . "', $date, " . intval($author_id) . ", '" . $db->sql_escape($username) . "', '" . $db->sql_escape($bbcode_uid) . "', '" . $db->sql_escape($article_text) . "', $type, $approve, 0)";

	if ( !($results = $db->sql_query($sql)) )
	{
	    message_die(GENERAL_ERROR, "Could not submit aritcle", '', __LINE__, __FILE__, $sql);
	}
	$article_id = intval($db->sql_nextid());
	$row = array(
		'article_id' => $article_id,
		'article_category_id' => $category,
		'article_type' => $type,
		'article_author_id' => $author_id,
		'article_title' => $title,
		'article_description' => $description,
		'article_body' => $article_text
	);

	if (  !$approve || $approve == 0)
	{	   
	     email_kb_admin($kb_config['notify']);
			
	}
	   
	if ( $approve == 1 && $kb_config['comments'] )
	{
		  // choose a user
//		  $user_id = $kb_config['admin_id'];
		  $user_id = $userdata['user_id'];

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

		  $subject = str_replace("'", "\'" , $subject);
		  $message = str_replace("'", "\'" , $message);
		  
		  $forum_id = $kb_config['forum_id'];
	
		  $topic_data = insert_post($message, $subject, $forum_id, $user['user_id'], $user['username'], $user['user_attachsig']);
		  
		  $sql = "UPDATE " . KB_ARTICLES_TABLE .
		     " SET topic_id = " . $topic_data['topic_id'] . " 
		 	 WHERE article_id = " . $article_id;
		 
		  if ( !($result = $db->sql_query($sql)) )
		  {
   	   	  	  message_die(GENERAL_ERROR, "Could not update article data", '', __LINE__, __FILE__, $sql);
	      }
	}
	if ($approve == 1)
	{
	       add_kb_words($article_id, $article_text);
		   $message = $lang['Article_submitted'] . '<br /><br />' . sprintf($lang['Click_return_kb'], '<a href="' . append_sid(this_kb_mxurl()) . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid($phpbb_root_path . "index.$phpEx") . '">', '</a>');
	}
  	else
	{
	   	$message = $lang['Article_submitted_Approve'] . '<br /><br />' . sprintf($lang['Click_return_kb'], '<a href="' . append_sid(this_kb_mxurl()) . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid($phpbb_root_path . "index.$phpEx") . '">', '</a>');
	}
		
	   message_die(GENERAL_MESSAGE, $message);	   
}	
?>
