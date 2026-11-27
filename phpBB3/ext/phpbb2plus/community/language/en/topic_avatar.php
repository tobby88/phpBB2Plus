<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(),array(
	'PLUS_TOPIC_DEFAULT_AVATAR'=>'Show the default avatar in topics',
	'PLUS_TOPIC_DEFAULT_AVATAR_EXPLAIN'=>'Show a shared image beside posts whose author has no personal avatar. Local gallery avatars must be enabled. To use a different image, place a GIF at images/plusavatars/default_avatar.gif.',
));
