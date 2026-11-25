<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_DL_RECENT'=>'Recent downloads',
    'PLUS_DL'=>'Downloads', 'PLUS_DL_SEARCH'=>'Search downloads', 'PLUS_DL_CATEGORIES'=>'Categories', 'PLUS_DL_FILES'=>'Files',
    'PLUS_DL_EMPTY'=>'No files are available here.', 'PLUS_DL_COUNT'=>'Downloads', 'PLUS_DL_PENDING'=>'This file is awaiting approval.',
    'PLUS_DL_BROKEN'=>'A broken download has been reported for this file.', 'PLUS_DL_VERSION'=>'Version', 'PLUS_DL_PREVIEW'=>'Preview',
    'PLUS_DL_RATING'=>'Rating', 'PLUS_DL_DOCS'=>'Documentation', 'PLUS_DL_GET'=>'Download file', 'PLUS_DL_MISSING'=>'The original file is unavailable.',
    'PLUS_DL_LICENSE'=>'Licence terms', 'PLUS_DL_ACCEPT'=>'Accept the terms and download', 'ACL_A_PLUS_DOWNLOADS'=>'Can administer the download library',
    'PLUS_DL_COMMENT'=>'Write a comment', 'PLUS_DL_DELETE_COMMENT'=>'Delete comment', 'PLUS_DL_VOTE'=>'Rate file',
    'PLUS_DL_CONFIRM_DELETE'=>'Delete this comment?', 'PLUS_DL_ALREADY_VOTED'=>'You have already rated this file.',
    'PLUS_DL_BAD_VOTE'=>'Choose a rating from 1 to 10.', 'PLUS_DL_BAD_COMMENT'=>'Enter a comment within the configured length limit.',
));
