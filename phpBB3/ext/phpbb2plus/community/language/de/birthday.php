<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_BIRTHDAY_SETTING' => 'Persönliche Geburtstagsgrüße',
	'PLUS_BIRTHDAY_SETTING_EXPLAIN' => 'Mitglieder erhalten bei ihrem Besuch am oder nach ihrem Geburtstag eine persönliche Benachrichtigung im Forum.',
	'PLUS_BIRTHDAY_OPTION' => 'Geburtstagsgrüße vom Forum',
	'PLUS_BIRTHDAY_TITLE' => 'Geburtstagsgrüße vom Forum',
	'PLUS_BIRTHDAY_TODAY' => 'Alles Gute zum Geburtstag!',
	'PLUS_BIRTHDAY_TODAY_AGE' => 'Herzlichen Glückwunsch zum %d. Geburtstag!',
	'PLUS_BIRTHDAY_PREVIOUS' => 'Nachträglich alles Gute zum Geburtstag am %s!',
	'PLUS_BIRTHDAY_PREVIOUS_AGE' => 'Nachträglich alles Gute zum %1$d. Geburtstag am %2$s!',
	'PLUS_BIRTHDAY_DATE' => '%1$d. %2$s',
	'PLUS_PROFILE_BIRTHDATE_FULL' => '%1$02d.%2$02d.%4$04d',
	'PLUS_PROFILE_BIRTHDATE_PARTIAL' => '%1$02d.%2$02d.',
	'PLUS_BIRTHDAY_UNSPECIFIED' => 'Keiner angegeben',
	'PLUS_BIRTHDAY_WESTERN' => 'Sternzeichen',
	'PLUS_BIRTHDAY_CHINESE' => 'Chinesisches Sternzeichen',
	'PLUS_BIRTHDAY_SIGNS' => array(
		'capricorn'=>'Steinbock', 'aquarius'=>'Wassermann', 'pisces'=>'Fische', 'aries'=>'Widder', 'taurus'=>'Stier', 'gemini'=>'Zwillinge',
		'cancer'=>'Krebs', 'leo'=>'Löwe', 'virgo'=>'Jungfrau', 'libra'=>'Waage', 'scorpio'=>'Skorpion', 'sagittarius'=>'Schütze',
		'rat'=>'Ratte', 'buffalo'=>'Büffel', 'tiger'=>'Tiger', 'cat'=>'Katze', 'dragon'=>'Drache', 'snake'=>'Schlange', 'horse'=>'Pferd',
		'goat'=>'Ziege', 'monkey'=>'Affe', 'cock'=>'Hahn', 'dog'=>'Hund', 'pig'=>'Schwein',
	),
));
