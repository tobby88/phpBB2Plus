<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_REG_NETWORK' => 'Registration address lookup',
	'ACL_A_PLUS_REG_NETWORK' => 'Can view archived registration hostnames and accounts sharing a registration address',
	'PLUS_REG_ADDRESS' => 'IP address at registration',
	'PLUS_REG_HOST' => 'Archived registration hostname',
	'PLUS_REG_UNKNOWN_IP' => 'No registration IP recorded',
	'PLUS_REG_UNKNOWN_HOST' => 'No hostname preserved',
	'PLUS_REG_MATCHES' => 'Accounts with this registration IP',
	'PLUS_REG_NONE' => 'No matching accounts on this page.',
	'PLUS_REG_NO_USER' => 'No matching account found.',
));
