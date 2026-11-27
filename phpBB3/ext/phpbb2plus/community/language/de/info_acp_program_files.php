<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang, array(
	'ACP_PLUS_CT_FILES' => 'CrackerTracker: Programmdateien',
	'ACL_A_PLUS_CT_FILES' => 'Kann Programmdateien prüfen und einen Referenzstand bestätigen',
	'PLUS_CT_FILES_EXPLAIN' => 'Vergleicht die ausgelieferten PHP-Programmdateien des Plus-Pakets mit dem hier bestätigten Stand. Die Prüfung liest die Dateien in mehreren Schritten. Nach dem Schließen dieser Seite kannst du sie hier fortsetzen.',
	'PLUS_CT_FILES_REFERENCE' => 'Bestätigter Referenzstand:',
	'PLUS_CT_FILES_NO_REFERENCE' => 'Bisher ist kein Stand bestätigt.',
	'PLUS_CT_FILES_CATALOG' => 'Die Dateiliste für dieses Paket fehlt oder stimmt mit der installierten Erweiterung nicht überein. Erstelle das vollständige Plus-Paket mit dem Build-Skript und installiere die zusammengehörigen Dateien.',
	'PLUS_CT_FILES_CATALOG_CHANGED' => 'Die laufende Prüfung gehört zu einem früheren Paket. Starte eine neue Prüfung.',
	'PLUS_CT_FILES_STATE' => 'Der gespeicherte Prüfstand ist nicht lesbar.',
	'PLUS_CT_FILES_STALE' => 'Der Prüfstand hat sich inzwischen geändert. Öffne die Dateiprüfung erneut.',
	'PLUS_CT_FILES_NOT_READY' => 'Nur eine abgeschlossene Prüfung mit lesbaren Programmdateien kann als neuer Referenzstand bestätigt werden.',
	'PLUS_CT_FILES_START' => 'Prüfung starten', 'PLUS_CT_FILES_RESTART' => 'Prüfung von vorn beginnen',
	'PLUS_CT_FILES_CONTINUE' => 'Prüfung fortsetzen', 'PLUS_CT_FILES_PROGRESS' => '%1$d von %2$d Paketpfaden geprüft.',
	'PLUS_CT_FILES_RESULT' => 'Ergebnis der Prüfung', 'PLUS_CT_FILES_ADOPT' => 'Ergebnis als Referenzstand übernehmen',
	'PLUS_CT_FILES_CONFIRM' => 'Diesen geprüften Dateistand für künftige Vergleiche übernehmen? Damit bestätigst du auch die angezeigten Änderungen und fehlenden Dateien. Der bisherige Referenzstand wird ersetzt.',
	'PLUS_CT_FILES_ADOPTED' => 'Der geprüfte Referenzstand wurde gespeichert.', 'LOG_PLUS_CT_FILES_REFERENCE' => '<strong>Neuen CrackerTracker-Referenzstand für Programmdateien bestätigt</strong>',
	'PLUS_CT_FILES_PRESENT' => 'Vorhanden', 'PLUS_CT_FILES_SAME' => 'Unverändert', 'PLUS_CT_FILES_CHANGED' => 'Inhalt geändert',
	'PLUS_CT_FILES_ADDED' => 'Neu vorhanden', 'PLUS_CT_FILES_MISSING' => 'Seit der Referenz fehlend', 'PLUS_CT_FILES_ABSENT' => 'Nicht installiert',
	'PLUS_CT_FILES_UNREADABLE' => 'Nicht zuverlässig lesbar', 'PLUS_CT_FILES_RETIRED' => 'Im neuen Paket nicht aufgeführt',
	'PLUS_CT_FILES_STATUS' => 'Dateizustand', 'PLUS_CT_FILES_DIFFERENCES' => 'Nur Abweichungen anzeigen', 'PLUS_CT_FILES_ALL' => 'Alle geprüften Pfade anzeigen',
	'PLUS_CT_FILES_NO_ENTRIES' => 'Für diese Auswahl gibt es keine Einträge.',
));
