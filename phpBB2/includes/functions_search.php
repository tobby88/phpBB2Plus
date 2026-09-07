<?php
/***************************************************************************
*                              functions_search.php
*                              -------------------
*     begin                : Wed Sep 05 2001
*     copyright            : (C) 2002 The phpBB Group
*     email                : support@phpbb.com
*
*     $Id: functions_search.php,v 1.8.2.18 2004/03/25 15:57:20 acydburn Exp $
*
****************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

function phpbb_search_return_chars($value)
{
	$value = is_scalar($value) ? intval($value) : 200;
	return ($value === -1) ? -1 : max(0, min(1000, $value));
}

// Excerpts contain visible text only. Count Unicode characters, not UTF-8 bytes
// or the bytes of their HTML entities, and escape again before rendering.
function phpbb_search_excerpt($message, $uid, $length)
{
	$length = max(0, phpbb_search_return_chars($length));
	if ($length === 0)
	{
		return '';
	}
	$message = strip_tags((string) $message);
	if ((string) $uid !== '')
	{
		$message = preg_replace('#\[.*?:' . preg_quote((string) $uid, '#') . ':?.*?\]#si', '', $message);
	}
	$message = preg_replace('#\[/?url(?:=[^\]]*)?\]#i', '', $message);
	$message = html_entity_decode($message, ENT_QUOTES, 'UTF-8');
	// Substitute malformed legacy bytes before asking PCRE to match Unicode.
	$message = html_entity_decode(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES, 'UTF-8');
	preg_match('/\A.{0,' . $length . '}/us', $message, $match);
	$excerpt = isset($match[0]) ? $match[0] : '';
	return htmlspecialchars($excerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ((strlen($excerpt) < strlen($message)) ? ' ...' : '');
}

function phpbb_search_readable_forums($auth)
{
	$forums = array();
	foreach ($auth as $forum_id => $permissions)
	{
		if (intval($forum_id) > 0 && !empty($permissions['auth_view']) && !empty($permissions['auth_read']))
		{
			$forums[] = intval($forum_id);
		}
	}
	return $forums;
}

function phpbb_search_no_results($show_results, $is_ajax)
{
	global $lang;
	if ($is_ajax)
	{
		AJAX_message_die(array('search_id' => 0, 'results' => 0, 'keywords' => ''));
	}
	message_die(GENERAL_MESSAGE, $lang[($show_results === 'bookmarks') ? 'No_Bookmarks' : 'No_search_match']);
}

// Match PHP 8's locale-independent ASCII folding on older supported runtimes.
// Byte-oriented strtolower under a non-C locale can corrupt UTF-8 on PHP 5/7.
// Do not introduce extension-dependent Unicode folding into an existing index.
function phpbb_search_ascii_lower($text)
{
	return strtr((string) $text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
}

function clean_words($mode, $entry, &$stopword_list, &$synonym_list)
{
	static $drop_char_match =   array('^', '$', '&', '(', ')', '<', '>', '`', '\'', '"', '|', ',', '@', '_', '?', '%', '-', '~', '+', '.', '[', ']', '{', '}', ':', '\\', '/', '=', '#', '\'', ';', '!');
	static $drop_char_replace = array(' ', ' ', ' ', ' ', ' ', ' ', ' ', '',  '',   ' ', ' ', ' ', ' ', '',  ' ', ' ', '',  ' ',  ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ' , ' ', ' ', ' ', ' ',  ' ', ' ');

	$entry = ' ' . strip_tags(phpbb_search_ascii_lower($entry)) . ' ';

	if ( $mode == 'post' )
	{
		// Replace line endings by a space
		$entry = preg_replace('/[\n\r]/is', ' ', $entry);
		// HTML entities like &nbsp;
		$entry = preg_replace('/\b&[a-z]+;\b/', ' ', $entry);
		// Remove URL's
		$entry = preg_replace('/\b[a-z0-9]+:\/\/[a-z0-9\.\-]+(\/[a-z0-9\?\.%_\-\+=&\/]+)?/', ' ', $entry);
		// Quickly remove BBcode.
		$entry = preg_replace('/\[img:[a-z0-9]{10,}\].*?\[\/img:[a-z0-9]{10,}\]/', ' ', $entry);
		$entry = preg_replace('/\[\/?url(=.*?)?\]/', ' ', $entry);
		$entry = preg_replace('/\[\/?[a-z\*=\+\-]+(\:?[0-9a-z]+)?:[a-z0-9]{10,}(\:[a-z0-9]+)?=?.*?\]/', ' ', $entry);
	}
	else if ( $mode == 'search' )
	{
		$entry = str_replace(' +', ' and ', $entry);
		$entry = str_replace(' -', ' not ', $entry);
	}

	//
	// Filter out strange characters like ^, $, &, change "it's" to "its"
	//
	for($i = 0; $i < count($drop_char_match); $i++)
	{
		$entry =  str_replace($drop_char_match[$i], $drop_char_replace[$i], $entry);
	}

	if ( $mode == 'post' )
	{
		$entry = str_replace('*', ' ', $entry);

		// 'words' that consist of <3 or >20 characters are removed.
		$entry = preg_replace('/[ ]([\S]{1,2}|[\S]{21,})[ ]/',' ', $entry);
	}

	if ( !empty($stopword_list) )
	{
		for ($j = 0; $j < count($stopword_list); $j++)
		{
			$stopword = trim($stopword_list[$j]);

			if ( $mode == 'post' || ( $stopword != 'not' && $stopword != 'and' && $stopword != 'or' ) )
			{
				$entry = str_replace(' ' . trim($stopword) . ' ', ' ', $entry);
			}
		}
	}

	if ( !empty($synonym_list) )
	{
		for ($j = 0; $j < count($synonym_list); $j++)
		{
			list($replace_synonym, $match_synonym) = explode(' ', trim(phpbb_search_ascii_lower($synonym_list[$j])));
			if ( $mode == 'post' || ( $match_synonym != 'not' && $match_synonym != 'and' && $match_synonym != 'or' ) )
			{
				$entry =  str_replace(' ' . trim($match_synonym) . ' ', ' ' . trim($replace_synonym) . ' ', $entry);
			}
		}
	}

	return $entry;
}

function split_words($entry, $mode = 'post')
{
	// If you experience problems with the new method, uncomment this block.
/*
	$rex = ( $mode == 'post' ) ? "/\b([\w±µ-ÿ][\w±µ-ÿ']*[\w±µ-ÿ]+|[\w±µ-ÿ]+?)\b/" : '/(\*?[a-z0-9±µ-ÿ]+\*?)|\b([a-z0-9±µ-ÿ]+)\b/';
	preg_match_all($rex, $entry, $split_entries);

	return $split_entries[1];
*/
	// Trim 1+ spaces to one space and split this trimmed string into words.
	return explode(' ', trim(preg_replace('#\s+#', ' ', $entry)));
}

