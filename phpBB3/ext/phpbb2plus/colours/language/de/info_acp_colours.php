<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(),array(
	'ACP_PLUS_COLOURS'=>'Namensfarben',
	'PLUS_COLOURS_ENTRY'=>'Farbzuordnung',
	'PLUS_COLOURS_LABEL'=>'Name',
	'PLUS_COLOURS_COLOUR'=>'Farbe',
	'PLUS_COLOURS_MEMBER'=>'Mitglied auswählen',
	'PLUS_COLOURS_FORMAT'=>'Hexadezimalwert wie #006699 oder klassischen CSS-Farbnamen eingeben. Für einen unformatierten Namen das Feld leer lassen.',
	'PLUS_COLOURS_POSITION'=>'Position',
	'PLUS_COLOURS_PRIORITY'=>'Eine persönliche Zuordnung hat Vorrang. Bei bestätigten Gruppen zählt die niedrigste Position. Bei gleicher Position entscheidet die ursprüngliche Farb-ID.',
	'PLUS_COLOURS_LEGEND'=>'In der Legende der Forenübersicht anzeigen',
	'PLUS_COLOURS_ADD'=>'Farbe hinzufügen',
	'PLUS_COLOURS_INHERIT'=>'Geerbte Farbe verwenden',
	'PLUS_COLOURS_CONFLICT'=>'Die Zuordnung oder Farbe wurde inzwischen geändert. Bitte das Formular vor dem Speichern erneut öffnen.',
	'PLUS_COLOURS_DUPLICATE'=>'Eine Farbe mit diesem Namen existiert bereits.',
	'PLUS_COLOURS_UNKNOWN'=>'Diese Farbe existiert nicht mehr.',
	'PLUS_COLOURS_INVALID'=>'Bitte einen Namen, eine gültige Farbe und eine Position im angezeigten Bereich eingeben.',
	'PLUS_COLOURS_DELETE'=>'Diese Farbe entfernen? Für bisherige Zuordnungen gilt anschließend die nächste zutreffende Farbe.',
	'LOG_PLUS_COLOUR_DELETE'=>'Namensfarbe <strong>%s</strong> entfernt',
	'LOG_PLUS_COLOUR_EDIT'=>'Namensfarbe <strong>%s</strong> gespeichert',
	'LOG_PLUS_COLOUR_ASSIGN'=>'Farbzuordnung für <strong>%s</strong> geändert',
));
