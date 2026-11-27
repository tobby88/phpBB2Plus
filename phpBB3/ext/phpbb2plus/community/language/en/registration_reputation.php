<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_SFS_ENABLE' => 'Check registrations with Stop Forum Spam',
	'PLUS_SFS_ENABLE_EXPLAIN' => 'After the local checks pass, the board asks Stop Forum Spam about the IP address, email address and username in that order. A match stops the registration and further lookups.',
	'PLUS_SFS_FAIL_CLOSED' => 'Block registrations while Stop Forum Spam is unavailable',
	'PLUS_SFS_FAIL_CLOSED_EXPLAIN' => 'When disabled, a service outage is logged and registration can continue.',
	'PLUS_SFS_SIGNUP' => 'To help prevent spam, this board checks your IP address, email address and username with Stop Forum Spam when you register.',
	'PLUS_SFS_IP' => 'This IP address was flagged by the registration spam check. If this is unexpected, please contact the board administrator.',
	'PLUS_SFS_EMAIL' => 'This email address was flagged by the registration spam check. If this is unexpected, please contact the board administrator.',
	'PLUS_SFS_USERNAME' => 'This username was flagged by the registration spam check. Please choose another username or contact the board administrator.',
	'PLUS_SFS_UNAVAILABLE' => 'The registration spam check is temporarily unavailable. Please try again later.',
));
