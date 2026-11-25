<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_POST_DELETION' => 'Deleting posts and topics',
	'PLUS_PRUNE_AGE_EXPLAIN' => 'Enter at least one age limit in days. Pruning removes older content and keeps its topic links as placeholders. A newer post protects the whole topic. You can include polls, announcements and sticky topics separately.',
	'PLUS_PRUNE_PERIOD_REQUIRED' => 'Enter a value from 1 to 36500 days for at least one age limit. Leave a field empty to skip that limit.',
	'PLUS_ALLOW_PERMANENT_DELETE' => 'Offer permanent removal at confirmation',
	'PLUS_ALLOW_PERMANENT_DELETE_EXPLAIN' => 'Members with the appropriate deletion permission can choose to remove an item permanently. The default leaves a placeholder for a previously published post.',
	'PLUS_PERMANENT_DELETE_DISABLED' => 'Permanent removal is disabled on this board. Open the deletion confirmation again.',
));
