<?php
/***************************************************************************
 *                                 db.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: db.php,v 1.10 2002/03/18 13:35:22 psotfx Exp $
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

if (!in_array($dbms, array('mysql', 'mysql4', 'mysqli'), true))
{
	message_die(CRITICAL_ERROR, 'Unsupported database driver. This build requires MySQL or MariaDB through MySQLi.');
}

if (!function_exists('mysqli_connect'))
{
	message_die(CRITICAL_ERROR, 'The PHP MySQLi extension is required.');
}

// Preserve legacy config.php values without retaining the removed mysql_* API.
include($phpbb_root_path . 'db/mysqli.' . $phpEx);

// Make the database connection.
//-- mod : run stats -----------------------------------------------------------
//-- delete
/*
$db = new sql_db($dbhost, $dbuser, $dbpasswd, $dbname, false);
*/
//-- add
include_once($phpbb_root_path . 'includes/class_db.' . $phpEx);
$db = new db_class($dbhost, $dbuser, $dbpasswd, $dbname, false);
//-- fin mod : run stats -------------------------------------------------------
if(!$db->db_connect_id)
{
	message_die(CRITICAL_ERROR, "Could not connect to the database");
}

?>
