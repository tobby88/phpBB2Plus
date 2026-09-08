<?php
/***************************************************************************
*                                 prune.php
*                            -------------------
*   begin                : Thursday, June 14, 2001
*   copyright            : (C) 2001 The phpBB Group
*   email                : support@phpbb.com
*
*   $Id: prune.php,v 1.19.2.6 2003/03/18 23:23:57 acydburn Exp $
*
*
***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

if ( !defined('IN_PHPBB') )
{
   die("Hacking attempt");
}

require_once dirname(__FILE__) . '/functions_prune_storage.php';

// Age pruning only. Full forum removal has its own explicit ACP operation.
function prune($forum_id, $prune_date, $prune_all = false)
{
	global $db;
	if ($prune_all) { phpbb_prune_error('Prune_selection_changed'); }
	return phpbb_prune_forum($db, $forum_id, $prune_date);
}

function auto_prune($forum_id = 0)
{
	global $db;
	$schedule_updated=false;
	$result=phpbb_prune_forum($db, $forum_id, null, $schedule_updated);
	// Also refresh a changed schedule when there were no old topics to remove.
	// This runs after the backend has released its writer connection.
	if ($schedule_updated) { cache_tree(true); }
	if ($result['topics']) { board_stats(); }
	return $result;
}
