<?php
/***************************************************************************
 *                                 kb_article.php
 *                            -------------------
 *   begin                : Sunday, Mar 31, 2003
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: kb_article.php,v 1.9 2004/05/30 20:49:22 jonohlsson Exp $
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
require_once dirname(__FILE__) . '/functions_topic_highlight.php';


	$article_id = (isset($_GET['k']) && is_scalar($_GET['k'])) ? intval($_GET['k']) : 0;
	$start = (isset($_GET['start']) && is_scalar($_GET['start'])) ? max(0, min(1000000, intval($_GET['start']))) : 0;
	$pagination = '';
	
	$sql = "SELECT *
		FROM " . KB_ARTICLES_TABLE . "
		WHERE article_id = $article_id";
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, "Could not obtain article data", '', __LINE__, __FILE__, $sql);
	}

	if ($row = $db->sql_fetchrow($result))
	{
	
	   $article_title = $row['article_title'];
	   $approved = $row['approved'];

	   $article_category_id = (int) $row['article_category_id'];
	   $category = get_kb_cat($article_category_id);
	   $article_category_name = isset($category['category_name']) && is_scalar($category['category_name']) ? (string) $category['category_name'] : '';

	   $temp_url = append_sid(this_kb_mxurl("mode=cat&amp;cat=$article_category_id"));
	   $category = '<a href="' . htmlspecialchars($temp_url, ENT_QUOTES, 'UTF-8', false) . '" class="gen">' . phpbb_stored_text($article_category_name) . '</a>';
	
	   $date = create_date($board_config['default_dateformat'], $row['article_date'], $board_config['board_timezone']);
	
	   // author information
	   $author_id = (int) $row['article_author_id'];

	   $author_identity = kb_display_author($row, $author_id > 0 ? get_kb_author($author_id) : '');
	   if ($author_identity['id'] === 0)
	   {
	       $author_name_plain = $author_identity['name'];
	       $author_kb_art = phpbb_profile_text($author_name_plain);
	   }
	   else
	   {
	       $author_name_plain = $author_identity['name'];
	   
	       $temp_url = append_sid($phpbb_root_path . "profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=$author_id");
	       $author_kb_art  = '<a href="' . htmlspecialchars($temp_url, ENT_QUOTES, 'UTF-8', false) . '" class="gen">' . phpbb_profile_text($author_name_plain) . '</a>';
	   }
	
	   $art_pages = explode('[page]', $row['article_body']);
	   $page_num = min($page_num, max(0, count($art_pages) - 1));
	   $article = trim($art_pages[$page_num]);
	   $article = str_replace('[toc]', '', $article);
	   $kb_art_description  = phpbb_stored_text($row['article_description']);
	   
	   $type_id = (int) $row['article_type'];
	   $type_plain = get_kb_type($type_id);
	   $type = phpbb_stored_text($type_plain);
	   $topic_id = (int) $row['topic_id'];
	   
	   $new_views = (int) $row['views'] + ($page_num === 0 ? 1 : 0);
	   $views = '<b>' . $lang['Views'] . '</b> ' . $new_views;

		if ($row['article_rating'] == 0 || $row['article_totalvotes'] == 0 )
		{
			$rating = 0;
			$rating_votes = 0;
			$rate_message = ''; 
		}
		else
		{
			$rating = round($row['article_rating']/$row['article_totalvotes'],2);
			$rating_votes = $row['article_totalvotes'];	
			$rating_message = $rating.'/10, '.$rating_votes. ' ' .$lang['Votes'] ;	
	   		$rate_message = '<b>' . $lang['Votes_label'] . '</b> ' . $rating_message;
		}
	}
	else
	{
		message_die(GENERAL_MESSAGE, $lang['Article_not_exsist']);
	}
	$db->sql_freeresult($result);

	$can_view_unapproved = $is_admin || ($userdata['session_logged_in'] && (int) $author_id === (int) $userdata['user_id']);
	if ((int) $approved !== 1 && !$can_view_unapproved)
	{
		message_die(GENERAL_MESSAGE, $lang['Article_not_exsist']);
	}
	
	if ( $page_num == 0 )
	{	
	   $sql = "UPDATE " . KB_ARTICLES_TABLE . " SET views = views + 1 WHERE article_id = " . $article_id;
		if (!($result2 = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, "Could not update article's views", '', __LINE__, __FILE__, $sql);
		}
	}
	
	//
	// Was a highlight request part of the URI?
	//
	$requested_highlight = phpbb_topic_highlight_query(isset($_GET['highlight']) ? $_GET['highlight'] : '');
	$highlight_match = phpbb_topic_highlight_pattern($requested_highlight);
	$highlight = urlencode($requested_highlight);
	$highlight_param = $requested_highlight === '' ? '' : '&amp;highlight=' . $highlight;
	
	if ( !$board_config['allow_html'] ) 
    { 
        $article = preg_replace('#(<)([\/]?.*?)(>)#is', "&lt;\\2&gt;", $article); 
    }
	
	//
	// Parse message
	//
	$bbcode_uid = $row['bbcode_uid'];
	
	if ( $board_config['allow_bbcode'] )
	{
		if ( $bbcode_uid != '' )
		{
			$article = ( $board_config['allow_bbcode'] ) ? bbencode_second_pass($article, $bbcode_uid) : preg_replace("/\:$bbcode_uid/si", '', $article);
		}
	}

	$article = make_clickable($article);

	//
	// Parse smilies
	//
	if ( $board_config['allow_smilies'] )
	{
		$article = smilies_pass($article);		
	}

	//
	// Replace naughty words
	//
	if ( count($orig_word) )
	{
		$article_title = preg_replace($orig_word, $replacement_word, $article_title);

		$article = phpbb_preg_replace_outside_tags($article, $orig_word, $replacement_word);
	}

	//
	// Replace newlines (we use this rather than nl2br because
	// till recently it wasn't XHTML compliant)
	//

	$article = str_replace("\n", "\n<br />\n", $article);
	
	$article = acronym_pass( $article );
	//
	// Highlight active words (primarily for search)
	//
	if ($highlight_match)
	{
		$article = phpbb_topic_highlight_apply($article, $highlight_match, isset($theme['fontcolor3']) ? $theme['fontcolor3'] : '000000');
	}
	
	$article_title = phpbb_stored_text($article_title);
	$page_title = $article_title;
	    if ( !$is_block && !$print_version )
		 {
		   include($phpbb_root_path . 'includes/page_header.'.$phpEx);
		 }	
		
		make_jumpbox($phpbb_root_path .'viewforum.'.$phpEx);
	
	//load header
	if ( !$print_version )
	{
		include ($phpbb_root_path ."includes/kb_header.".$phpEx);
	}
	//edit 
	if ($is_admin || (!empty($userdata['session_logged_in']) && $author_id > 0 && (int) $userdata['user_id'] === $author_id && !empty($kb_config['allow_edit'])))
	{
		 $temp_url = append_sid(this_kb_mxurl("mode=edit&amp;k=" . $article_id));
		 $edit_img = '<a href="' . $temp_url . '"><img src="' . $phpbb_root_path . $images['icon_edit'] . '" alt="' . $lang['Edit_delete_post'] . '" title="' . $lang['Edit_delete_post'] . '" border="0" /></a>';
		 $edit = '<a href="' . $temp_url . '">' . $lang['Edit_delete_post'] . '</a>';
	}
	else
	{
		$edit_img = '';
		$edit = '';
	}
	
	//
	//Build page
	//
	if ( !$print_version )
	{
 		$template->set_filenames(array(
			'body' => 'kb_article_body.tpl')
		);
	}
	else
	{
 		$template->set_filenames(array(
			'body' => 'kb_article_body_print.tpl')
		);
	}	
	
	if (!$article_title)
	{
	    $message = $lang['Article_not_exsist'] . '<br /><br />' . sprintf($lang['Click_return_kb'], '<a href="' . append_sid(this_kb_mxurl()) . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid($phpbb_root_path . "index.$phpEx") . '">', '</a>');
      	message_die(GENERAL_MESSAGE, $message);
  	}
  	else
  	{
		$topic=array();$comments='';
		if ($kb_config['comments'] && $topic_id > 0)
		{       
			$topic_row = kb_comment_topic($topic_id);
			if (is_array($topic_row))
			{
				$topic = $topic_row;
			}
	   
			if (!empty($topic['topic_id']))
			{
				$temp_url = append_sid($phpbb_root_path . "viewtopic.php?t=" . (int) $topic['topic_id']);
				$comments = '<b>' . $lang['Comments'] . '</b><a href="' . $temp_url . '" class="gen"> [' . (int) $topic['topic_replies'] . ' - ' . $lang['Post_comments'] . ']</a>';
				$template->assign_block_vars('switch_comments', array());
			}
		}

		if ( $kb_config['comments'] && $kb_config['comments_show'] && !empty($topic['topic_id']) && $topic['topic_replies'] != 0)
		{    
			//
			//page number
			//
			if ( isset( $page_num ) )
			{
				$page_numm = "&page_num=" . ($page_num + 1) ;
			}
			else
			{
    			$page_numm = '';
			}

			$show_num_comments = kb_stats_page_size($kb_config['comments_pagination']);
			$start = kb_stats_page_start($start,$show_num_comments,$topic['topic_replies']);
			$pagination = generate_pagination(this_kb_mxurl("mode=article&k=$article_id" . $page_numm . (!empty($print_version) ? '&print=true' : '') . $highlight_param), $topic['topic_replies'], $show_num_comments, $start);
			if (!empty($print_version) && $pagination !== '') {
				$template->assign_block_vars('switch_print_comment_pages', array());
			}
			get_kb_comments($topic_id, $start, $show_num_comments);   
		}
	
		//rate 
		if ( $kb_config['allow_rating'] && ( $kb_config['allow_anonymos_rating'] || ( !$kb_config['allow_anonymos_rating'] && $userdata['session_logged_in'])) )
		{
				$temp_url = append_sid(this_kb_mxurl("mode=rate&amp;k=" . $article_id . "&amp;cat=" . $article_category_id));
				$rate_img = '<a href="' . $temp_url . '" class="gen">' . $lang['ADD_RATING'] . '</a>';
				$rate = '<a href="' . $temp_url . '" class="gen">' . $lang['ADD_RATING'] . '</a>';
	    		$template->assign_block_vars('switch_ratings', array());
		}
		else
		{
			$rate_img = '';
			$rate = '';
		}
	
		$path_kb = ' ';
		$path_kb_array = array();
		get_kb_nav($article_category_id);

		$print_url = append_sid(this_kb_mxurl("mode=article&amp;k=" . $article_id . '&amp;page_num=' . ($page_num + 1) . '&amp;start=' . $start . '&amp;print=true' . $highlight_param, true));

		$template->assign_vars(array(
			'PAGINATION' => $pagination,
			'PAGE_NUMBER' => $pagination !== '' ? sprintf($lang['Page_of'], (int) floor($start / $show_num_comments) + 1, (int) ceil($topic['topic_replies'] / $show_num_comments)) : '',
			'L_GOTO_PAGE' => $lang['Goto_page'],

			'L_ARTICLE_DESCRIPTION' => $lang['Article_description'],
			'L_ARTICLE_DATE' => $lang['Date'],
			'L_ARTICLE_TYPE' => $lang['Article_type'],
			'L_ARTICLE_CATEGORY' => $lang['Category'],
			'L_ARTICLE_AUTHOR' => $lang['Author'],
			'L_TOC' => $lang['TOC'],
			'L_COMMENTS' => $lang['Comments_show_title'],
			'L_PRINT' => $lang['Print_version'],

			'U_PRINT' => $print_url,
		
			'ARTICLE_TITLE' => $article_title,
			'ARTICLE_AUTHOR' => $author_kb_art,
			'ARTICLE_CATEGORY' => $category,
			'ARTICLE_TEXT' => $article,
			'ARTICLE_DESCRIPTION' => $kb_art_description,
			'ARTICLE_DATE' => $date,
			'ARTICLE_TYPE' => $type,
			'EDIT_IMG' => $edit_img,
			'EDIT' => $edit,
			'VIEWS' => $views,

			'RATINGS' => $rate_message,
			'RATE_IMG' => $rate_img,
			'RATE' => $rate,
		
			'PATH' => $path_kb,
		
			'COMMENTS' => $comments)
		);


	//
	// Both navigation controls lead to the same page of the same article.
	$page_count = count($art_pages);
	if ($page_count > 1)
	{
		$template->assign_block_vars('switch_toc', array());
		$template->assign_block_vars('switch_pages', array());
		foreach ($art_pages as $index => $content)
		{
			$page_number = $index + 1;
			$label = (string) $page_number;
			$parts = explode('[toc]', $content, 2);
			if (count($parts) === 2) {
				$title = trim(preg_replace('/\[\/?[a-z][a-z0-9]*(?:[=:\s][^\]\r\n]*)?\]/i', '', strip_tags($parts[0])));
				if ($title !== '' && $orig_word) { $title = preg_replace($orig_word, $replacement_word, $title); }
				if ($title !== '') { $label .= ' - ' . phpbb_stored_text($title); }
			}

			$toc_item = $label;
			$page_link = (string) $page_number;
			if ($index !== $page_num) {
				$temp_url = append_sid(this_kb_mxurl("mode=article&amp;k=$article_id&amp;page_num=$page_number&amp;start=$start" . ($print_version ? '&amp;print=true' : '') . $highlight_param, (bool) $print_version));
				$anchor = '<a href="' . $temp_url . '" class="nav">';
				$toc_item = $anchor . $label . '</a>';
				$page_link = $anchor . $page_number . '</a>';
			}
			$last = $page_number === $page_count;
			$template->assign_block_vars('switch_toc.pages', array('TOC_ITEM' => $toc_item . ($last ? '' : '<br />')));
			$template->assign_block_vars('switch_pages.pages', array('PAGE_LINK' => $page_link . ($last ? '' : ', ')));
		}
	}
  }
  
?>
