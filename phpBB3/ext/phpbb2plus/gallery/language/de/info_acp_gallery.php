<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_PERSONAL_ALBUMS' => 'Persönliche Fotoalben',
	'LOG_PLUS_PERSONAL_ALBUMS' => '<strong>Einstellungen für persönliche Fotoalben geändert</strong>',
	'PLUS_ALBUM_CFG_EXPLAIN' => 'Die Berechtigung zum Anlegen persönlicher Alben lässt sich einzelnen Mitgliedern und Gruppen zuweisen. Bei einer Beschränkung auf Album-Administratoren dürfen nur diese weitere Bilder in persönliche Alben hochladen. Die Standard-Sichtbarkeit gilt für neu angelegte Alben. Bei Mengenbegrenzungen steht −1 für unbegrenzt, 0 verhindert weitere Bilder oder Unteralben.',
	'PLUS_ALBUM_CFG_PERSONAL_GALLERY' => 'Wer ein persönliches Album anlegen darf',
	'PLUS_ALBUM_CFG_PERSONAL_GALLERY_VIEW' => 'Standard-Sichtbarkeit neuer persönlicher Alben',
	'PLUS_ALBUM_CFG_PERSONAL_GALLERY_LIMIT' => 'Höchstzahl der Bilder je Mitglied über alle eigenen Alben',
	'PLUS_ALBUM_CFG_PERSONAL_ALLOW_GALLERY_MOD' => 'Mitglieder dürfen ihr Album moderieren',
	'PLUS_ALBUM_CFG_PERSONAL_ALLOW_SUB_CATEGORIES' => 'Mitglieder dürfen eigene Unteralben verwalten',
	'PLUS_ALBUM_CFG_PERSONAL_SUB_CATEGORY_LIMIT' => 'Höchstzahl der Unteralben je Mitglied',
	'PLUS_ALBUM_CFG_SHOW_PERSONAL_GALLERY_LINK' => 'Zugängliches Album im Benutzerprofil verlinken',
	'PLUS_ALBUM_CFG_GUESTS' => 'Alle Besucher', 'PLUS_ALBUM_CFG_MEMBERS' => 'Registrierte Mitglieder',
	'PLUS_ALBUM_CFG_SELECTED' => 'Mitglieder mit der Benutzerberechtigung',
	'PLUS_ALBUM_CFG_PRIVATE' => 'Privat', 'PLUS_ALBUM_CFG_MODS' => 'Album-Moderatoren', 'PLUS_ALBUM_CFG_ADMINS' => 'Album-Administratoren',
	'PLUS_ALBUM_CFG_YES' => 'Ja', 'PLUS_ALBUM_CFG_NO' => 'Nein', 'PLUS_ALBUM_CFG_IMPORTED' => 'Übernommener Wert (%s)',
	'PLUS_ALBUM_CONFIG_INVALID' => 'Prüfe den Wert für „%s“. Zulässig sind die angebotenen Auswahlmöglichkeiten und ganze Mengen von −1 bis 2147483647.',
	'PLUS_ALBUM_CONFIG_CHANGED' => 'Die Album-Einstellungen wurden inzwischen geändert. Prüfe die aktuellen Werte vor dem Speichern.',
));
