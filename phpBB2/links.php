<?php
/***************************************************************************
 *                             links.php
 *                            -----------
 *  MOD add-on page. Contains GPL code copyright of phpBB group.
 *  Author: OOHOO < webdev@phpbb-tw.net >
 *  Author: Stefan2k1 and ddonker from www.portedmods.com
 *  Author: CRLin from http://mail.dhjh.tcc.edu.tw/~gzqbyr/
 *  Demo: http://phpbb-tw.net/
 *  Version: 1.0.X - 2002/03/22 - for phpBB RC serial, and was named Related_Links_MOD
 *  Version: 1.1.0 - 2002/04/25 - Re-packed for phpBB 2.0.0, and renamed to Links_MOD
 *  Version: 1.2.0 - 2003/06/15 - Enhanced and Re-packed for phpBB 2.0.4
 *  Version: 1.2.1 - 2003/10/15 - Enhanced by CRLin
 *  Version: 1.2.2 - 2004/05/10 - Enhanced by CRLin
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

$phpbb_root_path = "./"; 
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . "common.$phpEx");

function links_html($value)
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', false);
}

function links_http_url($value)
{
	$url = phpbb_normalize_external_url($value);
	return ($url === false) ? '' : $url;
}

function links_image_url($value)
{
	$value = html_entity_decode(trim((string) $value), ENT_QUOTES, 'UTF-8');
	$external_url = links_http_url($value);
	if ($external_url !== '')
	{
		return $external_url;
	}

	$local_path = phpbb_profile_asset_path($value);
	return ($local_path !== '' && preg_match('/\.(?:gif|jpe?g|png|webp)$/iD', $local_path)) ? $local_path : '';
}

function links_like_sql($value)
{
	global $db;

	$value = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), (string) $value);
	return $db->sql_escape($value);
}

function links_logo_html($row, $link_config, $phpEx)
{
	$url = append_sid("links.$phpEx?action=go&amp;link_id=" . intval($row['link_id']));
	$logo = links_image_url($row['link_logo_src']);
	if ($logo === '')
	{
		$logo = 'images/links/weblink_88x31.png';
	}
	return '<a href="' . links_html($url) . '" target="_blank" rel="noopener noreferrer">'
		. '<img src="' . links_html($logo) . '" alt="' . links_html($row['link_title'])
		. '" width="' . max(1, min(1000, intval($link_config['width']))) . '" height="'
		. max(1, min(1000, intval($link_config['height']))) . '" border="0" /></a>';
}

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_LINKS);
init_userprefs($userdata);
require($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_main_link.' . $phpEx);

//
// Count and forwrad
//
if(!empty($_GET['action']) && is_scalar($_GET['action']) && $_GET['action'] == "go" && !empty($_GET['link_id']) && is_scalar($_GET['link_id']))
{
	$link_id = max(0, intval($_GET['link_id']));
	// Secure check
	if($link_id > 0)
	{
		$sql = "SELECT link_id, link_url, last_user_ip
			FROM " . LINKS_TABLE . "
			WHERE link_id = $link_id
			AND link_active = 1";

		if($result = $db->sql_query($sql))
		{
			$row = $db->sql_fetchrow($result);
			if($row && ($link_url = links_http_url($row['link_url'])))
			{
				if($user_ip != $row['last_user_ip'])
				{
					$user_ip_sql = $db->sql_escape($user_ip);
					// Update
					$sql = "UPDATE " . LINKS_TABLE . "
						SET link_hits = link_hits + 1, last_user_ip = '$user_ip_sql'
						WHERE link_id = $link_id";
					$result = $db->sql_query($sql);
				}

				header('Location: ' . $link_url, true, 302);
				exit;
			}
		}
	}
}

// Output the basic page
$page_title = $lang['Site_links'];
include('includes/page_header.'.$phpEx);

//
// Define initial vars
//
$start = (isset($HTTP_GET_VARS['start']) && is_scalar($HTTP_GET_VARS['start'])) ? max(0, min(1000000, intval($HTTP_GET_VARS['start']))) : 0;

if ( (isset($_POST['t']) && is_scalar($_POST['t'])) || (isset($_GET['t']) && is_scalar($_GET['t'])) )
{
	$t = ( isset($_POST['t']) && is_scalar($_POST['t']) ) ? (string) $_POST['t'] : (string) $_GET['t'];
} else {
	$t = 'index';
}
if ( (isset($_POST['cat']) && is_scalar($_POST['cat'])) || (isset($_GET['cat']) && is_scalar($_GET['cat'])) )
{
	$cat = ( isset($_POST['cat']) && is_scalar($_POST['cat']) ) ? intval($_POST['cat']) : intval($_GET['cat']);
} else {
	$cat = 1;
}
if ( (isset($_POST['search_keywords']) && is_scalar($_POST['search_keywords'])) || (isset($_GET['search_keywords']) && is_scalar($_GET['search_keywords'])) )
{
	$search_keywords = ( isset($_POST['search_keywords']) && is_scalar($_POST['search_keywords']) ) ? (string) $_POST['search_keywords'] : (string) $_GET['search_keywords'];
} else {
	$search_keywords = '';
}

switch($t)
{
	case 'pop':
	case 'new':
		$tmp = "links_popnew.tpl";
		break;
	case 'search':
		$tmp = "links_search.tpl";
		break;
	case 'sub_pages':
		$tmp = "links_body.tpl";
		break;
	default:
		$tmp = "links_index.tpl";
}

$template->set_filenames(array(
	'body' => $tmp
));

//
// Get Link Config
//
$sql = "SELECT *
		FROM ". LINK_CONFIG_TABLE;
if(!$result = $db->sql_query($sql))
{
	message_die(GENERAL_ERROR, "Could not query Link config information", "", __LINE__, __FILE__, $sql);
}
$link_config = array(
	'width' => 88,
	'height' => 31,
	'linkspp' => 10,
	'display_links_logo' => 1,
	'lock_submit_site' => 0,
	'allow_no_logo' => 0,
	'site_logo' => '',
	'site_url' => '',
);
while( $row = $db->sql_fetchrow($result) )
{
	$link_config_name = $row['config_name'];
	$link_config_value = $row['config_value'];
	$link_config[$link_config_name] = $link_config_value;
}
$link_config['width'] = max(1, min(1000, intval($link_config['width'])));
$link_config['height'] = max(1, min(1000, intval($link_config['height'])));
$linkspp = max(1, min(100, intval($link_config['linkspp'])));
$site_logo = links_image_url($link_config['site_logo']);
$site_logo = ($site_logo !== '') ? $site_logo : 'images/links/web_logo88a.gif';
$site_url = links_http_url($link_config['site_url']);
$link_us_syntax = sprintf($lang['Link_us_syntax'], $site_url, $site_logo, $link_config['width'], $link_config['height'], $board_config['sitename']);
$link_cat_option = '';
$pagination = '&nbsp;';
$total_links = 0;

if($link_config['lock_submit_site'] == 0)
{
	// display submit site
	$template->assign_block_vars('lock', array());

 	if(!$userdata['session_logged_in'])
	{
		$template->assign_block_vars('lock.logout', array());
	}

	if($userdata['session_logged_in'])
	{
		$template->assign_block_vars('lock.submit', array());
	}
}

if($link_config['allow_no_logo'])
{
	$tmp = $lang['Link_logo_src'];
}
else
{
	$tmp = $lang['Link_logo_src1'];
}

$template->assign_vars(array(
	'U_LINK_REG' => append_sid("link_register.$phpEx"),
	'S_LINK_TOKEN' => '<input type="hidden" name="sid" value="' . links_html($userdata['session_id']) . '" />',
	'L_LINK_REGISTER_RULE' => $lang['Link_register_rule'],
	'L_LINK_REGISTER_GUEST_RULE' => $lang['Link_register_guest_rule'],
	'L_LINK_TITLE' => $lang['Link_title'],
	'L_LINK_DESC' => $lang['Link_desc'],
	'L_LINK_URL' => $lang['Link_url'],
	'L_LINK_LOGO_SRC' => $tmp,
	'L_PREVIEW' => $lang['Links_Preview'],
	'L_LINK_CATEGORY' => $lang['Link_category'],
	'L_PLEASE_ENTER_YOUR' => $lang['Please_enter_your'],
	'L_LINK_REGISTER' => $lang['Link_register'],
	'L_SITE_LINKS' => $lang['Site_links'],
	'L_LINK_US' => links_html($lang['Link_us'] . $board_config['sitename']),
	'L_LINK_US_EXPLAIN' => sprintf($lang['Link_us_explain'], links_html($board_config['sitename'])), 'L_SUBMIT' => $lang['Submit'],
	'U_SITE_LINKS' => append_sid("links.$phpEx"),
	'L_LINK_CATEGORY' => $lang['Link_category'],
	'U_SITE_SEARCH' => append_sid("links.$phpEx?t=search"),
	'U_SITE_TOP' => append_sid("links.$phpEx?t=pop"),
	'U_SITE_NEW' => append_sid("links.$phpEx?t=new"),
	'U_SITE_LOGO' => links_html($site_logo),
	'LINK_US_SYNTAX' => links_html($link_us_syntax),
	'LINKS_HOME' => $lang['Links_home'],
	'L_SEARCH_SITE' => $lang['Search_site'],
	'L_DESCEND_BY_HITS' => $lang['Descend_by_hits'],
	'L_DESCEND_BY_JOINDATE' => $lang['Descend_by_joindate'],
	'L_LINK_JOINED' => $lang['Joined'],
	'L_LINK_HITS' => $lang['link_hits'],
	'L_LINK_SUBMITER' => $lang['link_submiter']
));

if ($t=='pop' || $t=='new') 
{
	if ($t=='pop')
	{
		$template->assign_vars(array(
			'L_LINK_TITLE1' => $lang['Descend_by_hits']
		));
	}
	else
	{
		$template->assign_vars(array(
			'L_LINK_TITLE1' => $lang['Descend_by_joindate']
		));
	}

	//
	// Grab link categories
	//
	$sql = "SELECT cat_id, cat_title FROM " . LINK_CATEGORIES_TABLE . " ORDER BY cat_order";

	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not query link categories list', '', __LINE__, __FILE__, $sql);
	}

	while($row = $db->sql_fetchrow($result))
	{
		$link_categories[$row['cat_id']] = $row['cat_title'];
	}

	//
	// Grab links
	//
	$sql = "SELECT * 
		FROM " . LINKS_TABLE . " l, " . USERS_TABLE . " u
		WHERE link_active = 1 AND l.user_id = u.user_id
		ORDER BY link_hits DESC, link_id DESC
		LIMIT $start, $linkspp";
	if ($t == 'new')
	{
		$sql = "SELECT * 
			FROM " . LINKS_TABLE . " l, " . USERS_TABLE . " u
			WHERE link_active = 1 AND l.user_id = u.user_id
			ORDER BY link_joined DESC, link_id DESC
			LIMIT $start, $linkspp";
	}

	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not query links list', '', __LINE__, __FILE__, $sql);
	}

	if ( $row = $db->sql_fetchrow($result) )
	{
		$i = 0;
		do
		{
			// if (empty($row['link_logo_src'])) $row['link_logo_src'] = 'images/links/no_logo88a.gif';
			if ($link_config['display_links_logo'])
			{
				$tmp = links_logo_html($row, $link_config, $phpEx);
			}
			else
			{
				$tmp = $lang['No_Display_Links_Logo'];
			}

			$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];
			$user_id = $row['user_id'];
			$username = $row['username'];

			$template->assign_block_vars("linkrow", array(
				'ROW_CLASS' => $row_class,
				'LINK_URL' => append_sid("links.$phpEx?action=go&amp;link_id=" . (int) $row['link_id']),
				'LINK_TITLE' => links_html($row['link_title']),
				'LINK_DESC' => links_html($row['link_desc']),
				'LINK_LOGO_SRC' => links_html(links_image_url($row['link_logo_src'])),
				'LINK_LOGO' => $tmp,
				'LINK_CATEGORY' => links_html(isset($link_categories[$row['link_category']]) ? $link_categories[$row['link_category']] : ''),
				'LINK_JOINED' => create_date($lang['DATE_FORMAT'], (int) $row['link_joined'], $board_config['board_timezone']),
				'LINK_HITS' => (int) $row['link_hits'],
				'U_LINK_USER' => ($user_id != ANONYMOUS ? ("<a href=\"profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=" . intval($user_id) . "\" target=\"_blank\">" . links_html($username) . "</a>") : links_html($username))
			));
			$i++;
		}
		while ( $row = $db->sql_fetchrow($result) );
	}

	//
	// Pagination
	//
	$sql = "SELECT count(*) AS total
		FROM " . LINKS_TABLE . "
		WHERE link_active = 1";

	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not query links number', '', __LINE__, __FILE__, $sql);
	}

	if ( $row = $db->sql_fetchrow($result) )
	{
		$total_links = $row['total'];
		$pagination = generate_pagination("links.$phpEx?t=$t", $total_links, $linkspp, $start). '&nbsp;';
	}
	else
	{
		$pagination = '&nbsp;';
		$total_links = 10;
	}

	//
	// Link categories dropdown list
	//
	foreach($link_categories as $cat_id => $cat_title)
	{
		$link_cat_option .= '<option value="' . intval($cat_id) . '">' . links_html($cat_title) . '</option>';
	}

	
	$template->assign_vars(array(
		'PAGINATION' => $pagination,
		'PAGE_NUMBER' => sprintf($lang['Page_of'], ( floor( $start / $linkspp ) + 1 ), max(1, ceil( $total_links / $linkspp ))),
		'L_GOTO_PAGE' => $lang['Goto_page'],

		'LINK_CAT_OPTION' => $link_cat_option
	));

	$template->pparse('body');

	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
	exit;
}

if ($t=='sub_pages') 
{
	if ( (isset($_GET['mode']) && is_scalar($_GET['mode'])) || (isset($_POST['mode']) && is_scalar($_POST['mode'])) )
	{
		$mode = ( isset($_POST['mode']) && is_scalar($_POST['mode']) ) ? (string) $_POST['mode'] : (string) $_GET['mode'];
	}
	else
	{
		$mode = 'link_joined';
	}

	if(isset($_POST['order']) && is_scalar($_POST['order']))
	{
		$sort_order = ($_POST['order'] == 'ASC') ? 'ASC' : 'DESC';
	}
	else if(isset($_GET['order']) && is_scalar($_GET['order']))
	{
		$sort_order = ($_GET['order'] == 'ASC') ? 'ASC' : 'DESC';
	}
	else
	{
		$sort_order = 'DESC';
	}

	//
	// Links sites sorting
	//
	$mode_types_text = array($lang['link_hits'], $lang['Joined'], $lang['Link_title'], $lang['Link_desc']);
	$mode_types = array('link_hits', 'link_joined', 'link_title', 'link_desc');
	if (!in_array($mode, $mode_types, true))
	{
		$mode = 'link_joined';
	}

	$select_sort_mode = '<select name="mode">';
	for($i = 0; $i < count($mode_types_text); $i++)
	{
		$selected = ( $mode == $mode_types[$i] ) ? ' selected="selected"' : '';
		$select_sort_mode .= '<option value="' . $mode_types[$i] . '"' . $selected . '>' . $mode_types_text[$i] . '</option>';
	}
	$select_sort_mode .= '</select>';

	$select_sort_order = '<select name="order">';
	if($sort_order == 'ASC')
	{
		$select_sort_order .= '<option value="ASC" selected="selected">' . $lang['Sort_Ascending'] . '</option><option value="DESC">' . $lang['Sort_Descending'] . '</option>';
	}
	else
	{
		$select_sort_order .= '<option value="ASC">' . $lang['Sort_Ascending'] . '</option><option value="DESC" selected="selected">' . $lang['Sort_Descending'] . '</option>';
	}
	$select_sort_order .= '</select>';

	$select_sort_order = $select_sort_order . '<input type="hidden" name="t" value="' . $t .'">';
	$select_sort_order = $select_sort_order . '<input type="hidden" name="cat" value="' . $cat .'">';

	$template->assign_vars(array(
		'L_SEARCH_SITE' => $lang['Search_site'],
		'L_SELECT_SORT_METHOD' => $lang['Select_sort_method'],
		'L_ORDER' => $lang['Order'],
		'L_SORT' =>  $lang['Sort'],
		'U_SITE_LINKS_CAT' => append_sid("links.$phpEx?t=$t&amp;cat=$cat"),
		'S_MODE_SELECT' => $select_sort_mode,
		'S_ORDER_SELECT' => $select_sort_order
	));

	//
	// Grab link categories
	//
	$sql = "SELECT cat_id, cat_title FROM " . LINK_CATEGORIES_TABLE . " WHERE cat_id = $cat";

	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not query link categories list', '', __LINE__, __FILE__, $sql);
	}

	$row = $db->sql_fetchrow($result);
	if (!$row)
	{
		message_die(GENERAL_MESSAGE, $lang['Link_category_not_exist']);
	}
	$link_categories[$row['cat_id']] = $row['cat_title'];
	$template->assign_vars(array(
		'LINK_CATEGORY' => links_html($row['cat_title'])
	));

	//
	// Grab links
	//
	$sql = "SELECT l.*, u.username
			FROM " . LINKS_TABLE . " l, " . USERS_TABLE . " u
			WHERE l.link_active = 1 AND l.link_category = $cat AND l.user_id = u.user_id
			ORDER BY $mode $sort_order, l.link_id DESC
			LIMIT $start, $linkspp";
	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not query links list', '', __LINE__, __FILE__, $sql);
	}


	if ( $row = $db->sql_fetchrow($result) )
	{
		$i = 0;
		do
		{
			//if (empty($row['link_logo_src'])) $row['link_logo_src'] = 'images/links/no_logo88a.gif';
			if ($link_config['display_links_logo'])
			{
				$tmp = links_logo_html($row, $link_config, $phpEx);
			}
			else
			{
				$tmp = $lang['No_Display_Links_Logo'];
			}
			
			$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];
			$user_id = $row['user_id'];
			$username = $row['username'];

			$template->assign_block_vars("linkrow", array(
				'ROW_CLASS' => $row_class,
				'LINK_URL' => append_sid("links.$phpEx?action=go&amp;link_id=" . (int) $row['link_id']),
				'LINK_TITLE' => links_html($row['link_title']),
				'LINK_DESC' => links_html($row['link_desc']),
				'LINK_LOGO_SRC' => links_html(links_image_url($row['link_logo_src'])),
				'LINK_LOGO' => $tmp,
				'LINK_CATEGORY' => links_html(isset($link_categories[$row['link_category']]) ? $link_categories[$row['link_category']] : ''),
				'LINK_JOINED' => create_date($lang['DATE_FORMAT'], (int) $row['link_joined'], $board_config['board_timezone']),
				'LINK_HITS' => (int) $row['link_hits'],
				'U_LINK_USER' => ($user_id != ANONYMOUS ? ("<a href=\"profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=" . intval($user_id) . "\" target=\"_blank\">" . links_html($username) . "</a>") : links_html($username))
			));
			$i++;
		}
		while ( $row = $db->sql_fetchrow($result) );
	}

	//
	// Pagination
	//
	$sql = "SELECT count(*) AS total
		FROM " . LINKS_TABLE . "
		WHERE link_active = 1 AND link_category = $cat";

	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not query links number', '', __LINE__, __FILE__, $sql);
	}

	if ( $row = $db->sql_fetchrow($result) )
	{
		$total_links = $row['total'];
		$pagination = generate_pagination("links.$phpEx?t=$t&amp;cat=$cat&amp;mode=$mode&amp;order=$sort_order", $total_links, $linkspp, $start). '&nbsp;';
	}
	else
	{
		$pagination = '&nbsp;';
		$total_links = 10;
	}

	//
	// Link categories dropdown list
	//
	foreach($link_categories as $cat_id => $cat_title)
	{
		$link_cat_option .= '<option value="' . intval($cat_id) . '">' . links_html($cat_title) . '</option>';
	}

	$template->assign_vars(array(
		'PAGINATION' => $pagination,
		'PAGE_NUMBER' => sprintf($lang['Page_of'], ( floor( $start / $linkspp ) + 1 ), max(1, ceil( $total_links / $linkspp ))),
		'L_GOTO_PAGE' => $lang['Goto_page'],

		'LINK_CAT_OPTION' => $link_cat_option
	));

	$template->pparse("body");

	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
	exit;
}

if ($t=='search') 
{
	if ( $search_keywords )
	{
		$search_keywords = substr(trim(stripslashes($search_keywords)), 0, 100);
		$search_keywords_sql = links_like_sql($search_keywords);
		$link_title =  $lang['Search_site'] . " &raquo; " . links_html($search_keywords);
		$template->assign_vars(array(
			'L_LINK_TITLE1' => $link_title,
			'L_SEARCH_SITE_TITLE' => $lang['Search_site_title']
		));
	}
	else
	{
		$template->assign_vars(array(
			'L_LINK_TITLE1' => $lang['Search_site'],
			'L_SEARCH_SITE_TITLE' => $lang['Search_site_title']
		));
		$start = 0;
	}
	//
	// Grab link categories
	//
	$sql = "SELECT cat_id, cat_title FROM " . LINK_CATEGORIES_TABLE . " ORDER BY cat_order";

	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not query link categories list', '', __LINE__, __FILE__, $sql);
	}

	while($row = $db->sql_fetchrow($result))
	{
		$link_categories[$row['cat_id']] = $row['cat_title'];
	}

	//
	// Grab links
	//
	if ( $search_keywords )
	{
		/*$sql = "SELECT * FROM " . LINKS_TABLE . "
			WHERE link_active = 1";*/
		$sql = "SELECT l.*, u.username
			FROM " . LINKS_TABLE . " l, " . USERS_TABLE . " u
			WHERE link_active = 1 AND l.user_id = u.user_id";
		$sql = $sql . " AND (link_title LIKE '%$search_keywords_sql%' OR link_desc LIKE '%$search_keywords_sql%') LIMIT $start, $linkspp";
		
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Could not query links list', '', __LINE__, __FILE__, $sql);
		}

		if ( $row = $db->sql_fetchrow($result) )
		{
			$i = 0;
			do
			{
				//if (empty($row['link_logo_src'])) $row['link_logo_src'] = 'images/links/no_logo88a.gif';
				if ($link_config['display_links_logo'])
				{
					$tmp = links_logo_html($row, $link_config, $phpEx);
				}
				else
				{
					$tmp = $lang['No_Display_Links_Logo'];
				}

				$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];
				$user_id = $row['user_id'];
				$username = $row['username'];

				$template->assign_block_vars("linkrow", array(
					'ROW_CLASS' => $row_class,
					'LINK_URL' => append_sid("links.$phpEx?action=go&amp;link_id=" . (int) $row['link_id']),
					'LINK_TITLE' => links_html($row['link_title']),
					'LINK_DESC' => links_html($row['link_desc']),
					'LINK_LOGO_SRC' => links_html(links_image_url($row['link_logo_src'])),
					'LINK_LOGO' => $tmp,
					'LINK_CATEGORY' => links_html(isset($link_categories[$row['link_category']]) ? $link_categories[$row['link_category']] : ''),
					'LINK_JOINED' => create_date($lang['DATE_FORMAT'], (int) $row['link_joined'], $board_config['board_timezone']),
					'LINK_HITS' => (int) $row['link_hits'],
					'U_LINK_USER' => ($user_id != ANONYMOUS ? ("<a href=\"profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=" . intval($user_id) . "\" target=\"_blank\">" . links_html($username) . "</a>") : links_html($username))
				));
				$i++;
			}
			while ( $row = $db->sql_fetchrow($result) );
		}

		//
		// Pagination
		//
		$sql = "SELECT count(*) AS total
			FROM " . LINKS_TABLE . "
			WHERE link_active = 1";
		$sql .= " AND (link_title LIKE '%$search_keywords_sql%' OR link_desc LIKE '%$search_keywords_sql%')";

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not query links number', '', __LINE__, __FILE__, $sql);
		}

		if ( $row = $db->sql_fetchrow($result) )
		{
			$total_links = $row['total'];
			$pagination = generate_pagination("links.$phpEx?t=search&amp;search_keywords=" . urlencode($search_keywords), $total_links, $linkspp, $start). '&nbsp;';
		}
		else
		{
			$pagination = '&nbsp;';
			$total_links = 10;
		}
	}

	//
	// Link categories dropdown list
	//
	foreach($link_categories as $cat_id => $cat_title)
	{
		$link_cat_option .= '<option value="' . intval($cat_id) . '">' . links_html($cat_title) . '</option>';
	}

	$template->assign_vars(array(
		'PAGINATION' => $pagination,
		'PAGE_NUMBER' => sprintf($lang['Page_of'], ( floor( $start / $linkspp ) + 1 ), max(1, ceil( $total_links / $linkspp ))),
		'L_GOTO_PAGE' => $lang['Goto_page'],

		'LINK_CAT_OPTION' => $link_cat_option
	));

	$template->pparse("body");

	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
	exit;
}

