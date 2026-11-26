<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(),array(
	'ACP_PLUS_ARC_LOG'=>'Fehlerprotokoll der Spielhalle','ACL_A_PLUS_ARC_LOG'=>'Kann das Fehlerprotokoll der Spielhalle lesen und bereinigen',
	'PLUS_ARC_LOG_ID'=>'Nr.','PLUS_ARC_LOG_IDS'=>'Ausgewählte Nummern','PLUS_ARC_LOG_TYPE'=>'Ereignistyp',
	'PLUS_ARC_LOG_MARK'=>'Eintrag %d auswählen','PLUS_ARC_LOG_DETAILS'=>'Diagnosedaten','PLUS_ARC_LOG_COUNT'=>'Anzahl der Einträge: %d',
	'PLUS_ARC_LOG_CLEAR_FILTER'=>'Alle Einträge dieses Filters bereinigen','PLUS_ARC_LOG_NO_ACCOUNT'=>'Konto nicht mehr zugeordnet',
	'PLUS_ARC_LOG_UNKNOWN'=>'Nicht überliefert','PLUS_ARC_LOG_NONE'=>'Dieser Filter enthält keine Einträge.',
	'PLUS_ARC_LOG_SELECT'=>'Bitte wähle mindestens einen Protokolleintrag aus.',
	'PLUS_ARC_LOG_CONFIRM_FILTER'=>'Alle %d Einträge dieses Filters dauerhaft löschen?',
	'PLUS_ARC_LOG_CONFIRM_SELECTED'=>'Die ausgewählten Einträge (%d) dauerhaft löschen?',
	'PLUS_ARC_LOG_CHANGED'=>'Die Auswahl hat sich geändert. Bitte prüfe die aktuelle Liste.',
	'PLUS_ARC_LOG_DONE'=>'Gelöschte Protokolleinträge: %d',
	'LOG_PLUS_ARC_DIAGNOSTICS'=>'Einträge im Fehlerprotokoll der Spielhalle gelöscht (%1$d, Filter %2$s)',
));
