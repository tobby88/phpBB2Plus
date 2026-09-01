<?php

define('IN_PHPBB', true);
$lang = array(
	'FB' => 'Facebook',
	'IG' => 'Instagram',
	'TWR' => 'X / Twitter',
	'TG' => 'Telegram',
	'LI' => 'LinkedIn',
	'TT' => 'TikTok',
	'DC' => 'Discord',
	'SIGNAL' => 'Signal',
	'THREEMA' => 'Threema'
);

require dirname(dirname(__DIR__)) . '/phpBB2/includes/functions.php';

function contact_test_same($expected, $actual, $message)
{
	if ($expected !== $actual)
	{
		fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
		exit(1);
	}
}

function contact_test_true($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, $message . "\n");
		exit(1);
	}
}

contact_test_same(
	'https://threema.id/ABCD1234',
	phpbb_social_profile_url('THREEMA', 'abcd1234', array('threema.id')),
	'Threema IDs must produce the official share URL.'
);
contact_test_same(
	'',
	phpbb_social_profile_url('SIGNAL', 'example.42', array('signal.me', 'signal.link')),
	'Signal usernames must not be turned into a guessed URL.'
);
$signal_link = 'https://signal.me/#eu/example-token';
contact_test_same(
	$signal_link,
	phpbb_social_profile_url('SIGNAL', $signal_link, array('signal.me', 'signal.link')),
	'Official Signal share links must remain usable.'
);
contact_test_same(
	'',
	phpbb_social_profile_allowed_url('https://facebook.com.example.test/profile', array('facebook.com')),
	'Lookalike hosts must not pass the service host boundary.'
);

contact_test_same(
	'mailto:user+board@example.test',
	phpbb_profile_email_uri('user+board@example.test'),
	'Valid profile email addresses must produce a mailto URI.'
);
contact_test_same(
	'',
	phpbb_profile_email_uri("user@example.test\r\n\" onmouseover=\"alert(1)"),
	'Profile email links must reject controls and attribute injection.'
);
contact_test_same(
	'',
	phpbb_profile_email_uri('not an email address'),
	'Malformed stored email addresses must not produce links.'
);
contact_test_same(
	'profile.php?mode=email&amp;u=42',
	phpbb_profile_email_uri('', 'profile.php?mode=email&amp;u=42'),
	'The internal board email form must remain available without exposing the address.'
);

$userdata = array('session_logged_in' => true);
$links = phpbb_social_profile_links(array(
	'user_fb' => 'javascript:alert(1)',
	'user_signal' => 'example.42',
	'user_threema' => 'ABCD1234',
	'user_icq' => '123456',
	'user_skp' => 'retired-account',
	'user_pt' => 'not-a-contact'
));
contact_test_true(strpos($links['FB_IMG'], 'href="javascript:') === false, 'Profile output must never contain a javascript: link.');
contact_test_true(strpos($links['THREEMA_IMG'], 'https://threema.id/ABCD1234') !== false, 'Threema must be clickable in compact profile output.');
contact_test_same('', $links['SIGNAL_IMG'], 'A bare Signal username must remain text-only.');
contact_test_true(strpos($links['PROFILE_ROWS'], 'example.42') !== false, 'Text-only Signal usernames must remain visible in the full profile.');
contact_test_true(strpos($links['PROFILE_ROWS'], 'ICQ') === false, 'Retired messenger data must not be rendered.');
contact_test_true(strpos($links['PROFILE_ROWS'], 'Pinterest') === false, 'Pinterest must not be rendered as a contact method.');
contact_test_true(strpos($links['PROFILE_ROWS'], '<img') === false, 'Contact output must not depend on missing legacy icon files.');

$userdata['session_logged_in'] = false;
$images = array('icon_signal' => 'signal.gif', 'icon_threema' => 'threema.gif');
$guest_links = phpbb_social_profile_links(array(
	'user_signal' => $signal_link,
	'user_threema' => 'ABCD1234'
));
$guest_output = implode(' ', $guest_links);
contact_test_true(strpos($guest_output, 'example-token') === false, 'Signal contact details must not be present in guest HTML.');
contact_test_true(strpos($guest_output, 'ABCD1234') === false, 'Threema contact details must not be present in guest HTML.');
contact_test_true(strpos($guest_output, 'href=') === false, 'Guest messenger indicators must not be contact links.');
contact_test_true(strpos($guest_output, '<img') !== false, 'Guests should still see that Signal or Threema is configured.');

$root = dirname(dirname(__DIR__)) . '/phpBB2/';
$contact_views = array(
	'viewtopic.php',
	'topic_view_users.php',
	'groupcp.php',
	'memberlist.php',
	'privmsg.php',
	'staff.php',
	'album_showpage.php',
	'includes/usercp_viewprofile.php',
	'admin/admin_account.php'
);
foreach ($contact_views as $contact_view)
{
	$source = file_get_contents($root . $contact_view);
	contact_test_true(strpos($source, "'mailto:' .") === false && strpos($source, "'mailto:'.") === false, $contact_view . ' must use the shared email URI validator.');
	contact_test_true(strpos($source, 'aim:goim') === false, $contact_view . ' must not render retired AIM links.');
	contact_test_true(strpos($source, 'edit.yahoo.com/config/send_webmesg') === false, $contact_view . ' must not render retired Yahoo Messenger links.');
	contact_test_true(strpos($source, 'icq.com/people') === false, $contact_view . ' must not render retired ICQ links.');
}

echo "Contact profile safety checks passed.\n";
