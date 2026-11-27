<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang, array(
	'PLUS_DAILY_HEADING' => 'Visitors today',
	'PLUS_DAILY_TOTAL' => array(1 => '%d person visited the board today.', 2 => '%d people visited the board today.'),
	'PLUS_DAILY_MEMBERS' => array(1 => '%d member visited the board today.', 2 => '%d members visited the board today.'),
	'PLUS_DAILY_BREAKDOWN' => 'Visible members: %1$d · Hidden members: %2$d · Guests: %3$d',
	'PLUS_DAILY_RECENT' => array(1 => '%d of them visited in the last hour.', 2 => '%d of them visited in the last hour.'),
	'PLUS_DAILY_MODE' => 'Show visitors today',
	'PLUS_DAILY_MODE_EXPLAIN' => 'The day starts at midnight in the board time zone. Each guest address counts once per day when the board’s online guest display is enabled.',
	'PLUS_DAILY_MEMBERS_ONLY' => 'Members',
	'PLUS_DAILY_WITH_GUESTS' => 'Members, guests and the last hour',
	'PLUS_DAILY_SCOPE' => 'Show daily activity on',
	'PLUS_DAILY_SCOPE_EXPLAIN' => 'The portal shows totals. Readers with permission to view profiles can also see member names on the index and category pages.',
	'PLUS_DAILY_INDEX' => 'Board index and portal',
	'PLUS_DAILY_CATEGORIES' => 'Board index, portal and categories',
	'PLUS_DAILY_FORCE' => 'Use this selection of pages for all members',
	'PLUS_DAILY_INHERIT' => 'Board default',
));
