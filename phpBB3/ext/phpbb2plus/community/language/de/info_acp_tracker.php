<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_TRACKER_SETTINGS' => 'CrackerTracker-Einstellungen',
	'ACL_A_PLUS_CT_SETTINGS' => 'Kann die CrackerTracker-Einstellungen verwalten',
	'PLUS_TRACKER_SETTINGS_CHANGED' => 'Die Einstellungen wurden inzwischen geändert. Öffne das Formular erneut, um die aktuellen Werte zu sehen.',
	'LOG_PLUS_TRACKER_SETTINGS' => '<strong>CrackerTracker-Einstellungen geändert</strong>',
	'ACP_PLUS_MEMBER_NOTICE' => 'Mitteilung der Forenleitung',
	'ACL_A_PLUS_CT_NOTICE' => 'Kann Mitteilungen der Forenleitung veröffentlichen und zurückziehen',
	'PLUS_MEMBER_NOTICE_AUDIENCE' => 'Die Mitteilung erscheint für alle zum Zeitpunkt der Veröffentlichung vorhandenen Mitglieder, bis sie sie als gelesen markieren. Erneutes Veröffentlichen zeigt sie wieder an.',
	'PLUS_MEMBER_NOTICE_FORMAT' => 'Format', 'PLUS_MEMBER_NOTICE_TEXT' => 'Text', 'PLUS_MEMBER_NOTICE_LINK' => 'Weblink',
	'PLUS_MEMBER_NOTICE_BODY' => 'Mitteilung oder Webadresse (bis zu 255 Zeichen)',
	'PLUS_MEMBER_NOTICE_PUBLISH' => 'Veröffentlichen', 'PLUS_MEMBER_NOTICE_WITHDRAW' => 'Zurückziehen',
	'PLUS_MEMBER_NOTICE_PUBLISHED' => 'Die Mitteilung wurde veröffentlicht.', 'PLUS_MEMBER_NOTICE_WITHDRAWN' => 'Die Mitteilung wurde zurückgezogen.',
	'PLUS_MEMBER_NOTICE_READ' => 'Als gelesen markieren',
	'PLUS_MEMBER_NOTICE_CHANGED' => 'Die Mitteilung wurde inzwischen geändert. Öffne die Seite erneut.',
	'PLUS_MEMBER_NOTICE_INVALID' => 'Gib einen Text mit bis zu 255 Zeichen oder einen vollständigen HTTP- oder HTTPS-Link ein.',
	'LOG_PLUS_NOTICE_PUBLISH' => '<strong>Mitteilung der Forenleitung veröffentlicht</strong>',
	'LOG_PLUS_NOTICE_WITHDRAW' => '<strong>Mitteilung der Forenleitung zurückgezogen</strong>',
));
