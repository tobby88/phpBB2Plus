<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACL_A_PLUS_KB' => 'Kann die Wissensdatenbank verwalten',
	'ACL_A_PLUS_KB_ARTICLES' => 'Kann Artikel der Wissensdatenbank im Administrationsbereich verwalten',
	'ACL_A_PLUS_KB_TYPES' => 'Kann Artikeltypen der Wissensdatenbank verwalten',
	'ACL_A_PLUS_KB_CATEGORIES' => 'Kann Kategorien der Wissensdatenbank und deren Inhalte verwalten',
	'ACL_A_PLUS_KB_SETTINGS' => 'Kann die Einstellungen der Wissensdatenbank verwalten',
	'ACL_M_PLUS_KB' => 'Kann Artikel der Wissensdatenbank moderieren',
));
