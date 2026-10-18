<?php
/***************************************************************************
*                            card.php
*             php Script needed for Yellow card mod (card ban/report mod)
*
*		giving the rigth POST actions, this file can:
*		ban, unban, warn postes or report a post to the forums moderators
*		now also posible to block a user for limited time if "protect user account" mod is pressent
*		the moderators of the forum can have a email notification,
*		this can be controled in the ADMIN PANEL
*
*                       -------------------
*   begin                : Feb 05, 2002
*   author			 : Niels Chr. Rød Denmark < ncr@db9.dk >
*   www			 : http://mods.db9.dk
*   email                : ncr@db9.dk
*
*      ver.1.4.14.
*
*   History:
*	ver 0.9.0. - initial beta for RC-2
*	ver 1.0.0. - initial release for RC-2
*	ver 1.2.0. - initial release for phpBB2 ver 2.0.0.
*	ver 1.2.4. - history started, major rechange on how this file works, now uses POST action, for improved security
*	ver 1.2.5. - fixed that post report was send out evry time, regardless of the admin settings
*	ver 1.2.6. - allow admin to warn, ban outside a forum (e.g. in viewprofile/memberlist)
*	ver 1.2.7. - fixed that after ver 1.2.4. users was not banned when reatched the warn limit
*	ver 1.2.8. - fixed that the first post report was not sendt out
*	ver 1.2.9. - fixed a error that couses the "back" link not to work if not moderators
*	ver 1.3.0. - now posible to send PM to users if they are resiving cards / users can write a message to moderators easy
*	ver 1.3.1. - security update, this mod, now uses it own DB field, for counting warning cards
*	ver 1.3.2. - now also posible to post a report into a specific report forum (defined in admin panel)
*	ver 1.3.3. - fix some minor isues, regarding posting a report
*	ver 1.4.0. - security update
*	ver 1.4.1. - added block time, if protect useraccount mod is installed
*	ver 1.4.2. - users are now banned imidiatly if they are logged in same time as they are getting a red card
*	ver 1.4.3. - block email notification was not showing how long the user was blocked, now corrected
*	ver 1.4.5. - minor update
*	ver 1.4.6. - ver 1.4.1 introduced a bug, witch caused the ban email was not sendt out, this is now fixed
*	ver 1.4.7. - now compatible with admin panel, ban users panel
*	ver 1.4.8. - log ip of user witch have given block user card
*	ver 1.4.9. - ver 1.4.8. had a typo, witch is corrected
*	ver 1.4.10. - updated the RFC syntax in emails send out
* 	ver 1.4.11. - fix that admin is allowed to give cards, outside viewtopic.php
*     ver 1.4.12. - replaced all RAPPORT and RAPORT with REPORT
*     ver 1.4.13. - fixed some isues about RY card
*	ver 1.4.14. - now uses 2.0.6. email format for subject
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
define('IN_PHPBB', true);
//
// Load default header
//
$phpbb_root_path = "./";
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);

// Find exactly one submitted card action. Image buttons add an _x suffix.
$card_actions = array(
	'report_x' => 'report',
	'report_reset_x' => 'report_reset',
	'ban_x' => 'ban',
	'unban_x' => 'unban',
	'warn_x' => 'warn',
	'block_x' => 'block',
);
$submitted_actions = array();
foreach ($card_actions as $field => $action)
{
	if (isset($_POST[$field]))
	{
		$submitted_actions[] = $action;
	}
}
$mode = (count($submitted_actions) === 1) ? $submitted_actions[0] : '';
$post_id = (isset($_POST['post_id']) && is_scalar($_POST['post_id'])) ? intval($_POST['post_id']) : 0;
$user_id = (isset($_POST[POST_USERS_URL]) && is_scalar($_POST[POST_USERS_URL])) ? intval($_POST[POST_USERS_URL]) : 0;
$sid = isset($_POST['sid']) && is_string($_POST['sid']) ? $_POST['sid'] : '';

$userdata = session_pagestart($user_ip, PAGE_CARD);
init_userprefs($userdata);

if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST' || $sid === '' || !hash_equals((string) $userdata['session_id'], $sid))
{
	message_die(GENERAL_ERROR, $lang['Not_Authorised']);
}

// Complete moderation publication precedes every cached post/role lookup.
// Report/reset remain a separate workflow below; they are not account bans.
if (in_array($mode, array('ban','unban','warn','block'), true))
{
    require_once($phpbb_root_path . 'includes/functions_card_storage.' . $phpEx);
    try { $outcome=phpbb_card_moderate($db,$_POST,$user_ip); }
    catch (PhpbbCardException $e) { message_die(GENERAL_MESSAGE,isset($lang[$e->getMessage()]) ? $lang[$e->getMessage()] : $lang['Card_storage_failed']); }
    catch (Exception $e) { message_die(GENERAL_MESSAGE,$lang['Card_storage_failed']); }
    catch (Error $e) { message_die(GENERAL_MESSAGE,$lang['Card_storage_failed']); }
    require_once($phpbb_root_path . 'includes/functions_card_notifications.' . $phpEx);
    $notification_ok=phpbb_card_notify($outcome);
    $message=$lang[$outcome['message']];
    if ($outcome['message']==='Ban_update_yellow') { $message=sprintf($message,$outcome['target']['user_warnings'],$outcome['limit']); }
    if ($outcome['message']==='Block_update') { $message=sprintf($message,phpbb_card_duration($outcome['minutes'])); }
    if (!$notification_ok) { $message.='<br /><br />'.$lang['Card_notification_failed']; }
    $message.='<br /><br />'.sprintf($lang['Send_PM_user'],'<a href="'.append_sid('privmsg.'.$phpEx.'?mode=post&u='.$outcome['target_id']).'">','</a>');
    $message.='<br /><br />'.($outcome['post_id']>0 ? sprintf($lang['Click_return_viewtopic'],'<a href="'.append_sid('viewtopic.'.$phpEx.'?p='.$outcome['post_id'].'#'.$outcome['post_id']).'">','</a>') : sprintf($lang['Click_return_index'],'<a href="'.append_sid('index.'.$phpEx).'">','</a>'));
    message_die(GENERAL_MESSAGE,$message);
}

// Report/reset independently owns current permissions and counter publication.
require_once($phpbb_root_path . 'includes/functions_report_storage.' . $phpEx);
try { $outcome=phpbb_report_post($db,$_POST,$user_ip); }
catch (PhpbbCardException $e) { message_die(GENERAL_MESSAGE,$e->getMessage()!=='Card_storage_failed' && isset($lang[$e->getMessage()]) ? $lang[$e->getMessage()] : $lang['Report_storage_failed']); }
catch (Exception $e) { message_die(GENERAL_MESSAGE,$lang['Report_storage_failed']); }
catch (Error $e) { message_die(GENERAL_MESSAGE,$lang['Report_storage_failed']); }
require_once($phpbb_root_path . 'includes/functions_report_notifications.' . $phpEx);
$notification_ok=phpbb_report_notify($outcome);
$message=$lang[$outcome['mode']==='report_reset' ? 'Post_reset' : 'Report_saved'];
if (!$notification_ok) { $message.='<br /><br />'.$lang['Report_notification_failed']; }
if ($outcome['destination']>0) {
    $compose='posting.'.$phpEx.'?mode='.($outcome['reply']>0 ? 'reply&t='.$outcome['reply'] : 'newtopic&f='.$outcome['destination']).'&postreport='.$outcome['post_id'];
    $message.='<br /><br />'.sprintf($lang['Send_message'],'<a href="'.append_sid($compose).'">','</a>');
}
$message.='<br /><br />'.sprintf($lang['Click_return_viewtopic'],'<a href="'.append_sid('viewtopic.'.$phpEx.'?p='.$outcome['post_id'].'#'.$outcome['post_id']).'">','</a>');
message_die(GENERAL_MESSAGE,$message);
