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
// Count and forward
//
if (isset($_GET['action']) && is_string($_GET['action']) && $_GET['action'] === 'go')
{
	header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
	header('Pragma: no-cache');
	header('Referrer-Policy: no-referrer');
	require_once($phpbb_root_path . 'includes/functions_link_click.' . $phpEx);
	$link_url = phpbb_link_click($db, isset($_GET['link_id']) ? $_GET['link_id'] : null, $user_ip);
	if ($link_url !== false)
	{
		header('Location: ' . $link_url, true, 302);
		exit;
	}
}

// Output the basic page
$page_title = $lang['Site_links'];
include('includes/page_header.'.$phpEx);
require_once($phpbb_root_path . 'includes/functions_link_listing.' . $phpEx);
$request = phpbb_link_list_request($_POST, $_GET);
try
{
    $listing = phpbb_link_listing($db, $request);
}
catch (Exception $error)
{
    if ($error instanceof PhpbbLinkException && $error->getMessage() === 'Link_category_not_exist')
    {
        message_die(GENERAL_MESSAGE, $lang['Link_category_not_exist']);
    }
    message_die(GENERAL_ERROR, $lang['Link_list_unavailable']);
}
catch (Error $error)
{
    message_die(GENERAL_ERROR, $lang['Link_list_unavailable']);
}

