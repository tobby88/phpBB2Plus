<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_TRACKER_FRIEND'=>'Forum and topic recommendations', 'PLUS_TRACKER_FRIEND_POLICY'=>'Sending policy',
	'PLUS_TRACKER_FRIEND_NATIVE'=>'Apply the board flood interval', 'PLUS_TRACKER_FRIEND_LIMIT'=>'Also wait after each successful recommendation',
	'PLUS_TRACKER_FRIEND_HOLD'=>'Suspend recommendations', 'PLUS_TRACKER_FRIEND_MINUTES'=>'Minutes between successful recommendations',
	'ACP_PLUS_TRACKER_SETTINGS' => 'CrackerTracker settings',
	'ACL_A_PLUS_CT_SETTINGS' => 'Can manage CrackerTracker settings',
	'PLUS_TRACKER_SETTINGS_CHANGED' => 'These settings have changed. Reopen the form to review the current values.',
	'LOG_PLUS_TRACKER_SETTINGS' => '<strong>Changed CrackerTracker settings</strong>',
	'ACP_PLUS_MEMBER_NOTICE' => 'Message from the board team',
	'ACL_A_PLUS_CT_NOTICE' => 'Can publish and withdraw board team messages',
	'PLUS_MEMBER_NOTICE_AUDIENCE' => 'Members who already have an account when you publish see the message until they mark it as read. Publishing it again shows it to them again.',
	'PLUS_MEMBER_NOTICE_FORMAT' => 'Format', 'PLUS_MEMBER_NOTICE_TEXT' => 'Text', 'PLUS_MEMBER_NOTICE_LINK' => 'Web link',
	'PLUS_MEMBER_NOTICE_BODY' => 'Message or web address (up to 255 characters)',
	'PLUS_MEMBER_NOTICE_PUBLISH' => 'Publish', 'PLUS_MEMBER_NOTICE_WITHDRAW' => 'Withdraw',
	'PLUS_MEMBER_NOTICE_PUBLISHED' => 'The message has been published.', 'PLUS_MEMBER_NOTICE_WITHDRAWN' => 'The message has been withdrawn.',
	'PLUS_MEMBER_NOTICE_READ' => 'Mark as read',
	'PLUS_MEMBER_NOTICE_CHANGED' => 'The message has changed. Reopen the page to see the current version.',
	'PLUS_MEMBER_NOTICE_INVALID' => 'Enter up to 255 characters of text or a complete HTTP or HTTPS link.',
	'LOG_PLUS_NOTICE_PUBLISH' => '<strong>Published a board team message</strong>',
	'LOG_PLUS_NOTICE_WITHDRAW' => '<strong>Withdrew a board team message</strong>',
));
