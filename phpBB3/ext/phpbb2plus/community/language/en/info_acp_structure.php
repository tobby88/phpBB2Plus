<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_STRUCTURE'=>'Repaired forum structure',
	'ACP_PLUS_STRUCTURE_HELP'=>'The converter reassigned the entries listed here. Compare each original location with its current position on the board.',
	'ACP_PLUS_STRUCTURE_FORUMS'=>'Forums and categories', 'ACP_PLUS_STRUCTURE_TOPICS'=>'Topics', 'ACP_PLUS_STRUCTURE_POSTS'=>'Posts',
	'ACP_PLUS_STRUCTURE_ITEM'=>'Item', 'ACP_PLUS_STRUCTURE_PREVIOUS'=>'In phpBB2 Plus', 'ACP_PLUS_STRUCTURE_CURRENT'=>'Current location',
	'ACP_PLUS_STRUCTURE_REASON'=>'Reason', 'ACP_PLUS_STRUCTURE_EMPTY'=>'No repairs were recorded in this section.',
	'ACP_PLUS_STRUCTURE_CATEGORY'=>'Category %d', 'ACP_PLUS_STRUCTURE_FORUM'=>'Forum %d', 'ACP_PLUS_STRUCTURE_TOPIC'=>'Topic %d',
	'ACP_PLUS_STRUCTURE_POST'=>'Post %d', 'ACP_PLUS_STRUCTURE_PARENT'=>'Under forum or category %d',
	'ACP_PLUS_STRUCTURE_POSITION'=>'Forum %1$d, topic %2$d', 'ACP_PLUS_STRUCTURE_GLOBAL'=>'Global announcement',
	'ACP_PLUS_STRUCTURE_MISSING_PARENT'=>'Missing parent', 'ACP_PLUS_STRUCTURE_CYCLE'=>'Cycle in the forum tree',
	'ACP_PLUS_STRUCTURE_MISSING_FORUM'=>'Missing original forum', 'ACP_PLUS_STRUCTURE_MISSING_TOPIC'=>'Missing original topic',
	'ACP_PLUS_STRUCTURE_MOVED_TOPIC'=>'The original topic was a move link.',
	'ACP_PLUS_STRUCTURE_POST_FORUM'=>'The post and its topic were filed in different forums.',
	'ACP_PLUS_STRUCTURE_POST_TOPIC'=>'The original topic was missing or was a move link.',
));
