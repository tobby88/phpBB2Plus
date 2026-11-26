<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_ARC_CATEGORIES'=>'Spielhallen-Kategorien', 'ACL_A_PLUS_ARC_CATEGORIES'=>'Kann Kategorien der Spielhalle verwalten',
	'PLUS_ARC_CAT_NAME'=>'Kategoriename', 'PLUS_ARC_CAT_NEW'=>'Kategorie hinzufügen', 'PLUS_ARC_CAT_EDIT'=>'Kategorie bearbeiten',
	'PLUS_ARC_CAT_RESYNC'=>'Kategorien neu abgleichen', 'PLUS_ARC_CAT_TYPE'=>'Kategorietyp',
	'PLUS_ARC_CAT_TYPE_P'=>'Hauptkategorie', 'PLUS_ARC_CAT_TYPE_S'=>'Unterkategorie', 'PLUS_ARC_CAT_TYPE_L'=>'Externer Link',
	'PLUS_ARC_CAT_TOPOLOGY'=>'Wenn du den Typ einer Hauptkategorie änderst, werden ihre Unterkategorien zu Hauptkategorien. Beim Wechsel zu einem Link werden ihre Spiele „Alle Spiele“ zugeordnet.',
	'PLUS_ARC_CAT_PARENT_LABEL'=>'Hauptkategorie der Unterkategorie', 'PLUS_ARC_CAT_DESC'=>'Beschreibung oder Zieladresse eines Links',
	'PLUS_ARC_CAT_ICON'=>'Kategoriebild', 'PLUS_ARC_CAT_ICON_HELP'=>'Optionaler Pfad zu einem gemeinsamen Spielhallen-Bild. Bereits eingelesene Kategoriebilder sind sofort verfügbar. Neue Dateien können mit dem Spielhallen-Bildimport aus der Offline-Kopie eingelesen werden.',
	'PLUS_ARC_CAT_GROUP'=>'Erforderliche Gruppe', 'PLUS_ARC_CAT_ALL'=>'Keine Gruppeneinschränkung', 'PLUS_ARC_CAT_KEEP_GROUP'=>'Bisherige Einschränkung beibehalten (%s)',
	'PLUS_ARC_CAT_CLEAR_MOD'=>'Verweis auf den ehemaligen Moderator entfernen', 'PLUS_ARC_CAT_SPECIAL'=>'Schwelle für selten gespielte Spiele',
	'PLUS_ARC_CAT_SPECIAL_HELP'=>'Die Liste erscheint, wenn die Kategorie mehr Spiele als diesen Wert enthält. Null deaktiviert die Liste.',
	'PLUS_ARC_CAT_GAMES'=>'Spiele und Ergebnisse verwalten', 'PLUS_ARC_CAT_CONFIRM_DELETE'=>'„%s“ entfernen? Die Unterkategorien werden zu Hauptkategorien und die Spiele bleiben unter „Alle Spiele“ erhalten. Bisher von dieser Kategorie geerbte Zugriffsrechte können sich ändern.',
	'PLUS_ARC_CAT_CONFIRM_RESYNC'=>'Reihenfolge, Spielzahlen und letzte Spielstarts neu abgleichen? Ungültige Unterkategorien werden zu Hauptkategorien. Spiele ohne gültige Kategorie werden „Alle Spiele“ zugeordnet.',
	'PLUS_ARC_CAT_CHANGED'=>'Die Kategorie oder ihre Position hat sich geändert. Öffne das Formular erneut.',
	'PLUS_ARC_CAT_REPAIR'=>'Die Kategoriehierarchie muss repariert werden. Gleiche die Kategorien vor dem Bearbeiten neu ab.',
	'PLUS_ARC_CAT_GLOBAL'=>'Die Sammelkategorie „Alle Spiele“ fehlt oder ist ungültig.',
	'PLUS_ARC_CAT_INVALID'=>'Prüfe den Namen, die Beschreibung und den Schwellenwert.',
	'PLUS_ARC_CAT_LINK_INVALID'=>'Gib eine vollständige HTTP- oder HTTPS-Zieladresse ohne Zugangsdaten ein.',
	'PLUS_ARC_CAT_ICON_INVALID'=>'Gib den Pfad eines gemeinsamen Spielhallen-Bildes im Format GIF, JPEG, PNG oder WebP ein.',
	'PLUS_ARC_CAT_PARENT'=>'Wähle eine andere Hauptkategorie als übergeordnete Kategorie.', 'PLUS_ARC_CAT_GROUP_INVALID'=>'Wähle eine vorhandene gemeinsame Gruppe.',
	'PLUS_ARC_CAT_USER_INVALID'=>'Das angegebene Mitglied wurde nicht gefunden.', 'PLUS_ARC_CAT_COUNT'=>'Die Spielzahlen überschreiten den unterstützten Wertebereich.',
));
