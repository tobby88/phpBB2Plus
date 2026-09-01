<?php
/***************************************************************************
 *                            album_picm.php
 *                            -------------------
 *   started            : Saturday, January 18, 2004
 *   copyright          : © Volodymyr (CLowN) Skoryk
 *   email              : blaatimmy72@yahoo.com
 *	 version            : 1.5
 *
 *   original work by smartor album_thumbnail.php
 *	 jan 13 .. added how many times this picture was viewed...(med thumb or full pic)
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

define('IN_PHPBB', true);
$phpbb_root_path = './';
$album_root_path = $phpbb_root_path . 'album_mod/';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_ALBUM);
init_userprefs($userdata);
//
// End session management
//


//
// Get general album information
//
include($album_root_path . 'album_common.'.$phpEx);


// ------------------------------------
// Check the request
// ------------------------------------

if( isset($_GET['pic_id']) && is_scalar($_GET['pic_id']) )
{
	$pic_id = intval($_GET['pic_id']);
}
else if( isset($_POST['pic_id']) && is_scalar($_POST['pic_id']) )
{
	$pic_id = intval($_POST['pic_id']);
}
else
{
	die('No pics specified');
}


//--- Album Category Hierarchy : begin
//--- version : 1.1.0
// ------------------------------------
// Get this pic info and current Category Info
// ------------------------------------
$sql = "SELECT p.*, c.*
		FROM ". ALBUM_TABLE ." AS p, ". ALBUM_CAT_TABLE ."  AS c
		WHERE p.pic_id = '$pic_id'
			AND c.cat_id = p.pic_cat_id";

if( !($result = $db->sql_query($sql)) )
{
	message_die(GENERAL_ERROR, 'Could not query pic information', '', __LINE__, __FILE__, $sql);
}

$thispic = $db->sql_fetchrow($result);
$db->sql_freeresult($result);

if( empty($thispic) )
{
	message_die(GENERAL_ERROR, $lang['Pic_not_exist']);
}

$cat_id = $thispic['pic_cat_id'];
$album_user_id = $thispic['cat_user_id'];

$stored_pic_filename = str_replace('\\', '/', (string) $thispic['pic_filename']);
$pic_filename = basename($stored_pic_filename);
$stored_pic_thumbnail = str_replace('\\', '/', (string) $thispic['pic_thumbnail']);
$pic_thumbnail = ($stored_pic_thumbnail === '') ? '' : basename($stored_pic_thumbnail);
if ($pic_filename === '' || $pic_filename !== $stored_pic_filename ||
	($stored_pic_thumbnail !== '' && $pic_thumbnail !== $stored_pic_thumbnail))
{
	message_die(GENERAL_MESSAGE, 'The filename data in the DB was corrupted');
}
$pic_filetype = strtolower(substr($pic_filename, -4));

if( !file_exists(ALBUM_UPLOAD_PATH . $pic_filename) )
{
	message_die(GENERAL_ERROR, $lang['Pic_not_exist']);
}

// ------------------------------------
// Check the permissions
// ------------------------------------
$album_user_access = album_permissions($album_user_id, $cat_id, ALBUM_AUTH_VIEW, $thispic);

if ($album_user_access['view'] == 0)
{
	die($lang['Not_Authorised']);
}


// ------------------------------------
// Check Pic Approval
// ------------------------------------

if ($userdata['user_level'] != ADMIN)
{
	if( ($thispic['cat_approval'] == ADMIN) or (($thispic['cat_approval'] == MOD) and !$album_user_access['moderator']) )
	{
		if ($thispic['pic_approval'] != 1)
		{
			die($lang['Not_Authorised']);
		}
	}
}

//--- Album Category Hierarchy : end
// ------------------------------------
// Check hotlink
// ------------------------------------

if (($album_config['hotlink_prevent'] == 1) && isset($HTTP_SERVER_VARS['HTTP_REFERER']) &&
	!phpbb_referer_is_allowed($HTTP_SERVER_VARS['HTTP_REFERER'], $board_config['server_name'], $album_config['hotlink_allowed']))
{
	die($lang['Not_Authorised']);
}


/*
+----------------------------------------------------------
| Main work here...
+----------------------------------------------------------
*/

// ------------------------------------
// Increase view counter
// ------------------------------------
$sql = "UPDATE ". ALBUM_TABLE ."
      SET pic_view_count = pic_view_count + 1
      WHERE pic_id = '$pic_id'";
if( !$result = $db->sql_query($sql) )
{
   message_die(GENERAL_ERROR, 'Could not update pic information', '', __LINE__, __FILE__, $sql);
}


// ------------------------------------
// Send Thumbnail to browser
// ------------------------------------

