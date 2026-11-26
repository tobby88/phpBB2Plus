<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_USER_REGISTER' => 'Benutzerkonto anlegen',
	'ACL_A_PLUS_USER_REGISTER' => 'Kann neue reguläre Benutzerkonten anlegen',
	'ACP_PLUS_REGISTER_EXPLAIN' => 'Das neue Mitglied kann sich sofort anmelden. Es gelten die Registrierungsfelder und die Gruppenregeln für neue Mitglieder. Es wird keine E-Mail verschickt.',
	'ACP_PLUS_REGISTER_DATE_FORMAT' => 'Datumsformat',
	'ACP_PLUS_REGISTER_DETAILS' => 'Zugangsdaten',
	'ACP_PLUS_REGISTER_BOARD_STYLE' => 'Board-Style',
	'ACP_PLUS_REGISTER_STYLE' => 'Wähle einen aktiven Board-Style.',
	'ACP_PLUS_REGISTER_DATE' => 'Gib ein Datumsformat mit höchstens 64 Zeichen ein.',
	'ACP_PLUS_REGISTER_FAILED' => 'Das Benutzerkonto konnte nicht angelegt werden.',
	'ACP_PLUS_REGISTERED' => 'Das Benutzerkonto „%s“ wurde angelegt.',
	'LOG_PLUS_USER_REGISTER' => '<strong>Benutzerkonto angelegt</strong><br>» %s',
));
