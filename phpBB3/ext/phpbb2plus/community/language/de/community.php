<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge(isset($lang) ? $lang : array(), array(
	'PLUS_PORTAL' => 'Portal',
	'PLUS_RECENT_TOPICS' => 'Aktuelle Themen',
	'PLUS_FORMER_MEMBER' => 'Ehemaliger Nutzer',
	'PLUS_SHOUTBOX' => 'Shoutbox',
	'PLUS_SHOUT_HIDDEN' => 'Diese Nachricht wurde ausgeblendet.',
	'PLUS_SHOUT_HIDE' => 'Ausblenden',
	'PLUS_SHOUT_RESTORE' => 'Wieder einblenden',
	'PLUS_SHOUT_DELETE' => 'Nachricht löschen',
	'PLUS_SHOUT_DELETE_CONFIRM' => 'Möchtest du diese Shoutbox-Nachricht dauerhaft löschen?',
	'PLUS_SHOUT_EMPTY' => 'Es gibt noch keine Nachrichten.',
	'PLUS_SHOUT_LENGTH' => 'Bitte gib eine Nachricht mit höchstens 16.000 Byte ein.',
	'ACL_U_PLUS_SHOUT' => 'Kann Nachrichten in der Shoutbox schreiben',
	'ACL_M_PLUS_SHOUT' => 'Kann die Shoutbox moderieren',
));
