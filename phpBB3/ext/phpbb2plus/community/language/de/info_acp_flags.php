<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge(isset($lang) ? $lang : array(), array(
	'ACP_PLUS_FLAGS'=>'Länderflaggen', 'ACP_PLUS_FLAGS_EXPLAIN'=>'Hier verwaltest du die Länder und Regionen, die Mitglieder im Profil auswählen können.',
	'ACP_PLUS_FLAG_LABEL'=>'Land oder Region', 'ACP_PLUS_FLAG_CREATE'=>'Länderflagge hinzufügen', 'ACP_PLUS_FLAG_EDIT'=>'Länderflagge bearbeiten',
	'ACP_PLUS_FLAG_DELETE'=>'Länderflagge entfernen', 'ACP_PLUS_FLAG_CONFIRM'=>'„%s“ aus der Profilauswahl entfernen? Falls ein weiterer Eintrag dasselbe Bild verwendet, behalten Mitglieder diese Flagge.',
	'ACP_PLUS_FLAG_IMAGE_HELP'=>'Gib einen vorhandenen Flaggen-Dateinamen an oder lege ein GIF, PNG oder JPEG unter images/plusflags ab. Für einen reinen Texteintrag lass das Feld leer.',
	'ACP_PLUS_FLAG_IMAGE_INVALID'=>'Gib einen vorhandenen Flaggen-Dateinamen mit höchstens 25 Zeichen an oder lass das Feld leer.',
	'ACP_PLUS_FLAG_NAME_INVALID'=>'Gib einen Länder- oder Regionsnamen mit höchstens 25 Zeichen an.',
	'ACP_PLUS_FLAG_CHANGED'=>'Der Katalog wurde inzwischen geändert. Öffne das Formular erneut.',
	'ACP_PLUS_FLAG_NATIVE'=>'Eine Einstellung oder Übersetzung des Profilfelds wurde separat geändert. Prüfe das Länderfeld in der Verwaltung der benutzerdefinierten Profilfelder.',
));
