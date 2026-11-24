<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge(isset($lang) ? $lang : array(), array(
	'PLUS_PORTAL' => 'Portal',
	'FIELD_PLUS_MULTI' => 'Multiple choice (checkboxes)',
	'PLUS_SHOW_ON_MEMBER_PROFILE' => 'Display on the member’s profile page',
	'PLUS_RECENT_TOPICS' => 'Recent topics',
	'PLUS_FORMER_MEMBER' => 'Former member',
	'PLUS_SHOUTBOX' => 'Shoutbox',
	'PLUS_SHOUT_HIDDEN' => 'This message was hidden by a moderator.',
	'PLUS_SHOUT_HIDE' => 'Hide',
	'PLUS_SHOUT_RESTORE' => 'Show again',
	'PLUS_SHOUT_DELETE' => 'Delete message',
	'PLUS_SHOUT_DELETE_CONFIRM' => 'Permanently delete this shoutbox message?',
	'PLUS_SHOUT_EMPTY' => 'There are no messages yet.',
	'PLUS_SHOUT_LENGTH' => 'Enter a message of up to 16,000 bytes.',
	'ACL_U_PLUS_SHOUT' => 'Can post shoutbox messages',
	'ACL_M_PLUS_SHOUT' => 'Can moderate the shoutbox',
));
