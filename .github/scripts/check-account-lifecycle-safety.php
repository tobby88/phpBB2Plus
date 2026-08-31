<?php

$root = dirname(dirname(__DIR__));
$sessions = (string) file_get_contents($root . '/phpBB2/includes/sessions.php');
$password = (string) file_get_contents($root . '/phpBB2/change_password.php');
$profile = (string) file_get_contents($root . '/phpBB2/profile.php');
$register = (string) file_get_contents($root . '/phpBB2/includes/usercp_register.php');
$activate = (string) file_get_contents($root . '/phpBB2/includes/usercp_activate.php');
$page_header = (string) file_get_contents($root . '/phpBB2/includes/page_header.php');
$send_password = (string) file_get_contents($root . '/phpBB2/includes/usercp_sendpasswd.php');
$admin_add_user = (string) file_get_contents($root . '/phpBB2/admin/admin_user_register.php');
$selects = (string) file_get_contents($root . '/phpBB2/includes/functions_selects.php');
$functions = (string) file_get_contents($root . '/phpBB2/includes/functions.php');
$admin_users = (string) file_get_contents($root . '/phpBB2/admin/admin_users.php');
$updater = (string) file_get_contents($root . '/update/update_from_153a.php');
$view_profile = (string) file_get_contents($root . '/phpBB2/includes/usercp_viewprofile.php');
$errors = array();

foreach (array(
	'$sessiondata = is_array($sessiondata) ? $sessiondata : array();',
	"preg_match('/^[a-f0-9]{32}$/iD', (string) \$sessiondata['autologinid'])",
	'$session_id = bin2hex(phpbb_random_bytes(16));',
	"is_scalar(\$_GET['sid'])",
	"is_scalar(\$HTTP_COOKIE_VARS[\$cookiename . '_sid'])"
) as $marker)
{
	if (strpos($sessions, $marker) === false)
	{
		$errors[] = 'Missing session lifecycle marker: ' . $marker;
	}
}

foreach (array(
	"\$cur_password === '' || !phpbb_password_verify",
	"assign_block_vars('switch_cur_passwd_on'",
	"hash_equals((string) \$userdata['session_id']"
) as $marker)
{
	if (strpos($password, $marker) === false)
	{
		$errors[] = 'Missing password-change marker: ' . $marker;
	}
}
if (strpos($password, "defined('EXTRA_SECURE')") !== false)
{
	$errors[] = 'Password verification still depends on the undefined EXTRA_SECURE switch.';
}

if (strpos($profile, "phpbb_board_url('profile.' . \$phpEx)") === false)
{
	$errors[] = 'Profile email links are not built from the validated board origin.';
}

foreach (array(
	'function usercp_installed_language',
	'function usercp_installed_style',
	'function usercp_timezone',
	'function usercp_dateformat',
	'$current_password_valid',
	"\$mode == 'editprofile' && !\$current_password_valid"
) as $marker)
{
	if (strpos($register, $marker) === false)
	{
		$errors[] = 'Missing profile lifecycle marker: ' . $marker;
	}
}

foreach (array(
	"preg_match('/^[a-f0-9]{6,32}$/iD', \$activation_key)",
	'hash_equals(trim($row[\'user_actkey\']), $activation_key)',
	"ct_last_pw_change='"
) as $marker)
{
	if (strpos($activate, $marker) === false)
	{
		$errors[] = 'Missing activation marker: ' . $marker;
	}
}

if (strpos($password, 'ct_last_pw_change=') === false)
{
	$errors[] = 'Forced password changes do not update the CrackerTracker password-age timestamp.';
}
if (strpos($page_header, "\$userdata['ct_last_pw_change']") === false ||
	strpos($page_header, "\$userdata['ct_last_pw_reset']") !== false)
{
	$errors[] = 'Password-age notices still use the reset-request cooldown timestamp.';
}
if (strpos($send_password, 'ct_last_pw_reset') === false || strpos($send_password, 'ct_last_pw_change') !== false)
{
	$errors[] = 'Password-reset throttling is not isolated from password-age tracking.';
}
foreach (array("\$db->sql_escape(\$username)", "\$db->sql_escape(\$email)", 'min(180, $pwreset_minutes)') as $marker)
{
	if (strpos($send_password, $marker) === false)
	{
		$errors[] = 'Password-reset input or cooldown hardening is missing: ' . $marker;
	}
}
if (substr_count($admin_users, 'ct_last_pw_change = $password_changed_at') < 2 ||
	substr_count($admin_users, 'user_passwd_change = $password_changed_at') < 2 ||
	strpos($admin_add_user, 'user_passwd_change, ct_last_pw_change') === false)
{
	$errors[] = 'Administrator password writes do not update both password-age timestamps.';
}
foreach (array('password_timestamps_split', 'ct_last_pw_change = CASE WHEN user_passwd_change > 0', 'ct_last_pw_reset = 0') as $marker)
{
	if (strpos($updater, $marker) === false)
	{
		$errors[] = 'Updater lacks the split-password-timestamp migration marker: ' . $marker;
	}
}

if (strpos($selects, '$timezone = $board_config[\'board_timezone\'];') === false)
{
	$errors[] = 'Timezone fallback still fails to assign the board default.';
}

foreach (array(
	'function phpbb_sync_username_references',
	"WHERE pic_user_id = \$user_id",
	"WHERE comment_user_id = \$user_id",
	"WHERE player_id = \$user_id",
	"WHERE shout_user_id = \$user_id",
	"highscore_player = '\$old_username_sql'",
	'AND g.group_single_user = 1'
) as $marker)
{
	if (strpos($functions, $marker) === false)
	{
		$errors[] = 'Missing username-reference synchronization marker: ' . $marker;
	}
}
if (strpos($register, "phpbb_sync_username_references(\$user_id, \$userdata['username'], \$username)") === false ||
	strpos($admin_users, "phpbb_sync_username_references(\$user_id, \$this_userdata['username'], \$rename_user)") === false)
{
	$errors[] = 'Public and ACP account renames do not share the reference synchronizer.';
}
foreach (array("'album'", "'album_comment'", "'ina_comment'", "'shout'") as $table_marker)
{
	if (strpos($updater, '$table_prefix . ' . $table_marker) === false)
	{
		$errors[] = 'Updater does not reconcile username snapshots in ' . $table_marker . '.';
	}
}
foreach (array("\$zodiac = '';", "\$u_zodiac = '';", "\$zodiac_img = '';", 'isset($lang[$zodiac_key])', 'isset($images[$zodiac_key])') as $marker)
{
	if (strpos($view_profile, $marker) === false)
	{
		$errors[] = 'Profile birthday output lacks a safe zodiac fallback: ' . $marker;
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Account lifecycle safety checks passed.\n";

?>
