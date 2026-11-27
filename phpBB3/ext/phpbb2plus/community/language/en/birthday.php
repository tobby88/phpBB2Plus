<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_BIRTHDAY_SETTING' => 'Personal birthday greetings',
	'PLUS_BIRTHDAY_SETTING_EXPLAIN' => 'Send members a private board notification when they visit on or after their birthday.',
	'PLUS_BIRTHDAY_OPTION' => 'Birthday wishes from the board',
	'PLUS_BIRTHDAY_TITLE' => 'Birthday wishes from the board',
	'PLUS_BIRTHDAY_TODAY' => 'Happy birthday!',
	'PLUS_BIRTHDAY_TODAY_AGE' => 'Happy birthday! You turn %d today.',
	'PLUS_BIRTHDAY_PREVIOUS' => 'Happy belated birthday for %s!',
	'PLUS_BIRTHDAY_PREVIOUS_AGE' => 'Happy belated birthday! You turned %1$d on %2$s.',
	'PLUS_BIRTHDAY_DATE' => '%2$s %1$d',
	'PLUS_PROFILE_BIRTHDATE_FULL' => '%1$02d %3$s %4$04d',
	'PLUS_PROFILE_BIRTHDATE_PARTIAL' => '%1$02d %3$s',
	'PLUS_BIRTHDAY_UNSPECIFIED' => 'None specified',
	'PLUS_BIRTHDAY_WESTERN' => 'Zodiac',
	'PLUS_BIRTHDAY_CHINESE' => 'Chinese zodiac',
	'PLUS_BIRTHDAY_SIGNS' => array(
		'capricorn'=>'Capricorn', 'aquarius'=>'Aquarius', 'pisces'=>'Pisces', 'aries'=>'Aries', 'taurus'=>'Taurus', 'gemini'=>'Gemini',
		'cancer'=>'Cancer', 'leo'=>'Leo', 'virgo'=>'Virgo', 'libra'=>'Libra', 'scorpio'=>'Scorpio', 'sagittarius'=>'Sagittarius',
		'rat'=>'Rat', 'buffalo'=>'Buffalo', 'tiger'=>'Tiger', 'cat'=>'Cat', 'dragon'=>'Dragon', 'snake'=>'Snake', 'horse'=>'Horse',
		'goat'=>'Goat', 'monkey'=>'Monkey', 'cock'=>'Cock', 'dog'=>'Dog', 'pig'=>'Pig',
	),
));