if( ($pic_filetype != '.jpg') and ($pic_filetype != '.png') )
{
	// --------------------------------
	// GD does not support GIF so we must SEND a premade No-thumbnail pic then EXIT
	// --------------------------------

	header('Content-type: image/jpeg');
	readfile($images['no_thumbnail']);
	exit;
}
else
{
	// --------------------------------
	// Check thumbnail cache. If cache is available we will SEND & EXIT
	// --------------------------------

	if( ($album_sp_config['midthumb_cache'] == 1) and ($pic_thumbnail != '') and file_exists(ALBUM_MED_CACHE_PATH . $pic_thumbnail) )
	{
		switch ($pic_filetype)
		{
			case '.jpg':
				header('Content-type: image/jpeg');
				break;
			case '.png':
				header('Content-type: image/png');
				break;
		}

		readfile(ALBUM_MED_CACHE_PATH . $pic_thumbnail);
		exit;
	}


	// --------------------------------
	// Hmm, cache is empty. Try to re-generate!
	// --------------------------------

	$pic_size = @getimagesize(ALBUM_UPLOAD_PATH . $pic_filename);
	$expected_image_type = ($pic_filetype == '.jpg') ? IMAGETYPE_JPEG : IMAGETYPE_PNG;
	if ($pic_size === false || !isset($pic_size[0], $pic_size[1], $pic_size[2]) ||
		intval($pic_size[2]) !== $expected_image_type || !phpbb_image_dimensions_safe($pic_size[0], $pic_size[1]))
	{
		header('Content-type: image/jpeg');
		readfile($images['no_thumbnail']);
		exit;
	}
	$pic_width = intval($pic_size[0]);
	$pic_height = intval($pic_size[1]);

	$gd_errored = FALSE;
	switch ($pic_filetype)
	{
		case '.jpg':
			$read_function = 'imagecreatefromjpeg';
			break;
		case '.png':
			$read_function = 'imagecreatefrompng';
			break;
	}

	$src = @$read_function(ALBUM_UPLOAD_PATH  . $pic_filename);

	if (!$src)
	{
		$gd_errored = TRUE;
		$pic_thumbnail = '';
	}
	else if( ($pic_width > $album_sp_config['midthumb_width']) or ($pic_height > $album_sp_config['midthumb_height']) )
	{
		// ----------------------------
		// Resize it
		// ----------------------------

		if ($pic_width > $pic_height)
		{
			$thumbnail_width = max(1, intval($album_sp_config['midthumb_width']));
			$thumbnail_height = max(1, (int) round($thumbnail_width * ($pic_height / $pic_width)));
		}
		else
		{
			$thumbnail_height = max(1, intval($album_sp_config['midthumb_height']));
			$thumbnail_width = max(1, (int) round($thumbnail_height * ($pic_width / $pic_height)));
		}

		$use_gd1 = (intval($album_config['gd_version']) == 1 || !function_exists('imagecreatetruecolor'));
		$thumbnail = $use_gd1 ? @imagecreate($thumbnail_width, $thumbnail_height) : @imagecreatetruecolor($thumbnail_width, $thumbnail_height);
		if (!$thumbnail)
		{
			$gd_errored = TRUE;
			$pic_thumbnail = '';
		}
		else
		{
			$resize_function = $use_gd1 ? 'imagecopyresized' : 'imagecopyresampled';
			if (!@$resize_function($thumbnail, $src, 0, 0, 0, 0, $thumbnail_width, $thumbnail_height, $pic_width, $pic_height))
			{
				$gd_errored = TRUE;
				$pic_thumbnail = '';
			}
		}
	}
	else
	{
		$thumbnail = $src;
	}

	if (!$gd_errored)
	{
		if ($album_sp_config['midthumb_cache'] == 1)
		{
			// ------------------------
			// Re-generate successfully. Write it to disk!
			// ------------------------

			$pic_thumbnail = $pic_filename;

			$thumbnail_written = false;
			switch ($pic_filetype)
			{
				case '.jpg':
					$thumbnail_quality = max(0, min(100, intval($album_config['thumbnail_quality'])));
					$thumbnail_written = @imagejpeg($thumbnail, ALBUM_MED_CACHE_PATH . $pic_thumbnail, $thumbnail_quality);
					break;
				case '.png':
					$thumbnail_written = @imagepng($thumbnail, ALBUM_MED_CACHE_PATH . $pic_thumbnail);
					break;
			}

			if ($thumbnail_written)
			{
				@chmod(ALBUM_MED_CACHE_PATH . $pic_thumbnail, 0664);
			}
			else
			{
				@unlink(ALBUM_MED_CACHE_PATH . $pic_thumbnail);
				$pic_thumbnail = '';
			}
		}


		// ----------------------------
		// After write to disk, donot forget to send to browser also
		// ----------------------------

		switch ($pic_filetype)
		{
			case '.jpg':
				header('Content-type: image/jpeg');
				@imagejpeg($thumbnail, null, max(0, min(100, intval($album_config['thumbnail_quality']))));
				break;
			case '.png':
				header('Content-type: image/png');
				@imagepng($thumbnail);
				break;
		}
		if ($thumbnail !== $src && function_exists('imagedestroy'))
		{
			@imagedestroy($thumbnail);
		}
		if (function_exists('imagedestroy'))
		{
			@imagedestroy($src);
		}

		exit;
	}
	else
	{
		// ----------------------------
		// It seems you have not GD installed :(
		// ----------------------------
		if (isset($thumbnail) && $thumbnail !== $src && function_exists('imagedestroy'))
		{
			@imagedestroy($thumbnail);
		}
		if ($src && function_exists('imagedestroy'))
		{
			@imagedestroy($src);
		}

		header('Content-type: image/jpeg');
		readfile($images['no_thumbnail']);
		exit;
	}
}


// +-------------------------------------------------------------+
// |  Powered by Photo Album 2.x.x (c) 2002-2003 Smartor         |
// |  with Volodymyr (CLowN) Skoryk's Service Pack 1 © 2003-2004 |
// +-------------------------------------------------------------+

?>