function add_search_words($mode, $post_id, $post_text, $post_title = '', $database = null)
{
	global $phpbb_root_path, $board_config, $lang;
	$db = $database !== null ? $database : $GLOBALS['db'];
	$post_ids = phpbb_search_post_ids($post_id);
	if (count($post_ids) !== 1) { message_die(GENERAL_ERROR, 'Invalid search index post selection'); }
	$post_id = $post_ids[0];

	$stopword_array = @file($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . "/search_stopwords.txt");
	$synonym_array = @file($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . "/search_synonyms.txt");

	$search_raw_words = array();
	$search_raw_words['text'] = split_words(clean_words('post', $post_text, $stopword_array, $synonym_array));
	$search_raw_words['title'] = split_words(clean_words('post', $post_title, $stopword_array, $synonym_array));

	@set_time_limit(0);

	$word = array();
	$word_insert_sql = array();
	foreach ($search_raw_words as $word_in => $search_matches)
	{
		$word_insert_sql[$word_in] = '';
		if ( !empty($search_matches) )
		{
			for ($i = 0; $i < count($search_matches); $i++)
			{
				$search_matches[$i] = trim($search_matches[$i]);

				if( $search_matches[$i] != '' )
				{
					$word[] = $search_matches[$i];
					if ( !strstr($word_insert_sql[$word_in], "'" . $db->sql_escape($search_matches[$i]) . "'") )
					{
						$word_insert_sql[$word_in] .= ( $word_insert_sql[$word_in] != "" ) ? ", '" . $db->sql_escape($search_matches[$i]) . "'" : "'" . $db->sql_escape($search_matches[$i]) . "'";
					}
				}
			}
		}
	}

	if ( count($word) )
	{
		sort($word);

		$prev_word = '';
		$word_text_sql = '';
		$temp_word = array();
		for($i = 0; $i < count($word); $i++)
		{
			if ( $word[$i] != $prev_word )
			{
				$temp_word[] = $word[$i];
				$word_text_sql .= ( ( $word_text_sql != '' ) ? ', ' : '' ) . "'" . $db->sql_escape($word[$i]) . "'";
			}
			$prev_word = $word[$i];
		}
		$word = $temp_word;

		$check_words = array();
		switch( SQL_LAYER )
		{
			case 'postgresql':
			case 'msaccess':
			case 'mssql-odbc':
			case 'oracle':
			case 'db2':
				$sql = "SELECT word_id, word_text
					FROM " . SEARCH_WORD_TABLE . "
					WHERE word_text IN ($word_text_sql)";
				if ( !($result = $db->sql_query($sql)) )
				{
					message_die(GENERAL_ERROR, 'Could not select words', '', __LINE__, __FILE__, $sql);
				}

				while ( $row = $db->sql_fetchrow($result) )
				{
					$check_words[$row['word_text']] = $row['word_id'];
				}
				break;
		}

		$value_sql = '';
		$match_word = array();
		for ($i = 0; $i < count($word); $i++)
		{
			$new_match = true;
			if ( isset($check_words[$word[$i]]) )
			{
				$new_match = false;
			}

			if ( $new_match )
			{
				switch( SQL_LAYER )
				{
					case 'mysql':
					case 'mysql4':
					case 'mysqli':
						$value_sql .= ( ( $value_sql != '' ) ? ', ' : '' ) . '(\'' . $db->sql_escape($word[$i]) . '\', 0)';
						break;
					case 'mssql':
					case 'mssql-odbc':
						$value_sql .= ( ( $value_sql != '' ) ? ' UNION ALL ' : '' ) . "SELECT '" . $db->sql_escape($word[$i]) . "', 0";
						break;
					default:
						$sql = "INSERT INTO " . SEARCH_WORD_TABLE . " (word_text, word_common)
							VALUES ('" . $db->sql_escape($word[$i]) . "', 0)";
						if( !$db->sql_query($sql) )
						{
							message_die(GENERAL_ERROR, 'Could not insert new word', '', __LINE__, __FILE__, $sql);
						}
						break;
				}
			}
		}

		if ( $value_sql != '' )
		{
			switch ( SQL_LAYER )
			{
				case 'mysql':
				case 'mysql4':
				case 'mysqli':
					$sql = "INSERT IGNORE INTO " . SEARCH_WORD_TABLE . " (word_text, word_common)
						VALUES $value_sql";
					break;
				case 'mssql':
				case 'mssql-odbc':
					$sql = "INSERT INTO " . SEARCH_WORD_TABLE . " (word_text, word_common)
						$value_sql";
					break;
			}

			if ( !$db->sql_query($sql) )
			{
				message_die(GENERAL_ERROR, 'Could not insert new word', '', __LINE__, __FILE__, $sql);
			}
		}
	}

	foreach ($word_insert_sql as $word_in => $match_sql)
	{
		$title_match = ( $word_in == 'title' ) ? 1 : 0;

		if ( $match_sql != '' )
		{
			$sql = "INSERT INTO " . SEARCH_MATCH_TABLE . " (post_id, word_id, title_match)
				SELECT $post_id, word_id, $title_match
					FROM " . SEARCH_WORD_TABLE . "
					WHERE word_text IN ($match_sql) AND word_common = 0";
			if ( !$db->sql_query($sql) )
			{
				message_die(GENERAL_ERROR, 'Could not insert new word matches', '', __LINE__, __FILE__, $sql);
			}
		}
	}

	if ($mode == 'single')
	{
		remove_common('single', 4/10, $word, $db);
	}

	return;
}

