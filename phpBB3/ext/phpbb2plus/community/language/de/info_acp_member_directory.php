<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_DIRECTORY_EMAILS' => 'E-Mail-Verzeichnis der Mitglieder',
	'ACP_PLUS_DIRECTORY_MEMBERS' => 'Administrative Benutzerliste',
	'ACL_A_PLUS_MAIL_LIST' => 'Kann die Namen und E-Mail-Adressen der Mitglieder auflisten',
	'ACL_A_PLUS_USER_LIST' => 'Kann die administrative Benutzerliste mit Kontostatus und Aktivität einsehen',
	'PLUS_DIRECTORY_QUERY' => 'Name oder E-Mail-Adresse enthält',
	'PLUS_DIRECTORY_INITIAL' => 'Anfangszeichen des Namens',
	'PLUS_DIRECTORY_ALL' => 'Alle Namen',
	'PLUS_DIRECTORY_OTHER' => 'Ziffern und andere Zeichen',
	'PLUS_DIRECTORY_ORDER' => 'Sortierrichtung',
	'PLUS_DIRECTORY_ASC' => 'Aufsteigend',
	'PLUS_DIRECTORY_DESC' => 'Absteigend',
	'PLUS_DIRECTORY_SIZE' => 'Konten pro Seite',
	'PLUS_DIRECTORY_ID' => 'ID',
	'PLUS_DIRECTORY_ACTIVE' => 'Aktiv',
	'PLUS_DIRECTORY_BAN' => 'Kontosperre',
	'PLUS_DIRECTORY_TOTAL' => array(0 => 'Keine passenden Konten.', 1 => '1 passendes Konto.', 2 => '%d passende Konten.'),
));
