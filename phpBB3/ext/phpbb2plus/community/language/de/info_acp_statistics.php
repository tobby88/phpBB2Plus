<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_STATS_MANAGE' => 'Statistikmodule verwalten', 'ACP_PLUS_STATS_SETTINGS' => 'Statistik konfigurieren',
	'PLUS_STATS_NAME' => 'Auswertung', 'PLUS_STATS_ADD' => 'Auswertung hinzufügen', 'PLUS_STATS_AUDIENCE' => 'Sichtbar für',
	'PLUS_STATS_EVERYONE' => 'Alle Besucher', 'PLUS_STATS_MEMBERS_ONLY' => 'Angemeldete Mitglieder', 'PLUS_STATS_ADMINS_ONLY' => 'Berechtigte Administratoren',
	'PLUS_STATS_UNMAPPED' => 'Unbekannte frühere Zugriffsregel', 'PLUS_STATS_STATE_0' => 'Deinstalliert',
	'PLUS_STATS_STATE_1' => 'Installiert und inaktiv', 'PLUS_STATS_STATE_2' => 'Aktiv', 'PLUS_STATS_POSITION' => 'Position auf der Statistikseite',
	'PLUS_STATS_SOURCE' => 'Ursprüngliche Einstellungen', 'PLUS_STATS_ARCHIVED' => 'Historische Registrierung', 'PLUS_STATS_NONE' => 'Es sind noch keine Auswertungen registriert.',
	'PLUS_STATS_SOURCE_RECORD' => 'Nr. %1$d · %2$s · Position %3$d · Installiert %4$d · Aktiv %5$d · Zugriffsregel %6$d · Früheres Aktualisierungsintervall %7$d Sekunden',
	'PLUS_STATS_SOURCE_DIR' => 'Früheres Modulverzeichnis', 'PLUS_STATS_LIMIT' => 'Höchstzahl der Einträge je Rangliste',
	'PLUS_STATS_STORAGE_TTL' => 'Datenbank- und Avatargröße für diese Anzahl Sekunden zwischenspeichern (0 für sofortige Messung)',
	'PLUS_STATS_CLEAR' => 'Speichergrößen beim nächsten Aufruf neu ermitteln',
	'PLUS_STATS_CHANGED' => 'Die Statistik wurde zwischenzeitlich geändert. Bitte öffne das Formular erneut.',
	'ACL_A_PLUS_STATS_MODULES' => 'Kann Auswahl, Reihenfolge und Sichtbarkeit der Statistikmodule verwalten',
	'ACL_A_PLUS_STATS_SETTINGS' => 'Kann die globalen Einstellungen der Statistik verwalten',
	'LOG_PLUS_STATS_SETTINGS' => '<strong>Statistikeinstellungen aktualisiert</strong>', 'LOG_PLUS_STATS_MODULE' => '<strong>Statistikmodul aktualisiert</strong><br>» %s',
));
