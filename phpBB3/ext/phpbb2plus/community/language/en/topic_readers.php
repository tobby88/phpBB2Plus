<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_READERS' => 'Who has read this topic?',
	'PLUS_READERS_LAST' => 'Last read',
	'PLUS_READERS_GUESTS' => 'Guests',
	'PLUS_READERS_TOP_TEN' => 'Ten readers by post count',
	'PLUS_READERS_TOTAL' => array(1 => '%d entry', 2 => '%d entries'),
	'PLUS_READERS_EMPTY' => 'There are no visits to display yet.',
	'PLUS_READERS_ENABLE' => 'Enable topic reader lists',
	'PLUS_READERS_ENABLE_EXPLAIN' => 'Members can see when and how often readers visited a topic they can access. Guest visits are counted together. Hidden sessions do not record a personal visit. Switching this feature off retains its existing data.',
));
