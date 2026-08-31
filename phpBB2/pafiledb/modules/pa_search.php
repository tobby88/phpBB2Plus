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
		
		
		if(!$this->auth_global['auth_search'])
		{
			if ( !$userdata['session_logged_in'] )
			{
				redirect(append_sid("login.$phpEx?redirect=dload.$phpEx?action=stats", true));
			}
	
			$message = sprintf($lang['Sorry_auth_search'], $this->auth_global['auth_search_type']);
			message_die(GENERAL_MESSAGE, $message);
		}
		
		include($phpbb_root_path . 'includes/functions_search.'.$phpEx);

		$search_keywords = trim(phpbb_request_scalar($_POST, 'search_keywords', phpbb_request_scalar($_GET, 'search_keywords')));
		$search_keywords = substr($search_keywords, 0, 500);
		$search_author = trim(phpbb_request_scalar($_POST, 'search_author', phpbb_request_scalar($_GET, 'search_author')));
		$search_author = substr($search_author, 0, 100);
		$search_id_value = phpbb_request_scalar($_POST, 'search_id', phpbb_request_scalar($_GET, 'search_id'));
		$search_id = preg_match('/^[1-9][0-9]*$/D', $search_id_value) ? intval($search_id_value) : 0;
		$search_terms = (phpbb_request_scalar($_POST, 'search_terms', phpbb_request_scalar($_GET, 'search_terms')) === 'all') ? 1 : 0;
		$cat_id = max(0, intval(phpbb_request_scalar($_POST, 'cat_id', phpbb_request_scalar($_GET, 'cat_id', 0))));
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
		$per_page = max(1, min(100, intval($pafiledb_config['settings_file_page'])));
		$limit_sql = $start . ', ' . $per_page;
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
					$search_author = str_replace('*', '%', trim($search_author));
					$search_author_sql = $db->sql_escape($search_author);

					$sql = "SELECT user_id
						FROM " . USERS_TABLE . "
						WHERE username LIKE '$search_author_sql'
						LIMIT 500";
					if ( !($result = $db->sql_query($sql)) )
					{
						message_die(GENERAL_ERROR, "Couldn't obtain list of matching users", "", __LINE__, __FILE__, $sql);
					}

					$matching_userids = '';
					if ( $row = $db->sql_fetchrow($result) )
					{
						do
						{
							$user_id = intval($row['user_id']);
							if ($user_id > 0)
							{
								$matching_userids .= (($matching_userids != '') ? ', ' : '') . $user_id;
							}
						}
						while( $row = $db->sql_fetchrow($result) );
					}
					else
					{
						message_die(GENERAL_MESSAGE, $lang['No_search_match']);
					}
					if ($matching_userids === '')
					{
						message_die(GENERAL_MESSAGE, $lang['No_search_match']);
					}
				
					$sql = "SELECT * 
						FROM " . PA_FILES_TABLE . " 
						WHERE user_id IN ($matching_userids)
							AND file_approved = 1
						LIMIT 12000";
					
					if ( !($result = $db->sql_query($sql)) )
					{
						message_die(GENERAL_ERROR, 'Could not obtain matched files list', '', __LINE__, __FILE__, $sql);
					}

					$search_ids = array();
					while( $row = $db->sql_fetchrow($result) )
					{
						$file_id = intval($row['file_id']);
						$file_cat_id = intval($row['file_catid']);
						if ($file_id > 0 && !empty($this->auth[$file_cat_id]['auth_view']))
						{
							$search_ids[$file_id] = $file_id;
						}
					}
					$db->sql_freeresult($result);

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
						$split_search[$index] = substr((string) $search_word, 0, 100);
					}

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
						$match_word = $db->sql_escape('%' . str_replace('*', '', $search_word) . '%');
						$current_results = array();

						$sql = "SELECT file_id
							FROM " . PA_FILES_TABLE . "
							WHERE file_name LIKE '$match_word'
								OR file_creator LIKE '$match_word'
								OR file_desc LIKE '$match_word'
								OR file_longdesc LIKE '$match_word'
							LIMIT 12000";
						if (!($result = $db->sql_query($sql)))
						{
							message_die(GENERAL_ERROR, 'Could not obtain matched files list', '', __LINE__, __FILE__, $sql);
						}
						while ($temp_row = $db->sql_fetchrow($result))
						{
							$file_id = intval($temp_row['file_id']);
							if ($file_id > 0)
							{
								$current_results[$file_id] = 1;
							}
						}
						$db->sql_freeresult($result);

						if ($comments_search)
						{
							$sql = "SELECT file_id
								FROM " . PA_COMMENTS_TABLE . "
								WHERE comments_title LIKE '$match_word'
									OR comments_text LIKE '$match_word'
								LIMIT 12000";
							if (!($result = $db->sql_query($sql)))
							{
								message_die(GENERAL_ERROR, 'Could not obtain matched comments list', '', __LINE__, __FILE__, $sql);
							}
							while ($temp_row = $db->sql_fetchrow($result))
							{
								$file_id = intval($temp_row['file_id']);
								if ($file_id > 0)
								{
									$current_results[$file_id] = 1;
								}
							}
							$db->sql_freeresult($result);
						}

						foreach ($current_results as $file_id => $match)
						{
							if (!$word_count || $current_match_type === 'or')
							{
								$result_list[$file_id] = 1;
							}
							else if ($current_match_type === 'not')
							{
								$result_list[$file_id] = 0;
							}
						}
						if ($current_match_type === 'and' && $word_count)
						{
							foreach ($result_list as $file_id => $match)
							{
								if (empty($current_results[$file_id]))
								{
									$result_list[$file_id] = 0;
								}
							}
						}
						if (count($result_list) > 12000)
						{
							$result_list = array_slice($result_list, 0, 12000, true);
						}
						$word_count++;
					}
					$search_ids = array();
					foreach ($result_list as $file_id => $matches)
					{
						$file_id = intval($file_id);
						if ($matches && $file_id > 0)
						{
							$search_ids[$file_id] = $file_id;
						}
					}	
					$search_ids = array_slice(array_values($search_ids), 0, 12000);
					$total_match_count = count($search_ids);
				}
			//
			// Author name search 
			//
				if ( $search_author != '' )
				{
					$search_author = str_replace('*', '%', trim($search_author));
					$search_author_sql = $db->sql_escape($search_author);
				}	

				if ( $total_match_count )
				{			
					$where_sql = ($cat_id) ? 'AND file_catid IN (' . $this->gen_cat_ids($cat_id, '') . ')' : '';

					if ( $search_author == '')
					{
						$sql = "SELECT file_id, file_catid 
							FROM " . PA_FILES_TABLE . "
							WHERE file_id IN (" . implode(", ", $search_ids) . ") 
								$where_sql 
							GROUP BY file_id";
					}
					else
					{
						$from_sql = PA_FILES_TABLE . " f"; 
						if ( $search_author != '' )
						{
							$from_sql .= ", " . USERS_TABLE . " u";
							$where_sql .= " AND u.user_id = f.user_id AND u.username LIKE '$search_author_sql' ";
						}

						$sql = "SELECT f.file_id, f.file_catid
							FROM $from_sql 
							WHERE f.file_id IN (" . implode(", ", $search_ids) . ") 
							$where_sql 
							GROUP BY f.file_id";
					}

					if ( !($result = $db->sql_query($sql)) )
					{
						message_die(GENERAL_ERROR, 'Could not obtain file ids', '', __LINE__, __FILE__, $sql);
					}

					$search_ids = array();
					while( $row = $db->sql_fetchrow($result) )
					{
						$file_id = intval($row['file_id']);
						$file_cat_id = intval($row['file_catid']);
						if ($file_id > 0 && !empty($this->auth[$file_cat_id]['auth_view']))
						{
							$search_ids[$file_id] = $file_id;
						}
					}
					$db->sql_freeresult($result);				
					$search_ids = array_slice(array_values($search_ids), 0, 12000);
					$total_match_count = count($search_ids);
				}
				else
				{
					message_die(GENERAL_MESSAGE, $lang['No_search_match']);
				}
			
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
					$sql = "SELECT search_array 
						FROM " . SEARCH_TABLE . " 
						WHERE search_id = " . intval($search_id) . "
							AND session_id = '$search_session_id_sql'";
					if ( !($result = $db->sql_query($sql)) )
					{
						message_die(GENERAL_ERROR, 'Could not obtain search results', '', __LINE__, __FILE__, $sql);
					}

					if ( $row = $db->sql_fetchrow($result) )
					{
						$search_data = phpbb_safe_unserialize_array($row['search_array']);
						if (!is_array($search_data) || empty($search_data['pafiledb']))
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
						$cached_sort_method = isset($search_data['sort_method']) && is_scalar($search_data['sort_method']) ? (string) $search_data['sort_method'] : '';
						$sort_method = in_array($cached_sort_method, $allowed_sort_methods, true) ? $cached_sort_method : $config_sort_method;
						$sort_order = (isset($search_data['sort_order']) && $search_data['sort_order'] === 'ASC') ? 'ASC' : 'DESC';
					}
					else
					{
						message_die(GENERAL_MESSAGE, $lang['No_search_match']);
					}
					$db->sql_freeresult($result);
				}
			}
		

			if ( $search_results != '' )
			{		
				$sql = "SELECT f1.*,
						(SELECT AVG(r.rate_point) FROM " . PA_VOTES_TABLE . " r WHERE r.votes_file = f1.file_id) AS rating,
						(SELECT COUNT(*) FROM " . PA_VOTES_TABLE . " r WHERE r.votes_file = f1.file_id) AS total_votes,
						u.user_id, u.username, c.cat_id, c.cat_name,
						(SELECT COUNT(*) FROM " . PA_COMMENTS_TABLE . " cm WHERE cm.file_id = f1.file_id) AS total_comments
					FROM " . PA_FILES_TABLE . " f1
					INNER JOIN " . PA_CATEGORY_TABLE . " c ON c.cat_id = f1.file_catid
					LEFT JOIN " . USERS_TABLE . " u ON u.user_id = f1.user_id
					WHERE f1.file_id IN ($search_results)
						AND f1.file_approved = 1
					ORDER BY $sort_method $sort_order
					LIMIT $limit_sql";

				if ( !$result = $db->sql_query($sql) )
				{
					message_die(GENERAL_ERROR, 'Could not obtain search results', '', __LINE__, __FILE__, $sql);
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
							$posticon = '<img src="' . ICONS_DIR . $searchset[$i]['file_posticon'] . '" border="0" />';
						}
					}
					else
					{
						$posticon = '<img src="' . $images['folder_sticky'] . '" border="0" />';
					}
				
					$poster = ( $searchset[$i]['user_id'] != ANONYMOUS ) ? '<a href="' . append_sid('profile.'.$phpEx.'?mode=viewprofile&amp;' . POST_USERS_URL . '=' . $searchset[$i]['user_id']) . '">' : '';
					$poster .= ( $searchset[$i]['user_id'] != ANONYMOUS ) ? $searchset[$i]['username'] : $lang['Guest'];
					$poster .= ( $searchset[$i]['user_id'] != ANONYMOUS ) ? '</a>' : '';

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
