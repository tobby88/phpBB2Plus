<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge(isset($lang) ? $lang : array(), array(
	'ACP_PLUS_ACRONYMS' => 'Acronyms',
	'ACL_A_PLUS_ACRONYMS' => 'Can manage the acronym dictionary',
	'PLUS_ACRONYM_EXPLAIN' => 'The board explains these acronyms in posts, news articles and the Knowledge Base. Matching is case-sensitive.',
	'PLUS_ACRONYM_TERM' => 'Acronym', 'PLUS_ACRONYM_MEANING' => 'Meaning', 'PLUS_ACRONYM_ADD' => 'Add an acronym',
	'PLUS_ACRONYM_DELETE' => 'Remove “%s” from the dictionary?',
	'PLUS_ACRONYM_EMPTY' => 'The dictionary is empty.',
	'PLUS_ACRONYM_STALE' => 'This entry has changed. Please reopen the dictionary.',
	'PLUS_ACRONYM_INVALID' => 'Enter an acronym of up to 80 characters and a meaning of up to 255 characters.',
	'PLUS_ACRONYM_EXISTS' => 'This acronym is already in the dictionary.',
));
