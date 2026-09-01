<?php

define('IN_PHPBB', true);
define('USER_AVATAR_NONE', 0);
define('USER_AVATAR_UPLOAD', 1);
define('USER_AVATAR_REMOTE', 2);
define('USER_AVATAR_GALLERY', 3);

require dirname(dirname(__DIR__)) . '/phpBB2/includes/functions.php';

function avatar_test_same($expected, $actual, $message)
{
	if ($expected !== $actual)
	{
		fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
		exit(1);
	}
}

function avatar_test_true($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, $message . "\n");
		exit(1);
	}
}

$board_config = array(
	'allow_avatar_upload' => 1,
	'allow_avatar_remote' => 1,
	'allow_avatar_local' => 1,
	'avatar_path' => 'images/avatars',
	'avatar_gallery_path' => 'images/avatars/gallery'
);

avatar_test_same('images/avatars/member_1.png', phpbb_avatar_asset_url('member_1.png', USER_AVATAR_UPLOAD), 'Uploaded avatar paths must be assembled from validated components.');
avatar_test_same('../images/avatars/member_1.png', phpbb_avatar_asset_url('member_1.png', USER_AVATAR_UPLOAD, '../'), 'Admin pages must be able to use the validated parent prefix.');
avatar_test_same('', phpbb_avatar_asset_url('../config.php', USER_AVATAR_UPLOAD), 'Uploaded avatar traversal must be rejected.');
avatar_test_same('', phpbb_avatar_asset_url('avatar.png" onerror="alert(1)', USER_AVATAR_UPLOAD), 'Uploaded avatar markup must be rejected.');

avatar_test_same('images/avatars/gallery/smilies/smile.png', phpbb_avatar_asset_url('smilies/smile.png', USER_AVATAR_GALLERY), 'Gallery avatars must retain their category and filename.');
avatar_test_same('', phpbb_avatar_asset_url('../smilies/smile.png', USER_AVATAR_GALLERY), 'Gallery avatar traversal must be rejected.');
avatar_test_same('', phpbb_avatar_asset_url('smilies/nested/smile.png', USER_AVATAR_GALLERY), 'Gallery avatars must have exactly one category level.');

avatar_test_same('https://cdn.example.test/avatar.png?size=1&amp;mode=square', phpbb_avatar_asset_url('https://cdn.example.test/avatar.png?size=1&mode=square', USER_AVATAR_REMOTE), 'Remote avatar query strings must be HTML-escaped.');
avatar_test_same('', phpbb_avatar_asset_url('javascript:alert(1)', USER_AVATAR_REMOTE), 'Executable remote-avatar schemes must be rejected.');
avatar_test_same('', phpbb_avatar_asset_url('https://user:secret@example.test/avatar.png', USER_AVATAR_REMOTE), 'Remote avatar credentials must be rejected.');

$image = phpbb_avatar_image('member_1.png', USER_AVATAR_UPLOAD, 120);
avatar_test_true(strpos($image, 'style="max-width: 120px; max-height: 120px;"') !== false, 'Avatar size limits must be emitted without probing remote resources.');
avatar_test_true(strpos($image, 'onerror=') === false, 'Rendered avatar markup must not contain injected attributes.');

$board_config['avatar_path'] = '../images/avatars';
avatar_test_same('', phpbb_avatar_asset_url('member_1.png', USER_AVATAR_UPLOAD), 'Unsafe configured avatar paths must fail closed.');

$renderers = array(
	'album_showpage.php',
	'groupcp.php',
	'memberlist.php',
	'portal.php',
	'shoutbox_max.php',
	'staff.php',
	'topic_view_users.php',
	'viewtopic.php',
	'includes/functions_calendar.php',
	'includes/usercp_register.php',
	'includes/usercp_viewprofile.php',
	'pafiledb/includes/functions_comment.php',
	'admin/admin_users.php'
);
$root = dirname(dirname(__DIR__)) . '/phpBB2/';
foreach ($renderers as $renderer)
{
	$source = file_get_contents($root . $renderer);
	avatar_test_true(strpos($source, 'phpbb_avatar_image(') !== false, $renderer . ' must use the shared avatar renderer.');
}

echo "Avatar rendering safety checks passed.\n";
