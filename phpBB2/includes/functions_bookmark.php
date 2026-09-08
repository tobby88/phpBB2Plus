<?php
/***************************************************************************
 *                           functions_bookmark.php
 *                            -------------------
 *   begin                : Sun Dec 01, 2002
 *   copyright            : (C) 2004 Philipp Kordowich
 *                          Parts: (C) 2002 The phpBB Group
 *
 *   part of Bookmark Mod 1.1.1
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_topic_preferences.php';

function phpbb_bookmark_preference($topic_id, $state)
{
	global $db;
	try { return phpbb_topic_preference($db, $topic_id, 'bookmark', $state); }
	catch (PhpbbTopicPreferenceException $exception) { message_die(GENERAL_MESSAGE, $exception->getMessage()); }
}
function is_bookmark_set($topic_id) { return phpbb_bookmark_preference($topic_id, null); }
function set_bookmark($topic_id) { return phpbb_bookmark_preference($topic_id, true); }
function remove_bookmark($topic_id) { return phpbb_bookmark_preference($topic_id, false); }
