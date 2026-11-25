<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'UCP_PLUS_LOGINS' => 'My logins',
	'PLUS_LOGIN_SETTINGS' => 'Personal login history',
	'PLUS_LOGIN_WHEN' => 'Date and time', 'PLUS_LOGIN_ADDRESS' => 'IP address',
	'PLUS_LOGIN_EMPTY' => 'No logins have been recorded for your account yet.',
	'PLUS_LOGIN_HISTORY_OFF' => 'Login history is turned off for this board.',
	'PLUS_LOGIN_UNKNOWN_IP' => 'Unknown',
	'PLUS_LOGIN_CHANGED' => 'Your login network changed from %1$s to %2$s.',
	'PLUS_LOGIN_PREFERENCE' => 'Show a notice when I log in from a different network',
	'PLUS_LOGIN_ACKNOWLEDGE' => 'Mark notice as read',
	'PLUS_LOGIN_CHANGED_AGAIN' => 'The notice or setting has changed. Open “My logins” again.',
	'PLUS_LOGIN_RECORD' => 'Record login history',
	'PLUS_LOGIN_RECORD_EXPLAIN' => 'Members can see the time and IP address of their own successful logins.',
	'PLUS_LOGIN_LIMIT' => 'Stored logins per account',
	'PLUS_LOGIN_LIMIT_EXPLAIN' => 'Between 1 and 60 entries. The next login for each member removes older entries above this limit.',
	'PLUS_LOGIN_NETWORK' => 'Allow network change notices',
	'PLUS_LOGIN_NETWORK_EXPLAIN' => 'Compare the IP networks of the two most recent logins. Members can turn notices on or off in their User Control Panel.',
));
