<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACL_A_PLUS_KB' => 'Can administer the Knowledge Base',
	'ACL_A_PLUS_KB_ARTICLES' => 'Can manage Knowledge Base articles in the Administration Control Panel',
	'ACL_A_PLUS_KB_TYPES' => 'Can manage Knowledge Base article types',
	'ACL_M_PLUS_KB' => 'Can moderate Knowledge Base articles',
));
