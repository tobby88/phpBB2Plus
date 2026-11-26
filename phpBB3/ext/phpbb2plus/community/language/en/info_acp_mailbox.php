<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_MAILBOX' => 'Private message counters',
	'PLUS_MAILBOX_HELP' => 'Compare the new and unread counters with each member’s mailbox. For each difference, the table shows the stored count followed by the current mailbox count.',
	'PLUS_MAILBOX_CHECKED' => array(1 => 'Checked %d account on this page.', 2 => 'Checked %d accounts on this page.'),
	'PLUS_MAILBOX_ACCOUNT' => 'Account ID',
	'PLUS_MAILBOX_NEW' => 'New messages', 'PLUS_MAILBOX_UNREAD' => 'Unread messages',
	'PLUS_MAILBOX_REPAIR' => 'Update counters', 'PLUS_MAILBOX_NEXT' => 'Check the next accounts',
	'PLUS_MAILBOX_MATCH' => 'All counters on this page match their mailboxes.',
	'PLUS_MAILBOX_CHANGED' => 'Updated the counters for account %d.',
	'PLUS_MAILBOX_STALE' => 'The mailbox for account %d has changed. Review the current counts.',
	'LOG_PLUS_MAILBOX_COUNTERS' => '<strong>Updated private message counters</strong><br>» Account %d',
));