$t = $request['t'];
$cat = $request['cat'];
$mode = $request['mode'];
$sort_order = $request['order'];
$search_keywords = $request['query'];
$link_config = $listing['config'];
$linkspp = $listing['size'];
$start = $listing['start'];
$total_links = $listing['total'];
$templates = array('index'=>'links_index.tpl', 'new'=>'links_popnew.tpl', 'pop'=>'links_popnew.tpl', 'sub_pages'=>'links_body.tpl', 'search'=>'links_search.tpl');
$template->set_filenames(array('body'=>$templates[$t]));
$link_categories = array();
$link_cat_option = '';
foreach ($listing['categories'] as $row)
{
    $link_categories[$row['cat_id']] = $row['cat_title'];
    $link_cat_option .= '<option value="' . (int)$row['cat_id'] . '">' . links_html($row['cat_title']) . '</option>';
}
$site_logo = links_image_url($link_config['site_logo']);
$site_logo = $site_logo !== '' ? $site_logo : 'images/links/web_logo88a.gif';
$site_url = links_http_url($link_config['site_url']);
$link_us_syntax = sprintf($lang['Link_us_syntax'], $site_url, $site_logo, $link_config['width'], $link_config['height'], $board_config['sitename']);
if ($link_config['lock_submit_site'] === '0')
{
    $template->assign_block_vars('lock', array());
    $template->assign_block_vars($userdata['session_logged_in'] ? 'lock.submit' : 'lock.logout', array());
}
$pagination_url = "links.$phpEx?t=$t";
if ($t === 'sub_pages') { $pagination_url .= "&amp;cat=$cat&amp;mode=$mode&amp;order=$sort_order"; }
if ($t === 'search') { $pagination_url .= '&amp;search_keywords=' . rawurlencode($search_keywords); }
$pagination = $t === 'index' ? '&nbsp;' : generate_pagination($pagination_url, $total_links, $linkspp, $start) . '&nbsp;';
$title = $t === 'pop' ? $lang['Descend_by_hits'] : ($t === 'new' ? $lang['Descend_by_joindate'] : $lang['Search_site']);
if ($t === 'search' && $search_keywords !== '') { $title .= ' &raquo; ' . links_html($search_keywords); }
$template->assign_vars(array(
    'U_LINK_REG'=>append_sid("link_register.$phpEx"),
    'S_LINK_TOKEN'=>'<input type="hidden" name="sid" value="' . links_html($userdata['session_id']) . '" />',
    'L_LINK_REGISTER_RULE'=>$lang['Link_register_rule'], 'L_LINK_REGISTER_GUEST_RULE'=>$lang['Link_register_guest_rule'],
    'L_LINK_TITLE'=>$lang['Link_title'], 'L_LINK_DESC'=>$lang['Link_desc'], 'L_LINK_URL'=>$lang['Link_url'],
    'L_LINK_LOGO_SRC'=>$lang[$link_config['allow_no_logo'] === '1' ? 'Link_logo_src' : 'Link_logo_src1'],
    'L_PREVIEW'=>$lang['Links_Preview'], 'L_LINK_CATEGORY'=>$lang['Link_category'], 'L_PLEASE_ENTER_YOUR'=>$lang['Please_enter_your'],
    'L_LINK_REGISTER'=>$lang['Link_register'], 'L_SITE_LINKS'=>$lang['Site_links'],
    'L_LINK_US'=>links_html($lang['Link_us'] . $board_config['sitename']), 'L_LINK_US_EXPLAIN'=>sprintf($lang['Link_us_explain'], links_html($board_config['sitename'])),
    'L_SUBMIT'=>$lang['Submit'], 'U_SITE_LINKS'=>append_sid("links.$phpEx"), 'U_SITE_SEARCH'=>append_sid("links.$phpEx?t=search"),
    'U_SITE_TOP'=>append_sid("links.$phpEx?t=pop"), 'U_SITE_NEW'=>append_sid("links.$phpEx?t=new"), 'U_SITE_LOGO'=>links_html($site_logo),
    'LINK_US_SYNTAX'=>links_html($link_us_syntax), 'LINKS_HOME'=>$lang['Links_home'], 'L_SEARCH_SITE'=>$lang['Search_site'],
    'L_DESCEND_BY_HITS'=>$lang['Descend_by_hits'], 'L_DESCEND_BY_JOINDATE'=>$lang['Descend_by_joindate'], 'L_LINK_JOINED'=>$lang['Joined'],
    'L_LINK_HITS'=>$lang['link_hits'], 'L_LINK_SUBMITER'=>$lang['link_submiter'], 'L_LINK_TITLE1'=>$title, 'L_SEARCH_SITE_TITLE'=>$lang['Search_site_title'],
    'SEARCH_KEYWORDS'=>links_html($search_keywords), 'LINK_CAT_OPTION'=>$link_cat_option, 'LINK_WIDTH'=>50, 'FOLDER_IMG'=>$images['folder'],
    'PAGINATION'=>$pagination, 'PAGE_NUMBER'=>sprintf($lang['Page_of'], (int)floor($start/$linkspp)+1, max(1, (int)ceil($total_links/$linkspp))), 'L_GOTO_PAGE'=>$lang['Goto_page']
));
if ($t === 'index')
{
    foreach ($listing['categories'] as $i=>$row)
    {
        if ($i % 2 === 0) { $template->assign_block_vars('catcol', array()); }
        $template->assign_block_vars('catcol.linkrow', array('LINK_URL'=>append_sid("links.$phpEx?t=sub_pages&amp;cat=" . (int)$row['cat_id']), 'LINK_TITLE'=>links_html($row['cat_title']), 'LINK_NUMBER'=>$row['link_count']));
    }
}
else
{
    if ($t === 'sub_pages')
    {
        $select_sort_mode = '<select name="mode">';
        foreach (array('link_hits'=>$lang['link_hits'], 'link_joined'=>$lang['Joined'], 'link_title'=>$lang['Link_title'], 'link_desc'=>$lang['Link_desc']) as $value=>$label)
        {
            $select_sort_mode .= '<option value="' . $value . '"' . ($mode === $value ? ' selected="selected"' : '') . '>' . links_html($label) . '</option>';
        }
        $select_sort_mode .= '</select>';
        $select_sort_order = '<select name="order">';
        foreach (array('ASC'=>$lang['Sort_Ascending'], 'DESC'=>$lang['Sort_Descending']) as $value=>$label)
        {
            $select_sort_order .= '<option value="' . $value . '"' . ($sort_order === $value ? ' selected="selected"' : '') . '>' . links_html($label) . '</option>';
        }
        $select_sort_order .= '</select><input type="hidden" name="t" value="sub_pages" /><input type="hidden" name="cat" value="' . $cat . '" />';
        $template->assign_vars(array('L_SELECT_SORT_METHOD'=>$lang['Select_sort_method'], 'L_ORDER'=>$lang['Order'], 'L_SORT'=>$lang['Sort'], 'U_SITE_LINKS_CAT'=>append_sid("links.$phpEx?t=sub_pages&amp;cat=$cat"), 'S_MODE_SELECT'=>$select_sort_mode, 'S_ORDER_SELECT'=>$select_sort_order, 'LINK_CATEGORY'=>links_html($listing['category']['cat_title'])));
    }
    foreach ($listing['rows'] as $i=>$row)
    {
        $author = links_html($lang['Guest']);
        if (isset($row['username']) && (int)$row['user_id'] > 0)
        {
            $author = '<a href="' . links_html(append_sid("profile.$phpEx?mode=viewprofile&" . POST_USERS_URL . '=' . (int)$row['user_id'])) . '" target="_blank" rel="noopener noreferrer">' . links_html($row['username']) . '</a>';
        }
        $template->assign_block_vars('linkrow', array(
            'ROW_CLASS'=>$theme[$i % 2 === 0 ? 'td_class1' : 'td_class2'], 'LINK_URL'=>append_sid("links.$phpEx?action=go&amp;link_id=" . (int)$row['link_id']),
            'LINK_TITLE'=>links_html($row['link_title']), 'LINK_DESC'=>links_html($row['link_desc']), 'LINK_LOGO_SRC'=>links_html(links_image_url($row['link_logo_src'])),
            'LINK_LOGO'=>$link_config['display_links_logo'] === '1' ? links_logo_html($row, $link_config, $phpEx) : $lang['No_Display_Links_Logo'],
            'LINK_CATEGORY'=>links_html(isset($link_categories[$row['link_category']]) ? $link_categories[$row['link_category']] : ''),
            'LINK_JOINED'=>create_date($lang['DATE_FORMAT'], $row['link_joined'], $board_config['board_timezone']), 'LINK_HITS'=>links_html($row['link_hits']), 'U_LINK_USER'=>$author
        ));
    }
}
$template->pparse('body');
include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
