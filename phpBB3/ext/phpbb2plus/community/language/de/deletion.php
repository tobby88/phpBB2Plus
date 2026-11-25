<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_POST_DELETION' => 'Löschen von Beiträgen und Themen',
	'PLUS_PRUNE_AGE_EXPLAIN' => 'Gib mindestens eine Frist in Tagen an. Die Bereinigung entfernt ältere Inhalte und erhält ihre Themenlinks als Platzhalter. Ein neuerer Beitrag schützt das gesamte Thema. Umfragen, Bekanntmachungen und wichtige Themen kannst du gesondert einbeziehen.',
	'PLUS_PRUNE_PERIOD_REQUIRED' => 'Gib für mindestens eine der beiden Fristen einen Wert von 1 bis 36500 Tagen an. Ein leeres Feld lässt diese Frist aus.',
	'PLUS_ALLOW_PERMANENT_DELETE' => 'Endgültiges Entfernen zur Auswahl anbieten',
	'PLUS_ALLOW_PERMANENT_DELETE_EXPLAIN' => 'Wer das entsprechende Löschrecht hat, kann beim Bestätigen die endgültige Entfernung auswählen. Ohne diese Auswahl bleibt ein Platzhalter für einen zuvor veröffentlichten Beitrag.',
	'PLUS_PERMANENT_DELETE_DISABLED' => 'Das endgültige Entfernen ist für dieses Forum ausgeschaltet. Öffne die Löschbestätigung erneut.',
));
