<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_READERS' => 'Wer hat dieses Thema gelesen?',
	'PLUS_READERS_LAST' => 'Zuletzt gelesen',
	'PLUS_READERS_GUESTS' => 'Gäste',
	'PLUS_READERS_TOP_TEN' => 'Zehn Leser nach Beitragszahl',
	'PLUS_READERS_TOTAL' => array(1 => '%d Eintrag', 2 => '%d Einträge'),
	'PLUS_READERS_EMPTY' => 'Bisher sind keine Aufrufe sichtbar.',
	'PLUS_READERS_ENABLE' => 'Leserliste für Themen aktivieren',
	'PLUS_READERS_ENABLE_EXPLAIN' => 'Mitglieder können für lesbare Themen den letzten Aufruf und die Anzahl der Aufrufe sehen. Gastaufrufe werden zusammengezählt. Versteckte Anmeldungen erzeugen keinen persönlichen Eintrag. Beim Abschalten bleiben die bisherigen Daten erhalten.',
));
