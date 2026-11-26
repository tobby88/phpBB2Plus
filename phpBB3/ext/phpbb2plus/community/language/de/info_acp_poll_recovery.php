<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_POLL_REVIEW'=>'Übernommene Umfragen prüfen',
	'ACP_PLUS_POLL_HELP'=>'Diese Umfragen enthalten widersprüchliche oder unvollständige Daten aus dem alten Forum. Über die Umfragenummer lassen sich die ursprüngliche Frage, die Antworten und die Stimmenzahlen einsehen.',
	'ACP_PLUS_POLL_ID'=>'Frühere Umfragenummer', 'ACP_PLUS_POLL_TOPIC'=>'Frühere Themennummer', 'ACP_PLUS_POLL_REASON'=>'Grund für die Prüfung',
	'ACP_PLUS_POLL_START'=>'Beginn als Unix-Zeit', 'ACP_PLUS_POLL_DURATION'=>'Dauer in Sekunden (0 für unbegrenzt)',
	'ACP_PLUS_POLL_RECEIPTS'=>'Erfasste Teilnahmen', 'ACP_PLUS_POLL_OPTION_ID'=>'Frühere Antwortnummer', 'ACP_PLUS_POLL_TEXT'=>'Antwort',
	'ACP_PLUS_POLL_TOTAL'=>'Stimmen', 'ACP_PLUS_POLL_EMPTY'=>'Alle übernommenen Umfragen sind zugeordnet.',
	'ACP_PLUS_POLL_NOT_FOUND'=>'Zu dieser Nummer liegt keine Umfrage zur Prüfung vor.', 'ACP_PLUS_POLL_MISSING_VALUE'=>'Wert fehlt',
	'ACP_PLUS_POLL_MISSING_TOPIC'=>'Das zugehörige Thema fehlt.', 'ACP_PLUS_POLL_MOVED_TOPIC'=>'Die Umfrage gehört zu einem Verschiebeverweis.',
	'ACP_PLUS_POLL_MULTIPLE_POLLS'=>'Mehrere Umfragen gehören zum selben Thema.', 'ACP_PLUS_POLL_NO_OPTIONS'=>'Es sind keine Antworten gespeichert.',
	'ACP_PLUS_POLL_INVALID_OPTION_ID'=>'Eine Antwortnummer fehlt oder liegt außerhalb des gültigen Bereichs.',
	'ACP_PLUS_POLL_DUPLICATE_OPTION_ID'=>'Mehrere Antworten tragen dieselbe Nummer.', 'ACP_PLUS_POLL_NATIVE_OPTION_LIMIT'=>'Es gibt zu viele Antworten für eine phpBB-Umfrage.',
	'ACP_PLUS_POLL_INVALID_TOTAL'=>'Eine Stimmenzahl fehlt oder ist negativ.', 'ACP_PLUS_POLL_INVALID_SCHEDULE'=>'Die gespeicherte Laufzeit lässt sich nicht übernehmen.',
	'ACP_PLUS_POLL_QUESTION_TOO_LONG'=>'Die Frage überschreitet die zulässige Speicherlänge.',
));
