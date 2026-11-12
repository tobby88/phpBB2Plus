<?php
/***************************************************************************
*                            link_register.php
*                            -------------------
*  MOD add-on page. Contains GPL code copyright of phpBB group.
*  Author: OOHOO < webdev@phpbb-tw.net >
*  Author: Stefan2k1 and ddonker from www.portedmods.com
*  Author: CRLin from http://mail.dhjh.tcc.edu.tw/~gzqbyr/
*  Demo: http://phpbb-tw.net/
*  Version: 1.0.X - 2002/03/22 - for phpBB RC serial, and was named Related_Links_MOD
*  Version: 1.1.0 - 2002/04/25 - Re-packed for phpBB 2.0.0, and renamed to Links_MOD
*  Version: 1.2.0 - 2003/06/15 - Enhanced and Re-packed for phpBB 2.0.4
*  Version: 1.2.1 - 2003/10/15 - Enhanced by CRLin
*  Version: 1.2.2 - 2004-05-10 - Enhanced by CRLin
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
include($phpbb_root_path . 'common.'.$phpEx);
include($phpbb_root_path . 'includes/bbcode.'.$phpEx);
include($phpbb_root_path . 'includes/functions_post.'.$phpEx);

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_LINKS);
init_userprefs($userdata);
$link_language = (isset($board_config['default_lang']) && preg_match('/^[a-z0-9_-]+$/i', (string) $board_config['default_lang'])) ? $board_config['default_lang'] : 'english';
$link_language_file = $phpbb_root_path . 'language/lang_' . $link_language . '/lang_main_link.' . $phpEx;
if (!is_file($link_language_file))
{
	$link_language_file = $phpbb_root_path . 'language/lang_english/lang_main_link.' . $phpEx;
}
require($link_language_file);
//
// End session management
//


// Users Authentication, members only area
if( !$userdata['session_logged_in'] )
{
	header("Location: " . append_sid("login.$phpEx?redirect=links.php", true));
	exit;
}

require_once $phpbb_root_path . 'includes/functions_link_submission.' . $phpEx;
require_once $phpbb_root_path . 'includes/functions_link_notifications.' . $phpEx;

try
{
	$outcome = phpbb_submit_link($db, $_POST, $userdata);
}
catch (PhpbbLinkException $error)
{
	$key = $error->getMessage();
	$message = isset($lang[$key]) ? $lang[$key] : $lang['Link_storage_failed'];
}
catch (Exception $error)
{
	$message = $lang['Link_storage_failed'];
}
catch (Error $error)
{
	$message = $lang['Link_storage_failed'];
}

if (isset($outcome))
{
	$message = $lang['Link_update_success'];
	try { $notified = phpbb_link_notify($db, $outcome); }
	catch (Exception $error) { $notified = false; }
	catch (Error $error) { $notified = false; }
	if (!$notified) { $message .= '<br /><br />' . $lang['Link_notification_failed']; }
}

$message .= '<br /><br />' . sprintf($lang['Click_return_links'], '<a href="' . append_sid("links.$phpEx") . '">', '</a>');
$message .= '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid("index.$phpEx") . '">', '</a>');

$template->assign_vars(array(
	'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid("links.$phpEx") . '">'
));
		
message_die(GENERAL_MESSAGE, $message);

?>
