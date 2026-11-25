<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_POST_DELETION' => 'Deleting posts and topics',
	'PLUS_ALLOW_PERMANENT_DELETE' => 'Offer permanent removal at confirmation',
	'PLUS_ALLOW_PERMANENT_DELETE_EXPLAIN' => 'Members with the appropriate deletion permission can choose to remove an item permanently. The default leaves a placeholder for a previously published post.',
	'PLUS_PERMANENT_DELETE_DISABLED' => 'Permanent removal is disabled on this board. Open the deletion confirmation again.',
));
