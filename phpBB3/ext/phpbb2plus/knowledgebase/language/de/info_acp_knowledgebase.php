<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_KB' => 'Wissensdatenbank', 'ACP_PLUS_KB_ARTICLES' => 'Artikel verwalten', 'ACP_PLUS_KB_ARTICLE' => 'Artikel',
	'ACP_PLUS_KB_ALL' => 'Alle Artikel', 'ACP_PLUS_KB_EDITED' => 'Überarbeitungen zur Freigabe', 'ACP_PLUS_KB_PENDING' => 'Neue Einreichungen',
	'ACP_PLUS_KB_PUBLISHED' => 'Veröffentlicht', 'ACP_PLUS_KB_EMPTY' => 'In dieser Ansicht sind keine Artikel vorhanden.',
	'ACP_PLUS_KB_TYPES' => 'Artikeltypen verwalten', 'ACP_PLUS_KB_ARTICLES_TOTAL' => 'Artikel',
	'ACP_PLUS_KB_TYPE_NAME' => 'Artikeltyp', 'ACP_PLUS_KB_TYPE_CREATE' => 'Artikeltyp anlegen', 'ACP_PLUS_KB_TYPE_EDIT' => 'Artikeltyp umbenennen',
	'ACP_PLUS_KB_TYPE_DELETE' => 'Artikeltyp löschen', 'ACP_PLUS_KB_TYPES_EMPTY' => 'Es sind keine Artikeltypen vorhanden.',
	'ACP_PLUS_KB_TYPE_DEST' => 'Zugehörige Artikel zuordnen', 'ACP_PLUS_KB_TYPE_NONE' => 'Artikel ohne Typ beibehalten',
	'ACP_PLUS_KB_TYPE_AFFECTED' => array(1 => '%d Artikel verwendet diesen Typ.', 2 => '%d Artikel verwenden diesen Typ.'),
	'ACP_PLUS_KB_TYPE_NAME_INVALID' => 'Gib einen Namen mit höchstens 255 Zeichen ein.',
	'ACP_PLUS_KB_TYPE_DEST_INVALID' => 'Wähle einen anderen Artikeltyp oder behalte die Artikel ohne Typ bei.',
	'ACP_PLUS_KB_TYPE_CONFLICT' => 'Die Artikeltypen oder ihre Zuordnungen wurden inzwischen geändert. Öffne die aktuelle Liste vor dem erneuten Absenden.',
	'ACP_PLUS_KB_DELETE_EXPLAIN' => 'Mit dem Artikel werden seine Seiten und Bewertungen gelöscht. Ob auch die verknüpfte Diskussion entfernt wird, hängt von den Diskussionseinstellungen und deinen Forenrechten ab.',
));
