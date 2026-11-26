<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_MAILBOX' => 'Zähler für private Nachrichten',
	'PLUS_MAILBOX_HELP' => 'Vergleicht die Zähler für neue und ungelesene Nachrichten mit dem jeweiligen Postfach. Bei Abweichungen zeigt die Tabelle zuerst den gespeicherten Zähler und danach die Anzahl im Postfach.',
	'PLUS_MAILBOX_CHECKED' => array(1 => '%d Konto auf dieser Seite geprüft.', 2 => '%d Konten auf dieser Seite geprüft.'),
	'PLUS_MAILBOX_ACCOUNT' => 'Konto-ID',
	'PLUS_MAILBOX_NEW' => 'Neue Nachrichten', 'PLUS_MAILBOX_UNREAD' => 'Ungelesene Nachrichten',
	'PLUS_MAILBOX_REPAIR' => 'Zähler aktualisieren', 'PLUS_MAILBOX_NEXT' => 'Weitere Konten prüfen',
	'PLUS_MAILBOX_MATCH' => 'Alle Zähler auf dieser Seite stimmen mit den Postfächern überein.',
	'PLUS_MAILBOX_CHANGED' => 'Die Zähler für Konto %d wurden aktualisiert.',
	'PLUS_MAILBOX_STALE' => 'Das Postfach von Konto %d hat sich geändert. Bitte prüfe die aktuellen Zähler.',
	'LOG_PLUS_MAILBOX_COUNTERS' => '<strong>Zähler für private Nachrichten aktualisiert</strong><br>» Konto %d',
));
