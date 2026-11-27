<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(),array(
	'PLUS_TOPIC_DEFAULT_AVATAR'=>'Standardavatar in Themen anzeigen',
	'PLUS_TOPIC_DEFAULT_AVATAR_EXPLAIN'=>'Zeigt neben Beiträgen ein gemeinsames Bild an, wenn der Autor keinen persönlichen Avatar hinterlegt hat. Dafür müssen Galerie-Avatare aktiviert sein. Ein eigenes GIF kannst du unter images/plusavatars/default_avatar.gif ablegen.',
));
