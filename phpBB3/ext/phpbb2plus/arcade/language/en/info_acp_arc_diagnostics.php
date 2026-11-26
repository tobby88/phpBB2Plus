<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(),array(
	'ACP_PLUS_ARC_LOG'=>'Arcade error log','ACL_A_PLUS_ARC_LOG'=>'Can read and clear the Arcade error log',
	'PLUS_ARC_LOG_ID'=>'No.','PLUS_ARC_LOG_IDS'=>'Selected record numbers','PLUS_ARC_LOG_TYPE'=>'Event type',
	'PLUS_ARC_LOG_MARK'=>'Select record %d','PLUS_ARC_LOG_DETAILS'=>'Diagnostic details','PLUS_ARC_LOG_COUNT'=>'Number of records: %d',
	'PLUS_ARC_LOG_CLEAR_FILTER'=>'Clear all records matching this filter','PLUS_ARC_LOG_NO_ACCOUNT'=>'No matching account',
	'PLUS_ARC_LOG_UNKNOWN'=>'Not recorded','PLUS_ARC_LOG_NONE'=>'There are no records for this filter.',
	'PLUS_ARC_LOG_SELECT'=>'Select at least one log record.',
	'PLUS_ARC_LOG_CONFIRM_FILTER'=>'Permanently delete all %d records matching this filter?',
	'PLUS_ARC_LOG_CONFIRM_SELECTED'=>'Permanently delete the selected records (%d)?',
	'PLUS_ARC_LOG_CHANGED'=>'The selection has changed. Review the current records.',
	'PLUS_ARC_LOG_DONE'=>'Log records deleted: %d',
	'LOG_PLUS_ARC_DIAGNOSTICS'=>'Cleared Arcade error log records (%1$d, filter %2$s)',
));
