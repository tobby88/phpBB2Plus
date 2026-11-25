<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_CARDS_COUNT' => 'Kartenstand aus phpBB2 Plus',
	'PLUS_CARDS_SOURCE' => 'Das alte Forum speicherte einen Zähler. Eine rote Karte konnte ihn direkt auf die Sperrschwelle setzen. Datum, Gründe und zuständige Moderatoren wurden beim Zähler nicht erfasst.',
	'PLUS_CARDS_THRESHOLD' => 'Die frühere Sperrschwelle lag bei %d. Aktuelle Verwarnungen und Sperren verwaltet phpBB getrennt.',
	'USER_ADMIN_PLUS_RESET_CARDS' => 'Historischen Kartenstand zurücksetzen',
	'PLUS_CARDS_CONFIRM' => 'Den historischen Kartenstand von %2$d für %1$s zurücksetzen? Aktuelle Verwarnungen und Sperren gelten weiterhin.',
	'PLUS_CARDS_NONE' => 'Für dieses Mitglied sind keine historischen Karten verzeichnet.',
	'PLUS_CARDS_RESET' => 'Der historische Kartenstand wurde zurückgesetzt.',
	'LOG_PLUS_CARDS_RESET' => '<strong>Historischen Kartenstand für %1$s zurückgesetzt</strong><br>» Bisheriger Stand: %2$d',
));
