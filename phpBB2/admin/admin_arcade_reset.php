<?php
/*************************************************************************** 
 *                          admin_arcade_reset.php 
 *                            ------------------- 
 *   begin                : 08/01/04
 *   copyright            : (C) 2005 Minesh Mistry & Ebaby
 *   website 1            : Support: www.phpbb-amod.co.uk
 *   website 2            : Demo and live site: www.gamelounge.co.uk
 *   version              : 1.0.1
 *   history              : Original by version Ebaby, Made in ACP panel By Minesh
 * 
 *   note: removing the original copyright is illegal even you have modified 
 *         the code.  Just append yours if you have modified it. 
 ***************************************************************************/ 

/*************************************************************************** 
 * 
 *   This program is free software; you can redistribute it and/or modify 
 *   it under the terms of the GNU General Public License as published by 
 *   the Free Software Foundation; either version 2 of the License, or 
 *   (at your option) any later version. 
 * 
 ***************************************************************************/
 
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
$phpbb_root_path = './../';

if( !empty($setmodules) )
{
	return;
}

require($phpbb_root_path . 'extension.inc');
if (!defined('ARCADE_ADMIN')) { define('ARCADE_ADMIN', 1); }
require('pagestart.' . $phpEx);
include_once($phpbb_root_path . 'includes/functions_arcade.' . $phpEx);
require_once dirname(__DIR__) . '/includes/functions_arcade_statistics_storage.php';

try
{
    phpbb_acp_actor($db, 'admin_arcade_reset.'.$phpEx.'?mode=home');
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST')
    {
        phpbb_admin_require_post_session();
        $mode = isset($HTTP_POST_VARS['mode']) ? $HTTP_POST_VARS['mode'] : null;
        phpbb_arcade_statistics_reset($db, $mode);
        message_die(GENERAL_MESSAGE, $lang['arcade_statistics_done'].'<br /><br /><a href="'.phpbb_admin_html(append_sid('admin_arcade_reset.'.$phpEx)).'">'.phpbb_admin_html($lang['arcade_statistics_title']).'</a>');
    }
}
catch (PhpbbAclException $error)
{
    $key = $error->getMessage();
    message_die(GENERAL_MESSAGE, isset($lang[$key]) ? $lang[$key] : $lang['arcade_statistics_failed']);
}
catch (PhpbbOwnedStorageFailure $error)
{
    $key = $error->getMessage();
    message_die(GENERAL_MESSAGE, isset($lang[$key]) ? $lang[$key] : $lang['arcade_statistics_failed']);
}

$form_action = phpbb_admin_html(append_sid('admin_arcade_reset.'.$phpEx));
echo '<table width="99%" cellpadding="4" cellspacing="1" border="0" align="center" class="forumline">';
echo '<tr><th colspan="2" class="thHead">'.phpbb_admin_html($lang['arcade_statistics_title']).'</th></tr>';
echo '<tr><td colspan="2" class="row1"><span class="genmed">'.phpbb_admin_html($lang['arcade_statistics_help']).'</span></td></tr>';
foreach (array('reset_played','reset_last_player','reset_last_time') as $mode)
{
    echo '<tr><td class="row1"><span class="gen">'.phpbb_admin_html($lang['arcade_statistics_'.$mode]).'</span></td>';
    echo '<td class="row2"><form method="post" action="'.$form_action.'">'.phpbb_admin_session_field();
    echo '<input type="hidden" name="mode" value="'.$mode.'" /><input type="submit" class="mainoption" value="'.phpbb_admin_html($lang['Reset']).'" onclick="return confirm('.phpbb_admin_html(json_encode($lang['arcade_statistics_confirm'])).');" /></form></td></tr>';
}
echo '</table><div align="center"><span class="gensmall">Original version by Ebaby | ACP version by Minesh</span></div>';
include('page_footer_admin.'.$phpEx);
