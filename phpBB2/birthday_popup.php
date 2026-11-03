<?php
define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);
//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_INDEX);
init_userprefs($userdata);
//
// End session management
//

$birthday_value = isset($userdata['user_birthday']) && (is_int($userdata['user_birthday']) || is_string($userdata['user_birthday'])) ? (string) $userdata['user_birthday'] : '';
// Birthday MOD stores signed whole days since 1970, including day zero.
if (empty($userdata['session_logged_in']) || empty($userdata['user_id']) || (int) $userdata['user_id'] <= 0 ||
	!preg_match('/^-?[0-9]{1,6}$/D', $birthday_value) || $birthday_value === '999999' ||
	(int) $birthday_value < mkrealdate(1, 1, 1901) || (int) $birthday_value > mkrealdate(31, 12, 2099))
{
	message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}
$birthday_days = (int) $birthday_value;
$date_today = create_date('Ymd', time(), $board_config['board_timezone']);
// Compare day counters before realdate() can encounter a post-2038 timestamp
// on 32-bit PHP 5.6. A future birthdate must never wrap into an older year.
if ($birthday_days > mkrealdate((int) substr($date_today, 6, 2), (int) substr($date_today, 4, 2), (int) substr($date_today, 0, 4)))
{
	message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}
$birthdate = realdate('Ymd', $birthday_days);
if (!preg_match('/^([0-9]{4})([0-9]{2})([0-9]{2})$/D', $birthdate, $birthday_match) ||
	!checkdate((int) $birthday_match[2], (int) $birthday_match[3], (int) $birthday_match[1]))
{
	message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}

// Calculate the most recent anniversary in the forum's local calendar.
// gmmktime normalizes February 29 to March 1 in non-leap years, matching
// Birthday MOD calendar events. No server-local year enters the age.
$year = (int) substr($date_today, 0, 4);
$anniversary = gmmktime(0, 0, 0, (int) $birthday_match[2], (int) $birthday_match[3], $year);
if (gmdate('Ymd', $anniversary) > $date_today)
{
	$year--;
	$anniversary = gmmktime(0, 0, 0, (int) $birthday_match[2], (int) $birthday_match[3], $year);
}
$age = $year - (int) $birthday_match[1];
$l_greeting = gmdate('Ymd', $anniversary) === $date_today ?
	sprintf($lang['Birthday_greeting_today'], $age) :
	sprintf($lang['Birthday_greeting_prev'], $age, realdate(str_replace('Y', '', $lang['DATE_FORMAT']), (int) floor($anniversary / 86400)));

$gen_simple_header = TRUE; 
$page_title = $lang['Greeting_Messaging']; 
include($phpbb_root_path . 'includes/page_header.'.$phpEx); 
$template->set_filenames(array( 
      'body' => 'greeting_popup.tpl')); 
$template->assign_vars(array( 
      'L_CLOSE_WINDOW' => $lang['Close_window'], 
      'L_MESSAGE' => $l_greeting )); 
$template->pparse('body'); 
include($phpbb_root_path . 'includes/page_tail.'.$phpEx); 

?>
