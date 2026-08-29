<?php
/***************************************************************************
*                               admin_news.php
*                              -------------------
*     begin                : Sunday, 25th Feb 2003
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

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if( !empty($setmodules) )
{
  $filename = basename(__FILE__);
  $module['News Admin']['Configuration'] = $filename;
  return;
}

//
// Let's set the root dir for phpBB
//
$phpbb_root_path = "./../";
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
include($phpbb_root_path . 'includes/functions_selects.'.$phpEx);

$submit = isset($_POST['submit']);
if ($submit)
{
	phpbb_admin_require_post_session();
}

function admin_news_relative_path($value, $allow_empty = false)
{
	$value = trim(str_replace('\\', '/', (string) $value));
	if (($allow_empty && $value === '') ||
		($value !== '' && strlen($value) <= 255 && $value[0] !== '/' && strpos($value, '..') === false && preg_match('#^[A-Za-z0-9_./-]+$#D', $value)))
	{
		return $value;
	}
	message_die(GENERAL_ERROR, 'Invalid local news path.');
}

function admin_news_base_url($value)
{
	$value = trim((string) $value);
	if ($value === '')
	{
		return '';
	}
	$parts = @parse_url($value);
	if (!$parts || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
		!in_array(strtolower($parts['scheme']), array('http', 'https'), true) || strpos($value, '\\') !== false || preg_match('/[\x00-\x20\x7f]/', $value))
	{
		message_die(GENERAL_ERROR, 'Invalid news base URL.');
	}
	return $value;
}

//
// Pull all news config data only
//
$default_config = array();
$new = array();
$sql = "SELECT *
  FROM " . CONFIG_TABLE . ' as c ' .
  " WHERE
    config_name = 'allow_news' OR
    config_name = 'news_base_url' OR
    config_name = 'news_index_file' OR
    config_name = 'news_item_trim' OR
    config_name = 'news_title_trim' OR
    config_name = 'news_item_num' OR
    config_name = 'news_path' OR
    config_name = 'allow_rss' OR
    config_name = 'news_rss_desc' OR
    config_name = 'news_rss_language' OR
    config_name = 'news_rss_ttl' OR
    config_name = 'news_rss_cat' OR
    config_name = 'news_rss_image' OR
    config_name = 'news_rss_image_desc' OR
    config_name = 'news_rss_item_count' OR
    config_name = 'news_rss_show_abstract'";

if(!$result = $db->sql_query($sql))
{
  message_die(CRITICAL_ERROR, "Could not query config information in admin_news", "", __LINE__, __FILE__, $sql);
}
else
{
  while( $row = $db->sql_fetchrow($result) )
  {
    $config_name = $row['config_name'];
    $config_value = $row['config_value'];
    $default_config[$config_name] = $config_value;

    $new[$config_name] = $submit ? phpbb_admin_post_string($config_name, $default_config[$config_name]) : $default_config[$config_name];

    if ($submit)
    {
      if ($config_name === 'news_path')
      {
        $new[$config_name] = admin_news_relative_path($new[$config_name]);
      }
      else if ($config_name === 'news_index_file')
      {
        $new[$config_name] = admin_news_relative_path($new[$config_name]);
      }
      else if ($config_name === 'news_base_url')
      {
        $new[$config_name] = admin_news_base_url($new[$config_name]);
      }
      $sql = "UPDATE " . CONFIG_TABLE . " SET
        config_value = '" . $db->sql_escape($new[$config_name]) . "'
        WHERE config_name = '" . $db->sql_escape($config_name) . "'";

      if( !$db->sql_query($sql) )
      {
        message_die(GENERAL_ERROR, "Failed to update news configuration for $config_name", "", __LINE__, __FILE__, $sql);
      }
    }
  }
	$db->sql_freeresult($result);

  if ($submit)
  {
    $message = $lang['Config_updated'] . "<br /><br />" . sprintf($lang['Click_return_newsadmin'], "<a href=\"" . append_sid("admin_news.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

    message_die(GENERAL_MESSAGE, $message);
  }
}

$news_yes = ( $new['allow_news'] ) ? "checked=\"checked\"" : "";
$news_no = ( !$new['allow_news'] ) ? "checked=\"checked\"" : "";

$rss_yes = ( $new['allow_rss'] ) ? "checked=\"checked\"" : "";
$rss_no = ( !$new['allow_rss'] ) ? "checked=\"checked\"" : "";

$rss_abstract_yes = ( $new['news_rss_show_abstract'] ) ? "checked=\"checked\"" : "";
$rss_abstract_no = ( !$new['news_rss_show_abstract'] ) ? "checked=\"checked\"" : "";

$template->set_filenames(array(
  "body" => "admin/news_config_body.tpl")
);

//
// Escape any quotes in the site description for proper display in the text
// box on the admin page
//
$new['news_rss_desc'] = strip_tags($new['news_rss_desc']);

$template->assign_vars(array(
  'S_CONFIG_ACTION' => append_sid("admin_news.$phpEx"),
  'S_HIDDEN_FIELDS' => phpbb_admin_session_field(),

  'L_YES' => $lang['Yes'],
  'L_NO' => $lang['No'],

  'L_SUBMIT' => $lang['Submit'],
  'L_RESET' => $lang['Reset'],

  'L_CONFIGURATION_TITLE' => $lang['News_Configuration'],
  'L_CONFIGURATION_EXPLAIN' => $lang['News_explain'],

  'L_GENERAL_SETTINGS' => $lang['News_settings'],

  'L_ALLOW_NEWS_POSTING' => $lang['Enable_News'],

  'L_NEWS_TRIM' => $lang['News_trim'],
  'L_NEWS_TRIM_EXPLAIN' => $lang['News_trim_explain'],

  'L_NEWS_BASE_URL' => $lang['News_base_url'],
  'L_NEWS_BASE_URL_EXPLAIN' => $lang['News_base_url_explain'],

  'L_NEWS_INDEX_FILE' => $lang['News_index_file'],
  'L_NEWS_INDEX_FILE_EXPLAIN' => $lang['News_index_file_explain'],

  'L_NEWS_TOPIC_TRIM' => $lang['News_topic_trim'],
  'L_NEWS_TOPIC_TRIM_EXPLAIN' => $lang['News_topic_trim_explain'],

  'L_NEWS_ITEMS_DISPLAY' => $lang['News_item_num'],
  'L_NEWS_ITEMS_DISPLAY_EXPLAIN' => $lang['News_item_num_explain'],

  'L_NEWS_PATH' => $lang['News_Path'],
  'L_NEWS_PATH_EXPLAIN' => $lang['News_Path_Explain'],

  'L_RSS_SETTINGS' => $lang['RSS_Configuration'],

  'L_ALLOW_RSS' => $lang['Enable_RSS'],
  'L_ALLOW_RSS_EXPLAIN' => $lang['Enable_RSS_explain'],
  
  'L_RSS_SHOW_ABSTRACT' => $lang['Show_RSS_abstract'],

  'L_RSS_DESC' => $lang['Feed_Description'],
  'L_RSS_DESC_EXPLAIN' => $lang['Feed_Description_Explain'],

  'L_RSS_LANG' => $lang['Feed_Language'],
  'L_RSS_LANG_EXPLAIN' => $lang['Feed_Language_Explain'],

  'L_RSS_TTL' => $lang['Feed_TTL'],
  'L_RSS_TTL_EXPLAIN' => $lang['Feed_TTL_Explain'],

  'L_RSS_CAT' => $lang['Feed_Category'],
  'L_RSS_IMG' => $lang['Feed_Image'],
  'L_RSS_IMG_EXPLAIN' => $lang['Feed_Image_Explain'],
  'L_RSS_IMG_DESC' => $lang['Feed_Image_Desc'],

  'NEWS_YES' => $news_yes,
  'NEWS_NO' => $news_no,
  
  'NEWS_BASE_URL' => phpbb_admin_html($new['news_base_url']),

  'NEWS_INDEX_FILE' => phpbb_admin_html($new['news_index_file']),

  'NEWS_ITEM_LENGTH' => phpbb_admin_html($new['news_item_trim']),
  'NEWS_TITLE_LENGTH' => phpbb_admin_html($new['news_title_trim']),
  'NEWS_ITEM_NUM' => phpbb_admin_html($new['news_item_num']),

  'NEWS_PATH' => phpbb_admin_html($new['news_path']),

  'RSS_YES' => $rss_yes,
  'RSS_NO' => $rss_no,
  'RSS_ABSTRACT_YES' => $rss_abstract_yes,
  'RSS_ABSTRACT_NO' => $rss_abstract_no,

  'RSS_ITEM_COUNT' => phpbb_admin_html($new['news_rss_item_count']),
  'RSS_DESC' => phpbb_admin_html($new['news_rss_desc']),
  'RSS_LANG' => phpbb_admin_html($new['news_rss_language']),
  'RSS_TTL'  => phpbb_admin_html($new['news_rss_ttl']),
  'RSS_CAT'  => phpbb_admin_html($new['news_rss_cat']),
  'RSS_IMG'  => phpbb_admin_html($new['news_rss_image']),
  'RSS_IMG_DESC' => phpbb_admin_html($new['news_rss_image_desc'])

  )
);

$template->pparse("body");

include('./page_footer_admin.'.$phpEx);

?>
