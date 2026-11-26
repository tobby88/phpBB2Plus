<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_DIRECTORY_EMAILS' => 'Member email directory',
	'ACP_PLUS_DIRECTORY_MEMBERS' => 'Administrative member list',
	'ACL_A_PLUS_MAIL_LIST' => 'Can list member names and email addresses',
	'ACL_A_PLUS_USER_LIST' => 'Can view the administrative member list with account status and activity',
	'PLUS_DIRECTORY_QUERY' => 'Name or email contains',
	'PLUS_DIRECTORY_INITIAL' => 'First character of name',
	'PLUS_DIRECTORY_ALL' => 'All names',
	'PLUS_DIRECTORY_OTHER' => 'Digits and other characters',
	'PLUS_DIRECTORY_ORDER' => 'Sort direction',
	'PLUS_DIRECTORY_ASC' => 'Ascending',
	'PLUS_DIRECTORY_DESC' => 'Descending',
	'PLUS_DIRECTORY_SIZE' => 'Accounts per page',
	'PLUS_DIRECTORY_ID' => 'ID',
	'PLUS_DIRECTORY_ACTIVE' => 'Active',
	'PLUS_DIRECTORY_BAN' => 'Account ban',
	'PLUS_DIRECTORY_TOTAL' => array(0 => 'No matching accounts.', 1 => '1 matching account.', 2 => '%d matching accounts.'),
));
