<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACL_U_PLUS_PROFILE_VISITS' => 'Can view time online, visits and page counts on member profiles',
	'PLUS_VISIT_TOTAL' => 'Total time online',
	'PLUS_VISIT_LAST' => 'Duration of last visit',
	'PLUS_VISIT_LOGINS' => 'Number of visits',
	'PLUS_VISIT_PAGES' => 'Page views',
	'PLUS_VISIT_YEARS' => array(1 => '%d year', 2 => '%d years'),
	'PLUS_VISIT_WEEKS' => array(1 => '%d week', 2 => '%d weeks'),
	'PLUS_VISIT_DAYS' => array(1 => '%d day', 2 => '%d days'),
));
