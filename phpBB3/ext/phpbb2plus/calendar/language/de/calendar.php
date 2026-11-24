<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge(isset($lang) ? $lang : array(), array(
	'PLUS_CALENDAR' => 'Kalender',
	'PLUS_CAL_DATE' => 'Datum',
	'PLUS_CAL_ALL' => 'Alle Foren',
	'PLUS_CAL_MONTH' => 'Monatsansicht',
	'PLUS_CAL_DAY' => 'Tagesansicht',
	'PLUS_CAL_HOURS' => 'Stunde auswählen',
	'PLUS_CAL_BIRTHDAY' => 'Geburtstag',
	'PLUS_CAL_EMPTY' => 'Für diesen Zeitraum gibt es keine Einträge.',
	'ACL_F_PLUS_CALENDAR' => 'Kann Kalendertermine für Themen anlegen und bearbeiten',
	'PLUS_CAL_EVENT' => 'Kalendertermin',
	'PLUS_CAL_START' => 'Beginn', 'PLUS_CAL_END' => 'Ende (optional)',
	'PLUS_CAL_START_TIME' => 'Uhrzeit des Beginns', 'PLUS_CAL_END_TIME' => 'Uhrzeit des Endes',
	'PLUS_CAL_REMOVE' => 'Termin aus dem Kalender entfernen',
	'PLUS_CAL_TIMES_HELP' => 'Ohne Uhrzeiten gilt der Termin ganztägig. Das Enddatum zählt zum Termin.',
	'PLUS_CAL_INVALID' => 'Prüfe Datum und Uhrzeit. Das Ende muss am oder nach dem Beginn liegen.',
	'PLUS_CAL_CONFLICT' => 'Der Termin wurde inzwischen geändert. Öffne den Editor erneut, um den aktuellen Stand zu sehen.',
));
