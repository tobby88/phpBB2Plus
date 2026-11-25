<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_POST_DELETION' => 'Löschen von Beiträgen und Themen',
	'PLUS_ALLOW_PERMANENT_DELETE' => 'Endgültiges Entfernen zur Auswahl anbieten',
	'PLUS_ALLOW_PERMANENT_DELETE_EXPLAIN' => 'Wer das entsprechende Löschrecht hat, kann beim Bestätigen die endgültige Entfernung auswählen. Ohne diese Auswahl bleibt ein Platzhalter für einen zuvor veröffentlichten Beitrag.',
	'PLUS_PERMANENT_DELETE_DISABLED' => 'Das endgültige Entfernen ist für dieses Forum ausgeschaltet. Öffne die Löschbestätigung erneut.',
));