//
// Check if specified words are too common now
//
function remove_common($mode, $fraction, $word_id_list = array(), $database = null)
{
	$db = $database !== null ? $database : $GLOBALS['db'];
	if ($mode === 'single' && !$word_id_list) { return; }

	$sql = "SELECT COUNT(post_id) AS total_posts
		FROM " . POSTS_TABLE;
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not obtain post count', '', __LINE__, __FILE__, $sql);
	}

	$row = $db->sql_fetchrow($result);

	if ( $row['total_posts'] >= 100 )
	{
		$common_threshold = floor($row['total_posts'] * $fraction);

		if ( $mode == 'single' && count($word_id_list) )
		{
			$word_id_sql = '';
			for($i = 0; $i < count($word_id_list); $i++)
			{
				$word_id_sql .= ( ( $word_id_sql != '' ) ? ', ' : '' ) . "'" . $db->sql_escape($word_id_list[$i]) . "'";
			}

			$sql = "SELECT m.word_id
				FROM " . SEARCH_MATCH_TABLE . " m, " . SEARCH_WORD_TABLE . " w
				WHERE w.word_text IN ($word_id_sql)
					AND m.word_id = w.word_id
				GROUP BY m.word_id
				HAVING COUNT(DISTINCT m.post_id) > $common_threshold";
		}
		else
		{
			$sql = "SELECT word_id
				FROM " . SEARCH_MATCH_TABLE . "
				GROUP BY word_id
				HAVING COUNT(DISTINCT post_id) > $common_threshold";
		}

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not obtain common word list', '', __LINE__, __FILE__, $sql);
		}

		$common_word_id = '';
		while ( $row = $db->sql_fetchrow($result) )
		{
			$common_word_id .= ( ( $common_word_id != '' ) ? ', ' : '' ) . $row['word_id'];
		}
		$db->sql_freeresult($result);

		if ( $common_word_id != '' )
		{
			$sql = "UPDATE " . SEARCH_WORD_TABLE . "
				SET word_common = " . TRUE . "
				WHERE word_id IN ($common_word_id)";
			if ( !$db->sql_query($sql) )
			{
				message_die(GENERAL_ERROR, 'Could not delete word list entry', '', __LINE__, __FILE__, $sql);
			}

			$sql = "DELETE FROM " . SEARCH_MATCH_TABLE . "
				WHERE word_id IN ($common_word_id)";
			if ( !$db->sql_query($sql) )
			{
				message_die(GENERAL_ERROR, 'Could not delete word match entry', '', __LINE__, __FILE__, $sql);
			}
		}
	}

	return;
}

