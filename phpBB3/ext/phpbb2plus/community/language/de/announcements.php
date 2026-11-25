<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(),array(
	'PLUS_ANNOUNCEMENTS'=>'Ankündigungen',
	'PLUS_ANNOUNCE_DEFAULT'=>'Standarddauer in Tagen',
	'PLUS_ANNOUNCE_DEFAULT_EXPLAIN'=>'Neue Ankündigungen können diese Dauer übernehmen. Bei 0 oder −1 ruht der automatische Ablauf aller Ankündigungen.',
	'PLUS_ANNOUNCE_STICKY'=>'Abgelaufene Ankündigungen als wichtig markieren',
	'PLUS_ANNOUNCE_STICKY_EXPLAIN'=>'Mit Ja bleiben die Themen oben angeheftet. Mit Nein werden sie zu normalen Themen.',
	'PLUS_ANNOUNCE_HINT'=>'Für Ankündigungen übernimmt 0 die Standarddauer von %d Tagen. −1 bedeutet unbefristet. Wichtige Themen bleiben bei 0 unbefristet.',
	'PLUS_ANNOUNCE_PAUSED'=>'Der automatische Ablauf von Ankündigungen ruht. 0 übernimmt die Standarddauer, sobald er wieder aktiv ist. −1 bedeutet unbefristet.',
	'PLUS_ANNOUNCE_RANGE'=>'Gib für diesen Thementyp eine ganze Anzahl Tage zwischen −1 und %d ein.',
	'PLUS_ANNOUNCE_CHANGED'=>'Art oder Dauer des Themas wurde inzwischen geändert. Prüfe deine Auswahl vor dem erneuten Speichern.',
));
