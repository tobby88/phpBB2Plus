<?php

//
// Set up for phpBB intergration.
//
define('IN_PHPBB', true);
$phpbb_root_path = './';

//
// phpBB related files
//

include_once( $phpbb_root_path . 'extension.inc' );
include_once( $phpbb_root_path . 'common.' . $phpEx );
include_once ($phpbb_root_path . 'includes/news.' . $phpEx );

//
// Start session management
//
$userdata = session_pagestart( $user_ip, PAGE_INDEX );
init_userprefs( $userdata );

//
// End session management
//

include($phpbb_root_path . 'includes/page_header.'.$phpEx); 

$content = new NewsModule($phpbb_root_path);
$news_cats = $content->data->fetchCategories();
$template->set_filenames(array('body' => 'news_cats.tpl'));

foreach ($news_cats as $i => $category)
{
  $template->assign_block_vars('news_cats', array(
    'ROW_COLOR' => '#' . $content->htmlText($theme[($i % 2) ? 'td_color2' : 'td_color1']),
    'ROW_CLASS' => $content->htmlText($theme[($i % 2) ? 'td_class2' : 'td_class1']),
    'TOPIC_COUNT' => (int) $category['topic_count'],
    'CATEGORY_IMG' => $content->htmlText($content->imageUrl($category['news_image'])),
    'L_CATEGORY' => $content->htmlText($category['news_category'])
  ));
}

$template->pparse('body');


//
// Page Footer
//
include($phpbb_root_path . 'includes/page_tail.'.$phpEx); 

?>