// Internal callers may pass one ID, a comma-separated list or an ID array.
// Never let an empty or malformed selection broaden a destructive query.
function phpbb_search_post_ids($selection)
{
	if ($selection === '' || $selection === array()) { return array(); }
	$values = is_array($selection) ? $selection : explode(',', is_int($selection) || is_string($selection) ? (string) $selection : '');
	$ids = array();
	foreach ($values as $value)
	{
		if (!is_int($value) && !is_string($value)) { message_die(GENERAL_ERROR, 'Invalid search index post selection'); }
		$value = trim((string) $value);
		$canonical = ltrim($value, '0');
		if (!preg_match('/^[0-9]+$/D', $value) || $canonical === '' || (string) intval($value) !== $canonical)
		{
			message_die(GENERAL_ERROR, 'Invalid search index post selection');
		}
		$ids[intval($value)] = intval($value);
	}
	return array_values($ids);
}

function remove_search_post($post_id_sql, $remove_subject = true, $remove_message = true, $database = null)
{
	$db = $database !== null ? $database : $GLOBALS['db'];
	$post_ids = phpbb_search_post_ids($post_id_sql);
	if (!$post_ids || (!$remove_subject && !$remove_message)) { return false; }
	$post_id_sql = implode(', ', $post_ids);
	$where_sql = (!$remove_subject || !$remove_message) ? ' AND title_match = ' . ($remove_subject ? 1 : 0) : '';

	$sql = 'SELECT DISTINCT word_id FROM ' . SEARCH_MATCH_TABLE . " WHERE post_id IN ($post_id_sql) $where_sql";
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not obtain search word matches', '', __LINE__, __FILE__, $sql);
	}
	$word_ids = array();
	while ($row = $db->sql_fetchrow($result)) { $word_ids[] = intval($row['word_id']); }
	$db->sql_freeresult($result);

	// Remove the requested references first. A word used by any surviving post,
	// title or body must remain, including references outside this selection.
	$sql = 'DELETE FROM ' . SEARCH_MATCH_TABLE . " WHERE post_id IN ($post_id_sql) $where_sql";
	if (!$db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not delete word match entry', '', __LINE__, __FILE__, $sql);
	}
	if (!$word_ids) { return false; }

	$word_id_sql = implode(', ', $word_ids);
	$sql = 'DELETE FROM ' . SEARCH_WORD_TABLE . " WHERE word_id IN ($word_id_sql) AND word_common = 0"
		. ' AND NOT EXISTS (SELECT 1 FROM ' . SEARCH_MATCH_TABLE
		. ' WHERE ' . SEARCH_MATCH_TABLE . '.word_id = ' . SEARCH_WORD_TABLE . '.word_id)';
	if (!$db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not delete word list entry', '', __LINE__, __FILE__, $sql);
	}
	return $db->sql_affectedrows();
}

