<?php

/***************************************************************************
 *                            functions_calendar.php
 *                            ----------------------
 *	begin			: 02/08/2003
 *	copyright		: Ptirhiik
 *	email			: admin@rpgnet-fr.com
 *	version			: 1.1.7 - 15/09/2003
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

include_once($phpbb_root_path . './includes/functions_post.' . $phpEx);
include_once($phpbb_root_path . './includes/bbcode.' . $phpEx);
include_once($phpbb_root_path.'includes/functions_color_groups.'.$phpEx);

// Month navigation keeps the selected day where it exists, otherwise the last
// day of the requested month. Keep the calendar's existing server-local dates.
// false leaves navigation at its supported/native timestamp boundary in place.
function calendar_month_date($month, $year, $day)
{
	$month = (int) $month;
	$year = (int) $year;
	if ($month === 0) { $month = 12; $year--; }
	if ($month === 13) { $month = 1; $year++; }
	if ($month < 1 || $month > 12 || $year < 1971 || $year > 2069) { return false; }
	$first = mktime(0, 0, 0, $month, 1, $year);
	if ($first === false) { return false; }
	$day = max(1, min((int) $day, (int) date('t', $first)));
	return mktime(0, 0, 0, $month, $day, $year);
}

function calendar_normalize_forum_filter($value)
{
	if (!is_scalar($value))
	{
		return '';
	}

	$value = (string) $value;
	if ($value === 'Root')
	{
		return 'Root';
	}
	if (!preg_match('/^([' . preg_quote(POST_FORUM_URL . POST_CAT_URL, '/') . '])(\d+)$/D', $value, $match))
	{
		return '';
	}

	$id = (int) $match[2];
	if ($match[1] === POST_CAT_URL && $id === 0)
	{
		return 'Root';
	}
	return ($id > 0) ? $match[1] . $id : '';
}

// function select
function calendar_get_tree_option($cur='')
{
	global $db, $userdata, $lang;

	// init
	if ( empty($cur) || ($cur == 'Root') )
	{
		$cur = POST_CAT_URL . 0;
	}
	$type = substr($cur, 0, 1);
	$id = intval(substr($cur, 1));
	if ( ($id == 0) || !in_array($type, array(POST_CAT_URL, POST_FORUM_URL)) )
	{
		$type = POST_CAT_URL;
		$id = 0;
	}
	$cur = $type . $id;

	// init res
	$selected = ($cur == POST_CAT_URL . 0) ? ' selected="selected"' : '';
	$res = '<option value="Root"' . $selected . '>' . $lang['Forum_index'] . '</option>';

	// get auth read
	$is_auth = auth(AUTH_ALL, AUTH_LIST_ALL, $userdata);
	$forum_ids = array();
	foreach ($is_auth as $forum_id => $auth)
	{
		if ($auth['auth_read'] && $auth['auth_view'])
		{
			$forum_ids[] = $forum_id;
		}
	}
	if (empty($forum_ids)) return $res;

	// list of forums authed
	$s_forum_list = implode(', ', $forum_ids);
	$sql = "SELECT f.cat_id, c.cat_title, f.forum_id, f.forum_name
				FROM " . FORUMS_TABLE . " AS f, " . CATEGORIES_TABLE . " AS c
				WHERE c.cat_id = f.cat_id 
					AND f.forum_id IN ($s_forum_list)
				ORDER BY cat_order, cat_title, forum_order, forum_name";
	if ( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, 'Couldn not obtain forums/categories informations', '', __LINE__, __FILE__, $sql);
	}
	$cat_id = -1;
	$first = true;
	while ($row = $db->sql_fetchrow($result))
	{
		// category
		if ( ($row['cat_id'] != $cat_id) || $first )
		{
			$first = false;
			$cat_id = $row['cat_id'];
			$fid = POST_CAT_URL . $row['cat_id'];
			$selected = ($cur == $fid) ? ' selected="selected"' : '';
			$res .= sprintf('<option value="%s"%s>|--[ %s ]</option>', $fid, $selected, htmlspecialchars($row['cat_title'], ENT_QUOTES, 'UTF-8'));
		}

		// forum
		$fid = POST_FORUM_URL . $row['forum_id'];
		$selected = ($cur == $fid) ? ' selected="selected"' : '';
		$res .= sprintf('<option value="%s"%s>|&nbsp;&nbsp;&nbsp;|--- %s</option>', $fid, $selected, htmlspecialchars($row['forum_name'], ENT_QUOTES, 'UTF-8'));
	}

	return $res;
}

function calendar_forum_select($selected_id='')
{
	global $db, $userdata, $lang;

	$forum_list = '<select name="selected_id" onchange="forms[\'_calendar\'].submit();">' . calendar_get_tree_option($selected_id) . '</select>';

	return $forum_list;
}

// translate a date for display
function date_dsp($format, $date)
{
	global $board_config, $lang;
	$text = date($format, $date);
	return $board_config['default_lang'] !== 'english' && isset($lang['datetime']) && is_array($lang['datetime'])
		? strtr($text, $lang['datetime']) : $text;
}

function get_calendar_title_date($calendar_start, $calendar_duration)
{
	global $lang, $images, $phpbb_root_path, $phpEx, $board_config, $userdata;
	$calendar_start = intval($calendar_start);
	$calendar_duration = intval($calendar_duration);
	if (empty($calendar_start)) return '';

	// get the component of the date and duration
	$year	= 0;
	$month	= 0;
	$day	= 0;
	$hour	= 0;
	$min	= 0;
	$d_day	= 0;
	$d_hour	= 0;
	$d_min	= 0;
	if (!empty($calendar_start))
	{
		$year	= intval( date('Y', $calendar_start) );
		$month	= intval( date('m', $calendar_start) );
		$day	= intval( date('d', $calendar_start) );
		$hour	= intval( date('H', $calendar_start) );
		$min	= intval( date('i', $calendar_start) );
		if ( !empty($calendar_duration) )
		{
			$d_dur = intval($calendar_duration);
			$d_day = intval($d_dur / 86400);
			$d_dur = $d_dur - 86400 * $d_day;
			$d_hour = intval($d_dur / 3600);
			$d_dur = $d_dur - 3600 * $d_hour;
			$d_min = intval($d_dur / 60);
		}
	}

	// quit if no date
	if ( empty($year) || empty($month) || empty($day) ) return '';

	// raz duration less than 1 day if no time for event start
	if (empty($hour) && empty($min))
	{
		$d_hour = 0;
		$d_min = 0;
	}

	// add the time to start date if present
	$fmt_start = $lang['DATE_FORMAT'];
	if (!empty($hour))
	{
		$fmt_start = $board_config['default_dateformat'];
	}

	// add the time to end date if duration
	$fmt_end = $lang['DATE_FORMAT'];
	if ( !empty($hour) || !empty($d_hour) )
	{
		$fmt_end = $board_config['default_dateformat'];
	}

	// apply it to dates
	$date_start		= date_dsp($fmt_start, $calendar_start);
	$date_end		= date_dsp($fmt_end, $calendar_start + $calendar_duration);

	// add period to the title
	$calendar_icon	= '<a href="' . append_sid( $phpbb_root_path . "./calendar.$phpEx?start=" . date( 'Ymd', $calendar_start)). '"><img src="' . $images['icon_calendar'] . '" hspace="3" border="0" align="top" alt="' . $lang['Calendar_event'] . '" /></a>';
	if (empty($calendar_duration))
	{
		$res = sprintf($lang['Calendar_time'], $date_start);
	}
	else
	{
		$res = sprintf($lang['Calendar_from_to'], $date_start, $date_end);
	}

	return $res;
}

function get_calendar_title($calendar_start, $calendar_duration)
{
	if (empty($calendar_start)) return '';

	$calendar_title = get_calendar_title_date($calendar_start, $calendar_duration);
	if (empty($calendar_title)) return '';

	// send back the full title
	$res = '<span class="gensmall"><br />' . $calendar_title . '</span>';
	return $res;
}

//------------------------------------------------------------------
// Event management : all events are stored in the array events
// ----------------
//	structure of this array :
//
//
//		event_id : letter + id : ie u2 = User, user_id=2
//
//		event_author_id :			id of the author of the event (for topic : topic poster)
//		event_author :				name of the event author
//		event_time :				date-time of the event creation
//
//		event_last_author_id :		for topics : author id of the last reply
//		event_last_author :			for topics : author name of the last reply
//		event_last_time :			for topics : date-time of creation of the last reply
//
//		event_replies :				for topics : number of replies
//		event_views :				for topics : number of views
//		event_type :				for topics : topic type
//		event_vote :				for topics : poll present
//		event_status :				for topics : topic status
//		event_moved_id :			for topics : topic moved id
//		event_last_id :				for topics : last post id
//		event_forum_id :			for topics : forum id
//
//		event_icon :				icon for the event title
//		event_title :				title of the event
//		event_short_title			short title of the event (according to the number of char allowed)
//		event_message :				full message (will be used as the overview flying window)
//		event_calendar_time :		start date-time of the event
//		event_calendar_duration :	duration of the event (in seconds)
//
//		event_link :				link to what should be called when clicking to the link
//		event_txt_class :			class of CSS used to display the title in the calendar cells
//		event_type_icon :			icon set to recognize a type of event in the calendar (full HTML <img src="...)
//------------------------------------------------------------------

//
// topics
//
// Shorten already-rendered preview HTML, retaining complete UTF-8 characters,
// entities, tag attributes and balanced containers. Rendering/escaping happens
// before this helper; it does not grant permission to include raw post HTML.
function calendar_html_excerpt($html, $length)
{
	$length = max(0, min(32767, (int) $length));
	if ($length === 0) { return ''; }
	$html = html_entity_decode(htmlspecialchars((string) $html, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_NOQUOTES, 'UTF-8');
	$parts = preg_split("~(<!--.*?-->|<(?:(?>[^<>\"']+)|\"[^\"]*\"|'[^']*')*>)~s", $html, -1, PREG_SPLIT_DELIM_CAPTURE);
	if (!is_array($parts)) { return $html; }
	// Media is one indivisible item: clipping its fallback content must not
	// remove source/param children such as Flash's allowScriptAccess setting.
	$media = array('object'=>true, 'audio'=>true, 'video'=>true, 'iframe'=>true);
	$whole_media = array();
	for ($index=1; $index < count($parts); $index+=2)
	{
		if (!preg_match('~^<\s*([a-z][a-z0-9]*)\b~i', $parts[$index], $tag) || !isset($media[strtolower($tag[1])])) { continue; }
		$name = strtolower($tag[1]); $depth = 1; $end = null;
		for ($next=$index+2; $next < count($parts); $next+=2)
		{
			if (preg_match('~^<\s*' . $name . '(?:\s|>)~i', $parts[$next])) { $depth++; }
			elseif (preg_match('~^</\s*' . $name . '\s*>$~iD', $parts[$next]) && --$depth === 0) { $end = $next; break; }
		}
		if ($end === null) { return $html; }
		$parts[$index] = implode('', array_slice($parts,$index,$end-$index+1));
		for ($next=$index+1; $next <= $end; $next++) { $parts[$next] = ''; }
		$whole_media[$index] = true;
	}
	$atoms = array('br'=>true, 'hr'=>true, 'img'=>true, 'input'=>true, 'embed'=>true, 'object'=>true, 'audio'=>true, 'video'=>true, 'iframe'=>true);
	$void = array('area'=>true, 'base'=>true, 'br'=>true, 'col'=>true, 'embed'=>true, 'hr'=>true, 'img'=>true, 'input'=>true, 'link'=>true, 'meta'=>true, 'param'=>true, 'source'=>true, 'track'=>true, 'wbr'=>true);
	$total = 0;
	foreach ($parts as $index => $part)
	{
		if ($index % 2)
		{
			if (preg_match('~^<\s*([a-z][a-z0-9]*)\b~i', $part, $tag) && isset($atoms[strtolower($tag[1])])) { $total++; }
		}
		else
		{
			if (strpos($part, '<') !== false) { return $html; }
			$count = preg_match_all('/./us', html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			if ($count === false) { return $html; }
			$total += $count;
		}
		if ($total > $length) { break; }
	}
	if ($total <= $length) { return $html; }
	$out = ''; $stack = array(); $remaining = $length;
	foreach ($parts as $index => $part)
	{
		if ($remaining === 0) { break; }
		if (!($index % 2))
		{
			$text = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$count = preg_match_all('/./us', $text);
			if ($count === false) { return $html; }
			if ($count <= $remaining) { $out .= $part; $remaining -= $count; }
			else
			{
				if (preg_match('/\A.{0,' . $remaining . '}/us', $text, $prefix) !== 1) { return $html; }
				$out .= htmlspecialchars($prefix[0], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'); $remaining = 0;
			}
			continue;
		}
		if (!preg_match('~^<\s*(/?)\s*([a-z][a-z0-9]*)\b~i', $part, $tag)) { $out .= $part; continue; }
		$name = strtolower($tag[2]);
		if (isset($whole_media[$index])) { $out .= $part; $remaining--; continue; }
		if ($tag[1] !== '')
		{
			$distance = array_search($name, array_reverse($stack), true);
			if ($distance !== false) { for ($i=0; $i <= $distance; $i++) { $out .= '</' . array_pop($stack) . '>'; } }
			continue;
		}
		// phpBB lists use optional </li> tags. Close an earlier item in this
		// list, while preserving an outer item around a nested list.
		if ($name === 'li')
		{
			for ($i=count($stack)-1; $i >= 0; $i--)
			{
				if ($stack[$i] === 'ul' || $stack[$i] === 'ol') { break; }
				if ($stack[$i] === 'li') { while (count($stack) > $i) { $out .= '</' . array_pop($stack) . '>'; } break; }
			}
		}
		$out .= $part;
		if (!isset($void[$name]) && !preg_match('~/\s*>$~D', $part)) { $stack[] = $name; }
		if (isset($atoms[$name])) { $remaining--; }
	}
	$out .= '...';
	while ($stack) { $out .= '</' . array_pop($stack) . '>'; }
	return $out;
}

function get_event_topics(&$events, &$number, $start_date, $end_date, $limit=false, $start=0, $max_limit=-1, $fid='')
{
	global $lang, $userdata, $board_config, $db, $tree;
	$number = 0;
	$first = phpbb_date_number($start_date); $last = phpbb_date_number($end_date);
	if ($first === null || $last === null || (int) $last <= (int) $first) { return; }
	$start_date = (int) $first; $end_date = (int) $last;
	$start = is_scalar($start) ? max(0, min(1000000, (int) $start)) : 0;
	if ($max_limit < 0) { $max_limit = $board_config['topics_per_page']; }
	$max_limit = is_scalar($max_limit) ? max(0, min(1000, (int) $max_limit)) : 0;
	$requested_filter = $fid;
	$fid = calendar_normalize_forum_filter($fid);
	if ($fid === '' && $requested_filter !== '' && $requested_filter !== null) { return; }
	$topic_title_length = isset($board_config['calendar_title_length']) ? max(0, min(255, (int) $board_config['calendar_title_length'])) : 30;
	$topic_text_length = isset($board_config['calendar_text_length']) ? max(0, min(32767, (int) $board_config['calendar_text_length'])) : 200;
	$orig_word = array(); $replacement_word = array();
	obtain_word_list($orig_word, $replacement_word);
	$cat_hierarchy = function_exists('get_auth_keys');
	$ids = array();
	if (!$cat_hierarchy)
	{
		$is_auth = auth(AUTH_ALL, AUTH_LIST_ALL, $userdata);
		$is_ask = array();
		if ($fid !== '' && $fid !== 'Root')
		{
			if ($fid[0] === POST_CAT_URL)
			{
				$sql = 'SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE cat_id=' . (int) substr($fid,1);
				$result = $db->sql_query($sql);
				if (!$result) { message_die(GENERAL_ERROR, 'Could not obtain forums informations', '', __LINE__, __FILE__, $sql); }
				try { while ($row = $db->sql_fetchrow($result)) { $is_ask[(int) $row['forum_id']] = true; } }
				finally { $db->sql_freeresult($result); }
			}
			else { $is_ask[(int) substr($fid,1)] = true; }
		}
		foreach ($is_auth as $forum_id => $permission)
		{
			if (!empty($permission['auth_read']) && ($fid === '' || $fid === 'Root' || isset($is_ask[$forum_id]))) { $ids[] = $forum_id; }
		}
	}
	else
	{
		$keys = get_auth_keys($fid === '' ? 'Root' : $fid, true, -1, -1, 'auth_read');
		foreach ($keys['id'] as $i => $key)
		{
			$index = (int) $keys['idx'][$i];
			if ($index >= 0 && isset($tree['type'][$index],$tree['id'][$index]) && $tree['type'][$index] === POST_FORUM_URL && !empty($tree['auth'][$key]['auth_read'])) { $ids[] = $tree['id'][$index]; }
		}
	}
	$forum_ids = array();
	foreach ($ids as $id)
	{
		if ((is_int($id) || is_string($id)) && preg_match('/^[1-9][0-9]{0,4}$/D',(string) $id) && (int) $id <= 65535) { $forum_ids[(int) $id] = (int) $id; }
	}
	if (!$forum_ids) { return; }
	$from = ' FROM ' . TOPICS_TABLE . ' t JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.topic_first_post_id'
		. ' JOIN ' . POSTS_TEXT_TABLE . ' pt ON pt.post_id=t.topic_first_post_id'
		. ' JOIN ' . USERS_TABLE . ' u ON u.user_id=p.poster_id'
		. ' JOIN ' . POSTS_TABLE . ' lp ON lp.post_id=t.topic_last_post_id'
		. ' JOIN ' . USERS_TABLE . ' lu ON lu.user_id=lp.poster_id'
		. ' JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id'
		. ' WHERE t.forum_id IN (' . implode(',',$forum_ids) . ') AND t.topic_calendar_time < ' . $end_date
		. ' AND (t.topic_calendar_time+t.topic_calendar_duration) >= ' . $start_date . ' AND t.topic_status <> ' . TOPIC_MOVED;
	if ($limit)
	{
		$sql = 'SELECT COUNT(*) AS total' . $from;
		$result = $db->sql_query($sql);
		if (!$result) { message_die(GENERAL_ERROR, 'Could not obtain topics information', '', __LINE__, __FILE__, $sql); }
		try { $count = $db->sql_fetchrow($result); $number = (int) $count['total']; }
		finally { $db->sql_freeresult($result); }
		if ($max_limit === 0 || $start >= $number) { return; }
	}
	$sql = 'SELECT t.*,p.poster_id,p.post_username,p.post_author_removed,p.enable_bbcode,p.enable_html,p.enable_smilies,'
		. 'u.username,pt.post_text,pt.bbcode_uid,lp.poster_id AS lp_poster_id,lu.username AS lp_username,'
		. 'lp.post_username AS lp_post_username,lp.post_author_removed AS lp_post_author_removed,lp.post_time AS lp_post_time,f.forum_name'
		. $from . ' ORDER BY t.topic_calendar_time,t.topic_calendar_duration DESC,t.topic_last_post_id DESC';
	if ($limit) { $sql .= ' LIMIT ' . $start . ',' . $max_limit; }
	$result = $db->sql_query($sql);
	if (!$result) { message_die(GENERAL_ERROR, 'Could not obtain topics information', '', __LINE__, __FILE__, $sql); }
	try
	{
		if (!$limit) { $number = $db->sql_numrows($result); }
		while ($row = $db->sql_fetchrow($result)) { $events[] = calendar_topic_event($row,$cat_hierarchy,$orig_word,$replacement_word,$topic_title_length,$topic_text_length); }
	}
	finally { $db->sql_freeresult($result); }
}

function calendar_topic_event($row, $cat_hierarchy, $orig_word, $replacement_word, $topic_title_length, $topic_text_length)
{
	global $template, $lang, $images, $userdata, $board_config, $phpbb_root_path, $phpEx;
	// prepare the message
	$topic_author_id			= $row['poster_id'];
	$topic_author 				= ($row['poster_id'] == ANONYMOUS) ? phpbb_post_author_name($row) : phpbb_stored_text($row['username']);
	$topic_time					= $row['topic_time'];

	$topic_last_author_id		= $row['lp_poster_id'];
	$topic_last_author			= ($row['lp_poster_id'] == ANONYMOUS) ? phpbb_post_author_name($row,'lp_post_username','lp_poster_id','lp_post_author_removed') : phpbb_stored_text($row['lp_username']);
	$topic_last_time			= $row['lp_post_time'];

	$topic_views				= $row['topic_views'];
	$topic_replies				= $row['topic_replies'];

	$topic_icon					= $row['topic_icon'];
	$plain_title = html_entity_decode(phpbb_stored_text($row['topic_title']), ENT_QUOTES, 'UTF-8');
	$forum_name = phpbb_stored_text($row['forum_name']);
	$message               = htmlspecialchars((string) $row['post_text'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
	$bbcode_uid					= $row['bbcode_uid'];
	$topic_calendar_time		= $row['topic_calendar_time'];
	$topic_calendar_duration	= $row['topic_calendar_duration'];
	$topic_link					= append_sid($phpbb_root_path . "./viewtopic.$phpEx?" . POST_TOPIC_URL . "=" . $row['topic_id']);

	// censor topic_title
	if ( count($orig_word) )
	{
		$censored_title = @preg_replace($orig_word, $replacement_word, $plain_title);
		if (is_string($censored_title)) { $plain_title = $censored_title; }
		$message = phpbb_preg_replace_outside_tags($message, $orig_word, $replacement_word);
	}
	$topic_title = phpbb_profile_text($plain_title);
	$short_title = preg_match_all('/./us', $plain_title) > $topic_title_length + 3
		? phpbb_profile_text(phpbb_text_excerpt($plain_title, $topic_title_length)) : $topic_title;
	$dsp_topic_icon = '';
	if (function_exists('get_icon_title'))
	{
		$dsp_topic_icon = get_icon_title($topic_icon, 0, POST_CALENDAR);
	}

	// Render complete source before shortening its generated HTML.
	// Parse bbcodes
	if ($bbcode_uid !== '')
	{
		$message = !empty($board_config['allow_bbcode']) ? bbencode_second_pass($message, $bbcode_uid) : str_ireplace(':' . $bbcode_uid, '', $message);
	}
	// Parse smilies
	if (!empty($board_config['allow_smilies']) && !empty($row['enable_smilies']))
	{
		$message = smilies_pass($message);
	}

	// get the date format
	$fmt = $lang['DATE_FORMAT'];
	if (!empty($topic_calendar_duration))
	{
		$fmt = $board_config['default_dateformat'];
	}

	// replace \n with <br />
	$message = preg_replace("/[\n\r]{1,2}/", '<br />', $message);

	$message = calendar_html_excerpt($message, $topic_text_length);

	// build the overview
	$sav_tpl = $template->_tpldata;
	try
	{
	$det_handler = '_overview_topic_' . $row['topic_id'];
	$template->set_filenames(array(
		$det_handler => 'calendar_overview_topic.tpl')
	);

	$nav_desc = '';
	if ($cat_hierarchy)
	{
		$nav_desc = make_cat_nav_tree(POST_FORUM_URL . $row['forum_id'], '', 'gensmall');
	}
	else
	{
		$nav_desc = '<a href="' . append_sid($phpbb_root_path . "./viewforum.$phpEx?" . POST_FORUM_URL . '=' . $row['forum_id']) . '" class="gensmall">' . $forum_name . '</a>';
	}
	$template->assign_vars(array(
		'L_CALENDAR_EVENT'	=> $lang['Calendar_event'],
		'L_AUTHOR'			=> $lang['Author'],
		'L_TOPIC_DATE'		=> $lang['Date'],
		'L_FORUM'			=> $lang['Forum'],
		'L_VIEWS'			=> $lang['Views'],
		'L_REPLIES'			=> $lang['Replies'],
		'TOPIC_TITLE'		=> $dsp_topic_icon . '&nbsp;' . $topic_title,
		'CALENDAR_EVENT'	=> get_calendar_title_date($topic_calendar_time, $topic_calendar_duration),
		'AUTHOR'			=> $topic_author,
		'TOPIC_DATE'		=> create_date($userdata['user_dateformat'], $topic_time, $board_config['board_timezone']),
		'NAV_DESC'			=> $nav_desc,
		'MESSAGE'			=> $message,
		'VIEWS'				=> $topic_views,
		'REPLIES'			=> $topic_replies,
		)
	);

	$template->assign_var_from_handle('_calendar_overview', $det_handler);
	$message = $template->_tpldata['.'][0]['_calendar_overview'];
	}
	finally { $template->_tpldata = $sav_tpl; }

	// remove \n remaining from the template
	$message = preg_replace("/[\n\r]{1,2}/", '', $message);

	// store only the new values
	$new_row = array();
	$new_row['event_id']				= POST_TOPIC_URL . $row['topic_id'];

	$new_row['event_author_id']			= $topic_author_id;
	$new_row['event_author']			= $topic_author;
	$new_row['event_time']				= $topic_time;

	$new_row['event_last_author_id']	= $topic_last_author_id;
	$new_row['event_last_author']		= $topic_last_author;
	$new_row['event_last_time']			= $topic_last_time;

	$new_row['event_replies']			= $topic_replies;
	$new_row['event_views']				= $topic_views;
	$new_row['event_type']				= $row['topic_type'];
	$new_row['event_vote']				= $row['topic_vote'];
	$new_row['event_status']			= $row['topic_status'];
	$new_row['event_moved_id']			= $row['topic_moved_id'];
	$new_row['event_last_id']			= $row['topic_last_post_id'];
	$new_row['event_forum_id']			= $row['forum_id'];
	$new_row['event_forum_name']		= $forum_name;

	$new_row['event_icon']				= $topic_icon;
	$new_row['event_title']				= $topic_title;
	$new_row['event_short_title']		= $short_title;
	$new_row['event_message']			= $message;
	$new_row['event_calendar_time']		= $topic_calendar_time;
	$new_row['event_calendar_duration']	= $topic_calendar_duration;
	$new_row['event_link']				= $topic_link;
	$new_row['event_txt_class']			= 'genmed';
	$new_row['event_type_icon']			= '<img src="' . $images['icon_tiny_topic'] . '" border="0" align="middle" hspace="2" />';
	return $new_row;
}

//
//
// Birthday MOD calendar events
// 
// Birthday MOD stores whole days from 1970-01-01, not PCP's YYYYMMDD.
// Count and page only occurrences overlapping the requested half-open interval.
function get_birthday(&$events, &$number, $start_date, $end_date, $limit=false, $start=0, $max_limit=-1)
{
    global $lang, $images, $userdata, $board_config, $db, $phpbb_root_path, $phpEx;
    $number = 0;
    // Match the profile and birthday-popup policy, including the user's resolved
    // calendar preference. Guests must not learn hidden birthdates or ages here.
    if (empty($board_config['calendar_birthday']) || empty($userdata['session_logged_in']) ||
        empty($userdata['user_id']) || (int)$userdata['user_id'] <= 0) { return; }
    if (!is_numeric($start_date) || !is_numeric($end_date)) { return; }
    $start_date = (int)$start_date; $end_date = (int)$end_date;
    if ($end_date <= $start_date) { return; }
    $first_year = (int)date('Y', $start_date); $last_year = (int)date('Y', $end_date - 1);
    if ($first_year < 1971 || $last_year > 2070) { return; }
    $start = is_scalar($start) ? max(0, min(1000000, (int)$start)) : 0;
    $max_limit = is_scalar($max_limit) ? (int)$max_limit : 0;
    if ($max_limit < 0) { $max_limit = (int)$board_config['topics_per_page']; }
    $max_limit = max(0, min(1000, $max_limit));
    $sql = 'SELECT user_id, username, user_birthday FROM ' . USERS_TABLE .
        ' WHERE user_id <> ' . ANONYMOUS . ' AND user_birthday <> 999999 ORDER BY username, user_id';
    if (!$result = $db->sql_query($sql))
    {
        message_die(GENERAL_ERROR, 'Could not read user birthdays', '', __LINE__, __FILE__, $sql);
    }
    try
    {
        while ($row = $db->sql_fetchrow($result))
        {
            if ((int)$row['user_id'] <= 0 || !is_scalar($row['user_birthday']) ||
                !preg_match('/^-?[0-9]{1,6}$/D', (string)$row['user_birthday']) ||
                (int)$row['user_birthday'] === 999999) { continue; }
            $birthdate = realdate('Ymd', (int)$row['user_birthday']);
            if (!preg_match('/^([0-9]{4})([0-9]{2})([0-9]{2})$/D', $birthdate, $parts) ||
                (int)$parts[1] < 1901 || (int)$parts[1] > 2099 ||
                !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) { continue; }
            for ($year = max($first_year, (int)$parts[1]); $year <= $last_year; $year++)
            {
                // Preserve the Birthday MOD's March 1 occurrence for February 29
                // in non-leap years. A birthday lasts the local day, including DST.
                $event_time = mktime(0, 0, 0, (int)$parts[2], (int)$parts[3], $year);
                $next_day = mktime(0, 0, 0, (int)date('n', $event_time), (int)date('j', $event_time) + 1, $year);
                if ($event_time >= $end_date || $next_day <= $start_date) { continue; }
                $position = $number++;
                if ($limit && ($position < $start || $position - $start >= $max_limit)) { continue; }
                $user_id = (int)$row['user_id']; $username = phpbb_stored_text($row['username']);
                $age = $year - (int)$parts[1];
                $markup = '<table cellspacing="1" cellpadding="2" border="0" width="100%" class="forumline"><tr><td class="row1" align="center"><b>' .
                    phpbb_profile_text($lang['birthday_header']) . '</b><hr /><span class="genmed">' .
                    sprintf($lang['birthday'], $username) . ($age > 0 ? '&nbsp;[' . $age . ']' : '') . '</span></td></tr></table>';
                $icon = isset($images['icon_birthday']) ? $images['icon_birthday'] : (isset($images['happy_birthday']) ? $images['happy_birthday'] : '');
                $profile_icon = isset($images['icon_tiny_profile']) ? $images['icon_tiny_profile'] : '';
                $events[] = array(
                    'event_id' => POST_USERS_URL . $user_id,
                    'event_author_id' => $user_id, 'event_author' => $row['username'], 'event_time' => $event_time,
                    'event_last_author_id' => '', 'event_last_author' => '', 'event_last_time' => '',
                    'event_replies' => '', 'event_views' => '', 'event_type' => POST_BIRTHDAY, 'event_vote' => '',
                    'event_status' => '', 'event_moved_id' => '', 'event_last_id' => '', 'event_forum_id' => '', 'event_forum_name' => '',
                    'event_icon' => $icon, 'event_title' => $username, 'event_short_title' => $username,
                    'event_message' => htmlspecialchars($markup, ENT_QUOTES, 'UTF-8'),
                    'event_calendar_time' => $event_time, 'event_calendar_duration' => $next_day - $event_time - 1,
                    'event_link' => append_sid($phpbb_root_path . 'profile.' . $phpEx . '?mode=viewprofile&' . POST_USERS_URL . '=' . $user_id),
                    'event_txt_class' => 'genmed',
                    'event_type_icon' => '<img src="' . phpbb_profile_text($profile_icon) . '" border="0" alt="" />'
                );
            }
        }
    }
    finally { $db->sql_freeresult($result); }
}

function display_calendar($main_template, $nb_days=0, $start=0, $fid='')
{
	global $template, $lang, $images, $userdata, $board_config, $db, $phpbb_root_path, $phpEx;
	global $tree;
	static $handler;
	if (empty($handler))
	{
		$handler = 1;
	}
	else
	{
		$handler++;
	}

	$day_of_week = array(
			$lang['datetime']['Sunday'],
			$lang['datetime']['Monday'],
			$lang['datetime']['Tuesday'],
			$lang['datetime']['Wednesday'],
			$lang['datetime']['Thursday'],
			$lang['datetime']['Friday'],
			$lang['datetime']['Saturday'],
		);
	$months = array( 
			' ------------ ',
			$lang['datetime']['January'], 
			$lang['datetime']['February'], 
			$lang['datetime']['March'],
			$lang['datetime']['April'],
			$lang['datetime']['May'],
			$lang['datetime']['June'],
			$lang['datetime']['July'],
			$lang['datetime']['August'],
			$lang['datetime']['September'],
			$lang['datetime']['October'],
			$lang['datetime']['November'],
			$lang['datetime']['December'],
		);

	// get some parameter
	$first_day_of_week = isset($board_config['calendar_week_start']) ? intval($board_config['calendar_week_start']) : 1;
	$nb_row_per_cell = isset($board_config['calendar_nb_row']) ? intval($board_config['calendar_nb_row']) : 5;

	// get the start date - calendar doesn't go before 1971
	$cur_date = (empty($start) || (intval(date('Y', $start)) < 1971) ) ? time() : $start;
	$cur_date = mktime( 0,0,0, intval(date('m', $cur_date)), intval(date('d', $cur_date)), intval(date('Y', $cur_date)) );

	// the full month is displayed
	if (empty($nb_days))
	{
		// set indicator
		$full_month = true;

		// set the start day on the start of the month
		$start_date = mktime( 0,0,0, intval(date('m', $cur_date)), 01, intval(date('Y', $cur_date)) );

		// get the day number set as start of the display
		$cfg_week_day_start = $first_day_of_week;

		// get the number of blank cells
		$start_inc = intval(date('w', $start_date )) - $cfg_week_day_start;
		if ($start_inc < 0)
		{
			$start_inc = 7 + $start_inc;
		}

		//  get the end date
		$year = intval(date('Y', $start_date));
		$month = intval(date('m', $start_date))+1;
		if ($month > 12)
		{
			$year++;
			$month = 1;
		}
		$end_date = mktime( 0,0,0, $month, 01, $year);

		// set the number of cells per line
		$nb_cells = 7;

		// get the number of rows
		$nb_rows = intval( ($start_inc + intval(($end_date - $start_date) / 86400)) / $nb_cells ) + 1;
	}
	else
	{
		// set indicator
		$full_month = false;

		// set the start date to the day before the date selected
		$start_date = mktime( 0,0,0, date('m', $cur_date), date('d', $cur_date)-1, date('Y', $cur_date));

		// get the day number set as start of the week
		$cfg_week_day_start = intval(date('w', $start_date));

		// get the numbe of blank cells
		$start_inc = 0;

		// get the end date
		$end_date = mktime( 0,0,0, date('m', $start_date), date('d', $start_date) + $nb_days, date('Y', $start_date));

		// set the number of cells per line
		$nb_cells = $nb_days;

		// set the number of rows
		$nb_rows = 1;
	}

	//
	// Ok, let's get the various events :)
	//
	$events = array();
	$number = 0;

	// topics
	get_event_topics($events, $number, $start_date, $end_date, false, 0, -1, $fid);

	// birthday
	get_birthday($events, $number, $start_date, $end_date); 
        //
	// And now display them
	//

	// build a list per date
	$map = array();
	for ($i=0; $i < count($events); $i++)
	{
		$event_time = $events[$i]['event_calendar_time'];

		// adjust the event period to the start of day
		$event_time_end = ((int) $event_time) + ((int) $events[$i]['event_calendar_duration']);
		$event_end = mktime( 0,0,0, intval(date('m', $event_time_end)), intval(date('d', $event_time_end)), intval(date('Y', $event_time_end)) );
		$event_start = mktime( 0,0,0, intval(date('m', $event_time)), intval(date('d', $event_time)), intval(date('Y', $event_time)) );

		if ($event_start < $start_date)
		{
			$event_start = $start_date;
		}
		if ($event_end > $end_date)
		{
			$event_end = $end_date;
		}

		// search a free day map offset in the start day
		$event_id = $events[$i]['event_id'];
		$offset_date = $event_start;
		if (!isset($map[$event_start]) || !is_array($map[$event_start]))
		{
			$map[$event_start] = array();
		}
		$map_offset = count($map[$event_start]);
		$found = false;
		for ($k=0; ($k < count($map[$event_start])) && !$found; $k++)
		{
			if ($map[$event_start][$k] == -1)
			{
				$found = true;
				$map_offset = $k;
			}
		}

		// mark the offset as used for the whole event period
		$offset_date = $event_start;
		while ($offset_date <= $event_end)
		{
			if (!isset($map[$offset_date]) || !is_array($map[$offset_date]))
			{
				$map[$offset_date] = array();
			}
			for ($l=count($map[$offset_date]); $l <= $map_offset; $l++)
			{
				$map[$offset_date][$l] = -1;
			}
			$map[$offset_date][$map_offset] = $i;
			$offset_date = mktime( 0,0,0, date('m', $offset_date), date('d', $offset_date)+1, date('Y', $offset_date));
		}
	}

	// template
	$template->set_filenames(array(
		'_calendar_body' . $handler => 'calendar_box.tpl')
	);

	// buid select list for month
	$month = intval(date('m', $start_date));
	$s_month = '<select name="start_month" onchange="forms[\'_calendar\'].submit();" }>';
	for ($i=1; $i < count($months); $i++)
	{
		$selected = ($month == $i) ? ' selected="selected"' : '';
		$s_month .= '<option value="' . $i . '"' . $selected . '>' . $months[$i] . '</option>';
	}
	$s_month .= '</select>';

	// buid select list for year
	$year = intval(date('Y', $start_date));
	$s_year = '<select name="start_year" onchange="forms[\'_calendar\'].submit();">';
	for ($i=1971; $i < 2070; $i++)
	{
		$selected = ($year == $i) ? ' selected="selected"' : '';
		$s_year .= '<option value="' . $i . '"' . $selected . '>' . $i . '</option>';
	}
	$s_year .= '</select>';

	// build a forum select list
	$cat_hierarchy = function_exists('get_auth_keys');
	if (!$cat_hierarchy)
	{
		$s_forum_list = '<select name="selected_id" onchange="forms[\'_calendar\'].submit();">' . calendar_get_tree_option($fid) . '</select>';
	}
	else
	{
		$s_forum_list = '<select name="selected_id" onchange="forms[\'_calendar\'].submit();">' . get_tree_option($fid) . '</select>';
	}

	// header
	$template->assign_vars(array(
		'UP_ARROW'			=> $images['up_arrow'],
		'DOWN_ARROW'		=> $images['down_arrow'],
		'TOGGLE_ICON'		=> intval($board_config['calendar_display_open']) ? $images['up_arrow'] : $images['down_arrow'],
		'TOGGLE_STATUS'		=> intval($board_config['calendar_display_open']) ? '' : 'none',
		)
	);
	$prec = (date('Ym', $start_date) > 197101) ? date('Ymd', mktime(0,0,0, date('m', $start_date)-1, 01, date('Y', $start_date)) ) : date('Ymd', $start_date);
	$next = date( 'Ymd', mktime(0,0,0, date('m', $start_date)+1, 01, date('Y', $start_date)) );
	$template->assign_block_vars('_calendar_box', array(
		'L_CALENDAR'		=> '<a href="' . append_sid($phpbb_root_path . "./calendar.$phpEx?start=" . date( 'Ymd', time())) . '"><img src="' . $images['icon_calendar'] . '" hspace="3" border="0" align="top" alt="' . $lang['Calendar_event'] . '" /></a>' . $lang['Calendar'],
		'L_CALENDAR_TXT'	=> $lang['Calendar'],
		'SPAN_ALL'			=> $nb_cells,
		'S_MONTH'			=> $s_month,
		'S_YEAR'			=> $s_year,
		'S_FORUM_LIST'		=> $s_forum_list,
		'L_GO'				=> $lang['Go'],
		'ACTION'			=> append_sid($phpbb_root_path . "./calendar.$phpEx"),
		'U_PREC'			=> append_sid("./calendar.$phpEx?start=$prec&fid=$fid"),
		'U_NEXT'			=> append_sid("./calendar.$phpEx?start=$next&fid=$fid"),
		)
	);
	if ($full_month)
	{
		$template->assign_block_vars('_calendar_box.switch_full_month', array());
		$offset = $cfg_week_day_start;
		for ($j=0; $j < $nb_cells; $j++)
		{
			if ($offset >= count($day_of_week)) $offset = 0;
			$template->assign_block_vars('_calendar_box.switch_full_month._cell', array(
				'WIDTH'		=> floor(100 / $nb_cells),
				'L_DAY'		=> $day_of_week[$offset],
				)
			);
			$offset++;
		}
	}
	else
	{
		$template->assign_block_vars('_calendar_box.switch_full_month_no', array());
	}

	// display
	$offset_date = mktime( 0,0,0, date('m', $start_date), date('d', $start_date) - $start_inc, date('Y', $start_date));
	for ($i=0; $i < $nb_rows; $i++)
	{
		$template->assign_block_vars('_calendar_box._row', array());
		for ($j=0; $j < $nb_cells; $j++)
		{
			$span = 1;

			// date less than start
			if ( intval(date('Ymd', $offset_date)) < intval(date('Ymd', $start_date)) )
			{
				// compute the cell to span
				$span = $start_inc;
				$j = $start_inc-1;
				$offset_date = mktime( 0,0,0, date('m', $start_date), date('d', $start_date)-1, date('Y', $start_date));
			}

			// date greater than last
			if ( intval(date('Ymd', $offset_date)) >= intval(date('Ymd', $end_date)) )
			{
				// compute the cell to span
				$span = $nb_cells-$j;
				$j = $nb_cells;
			}

			$format = ( intval(date('Ymd', $offset_date)) == intval(date('Ymd', time())) ) ? '<b>%s</b>' : '%s';
			$template->assign_block_vars('_calendar_box._row._cell', array(
				'WIDTH'		=> floor(100 / $nb_cells),
				'SPAN'		=> $span,
				'DATE'		=> sprintf( $format, date_dsp( ($full_month ? '' : 'D ') . $lang['DATE_FORMAT'], $offset_date)),
				'U_DATE'	=> append_sid($phpbb_root_path . "./calendar_scheduler.$phpEx?d=" . $offset_date . "&fid=$fid"),
				)
			);
			// blank cells
			if ( (intval(date('Ymd', $offset_date)) >= intval(date('Ymd', $start_date))) && (intval(date('Ymd', $offset_date)) < intval(date('Ymd', $end_date))) )
			{
				$template->assign_block_vars('_calendar_box._row._cell.switch_filled', array(
					'EVENT_DATE'	=> $offset_date,
					'TOGGLE_STATUS'	=> 'none',
					'TOGGLE_ICON'	=> $images['down_arrow'],
					)
				);

				// send events
				$more = false;
				if (!isset($map[$offset_date]) || !is_array($map[$offset_date]))
				{
					$map[$offset_date] = array();
				}
				$over = ( count($map[$offset_date]) > $nb_row_per_cell);
				for ($k=0; $k < count($map[$offset_date]); $k++)
				{
					// we are just over the limit
					if ( $over && ($k == $nb_row_per_cell) )
					{
						$more = true;
						$template->assign_block_vars('_calendar_box._row._cell.switch_filled._event._more_header', array());
					}

					$ind = $map[$offset_date][$k];
					if ($ind > -1)
					{
						$template->assign_block_vars('_calendar_box._row._cell.switch_filled._event', array(
							'U_EVENT'		=> $events[$ind]['event_link'],
							'EVENT_TYPE'	=> stripslashes($events[$ind]['event_type_icon']),
							'EVENT_TITLE'	=> $events[$ind]['event_short_title'],
							'EVENT_CLASS'	=> $events[$ind]['event_txt_class'],
							'EVENT_MESSAGE'	=> $events[$ind]['event_message'],
							)
						);
					}
					else
					{
						// Multi-day events can leave empty positions in a day's map.
						$template->assign_block_vars('_calendar_box._row._cell.switch_filled._event', array());
					}
					$flag = ( $over && ($k == $nb_row_per_cell-1) );
					if ($ind > -1)
					{
						$template->assign_block_vars('_calendar_box._row._cell.switch_filled._event.switch_event', array());
						if ($flag)
						{
							$template->assign_block_vars('_calendar_box._row._cell.switch_filled._event.switch_event._more', array());
						}
						else
						{
							$template->assign_block_vars('_calendar_box._row._cell.switch_filled._event.switch_event._more_no', array());
						}
					}
					else
					{
						$template->assign_block_vars('_calendar_box._row._cell.switch_filled._event.switch_event_no', array());
						if ($flag)
						{
							$template->assign_block_vars('_calendar_box._row._cell.switch_filled._event.switch_event_no._more', array());
						}
						else
						{
							$template->assign_block_vars('_calendar_box._row._cell.switch_filled._event.switch_event_no._more_no', array());
						}
					}

					if (($k == count($map[$offset_date])-1) && $more)
					{
						$template->assign_block_vars('_calendar_box._row._cell.switch_filled._event._more_footer', array());
					}
				}
			}
			else
			{
				$template->assign_block_vars('_calendar_box._row._cell.switch_filled_no', array());
			}
			$offset_date = mktime( 0,0,0, date('m', $offset_date), date('d', $offset_date)+1, date('Y', $offset_date));
		}
	}

	// fill the main template
	$template->assign_var_from_handle($main_template, '_calendar_body' . $handler);
}

?>
