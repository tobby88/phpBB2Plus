<?php
/*
  paFileDB 3.0
  ©2001/2002 PHP Arena
  Written by Todd
  todd@phparena.net
  http://www.phparena.net
  Keep all copyright links on the script visible
  Please read the license included with this script for more information.
*/

class pafiledb_search extends pafiledb_public
{
	function main($action = false)
	{
		global $pafiledb_template, $lang, $board_config, $phpEx, $pafiledb_config, $db, $images;
		global $_POST, $_GET, $phpbb_root_path, $userdata;
		require_once dirname(__DIR__) . '/includes/functions_search.php';
		
		
		if(!$this->auth_global['auth_search'])
		{
			if ( !$userdata['session_logged_in'] )
			{
				redirect(append_sid("login.$phpEx?redirect=dload.$phpEx?action=search", true));
			}
	
			$message = sprintf($lang['Sorry_auth_search'], $this->auth_global['auth_search_type']);
			message_die(GENERAL_MESSAGE, $message);
		}
		
		include_once($phpbb_root_path . 'includes/functions_search.'.$phpEx);

		try
		{
			$search_keywords = pafiledb_search_input(phpbb_request_scalar($_POST, 'search_keywords', phpbb_request_scalar($_GET, 'search_keywords')), 500);
			$search_author = pafiledb_search_input(phpbb_request_scalar($_POST, 'search_author', phpbb_request_scalar($_GET, 'search_author')), 100);
		}
		catch (InvalidArgumentException $error) { message_die(GENERAL_MESSAGE, $lang['No_search_match']); return; }
		$search_id_value = phpbb_request_scalar($_POST, 'search_id', phpbb_request_scalar($_GET, 'search_id'));
		$search_id = preg_match('/^[1-9][0-9]*$/D', $search_id_value) ? intval($search_id_value) : 0;
		$search_terms = (phpbb_request_scalar($_POST, 'search_terms', phpbb_request_scalar($_GET, 'search_terms')) === 'all') ? 1 : 0;
		$cat_id = max(0, intval(phpbb_request_scalar($_POST, 'cat_id', phpbb_request_scalar($_GET, 'cat_id', 0))));
		$search_categories = pafiledb_search_categories($this, $cat_id);
		$search_file_scope = 'f.file_approved=1 AND ' . pafiledb_toplist_scope_sql($search_categories) . ' AND ' . pafiledb_search_author_sql($db, $search_author);
		$comment_categories = array();
		foreach ($search_categories as $category) { if (!empty($this->auth[$category]['auth_view_comment'])) { $comment_categories[] = $category; } }
		$comments_search = (phpbb_request_scalar($_POST, 'comments_search', phpbb_request_scalar($_GET, 'comments_search')) === 'YES') ? 1 : 0;
		$start = max(0, intval(phpbb_request_scalar($_POST, 'start', phpbb_request_scalar($_GET, 'start', 0))));

		$allowed_sort_methods = array('file_name', 'file_time', 'file_dls', 'rating', 'file_update_time');
		$config_sort_method = isset($pafiledb_config['sort_method']) ? (string) $pafiledb_config['sort_method'] : 'file_time';
		if ($config_sort_method === 'file_rating')
		{
			$config_sort_method = 'rating';
		}
		if (!in_array($config_sort_method, $allowed_sort_methods, true))
		{
			$config_sort_method = 'file_time';
		}
		$requested_sort_method = phpbb_request_scalar($_POST, 'sort_method', phpbb_request_scalar($_GET, 'sort_method', $config_sort_method));
		$requested_sort_method = ($requested_sort_method === 'file_rating') ? 'rating' : $requested_sort_method;
		$sort_method = in_array($requested_sort_method, $allowed_sort_methods, true) ? $requested_sort_method : $config_sort_method;

		$config_sort_order = (isset($pafiledb_config['sort_order']) && $pafiledb_config['sort_order'] === 'ASC') ? 'ASC' : 'DESC';
		$requested_sort_order = phpbb_request_scalar($_POST, 'sort_order', phpbb_request_scalar($_GET, 'sort_order', $config_sort_order));
		$sort_order = ($requested_sort_order === 'ASC') ? 'ASC' : 'DESC';
		$list_options = pafiledb_listing_options($sort_method, $sort_order, $start, $pafiledb_config['settings_file_page']);
		$per_page = $list_options['per_page'];
		$search_results = '';
		$total_match_count = 0;
		$split_search = array();
		$searchset = array();
		$search_language = preg_match('/^[a-z0-9_-]+$/iD', (string) $board_config['default_lang']) &&
			is_dir($phpbb_root_path . 'language/lang_' . $board_config['default_lang'])
			? (string) $board_config['default_lang']
			: 'english';
		//
		// encoding match for workaround
		//
		$multibyte_charset = 'utf-8, big5, shift_jis, euc-kr, gb2312';


		if ( isset($_POST['submit']) ||  $search_author != '' || $search_keywords != '' || $search_id )
		{
			if($search_author != '' || $search_keywords != '')
			{
				if ( $search_author != '' && $search_keywords == '' )
				{
					$sql = 'SELECT f.file_id FROM ' . PA_FILES_TABLE . ' f WHERE ' . $search_file_scope . ' ORDER BY f.file_id LIMIT 12000';
					try { $matched = pafiledb_listing_rows($db, $sql); }
					catch (Exception $error) { message_die(GENERAL_ERROR, $lang['PA_search_unavailable']); return; }
					$search_ids = array_map(function($row) { return (int)$row['file_id']; }, $matched);
					$total_match_count = count($search_ids);
				}
				else if ( $search_keywords != '' )
				{
					$stopword_array = @file($phpbb_root_path . 'language/lang_' . $search_language . '/search_stopwords.txt');
					$synonym_array = @file($phpbb_root_path . 'language/lang_' . $search_language . '/search_synonyms.txt');
					$stopword_array = is_array($stopword_array) ? $stopword_array : array();
					$synonym_array = is_array($synonym_array) ? $synonym_array : array();
	
					$split_search = ( !strstr($multibyte_charset, $lang['ENCODING']) ) ? split_words(clean_words('search', stripslashes($search_keywords), $stopword_array, $synonym_array), 'search') : preg_split('/\s+/', trim($search_keywords), -1, PREG_SPLIT_NO_EMPTY);
					$split_search = is_array($split_search) ? array_slice($split_search, 0, 50) : array();
					foreach ($split_search as $index => $search_word)
					{
						$split_search[$index] = pafiledb_search_input((string) $search_word, 100);
					}

					$sql = pafiledb_search_match_sql($db, $search_categories, $comment_categories, $split_search, $search_terms, $search_author, $comments_search);
					try { $matched = pafiledb_listing_rows($db, $sql); }
					catch (Exception $error) { message_die(GENERAL_ERROR, $lang['PA_search_unavailable']); return; }
					$search_ids = array_map(function($row) { return (int)$row['file_id']; }, $matched);
					$total_match_count = count($search_ids);
				}
				try { $search_ids = pafiledb_search_refresh_ids($db, $search_categories, $search_ids, $search_author); }
				catch (Exception $error) { message_die(GENERAL_ERROR, $lang['PA_search_unavailable']); return; }
				$total_match_count = count($search_ids);
				if (!$total_match_count) { message_die(GENERAL_MESSAGE, $lang['No_search_match']); }
			
				//
				// Finish building query (for all combinations)
				// and run it ...
				//
				$current_time = time();
				$session_length = max(60, intval($board_config['session_length']));
				$sql = "DELETE FROM " . SEARCH_TABLE . "
					WHERE search_time < " . ($current_time - $session_length);
				if (!$db->sql_query($sql))
				{
					message_die(GENERAL_ERROR, 'Could not delete old search id sessions', '', __LINE__, __FILE__, $sql);
				}
			
				//
				// Store new result data
				//
				$search_results = implode(', ', $search_ids);
	
				$store_search_data = array(
					'pafiledb' => 1,
					'search_results' => $search_results,
					'total_match_count' => $total_match_count,
					'split_search' => $split_search,
					'sort_method' => $sort_method,
					'sort_order' => $sort_order,
				);

				$result_array_sql = $db->sql_escape(serialize($store_search_data));
				unset($store_search_data);
				$search_session_id_sql = $db->sql_escape($userdata['session_id']);
				$search_id = mt_rand(1, 2147483647);

				$sql = "UPDATE " . SEARCH_TABLE . " 
					SET search_id = $search_id, search_time = $current_time, search_array = '$result_array_sql'
					WHERE session_id = '$search_session_id_sql'";
				if ( !($result = $db->sql_query($sql)) || !$db->sql_affectedrows() )
				{
					$sql = "INSERT INTO " . SEARCH_TABLE . " (search_id, session_id, search_time, search_array)
						VALUES ($search_id, '$search_session_id_sql', $current_time, '$result_array_sql')";
					if ( !($result = $db->sql_query($sql)) )
					{
						message_die(GENERAL_ERROR, 'Could not insert search results', '', __LINE__, __FILE__, $sql);
					}
				}
			}
			else
			{
				if ( $search_id )
				{
					$search_session_id_sql = $db->sql_escape($userdata['session_id']);
					$sql = "SELECT search_array FROM " . SEARCH_TABLE . " WHERE search_id=" . intval($search_id) . " AND session_id='$search_session_id_sql'";
					try { $cached = pafiledb_listing_rows($db, $sql); }
					catch (Exception $error) { message_die(GENERAL_ERROR, $lang['PA_search_unavailable']); return; }

					if (count($cached) === 1)
					{
						$row = $cached[0];
						$search_data = phpbb_safe_unserialize_array($row['search_array']);
						if (!is_array($search_data) || empty($search_data['pafiledb']))
						{
							message_die(GENERAL_MESSAGE, $lang['No_search_match']);
						}
						$validated_ids = pafiledb_search_ids(isset($search_data['search_results']) ? $search_data['search_results'] : array());
						$search_results = implode(', ', $validated_ids);
						$total_match_count = count($validated_ids);
						$split_search = array();
						if (isset($search_data['split_search']) && is_array($search_data['split_search']))
						{
							foreach (array_slice($search_data['split_search'], 0, 50) as $cached_word)
							{
								if (is_scalar($cached_word) && preg_match('//u', (string)$cached_word) === 1)
								{
									$split_search[] = pafiledb_search_input((string) $cached_word, 100);
								}
							}
						}
						$cached_sort_method = isset($search_data['sort_method']) && is_scalar($search_data['sort_method']) ? (string) $search_data['sort_method'] : '';
						$sort_method = in_array($cached_sort_method, $allowed_sort_methods, true) ? $cached_sort_method : $config_sort_method;
						$sort_order = (isset($search_data['sort_order']) && $search_data['sort_order'] === 'ASC') ? 'ASC' : 'DESC';
					}
					else
					{
						message_die(GENERAL_MESSAGE, $lang['No_search_match']);
					}

				}
			}
		

			// Cached identities are only candidates: recheck approval and current
			// permissions before counting, ordering or applying the page limit.
			try { $current_ids = pafiledb_search_refresh_ids($db, $search_categories, $search_results); }
			catch (Exception $error) { message_die(GENERAL_ERROR, $lang['PA_search_unavailable']); return; }
			$total_match_count = count($current_ids);
			$search_results = implode(', ', $current_ids);
			if ($start >= $total_match_count) { $start = $total_match_count ? (int)(floor(($total_match_count - 1) / $per_page) * $per_page) : 0; }
			if ( $search_results != '' )
			{		
				$sql = pafiledb_search_page_sql($search_categories, $current_ids, $sort_method, $sort_order, $start, $per_page);

				if ( !$result = $db->sql_query($sql) )
				{
					message_die(GENERAL_ERROR, $lang['PA_search_unavailable']);
				}
			
				$searchset = array();
				while( $row = $db->sql_fetchrow($result) )
				{
					$searchset[] = $row;
				}
		
				$db->sql_freeresult($result);
			
				$l_search_matches = ( $total_match_count == 1 ) ? sprintf($lang['Found_search_match'], $total_match_count) : sprintf($lang['Found_search_matches'], $total_match_count);
			
				$pafiledb_template->assign_vars(array(
					'L_SEARCH_MATCHES' => $l_search_matches)
				);

				for($i = 0; $i < count($searchset); $i++)
				{
					$cat_url = append_sid('dload.'.$phpEx.'?action=category&cat_id=' . $searchset[$i]['cat_id']);
					$file_url = append_sid('dload.'.$phpEx.'?action=file&file_id=' . $searchset[$i]['file_id']);
					//===================================================
					// Format the date for the given file
					//===================================================

					$date = create_date($board_config['default_dateformat'], $searchset[$i]['file_time'], $board_config['board_timezone']);
		
					//===================================================
					// Get rating for the file and format it
					//===================================================

					$rating = ($searchset[$i]['rating'] != 0) ? round($searchset[$i]['rating'], 2) . ' / 10' : $lang['Not_rated'];

					//===================================================
					// If the file is new then put a new image in front of it
					//===================================================
		
					$is_new = FALSE;
					if (time() - ($pafiledb_config['settings_newdays'] * 24 * 60 * 60) < $searchset[$i]['file_time'])
					{
						$is_new = TRUE;
					}
		
					//===================================================
					// Get the post icon fot this file
					//===================================================
					if ($searchset[$i]['file_pin'] != FILE_PINNED)
					{
						if ($searchset[$i]['file_posticon'] == 'none' || $searchset[$i]['file_posticon'] == 'none.gif') 
						{
							$posticon = '&nbsp;';
						} 
						else 
						{
							$posticon = '<img src="' . htmlspecialchars(ICONS_DIR . $searchset[$i]['file_posticon'], ENT_QUOTES, 'UTF-8') . '" border="0" />';
						}
					}
					else
					{
						$posticon = '<img src="' . htmlspecialchars($images['folder_sticky'], ENT_QUOTES, 'UTF-8') . '" border="0" />';
					}
				
					$poster = pafiledb_toplist_submitter($searchset[$i]);

					$pafiledb_template->assign_block_vars('searchresults', array( 
						'CAT_NAME' => pafiledb_html($searchset[$i]['cat_name']),
						'FILE_NEW_IMAGE' => $images['pa_file_new'],
						'PIN_IMAGE' => $posticon,

						'IS_NEW_FILE' => $is_new,
						'FILE_NAME' => pafiledb_html($searchset[$i]['file_name']),
						'FILE_DESC' => pafiledb_html($searchset[$i]['file_desc']),
						'FILE_SUBMITER' => $poster,
						'DATE' => $date,
						'RATING' => $rating,
						'DOWNLOADS' => $searchset[$i]['file_dls'],
						'U_FILE' => $file_url,
						'U_CAT' => $cat_url)
					);
				}
				$base_url = append_sid("dload.$phpEx?action=search&amp;search_id=$search_id");

				$pafiledb_template->assign_vars(array(
					'PAGINATION' => generate_pagination($base_url, $total_match_count, $per_page, $start),
					'PAGE_NUMBER' => sprintf($lang['Page_of'], (floor($start / $per_page) + 1), ceil($total_match_count / $per_page)),
					'DOWNLOAD' => $pafiledb_config['settings_dbname'],
	
					'U_INDEX' => append_sid('index.'.$phpEx),
					'U_DOWNLOAD' => append_sid('dload.'.$phpEx),

					'L_INDEX' => sprintf($lang['Forum_Index'], $board_config['sitename']),
					'L_RATE' => $lang['DlRating'],
					'L_DOWNLOADS' => $lang['Dls'],
					'L_DATE' => $lang['Date'],
					'L_NAME' => $lang['Name'],
					'L_FILE' => $lang['File'],
					'L_SUBMITER' => $lang['Submiter'],
					'L_CATEGORY' => $lang['Category'],
					'L_NEW_FILE' => $lang['New_file'])
				);
			
				$this->display($lang['Download'], 'pa_search_result.tpl');
			}
			else
			{
				message_die(GENERAL_MESSAGE, $lang['No_search_match']);
			}
		}
		if ( !isset($_POST['submit']) || ($search_author == '' && $search_keywords == '' && !$search_id)  )
		{
			$dropmenu = $this->jumpmenu_option();

			$pafiledb_template->assign_vars(array(
				'S_SEARCH_ACTION' => append_sid('dload.php'),
				'S_CAT_MENU' => $dropmenu,

				'DOWNLOAD' => $pafiledb_config['settings_dbname'],
	
				'U_INDEX' => append_sid('index.'.$phpEx),
				'U_DOWNLOAD' => append_sid('dload.'.$phpEx),

				'L_YES' => $lang['Yes'],
				'L_NO' => $lang['No'],
				'L_SEARCH_OPTIONS' => $lang['Search_options'], 
				'L_SEARCH_KEYWORDS' => $lang['Search_keywords'], 
				'L_SEARCH_KEYWORDS_EXPLAIN' => $lang['Search_keywords_explain'], 
				'L_SEARCH_AUTHOR' => $lang['Search_author'],
				'L_SEARCH_AUTHOR_EXPLAIN' => $lang['Search_author_explain'], 
				'L_SEARCH_ANY_TERMS' => $lang['Search_for_any'],
				'L_SEARCH_ALL_TERMS' => $lang['Search_for_all'], 
				'L_INCLUDE_COMMENTS' => $lang['Include_comments'],
				'L_SORT_BY' => $lang['Select_sort_method'],
				'L_SORT_DIR' => $lang['Order'],
				'L_SORT_ASCENDING' => $lang['Sort_Ascending'],
				'L_SORT_DESCENDING' => $lang['Sort_Descending'],
				'L_INDEX' => sprintf($lang['Forum_Index'], $board_config['sitename']),
				'L_RATING' => $lang['DlRating'],
				'L_DOWNLOADS' => $lang['Dls'],
				'L_DATE' => $lang['Date'],
				'L_NAME' => $lang['Name'],
				'L_UPDATE_TIME' => $lang['Update_time'],
				'L_SEARCH' => $lang['Search'],
				'L_SEARCH_FOR' => $lang['Search_for'],
				'L_ALL' => $lang['All'],
				'L_CHOOSE_CAT' => $lang['Choose_cat'])
			);         
			$this->display($lang['Download'], 'pa_search_body.tpl');
		}
	}
}

?>
