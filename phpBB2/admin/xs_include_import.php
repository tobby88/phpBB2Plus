<?php

/***************************************************************************
 *                           xs_include_import.php
 *                           ---------------------
 *   copyright            : (C) 2003 - 2005 CyberAlien
 *   support              : http://www.phpbbstyles.com
 *
 *   version              : 2.3.1
 *
 *   file revision        : 70
 *   project revision     : 78
 *   last modified        : 05 Dec 2005  13:54:54
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

if (!defined('IN_PHPBB') || !defined('IN_XS'))
{
	die("Hacking attempt");
}



function generate_style_name($str)
{
	$str = 'style_' . $str . '_%02d' . STYLE_EXTENSION;
	$num = 0;
	$found = true;
	while($found)
	{
		$filename = sprintf($str, $num);
		$found = @file_exists(XS_TEMP_DIR.$filename);
		$num ++;
	}
	return $filename;
}

?>
