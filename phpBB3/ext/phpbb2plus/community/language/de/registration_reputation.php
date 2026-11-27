<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_SFS_ENABLE' => 'Registrierungen mit Stop Forum Spam prüfen',
	'PLUS_SFS_ENABLE_EXPLAIN' => 'Nach erfolgreicher lokaler Prüfung fragt das Forum bei Stop Forum Spam nacheinander IP-Adresse, E-Mail-Adresse und Benutzername ab. Bei einem Treffer endet die Registrierung und es folgen keine weiteren Abfragen.',
	'PLUS_SFS_FAIL_CLOSED' => 'Registrierungen sperren, wenn Stop Forum Spam nicht erreichbar ist',
	'PLUS_SFS_FAIL_CLOSED_EXPLAIN' => 'Bei ausgeschalteter Option wird ein Ausfall protokolliert und die Registrierung kann fortgesetzt werden.',
	'PLUS_SFS_SIGNUP' => 'Zum Schutz vor Spam prüft dieses Forum bei der Registrierung deine IP-Adresse, deine E-Mail-Adresse und deinen Benutzernamen mit Stop Forum Spam.',
	'PLUS_SFS_IP' => 'Die Spamprüfung hat diese IP-Adresse gemeldet. Falls du das für einen Fehler hältst, wende dich bitte an die Forenadministration.',
	'PLUS_SFS_EMAIL' => 'Die Spamprüfung hat diese E-Mail-Adresse gemeldet. Falls du das für einen Fehler hältst, wende dich bitte an die Forenadministration.',
	'PLUS_SFS_USERNAME' => 'Die Spamprüfung hat diesen Benutzernamen gemeldet. Bitte wähle einen anderen Namen oder wende dich an die Forenadministration.',
	'PLUS_SFS_UNAVAILABLE' => 'Die Spamprüfung ist vorübergehend nicht erreichbar. Bitte versuche die Registrierung später erneut.',
));
