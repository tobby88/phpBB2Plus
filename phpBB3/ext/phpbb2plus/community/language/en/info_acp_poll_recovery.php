<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_POLL_REVIEW'=>'Review imported polls',
	'ACP_PLUS_POLL_HELP'=>'These polls contain conflicting or incomplete data from the old board. Select a poll number to inspect its original question, options and vote totals.',
	'ACP_PLUS_POLL_ID'=>'Source poll number', 'ACP_PLUS_POLL_TOPIC'=>'Source topic number', 'ACP_PLUS_POLL_REASON'=>'Reason for review',
	'ACP_PLUS_POLL_START'=>'Start time (Unix timestamp)', 'ACP_PLUS_POLL_DURATION'=>'Duration in seconds (0 for unlimited)',
	'ACP_PLUS_POLL_RECEIPTS'=>'Recorded participations', 'ACP_PLUS_POLL_OPTION_ID'=>'Source option number', 'ACP_PLUS_POLL_TEXT'=>'Option',
	'ACP_PLUS_POLL_TOTAL'=>'Votes', 'ACP_PLUS_POLL_EMPTY'=>'All imported polls have been assigned.',
	'ACP_PLUS_POLL_NOT_FOUND'=>'No poll with this number is awaiting review.', 'ACP_PLUS_POLL_MISSING_VALUE'=>'Missing value',
	'ACP_PLUS_POLL_MISSING_TOPIC'=>'The original topic is missing.', 'ACP_PLUS_POLL_MOVED_TOPIC'=>'The poll belongs to a moved-topic link.',
	'ACP_PLUS_POLL_MULTIPLE_POLLS'=>'Several polls belong to the same topic.', 'ACP_PLUS_POLL_NO_OPTIONS'=>'No options were recorded.',
	'ACP_PLUS_POLL_INVALID_OPTION_ID'=>'An option number is missing or out of range.',
	'ACP_PLUS_POLL_DUPLICATE_OPTION_ID'=>'Several options share the same number.', 'ACP_PLUS_POLL_NATIVE_OPTION_LIMIT'=>'There are too many options for a phpBB poll.',
	'ACP_PLUS_POLL_INVALID_TOTAL'=>'A vote count is missing or negative.', 'ACP_PLUS_POLL_INVALID_SCHEDULE'=>'The original voting period cannot be represented.',
	'ACP_PLUS_POLL_QUESTION_TOO_LONG'=>'The question exceeds the storage limit.',
));
