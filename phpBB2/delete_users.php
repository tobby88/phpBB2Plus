<?php
#########################################################
## Author: Niels Chr. Rød
## Nickname: Niels Chr. Denmark
## Email: ncr@db9.dk
## http://mods.db9.dk
##
## Ver 1.2.11
## Developed as a drop-in to phpBB2 ver 2.0.2
##
## phpBB2 drop-in mod, that checks for unused accounts for X days
## use the script while logged in as ADMIN, add the days=X as a extra parameter
##   e.g. www.yourdomain.com/delete_users.php?mode=not_login&days=10
## will delete all accounts who have never logged in and are older than 10 days
##
## And zero postes
##   e.g. www.yourdomain.com/delete_users.php?mode=zero_poster&days=10
## will delete all accounts who have never posted and are older than 10 days
##
## You can also delete specific users
##   e.g. www.yourdomain.com/delete_users.php?mode=user_name&del_user=Niels
##   or www.yourdomain.com/delete_users.php?mode=user_id&del_user=18
## Will delete a specific user either by name or by id, remember that is is NOT case sensetive
## if the user have posted, then his/her posts will be converted to posted by guest, and the users
## name wil still be showen
##
## History:
##	1.0.0. - initial release
##	1.0.3. - history started, added delete not activated
##	1.1.0. - The old version did not delete all entrys, therfore this one works as the original code in ADMIN panel
##	1.2.0. - updated the code to work same as phpBB2 ver 2.0.2.
##	1.2.1. - fix, "could not update posts table"
##	1.2.2. - fix, there was a error in the sql, regarding the new prune option #4
##	1.2.3. - fix, usernames with ' was giving a erro, when trying to delete, this is now posible
##	1.2.4. - fix, list of usernames was not showen
##	1.2.5. - made MODE, only show if debug is enabled
##	1.2.6. - more debug info, if no group
##	1.2.7. - now support email notification
##	1.2.9. - removed some debug info
##    1.2.10. - changed the php initial tag
##	1.2.11. - extended time execution, if allowed - it may take some time, if email notification is enabled

#########################################################

define('IN_PHPBB', true);
// Set false here to disable deletion notifications.
define('NOTIFY_USERS', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.' . $phpEx);
include($phpbb_root_path . 'includes/emailer.' . $phpEx);
$userdata = session_pagestart($user_ip, PAGE_INDEX);
init_userprefs($userdata);
include($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_admin.' . $phpEx);
include($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_prune_users.' . $phpEx);
require_once($phpbb_root_path . 'includes/functions_user_prune.' . $phpEx);

try { phpbb_removal_actor(new PhpbbRemovalDatabase($db), 'prune'); }
catch (PhpbbRemovalException $error) { message_die(GENERAL_MESSAGE, phpbb_prune_html($error->getMessage())); }

$messages = ''; $deleted_users = 0; $name_list = ''; $attempted = false;
try
{
	// A recovery POST carries only its original job token, never new criteria.
	if (isset($_POST['removal_resume']) || isset($_POST['removal_cancel']))
	{
		$attempted = true;
		$result = phpbb_prune_user_remove($db, $_POST);
		$messages = phpbb_prune_html($lang[is_array($result) ? $result['status'] : $result]);
		if (!phpbb_prune_notify($result)) { $messages .= '<p>' . phpbb_prune_html($lang['Prune_notification_failed']) . '</p>'; }
	}
	else
	{
		$is_post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
		$request = $is_post ? $_POST : $_GET;
		$policy = phpbb_prune_policy($request);
		$target = $policy['mode'] === 'user_id' ? phpbb_removal_id(isset($request['del_user']) ? $request['del_user'] : null) : 0;
		$confirmed = $is_post && isset($_POST['confirm']);
		if (!$confirmed)
		{
			$messages = '<form method="post" action="' . phpbb_prune_html(append_sid('delete_users.' . $phpEx)) . '"><p>' . phpbb_prune_html($lang['Confirm']) . ': ' . phpbb_prune_html($policy['mode']) . '</p>'
				. '<input type="hidden" name="mode" value="' . phpbb_prune_html($policy['mode']) . '" />'
				. '<input type="hidden" name="days" value="' . $policy['days'] . '" />'
				. '<input type="hidden" name="del_user" value="' . $target . '" />'
				. '<input type="hidden" name="sid" value="' . phpbb_prune_html($userdata['session_id']) . '" />'
				. '<button type="submit" name="confirm" class="mainoption" value="1">' . phpbb_prune_html($lang['Confirm']) . '</button></form>';
		}
		else
		{
			// Validate the original POST even when no eligible accounts remain.
			if (!is_scalar($_POST['confirm']) || !isset($_POST['sid']) || !is_string($_POST['sid']) || empty($userdata['session_id']) || !hash_equals((string) $userdata['session_id'], $_POST['sid'])) { phpbb_removal_error('Session_invalid'); }
			$selection = phpbb_prune_where($policy) . ($target ? ' AND user_id = ' . $target : '');
			$users = phpbb_removal_rows(new PhpbbRemovalDatabase($db), 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE ' . $selection . ' ORDER BY username,user_id LIMIT 800');
			foreach ($users as $candidate)
			{
				// Leave room for the shared writer's ten-second contention wait.
				@set_time_limit(30);
				$attempted = true;
				$result = phpbb_prune_user_remove($db, $_POST, $candidate['user_id'], $policy);
				if ($result['status'] === 'Prune_skipped') { continue; }
				$deleted_users++;
				$name_list .= ($name_list === '' ? '' : ', ') . phpbb_prune_html($result['username']);
				if (!phpbb_prune_notify($result)) { $messages .= '<p>' . phpbb_prune_html($lang['Prune_notification_failed']) . '</p>'; }
			}
			$messages = '<p>' . phpbb_prune_html($deleted_users ? sprintf($lang['Prune_users_number'], $deleted_users) : $lang['Prune_no_users']) . ' ' . $name_list . '</p>' . $messages;
		}
	}
}
catch (Exception $error)
{
	$messages .= '<p>' . phpbb_prune_html($error instanceof PhpbbRemovalException ? $error->getMessage() : $lang['Removal_storage_failed']) . '</p>';
	if ($deleted_users) { $messages .= '<p>' . phpbb_prune_html(sprintf($lang['Prune_users_number'], $deleted_users)) . ' ' . $name_list . '</p>'; }
}
catch (Error $error) { $messages .= '<p>' . phpbb_prune_html($lang['Removal_storage_failed']) . '</p>'; }
// Cache refresh and mail dispatch are outside every owning storage connection.
if ($attempted) { cache_tree(true); }
try { $messages .= phpbb_prune_pending_html($db); }
catch (Exception $error) { $messages .= '<p>' . phpbb_prune_html($lang['Removal_jobs_unavailable']) . '</p>'; }
catch (Error $error) { $messages .= '<p>' . phpbb_prune_html($lang['Removal_jobs_unavailable']) . '</p>'; }
message_die(GENERAL_MESSAGE, $messages . '<p><a href="' . phpbb_prune_html(append_sid('admin/admin_prune_users.' . $phpEx)) . '">' . phpbb_prune_html($lang['Prune_users']) . '</a></p>');
