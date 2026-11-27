<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_LAST_LOGIN' => 'Letzte Anmeldung',
	'PLUS_LAST_LOGIN_HIDDEN' => 'Verborgen',
	'PLUS_HIDE_LAST_LOGIN' => 'Letzte Anmeldung in Profilen und der Mitgliederliste verbergen',
	'PLUS_HIDE_LAST_LOGIN_EXPLAIN' => 'Nur Personen mit den Rechten zur Kontenverwaltung und zur Anzeige unsichtbarer Mitglieder sehen verborgene Anmeldezeiten. Unsichtbare Anmeldungen bleiben auch bei ausgeschalteter Option geschützt.',
	'ACL_U_PLUS_PROFILE_VISITS' => 'Kann Onlinezeiten, Besuche und Seitenaufrufe in Mitgliederprofilen sehen',
	'PLUS_VISIT_TOTAL' => 'Gesamte Onlinezeit',
	'PLUS_VISIT_LAST' => 'Dauer des letzten Besuchs',
	'PLUS_VISIT_LOGINS' => 'Anzahl der Besuche',
	'PLUS_VISIT_PAGES' => 'Seitenaufrufe',
	'PLUS_VISIT_YEARS' => array(1 => '%d Jahr', 2 => '%d Jahre'),
	'PLUS_VISIT_WEEKS' => array(1 => '%d Woche', 2 => '%d Wochen'),
	'PLUS_VISIT_DAYS' => array(1 => '%d Tag', 2 => '%d Tage'),
));
