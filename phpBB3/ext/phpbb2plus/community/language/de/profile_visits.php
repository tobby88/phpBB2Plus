<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACL_U_PLUS_PROFILE_VISITS' => 'Kann Onlinezeiten, Besuche und Seitenaufrufe in Mitgliederprofilen sehen',
	'PLUS_VISIT_TOTAL' => 'Gesamte Onlinezeit',
	'PLUS_VISIT_LAST' => 'Dauer des letzten Besuchs',
	'PLUS_VISIT_LOGINS' => 'Anzahl der Besuche',
	'PLUS_VISIT_PAGES' => 'Seitenaufrufe',
	'PLUS_VISIT_YEARS' => array(1 => '%d Jahr', 2 => '%d Jahre'),
	'PLUS_VISIT_WEEKS' => array(1 => '%d Woche', 2 => '%d Wochen'),
	'PLUS_VISIT_DAYS' => array(1 => '%d Tag', 2 => '%d Tage'),
));
