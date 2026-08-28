<?php
/*************************************************************************** 
 *                          admin_arcade_ban.php 
 *                          --------------------- 
 *   begin                : Tuesday, Jan 2nd, 2007
 *   copyright            : (c) 2007 dEfEndEr
 *   email                : defenders_realm@yahoo.com
 *
 *   $Id: admin_arcade_ban.php,v 1.0.0 2007/01/02 12:45:00 dEfEndEr Exp $
 ***************************************************************************
 * 
 *   This program is free software; you can redistribute it and/or modify 
 *   it under the terms of the GNU General Public License as published by 
 *   the Free Software Foundation; either version 2 of the License, or 
 *   (at your option) any later version. 
 * 
 ***************************************************************************/
//
//  Make this file apart of the phpBB system files.
//
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if (!defined('ARCADE_ADMIN')) { define('ARCADE_ADMIN', 1); }
//
//  Make sure the ACP doesn't go and run something.
//
if( !empty($setmodules) )
{
//	$module['Arcade']['Ban_Users'] = "admin_arcade_ban." . $phpEx;
	return;
}
//
//  Set the system ROOT directory
//
$phpbb_root_path = './../';
//
//  Load phpBB System required files
//
require($phpbb_root_path . 'extension.inc');
require('pagestart.' . $phpEx);
//
//  Load the Arcade required files
//
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
//
//  Check the phpBB Arcade Mod version
//
$version = $arcade->version('./../');
//
//  Set filename
//
$file = basename(__FILE__);
//
// Lets start work :)
//

?>
