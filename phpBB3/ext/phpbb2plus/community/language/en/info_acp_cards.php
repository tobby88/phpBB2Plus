<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_CARDS_COUNT' => 'Card count from phpBB2 Plus',
	'PLUS_CARDS_SOURCE' => 'The old board stored a count. Issuing a red card could also set it directly to the ban threshold. Dates, reasons and issuing moderators were not recorded with the count.',
	'PLUS_CARDS_THRESHOLD' => 'The old ban threshold was %d. phpBB manages current warnings and bans separately.',
	'USER_ADMIN_PLUS_RESET_CARDS' => 'Clear the historical card count',
	'PLUS_CARDS_CONFIRM' => 'Clear the historical card count of %2$d for %1$s? Current warnings and bans will remain in force.',
	'PLUS_CARDS_NONE' => 'This member has no historical cards.',
	'PLUS_CARDS_RESET' => 'The historical card count has been cleared.',
	'LOG_PLUS_CARDS_RESET' => '<strong>Cleared the historical card count for %1$s</strong><br>» Previous count: %2$d',
));
