<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang, array(
	'ACP_PLUS_CT_FILES' => 'CrackerTracker program files',
	'ACL_A_PLUS_CT_FILES' => 'Can check program files and approve a reference',
	'PLUS_CT_FILES_EXPLAIN' => 'Compare the PHP program files supplied in the Plus package with the reference approved here. The check reads files in batches. You can close this page and return to continue.',
	'PLUS_CT_FILES_REFERENCE' => 'Approved reference:',
	'PLUS_CT_FILES_NO_REFERENCE' => 'No reference has been approved.',
	'PLUS_CT_FILES_CATALOG' => 'The file list is missing or does not match the installed extension. Assemble the complete Plus package with the build script and install its matching files.',
	'PLUS_CT_FILES_CATALOG_CHANGED' => 'The current check belongs to an earlier package. Start a new check.',
	'PLUS_CT_FILES_STATE' => 'The saved check could not be read.',
	'PLUS_CT_FILES_STALE' => 'The check has changed since this form was opened. Reopen the program file check.',
	'PLUS_CT_FILES_NOT_READY' => 'Only a completed check with readable program files can be approved as a new reference.',
	'PLUS_CT_FILES_START' => 'Start check', 'PLUS_CT_FILES_RESTART' => 'Restart check', 'PLUS_CT_FILES_CONTINUE' => 'Continue check',
	'PLUS_CT_FILES_PROGRESS' => 'Checked %1$d of %2$d package paths.', 'PLUS_CT_FILES_RESULT' => 'Check results',
	'PLUS_CT_FILES_ADOPT' => 'Use these results as the reference',
	'PLUS_CT_FILES_CONFIRM' => 'Use this checked set of files for future comparisons? This also approves any changes or missing files shown in the results. The previous reference will be replaced.',
	'PLUS_CT_FILES_ADOPTED' => 'The checked reference has been saved.', 'LOG_PLUS_CT_FILES_REFERENCE' => '<strong>Approved a new CrackerTracker program file reference</strong>',
	'PLUS_CT_FILES_PRESENT' => 'Present', 'PLUS_CT_FILES_SAME' => 'Unchanged', 'PLUS_CT_FILES_CHANGED' => 'Contents changed',
	'PLUS_CT_FILES_ADDED' => 'Newly present', 'PLUS_CT_FILES_MISSING' => 'Missing since the reference', 'PLUS_CT_FILES_ABSENT' => 'Not installed',
	'PLUS_CT_FILES_UNREADABLE' => 'Could not be read reliably', 'PLUS_CT_FILES_RETIRED' => 'No longer listed in the package',
	'PLUS_CT_FILES_STATUS' => 'File status', 'PLUS_CT_FILES_DIFFERENCES' => 'Show differences only', 'PLUS_CT_FILES_ALL' => 'Show all checked paths',
	'PLUS_CT_FILES_NO_ENTRIES' => 'There are no entries for this selection.',
));
