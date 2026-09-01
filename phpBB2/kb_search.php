<?php
/***************************************************************************
 *                                 kb_search.php
 *                            -------------------
 *   begin                : Sunday, Mar 31, 2003
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: kb_search.php,v 1.0.0 
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
define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_KB);
init_userprefs($userdata);
//
// End session management
//

include($phpbb_root_path . 'includes/kb_constants.'.$phpEx);
include($phpbb_root_path . 'includes/functions_kb.'.$phpEx);
include_once($phpbb_root_path . 'includes/bbcode.'.$phpEx);
include_once($phpbb_root_path . 'includes/functions_search.'.$phpEx);

//
// Define initial vars
//
$is_block = false;
$show_new = true;
$mode = phpbb_request_scalar($_POST, 'mode', phpbb_request_scalar($_GET, 'mode'));
$mode = ($mode === 'results') ? 'results' : '';
$search_keywords = trim(phpbb_request_scalar($_POST, 'search_keywords', phpbb_request_scalar($_GET, 'search_keywords')));
$search_keywords = substr($search_keywords, 0, 500);
$search_id_value = phpbb_request_scalar($_GET, 'search_id');
$search_id = preg_match('/^[1-9][0-9]*$/D', $search_id_value) ? intval($search_id_value) : 0;

if ($search_keywords === '' && !$search_id)
{
	$mode = '';
}

$show_results = 'posts';
$search_terms = (phpbb_request_scalar($_POST, 'search_terms') === 'all') ? 1 : 0;
$search_fields = (phpbb_request_scalar($_POST, 'search_fields') === 'all') ? 1 : 0;
$sort_by = 0;
$sort_dir = (phpbb_request_scalar($_POST, 'sort_dir') === 'ASC') ? 'ASC' : 'DESC';
$start = max(0, min(1000000, intval(phpbb_request_scalar($_GET, 'start', 0))));
$per_page = max(1, min(100, intval($board_config['topics_per_page'])));
$search_results = '';
$total_match_count = 0;
$split_search = array();
$searchset = array();
$stopword_array = array();
$synonym_array = array();
$store_vars = array('search_results', 'total_match_count', 'split_search', 'sort_dir');
$multibyte_charset = 'utf-8, big5, shift_jis, euc-kr, gb2312';
$search_language = preg_match('/^[a-z0-9_-]+$/iD', (string) $board_config['default_lang']) &&
	is_dir($phpbb_root_path . 'language/lang_' . $board_config['default_lang'])
	? (string) $board_config['default_lang']
	: 'english';

switch($mode)
{
    case "results":
		if ($search_keywords !== '')
		{
			$stopword_array = @file($phpbb_root_path . 'language/lang_' . $search_language . '/search_stopwords.txt');
			$synonym_array = @file($phpbb_root_path . 'language/lang_' . $search_language . '/search_synonyms.txt');
			$stopword_array = is_array($stopword_array) ? $stopword_array : array();
			$synonym_array = is_array($synonym_array) ? $synonym_array : array();

			$split_search = (!strstr($multibyte_charset, $lang['ENCODING']))
				? split_words(clean_words('search', stripslashes($search_keywords), $stopword_array, $synonym_array), 'search')
				: preg_split('/\s+/', trim($search_keywords), -1, PREG_SPLIT_NO_EMPTY);
			$split_search = is_array($split_search) ? array_slice($split_search, 0, 50) : array();
			foreach ($split_search as $index => $search_word)
			{
				$split_search[$index] = substr((string) $search_word, 0, 100);
			}

			$search_msg_only = (!$search_fields) ? 'AND m.title_match = 0' : '';
			$word_count = 0;
			$current_match_type = 'or';
			$result_list = array();

			foreach ($split_search as $search_word)
			{
				switch ($search_word)
				{
					case 'and':
					case 'or':
					case 'not':
						$current_match_type = $search_word;
						continue 2;
				}

				if (!empty($search_terms))
				{
					$current_match_type = 'and';
				}

				if (!strstr($multibyte_charset, $lang['ENCODING']))
				{
					$match_word = $db->sql_escape(str_replace('*', '%', $search_word));
					$sql = "SELECT m.article_id
						FROM " . KB_WORD_TABLE . " w, " . KB_MATCH_TABLE . " m, " . KB_ARTICLES_TABLE . " a
						WHERE w.word_text LIKE '$match_word'
							AND m.word_id = w.word_id
							AND a.article_id = m.article_id
							AND a.approved = 1
							AND w.word_common <> 1
							$search_msg_only";
				}
				else
				{
					$match_word = $db->sql_escape('%' . str_replace('*', '', $search_word) . '%');
					$title_sql = $search_fields ? " OR article_title LIKE '$match_word'" : '';
					$sql = "SELECT article_id
						FROM " . KB_ARTICLES_TABLE . "
						WHERE approved = 1
							AND (article_body LIKE '$match_word'$title_sql)";
				}

				if (!($result = $db->sql_query($sql)))
				{
					message_die(GENERAL_ERROR, 'Could not obtain matched articles list', '', __LINE__, __FILE__, $sql);
				}

				$current_results = array();
				while ($temp_row = $db->sql_fetchrow($result))
				{
					$article_id = intval($temp_row['article_id']);
					if ($article_id <= 0)
					{
						continue;
					}
					$current_results[$article_id] = 1;
					if (!$word_count || $current_match_type === 'or')
					{
						$result_list[$article_id] = 1;
					}
					else if ($current_match_type === 'not')
					{
						$result_list[$article_id] = 0;
					}
				}
				$db->sql_freeresult($result);

				if ($current_match_type === 'and' && $word_count)
				{
					foreach ($result_list as $article_id => $matches)
					{
						if (empty($current_results[$article_id]))
						{
							$result_list[$article_id] = 0;
						}
					}
				}
				$word_count++;
			}

			$search_ids = array();
			foreach ($result_list as $article_id => $matches)
			{
				$article_id = intval($article_id);
				if ($matches && $article_id > 0)
				{
					$search_ids[$article_id] = $article_id;
				}
			}
			$search_ids = array_slice(array_values($search_ids), 0, 12000);
			$search_results = implode(', ', $search_ids);
			$total_match_count = count($search_ids);

			$store_search_data = array();
			foreach ($store_vars as $store_var)
			{
				$store_search_data[$store_var] = ${$store_var};
			}
			$result_array_sql = $db->sql_escape(serialize($store_search_data));
			$search_session_id_sql = $db->sql_escape($userdata['session_id']);
			$search_id = mt_rand(1, 2147483647);

			$sql = "UPDATE " . KB_SEARCH_TABLE . "
				SET search_id = $search_id, search_array = '$result_array_sql'
				WHERE session_id = '$search_session_id_sql'";
			if (!($result = $db->sql_query($sql)) || !$db->sql_affectedrows())
			{
				$sql = "INSERT INTO " . KB_SEARCH_TABLE . " (search_id, session_id, search_array)
					VALUES ($search_id, '$search_session_id_sql', '$result_array_sql')";
				if (!($result = $db->sql_query($sql)))
				{
					message_die(GENERAL_ERROR, 'Could not insert search results', '', __LINE__, __FILE__, $sql);
				}
			}
		}
		else if ($search_id)
		{
			$search_session_id_sql = $db->sql_escape($userdata['session_id']);
			$sql = "SELECT search_array
				FROM " . KB_SEARCH_TABLE . "
				WHERE search_id = " . intval($search_id) . "
					AND session_id = '$search_session_id_sql'";
			if (!($result = $db->sql_query($sql)))
			{
				message_die(GENERAL_ERROR, 'Could not obtain search results', '', __LINE__, __FILE__, $sql);
			}
			if ($row = $db->sql_fetchrow($result))
			{
				$search_data = phpbb_safe_unserialize_array($row['search_array']);
				if (!is_array($search_data))
				{
					message_die(GENERAL_MESSAGE, $lang['No_search_match']);
				}

				$cached_ids = isset($search_data['search_results']) && is_scalar($search_data['search_results'])
					? preg_split('/\s*,\s*/', (string) $search_data['search_results'], -1, PREG_SPLIT_NO_EMPTY)
					: array();
				$validated_ids = array();
				foreach (array_slice($cached_ids, 0, 12000) as $cached_id)
				{
					if (preg_match('/^[1-9][0-9]*$/D', (string) $cached_id))
					{
						$validated_ids[intval($cached_id)] = intval($cached_id);
					}
				}
				$search_results = implode(', ', $validated_ids);
				$total_match_count = count($validated_ids);
				$split_search = array();
				if (isset($search_data['split_search']) && is_array($search_data['split_search']))
				{
					foreach (array_slice($search_data['split_search'], 0, 50) as $cached_word)
					{
						if (is_scalar($cached_word))
						{
							$split_search[] = substr((string) $cached_word, 0, 100);
						}
					}
				}
				$sort_dir = (isset($search_data['sort_dir']) && $search_data['sort_dir'] === 'ASC') ? 'ASC' : 'DESC';
			}
			else
			{
				message_die(GENERAL_MESSAGE, $lang['No_search_match']);
			}
			$db->sql_freeresult($result);
		}

		$orig_word = array();
		$replacement_word = array();
		if ($search_results !== '')
		{
			$sql = "SELECT t.*, u.username, u.user_id
				FROM " . KB_ARTICLES_TABLE . " t, " . USERS_TABLE . " u
				WHERE t.article_id IN ($search_results)
					AND t.approved = 1
					AND t.article_author_id = u.user_id
				ORDER BY t.article_title $sort_dir
				LIMIT $start, $per_page";
			if (!($result = $db->sql_query($sql)))
			{
				message_die(GENERAL_ERROR, 'Could not obtain search results', '', __LINE__, __FILE__, $sql);
			}
			while ($row = $db->sql_fetchrow($result))
			{
				$searchset[] = $row;
			}
			$db->sql_freeresult($result);
			obtain_word_list($orig_word, $replacement_word);
		}

		$page_title = $lang['Search'];
		if (!$is_block)
		{
			include($phpbb_root_path . 'includes/page_header.' . $phpEx);
		}
		include($phpbb_root_path . 'includes/kb_header.' . $phpEx);
		$template->set_filenames(array('body' => 'kb_search_results.tpl'));
		make_jumpbox($phpbb_root_path . 'viewforum.' . $phpEx);

		if ($total_match_count > 0)
		{
			$matches_label = ($total_match_count == 1) ? $lang['Found_search_match'] : $lang['Found_search_matches'];
			$template->assign_vars(array('L_SEARCH_MATCHES' => sprintf($matches_label, $total_match_count)));
		}
		else
		{
			$template->assign_vars(array('L_SEARCH_MATCHES' => sprintf($lang['Found_search_matches'], 0)));
			$template->assign_block_vars('no_results', array('NO_RESULTS' => $lang['No_search_match']));
		}

		$highlight_active = '';
		foreach ($split_search as $split_word)
		{
			$split_word = (string) $split_word;
			if ($split_word !== 'and' && $split_word !== 'or' && $split_word !== 'not')
			{
				$highlight_active .= ' ' . $split_word;
				foreach ($synonym_array as $synonym)
				{
					$synonym_parts = preg_split('/\s+/', trim(strtolower($synonym)), 2, PREG_SPLIT_NO_EMPTY);
					if (isset($synonym_parts[1]) && $synonym_parts[0] === $split_word)
					{
						$highlight_active .= ' ' . $synonym_parts[1];
					}
				}
			}
		}
		$highlight_active = urlencode(trim($highlight_active));

		foreach ($searchset as $article)
		{
			$article_id = intval($article['article_id']);
			$article_url = htmlspecialchars(append_sid(this_kb_mxurl("mode=article&amp;k=$article_id&amp;highlight=$highlight_active")), ENT_QUOTES, 'UTF-8');
			$article_title = $article['article_title'];
			if (count($orig_word))
			{
				$article_title = preg_replace($orig_word, $replacement_word, $article_title);
			}
			$article_title = phpbb_profile_text(stripslashes($article_title));
			$category_id = intval($article['article_category_id']);
			$kb_cat = get_kb_cat($category_id);
			$temp_url = htmlspecialchars(append_sid(this_kb_mxurl("mode=cat&amp;cat=$category_id")), ENT_QUOTES, 'UTF-8');
			$category_name = isset($kb_cat['category_name']) ? phpbb_profile_text(stripslashes($kb_cat['category_name'])) : '';
			$category = '<a href="' . $temp_url . '" class="name">' . $category_name . '</a>';
			$type = phpbb_profile_text(stripslashes(get_kb_type((int) $article['article_type'])));
			$author_url = htmlspecialchars(append_sid($phpbb_root_path . "profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . '=' . intval($article['user_id'])), ENT_QUOTES, 'UTF-8');
			$article_author = '<a href="' . $author_url . '" class="name">' . phpbb_profile_text(stripslashes($article['username'])) . '</a>';

			$template->assign_block_vars('searchresults', array(
				'ARTICLE_ID' => $article_id,
				'ARTICLE_AUTHOR' => $article_author,
				'ARTICLE_TITLE' => $article_title,
				'ARTICLE_DESCRIPTION' => phpbb_profile_text(stripslashes($article['article_description'])),
				'ARTICLE_CATEGORY' => $category,
				'ARTICLE_TYPE' => $type,
				'U_VIEW_ARTICLE' => $article_url
			));
		}

		$base_url = this_kb_mxurl_search("mode=results&amp;search_id=$search_id");
		$template->assign_vars(array(
			'PAGINATION' => generate_pagination($base_url, $total_match_count, $per_page, $start),
			'PAGE_NUMBER' => count($searchset) ? sprintf($lang['Page_of'], (floor($start / $per_page) + 1), ceil($total_match_count / $per_page)) : '',
			'L_AUTHOR' => $lang['Author'],
			'L_MESSAGE' => $lang['Message'],
			'L_TOPICS' => $lang['Article'],
			'L_TYPE' => $lang['Article_type'],
			'L_CATEGORY' => $lang['Category']
		));
		break;
		
	default:
	
	    //
		// Output the basic page
		//
		$page_title = $lang['Search'];
 					if ( !$is_block )
 					{
						include($phpbb_root_path . 'includes/page_header.'.$phpEx);
					}	

		include ($phpbb_root_path ."includes/kb_header.".$phpEx);
		
		$template->set_filenames(array(
		    'body' => 'kb_search_body.tpl')
		);
		make_jumpbox($phpbb_root_path .'viewforum.'.$phpEx);

		$template->assign_vars(array(
		    'L_SEARCH_QUERY' => $lang['Search_query'], 
			'L_SEARCH_KEYWORDS' => $lang['Search_keywords'], 
			'L_SEARCH_KEYWORDS_EXPLAIN' => $lang['Search_keywords_explain'], 
			'L_SEARCH_ANY_TERMS' => $lang['Search_for_any'],
			'L_SEARCH_ALL_TERMS' => $lang['Search_for_all'],  

			'S_SEARCH_ACTION' => append_sid(this_kb_mxurl_search("mode=results")),
			'S_HIDDEN_FIELDS' => '',
			'S_SEARCH' => $lang['Search'])
		);
	
	    break;		
}

$template->pparse('body');

//load footer 
include ($phpbb_root_path ."includes/kb_footer.".$phpEx); 

if ( !$is_block )
 {
	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
 }	

?>	
