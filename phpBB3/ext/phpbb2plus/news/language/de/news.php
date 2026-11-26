<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_NEWS'=>'Nachrichten', 'PLUS_NEWS_ARCHIVE'=>'Nachrichtenarchiv', 'PLUS_NEWS_CATEGORIES'=>'Nachrichtenkategorien',
	'PLUS_NEWS_EMPTY'=>'Für diese Auswahl gibt es keine Nachrichten.', 'PLUS_NEWS_FEED'=>'RSS-Feed',
	'PLUS_NEWS_FILTER'=>'Nachrichten filtern', 'PLUS_NEWS_COMMENTS'=>'Kommentare',
	'PLUS_NEWS_PRINT'=>'Druckansicht',
	'PLUS_NEWS_EMAIL'=>'Artikel per E-Mail empfehlen',
	'ACL_F_PLUS_NEWS'=>'Kann Themen als Nachrichten veröffentlichen', 'PLUS_NEWS_CATEGORY'=>'Als Nachricht in dieser Kategorie veröffentlichen',
	'PLUS_NEWS_NONE'=>'Nicht als Nachricht veröffentlichen', 'PLUS_NEWS_RETIRED'=>'Archivierte Kategorie #%d',
	'PLUS_NEWS_CONFLICT'=>'Die Nachrichtenkategorie wurde inzwischen geändert. Prüfe die aktuelle Auswahl vor dem Speichern.',
	'PLUS_NEWS_UNKNOWN'=>'Wähle eine verfügbare Nachrichtenkategorie.',
	'PLUS_NEWS_SETTING_PUBLISH'=>'Themen als Nachrichten veröffentlichen erlauben', 'PLUS_NEWS_SETTING_PAGE_SIZE'=>'Artikel pro Seite',
	'PLUS_NEWS_SETTING_TRIM'=>'Länge des Anrisses (0 für den vollständigen Text)', 'PLUS_NEWS_SETTING_TITLE_TRIM'=>'Länge der Überschrift (0 für den vollständigen Titel)',
	'PLUS_NEWS_SETTING_FEED'=>'RSS-Feed für Nachrichten aktivieren', 'PLUS_NEWS_SETTING_FEED_LIMIT'=>'Artikel pro Feed',
	'PLUS_NEWS_SETTING_FEED_ABSTRACT'=>'Anrisse im Feed verwenden', 'PLUS_NEWS_SETTING_FEED_DESCRIPTION'=>'Feed-Beschreibung',
	'PLUS_NEWS_SETTING_FEED_LANGUAGE'=>'Sprachcode', 'PLUS_NEWS_SETTING_FEED_TTL'=>'Aktualisierungsintervall in Minuten',
	'PLUS_NEWS_SETTING_FEED_CATEGORY'=>'Feed-Kategorie', 'PLUS_NEWS_SETTING_FEED_IMAGE'=>'URL oder forumrelativer Pfad des Feed-Bildes',
	'PLUS_NEWS_SETTING_FEED_IMAGE_DESC'=>'Beschreibung des Feed-Bildes',
	'PLUS_NEWS_NAME'=>'Kategoriename', 'PLUS_NEWS_IMAGE'=>'Kategoriebild', 'PLUS_NEWS_IMAGE_NONE'=>'Kein Bild',
	'PLUS_NEWS_ADD'=>'Nachrichtenkategorie anlegen', 'PLUS_NEWS_ACTIVE'=>'Artikel dieser Kategorie veröffentlichen',
	'PLUS_NEWS_INVALID_CATEGORY'=>'Gib einen Namen mit bis zu 255 Zeichen an und wähle ein verfügbares Bild.',
	'PLUS_NEWS_DELETE'=>'Nachrichtenkategorie löschen', 'PLUS_NEWS_DELETE_CONFIRM'=>'Diese Kategorie von allen zugehörigen Artikeln entfernen? Die Forenthemen bleiben erhalten.',
));
