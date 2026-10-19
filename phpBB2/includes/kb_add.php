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
		$pt_header = phpbb_stored_text($kb_config['pt_header']);
		$pt_body = phpbb_stored_text($kb_config['pt_body']);
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
			'ARTICLE_TITLE' => phpbb_profile_text($preview_title),
			'ARTICLE_DESC' => phpbb_profile_text($preview_desc),
			'ARTICLE_BODY' => phpbb_profile_text($message),
			'USERNAME' => phpbb_profile_text($preview_username),
			
			'PREVIEW_MESSAGE' => $preview_message)
		);
		$template->assign_var_from_handle('KB_PREVIEW_BOX', 'preview');
	}
	
	
// Submit a new article through the dedicated SQL owner.
if ($article_submit)
{
	require_once dirname(__FILE__) . '/functions_kb_write_storage.php';
	require_once dirname(__FILE__) . '/functions_kb_write_notifications.php';
	$input = array(
		'title'=>isset($_POST['article_name']) ? $_POST['article_name'] : null,
		'description'=>isset($_POST['article_desc']) ? $_POST['article_desc'] : null,
		'body'=>isset($_POST['message']) ? $_POST['message'] : null,
		'username'=>isset($_POST['username']) ? $_POST['username'] : '',
		'category'=>(isset($_POST['cat']) ? $_POST['cat'] : (isset($_GET['cat']) ? $_GET['cat'] : null)),
		'type'=>isset($_POST['type_id']) ? $_POST['type_id'] : null,
		'html_on'=>(bool)$html_on,'bbcode_on'=>(bool)$bbcode_on,'smilies_on'=>(bool)$smilies_on);
	try {
		$outcome = kb_write_article($db, 'add', 0, $input);
	} catch (PhpbbKbPublicationException $error) { message_die(GENERAL_ERROR, $error->getMessage()); }
	$notice = kb_notify_after_write($outcome) ? '' : '<br /><br />' . $lang['KB_notification_failed'];
	$message = $lang[$outcome['approved'] === 1 ? 'Article_submitted' : 'Article_submitted_Approve'] . $notice;
	$message .= '<br /><br />' . sprintf($lang['Click_return_kb'], '<a href="' . append_sid(this_kb_mxurl()) . '">', '</a>');
	$message .= '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid($phpbb_root_path . 'index.' . $phpEx) . '">', '</a>');
	message_die(GENERAL_MESSAGE, $message);
}
?>
