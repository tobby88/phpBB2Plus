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

// Tell the template class which template to use.
$template->set_filenames( array( 'news' => 'news.tpl' ) );
    
$content = new NewsModule( $phpbb_root_path );

$content->setVariables( array(
    'L_INDEX' => $lang['Index'],
    'L_CATEGORIES' => $lang['Categories'],
    'L_ARCHIVES' => $lang['Archives']
    ) );
$content->render( );

$content->display( );
$content->clear( );

include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
?>