$template->assign_vars(array(
	'FOLDER_IMG' => $images['folder']
));

//
// Grab link categories
//
$sql = "SELECT cat_id, cat_title FROM " . LINK_CATEGORIES_TABLE . " ORDER BY cat_order";

if(!$result = $db->sql_query($sql))
{
	message_die(GENERAL_ERROR, 'Could not query link categories list', '', __LINE__, __FILE__, $sql);
}

//
// Separate link categories into $catcol columns
//
$catnum = $db->sql_numrows($result);
$catcol = 2;
$num = intval($catnum/$catcol);
if ($catnum % $catcol ) $num++;
$template->assign_vars(array('LINK_WIDTH' => 100/$catcol));
for( $i = 0;$i < $num; $i++)
{
	$template->assign_block_vars('catcol', array());
	if ( ($catnum % $catcol) && ($i==$num-1) ) $catcol = $catnum % $catcol;
	for( $j = 0;$j < $catcol; $j++)
	{
		$row = $db->sql_fetchrow($result);
		$link_categories[$row['cat_id']] = $row['cat_title'];
		$sql = "SELECT link_category FROM " . LINKS_TABLE . "
			WHERE link_active = 1 AND link_category = ";
		$sql .= $row['cat_id'];
		if(!$linknum = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Could not query links list', '', __LINE__, __FILE__, $sql);
		}
		$template->assign_block_vars('catcol.linkrow', array(
			'LINK_URL' => append_sid("links.$phpEx?t=sub_pages&amp;cat=" . intval($row['cat_id'])),
			'LINK_TITLE' => links_html($row['cat_title']),
			'LINK_NUMBER' => $db->sql_numrows($linknum)
			)
		);
	}
}

//
// Link categories dropdown list
//
foreach($link_categories as $cat_id => $cat_title)
{
	$link_cat_option .= '<option value="' . intval($cat_id) . '">' . links_html($cat_title) . '</option>';
}
	
$template->assign_vars(array(
	'LINK_CAT_OPTION' => $link_cat_option
));

$template->pparse("body");

include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>