//
// Username search
//
function username_search($search_match)
{
	global $db, $board_config, $template, $lang, $images, $theme, $phpEx, $phpbb_root_path;
	global $starttime, $gen_simple_header, $userdata;

	$gen_simple_header = TRUE;

	$username_list = '';
	if ( !empty($search_match) )
	{
		$username_search = preg_replace('/\*/', '%', phpbb_clean_username($search_match));

		$sql = "SELECT username
			FROM " . USERS_TABLE . "
			WHERE username LIKE '" . str_replace("\'", "''", $username_search) . "' AND user_id <> " . ANONYMOUS . "
			ORDER BY username";
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not obtain search results', '', __LINE__, __FILE__, $sql);
		}

		if ( $row = $db->sql_fetchrow($result) )
		{
			do
			{
				$username_list .= '<option value="' . $row['username'] . '">' . $row['username'] . '</option>';
			}
			while ( $row = $db->sql_fetchrow($result) );
		}
		else
		{
			$username_list .= '<option>' . $lang['No_match']. '</option>';
		}
		$db->sql_freeresult($result);
	}

	$page_title = $lang['Search'];
	include($phpbb_root_path . 'includes/page_header.'.$phpEx);

	$template->set_filenames(array(
		'search_user_body' => 'search_username.tpl')
	);

	$template->assign_vars(array(
		'USERNAME' => (!empty($search_match)) ? phpbb_clean_username($search_match) : '',

		'L_CLOSE_WINDOW' => $lang['Close_window'],
		'L_SEARCH_USERNAME' => $lang['Find_username'],
		'L_UPDATE_USERNAME' => $lang['Select_username'],
		'L_SELECT' => $lang['Select'],
		'L_SEARCH' => $lang['Search'],
		'L_SEARCH_EXPLAIN' => $lang['Search_author_explain'],
		'L_CLOSE_WINDOW' => $lang['Close_window'],

		'S_USERNAME_OPTIONS' => $username_list,
		'S_SEARCH_ACTION' => append_sid("search.$phpEx?mode=searchuser"))
	);

	if ( $username_list == '' )
	{
		$template->assign_var('USERNAME_LIST_VIS', 'style="display:none;"');
	}

	$template->pparse('search_user_body');

	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

	return;
}

?>
