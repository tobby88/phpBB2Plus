<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_USER_REGISTER' => 'Create member account',
	'PLUS_REGISTRATION_NOTICE' => 'Reason for closing registration',
	'PLUS_REGISTRATION_NOTICE_EXPLAIN' => 'Visitors see this text when account registration is disabled. Leave it empty to show the standard message.',
	'ACL_A_PLUS_USER_REGISTER' => 'Can create regular member accounts',
	'ACP_PLUS_REGISTER_EXPLAIN' => 'The new member can sign in immediately. Registration profile fields and new-member group settings apply. No email is sent.',
	'ACP_PLUS_REGISTER_DATE_FORMAT' => 'Date format',
	'ACP_PLUS_REGISTER_DETAILS' => 'Account details',
	'ACP_PLUS_REGISTER_BOARD_STYLE' => 'Board style',
	'ACP_PLUS_REGISTER_STYLE' => 'Select an active board style.',
	'ACP_PLUS_REGISTER_DATE' => 'Enter a date format of up to 64 characters.',
	'ACP_PLUS_REGISTER_FAILED' => 'The member account could not be created.',
	'ACP_PLUS_REGISTERED' => 'The account “%s” has been created.',
	'LOG_PLUS_USER_REGISTER' => '<strong>Created member account</strong><br>» %s',
));
