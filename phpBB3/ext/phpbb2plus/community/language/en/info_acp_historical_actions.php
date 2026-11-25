<?php
if (!defined('IN_PHPBB')) { exit; }
$actions = array('DELETE' => 'Deleted topic #%1$d', 'MOVE' => 'Moved topic #%1$d', 'LOCK' => 'Locked topic #%1$d',
	'UNLOCK' => 'Unlocked topic #%1$d', 'SPLIT' => 'Split topic #%1$d', 'MERGE' => 'Merged topic #%1$d',
	'EDIT' => 'Edited a post in topic #%1$d', 'ANNOUNCE' => 'Changed topic #%1$d to an announcement',
	'STICKY' => 'Changed topic #%1$d to a sticky', 'NORMAL' => 'Changed topic #%1$d to a normal topic',
	'OTHER' => 'Performed “%5$s” on topic #%1$d');
foreach ($actions as $key => $action)
{
	$lang['LOG_PLUS_ARCHIVE_' . $key] = $action . '. Recorded in phpBB2 as “%2$s” (account %3$d, entry %4$d).';
}
