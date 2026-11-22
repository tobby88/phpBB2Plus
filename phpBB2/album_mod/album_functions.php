<?php
/***************************************************************************
 *							  album_functions.php
 *                            -------------------
 *   begin                : Tuesday, February 04, 2003
 *   copyright            : (C) 2003 Smartor
 *   email                : smartor_xp@hotmail.com
 *
 *   $Id: album_functions.php,v 1.1.4 2003/04/03 21:08:31 ngoctu Exp $
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


require_once dirname(__DIR__) . '/includes/functions_image_orientation.php';

// ------------------------------------
// All common functions are here!
// ------------------------------------
// You cannot call this file directly from your browser
//
if ( !defined('IN_PHPBB') )
{
	die('Hacking attempt');
}

function album_picture_details_sql($picture_id)
{
	// Keep unsigned IDs readable on 32-bit PHP and keep the category join
	// mandatory. Missing categories must not be treated as public pictures.
	$picture_id = (string) $picture_id;
	if (!preg_match('/^[0-9]{1,10}$/D', $picture_id))
	{
		$picture_id = '0';
	}

	return 'SELECT p.*, ac.*, u.user_id, u.username, u.user_rank,
				(SELECT AVG(NULLIF(r.rate_point, 0)) FROM '.ALBUM_RATE_TABLE.' r WHERE r.rate_pic_id = p.pic_id) AS rating,
				(SELECT COUNT(*) FROM '.ALBUM_COMMENT_TABLE.' c WHERE c.comment_pic_id = p.pic_id) AS comments_count
			FROM '.ALBUM_TABLE.' p
				INNER JOIN '.ALBUM_CAT_TABLE.' ac ON ac.cat_id = p.pic_cat_id
				LEFT JOIN '.USERS_TABLE.' u ON u.user_id = p.pic_user_id
			WHERE p.pic_id = '.$picture_id.'
			LIMIT 1';
}

function album_inline_smilies_sql($limit)
{
	// Use the first whole entry for each image, including its own code and label.
	return 'SELECT s.emoticon, s.code, s.smile_url
			FROM '.SMILIES_TABLE.' s
			INNER JOIN (
				SELECT MIN(smilies_id) AS id FROM '.SMILIES_TABLE.'
				GROUP BY smile_url ORDER BY id LIMIT '.(int) $limit.'
			) first_smiley ON first_smiley.id = s.smilies_id
			ORDER BY s.smilies_id';
}

function album_nuffload_base_path($configured_path)
{
	$path = str_replace('\\', '/', trim((string) $configured_path));
	while (strpos($path, './') === 0)
	{
		$path = substr($path, 2);
	}
	if ($path === '' || $path[0] === '/' || preg_match('#^[a-z]:|://|[\x00\r\n]#i', $path) || preg_match('#(^|/)\.\.?(?:/|$)#', $path))
	{
		return false;
	}
	return trim($path, '/') . '/';
}

function album_nuffload_owner_token($userdata)
{
	$session_id = isset($userdata['session_id']) ? (string) $userdata['session_id'] : '';
	$user_id = isset($userdata['user_id']) ? (int) $userdata['user_id'] : 0;
	if (!preg_match('/^[a-f0-9]{32}$/i', $session_id))
	{
		return false;
	}
	return hash('sha256', $session_id . "\0" . $user_id);
}

function album_nuffload_cleanup_temp($tmp_path, $max_age = 3600)
{
	$tmp_path = rtrim((string) $tmp_path, '/\\') . '/';
	if (!is_dir($tmp_path) || !($handle = @opendir($tmp_path)))
	{
		return;
	}
	while (($file = readdir($handle)) !== false)
	{
		if (preg_match('/^[a-f0-9]{32}_(?:actualdata[0-9]+|derived_[a-f0-9]{32}|qstring|flength|postdata|received|complete|owner)$/i', $file)
			&& is_file($tmp_path . $file) && filemtime($tmp_path . $file) < (time() - (int) $max_age))
		{
			@unlink($tmp_path . $file);
		}
		else if (preg_match('/^[a-f0-9]{32}_zip_[0-9]+$/iD', $file)
			&& is_dir($tmp_path . $file) && filemtime($tmp_path . $file) < (time() - (int) $max_age))
		{
			$archive_tmp_path = $tmp_path . $file . '/';
			if ($archive_handle = @opendir($archive_tmp_path))
			{
				while (($archive_file = readdir($archive_handle)) !== false)
				{
					if ($archive_file !== '.' && $archive_file !== '..' && is_file($archive_tmp_path . $archive_file))
					{
						@unlink($archive_tmp_path . $archive_file);
					}
				}
				closedir($archive_handle);
				@rmdir($tmp_path . $file);
			}
		}
	}
	closedir($handle);
}

function album_nuffload_is_staged_file($source, $configured_path, $psid)
{
	if (!preg_match('/^[a-f0-9]{32}$/i', (string) $psid) || !is_string($source) || $source === '' || is_link($source))
	{
		return false;
	}
	$base_path = album_nuffload_base_path($configured_path);
	if ($base_path === false)
	{
		return false;
	}
	$source_real = realpath($source);
	$tmp_real = realpath($base_path . 'tmp');
	if ($source_real === false || $tmp_real === false || !is_file($source_real))
	{
		return false;
	}
	$source_dir = str_replace('\\', '/', dirname($source_real));
	$tmp_dir = rtrim(str_replace('\\', '/', $tmp_real), '/');
	return $source_dir === $tmp_dir
		&& preg_match('/^' . preg_quote((string) $psid, '/') . '_actualdata[0-9]+$/iD', basename($source_real));
}

// ----------------------------------------------------------------------------
// This function will return the access data of the current user for a category
// Default returning value is "0" (means NOT AUTHORISED)
//
// All $*_check must be "1" or "0"
//
// $passed_auth must be a full row from ALBUM_CAT_TABLE. This function still works without
// ... but $passed_auth will make it worked very much faster (because this function is often
// called in a loop)
//
function album_user_access($cat_id, $passed_auth = 0, $view_check = false, $upload_check = false, $rate_check = false, $comment_check = false, $edit_check = false, $delete_check = false)
{
	global $db, $album_config, $userdata;
	$logged_in = !empty($userdata['session_logged_in']) && isset($userdata['user_id']) && (int) $userdata['user_id'] > 0;

	// --------------------------------
	// Force to check moderator status
	// --------------------------------
	$moderator_check = 1;


	// --------------------------------
	// Here the array which this function would return. Now we initiate it!
	// --------------------------------
	$album_user_access = array(
		'view' => 0,
		'upload' => 0,
		'rate' => 0,
		'comment' => 0,
		'edit' => 0,
		'delete' => 0,
		'moderator' => 0
	);
	$album_user_access_keys = array_keys($album_user_access);
	//
	// END initiation $album_user_access
	//


	// --------------------------------
	// Check $cat_id
	// --------------------------------
	//--- Album Category Hierarchy : begin
	//--- version : 1.1.0
	//--- removed
	//if ($cat_id == PERSONAL_GALLERY)
	//{
	//	$personal_gallery_access = personal_gallery_access(1,1);
	//	if ($personal_gallery_access['view'])
	//	{
	//		$album_user_access['view'] = 1;
	//	}
	//	if ($personal_gallery_access['upload'])
	//	{
	//		$album_user_access['upload'] = 1;
	//		$album_user_access['rate'] = 1;
	//		$album_user_access['comment'] = 1;
	//		$album_user_access['edit'] = 1;
	//		$album_user_access['delete'] = 1;
	//		if ($userdata['session_logged_in'])
	//		{
	//			if ($userdata['user_level'] == ADMIN)
	//			{
	//				$album_user_access['moderator'] = 1;
	//			}
	//		}
	//	}
	//	return $album_user_access;
	//}
	//else if ($cat_id < 0)	
	if ($cat_id <= ALBUM_ROOT_CATEGORY && !is_array($passed_auth))
	//--- Album Category Hierarchy : end

	{
		message_die(GENERAL_ERROR, 'Bad cat_id arguments for function album_user_access()');
	}
	//
	// END check $cat_id
	//


	// --------------------------------
	// If the current user is an ADMIN (ALBUM_ADMIN == ADMIN)
	// --------------------------------
	if ($logged_in && $userdata['user_level'] == ADMIN)
	{
		for ($i = 0; $i < count($album_user_access); $i++)
		{
			$album_user_access[$album_user_access_keys[$i]] = 1; // Authorised All
		}

		//
		// Function EXIT here
		//
		return $album_user_access;
	}
	//
	// END check ADMIN
	//


	// --------------------------------
	// if this is a GUEST, we will ignore some checking
	// --------------------------------
	if (!$logged_in)
	{
		$edit_check = 0;
		$delete_check = 0;
		$moderator_check = 0;
	}
	//
	// END check GUEST
	//


	// --------------------------------
	// check if RATE or COMMENT are turned off by Album Config, so we can ignore them
	// --------------------------------
	if ($album_config['rate'] == 0)
	{
		$rate_check = 0;
	}
	if ($album_config['comment'] == 0)
	{
		$comment_check = 0;
	}
	//
	// END Check RATE & COMMENT
	//


	// --------------------------------
	// The array that list all access type this function will look for (except MODERATOR)
	// --------------------------------
	$access_type = array();

	if ($view_check != 0)
	{
		$access_type[] = 'view';
	}

	if ($upload_check != 0)
	{
		$access_type[] = 'upload';
	}

	if ($rate_check != 0)
	{
		$access_type[] = 'rate';
	}

	if ($comment_check != 0)
	{
		$access_type[] = 'comment';
	}

	if ($edit_check != 0)
	{
		$access_type[] = 'edit';
	}

	if ($delete_check != 0)
	{
		$access_type[] = 'delete';
	}
	//
	// END generating array $access_type
	//


	// --------------------------------
	// If everything is empty
	// --------------------------------
	if( empty($access_type) and (!$moderator_check) )
	{
		//
		// Function EXIT here
		//
		return $album_user_access;
	}
	//
	// END check empty
	//


	// --------------------------------
	// Generate the SQL query based on $access_type and $moderator_check
	// --------------------------------
	//--- Album Category Hierarchy : begin
//--- version : 1.1.0
//--- removed
//	$sql = 'SELECT cat_id';
//--- added
	$sql = 'SELECT cat_id, cat_user_id';
//--- Album Category Hierarchy : end

	for ($i = 0; $i < count($access_type); $i++)
	{
		$sql .= ', cat_'. $access_type[$i] .'_level, cat_'. $access_type[$i] .'_groups';
	}

	if ($moderator_check)
	{
		$sql .= ', cat_moderator_groups';
	}

	$sql .= "
			FROM ". ALBUM_CAT_TABLE ."
			WHERE cat_id = '$cat_id'";
	//
	// END SQL query generating
	//


	// --------------------------------
	// Query the $sql then Fetchrow if $passed_auth == 0
	// --------------------------------
	if( !is_array($passed_auth) )
	{
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, 'Could not query Album Category information' ,'' , __LINE__, __FILE__, $sql);
		}

		$thiscat = $db->sql_fetchrow($result);
	}
	else
	{
		$thiscat = $passed_auth;
	}
	//
	// END Query and Fetchrow
	//


	// --------------------------------
	// Maybe the access level is not PRIVATE or the groups list is empty
	// ... so we can skip some queries ;)
	// --------------------------------
	$groups_access = array();
	for ($i = 0; $i < count($access_type); $i++)
	{
		switch ($thiscat['cat_'. $access_type[$i] .'_level'])
		{
			case ALBUM_GUEST:
				$album_user_access[$access_type[$i]] = 1;
				break;

			case ALBUM_USER:
				if ($logged_in)
				{
					$album_user_access[$access_type[$i]] = 1;
				}
				break;

			case ALBUM_PRIVATE:
				if( ($thiscat['cat_'. $access_type[$i] .'_groups'] != '') and $logged_in )
				{
					$groups_access[] = $access_type[$i];
				}
				break;

			case ALBUM_MOD:
				// this will be checked later
				break;

			case ALBUM_ADMIN:
				// ADMIN already returned before at the checking code
				// at the top of this function. So this user cannot be authorised
				$album_user_access[$access_type[$i]] = 0;
				break;

			default:
				$album_user_access[$access_type[$i]] = 0;
		}
	}
	//
	// END Check Access Level
	//


	// --------------------------------
	// We can return now if $groups_access is empty AND $moderator_check == 0
	// --------------------------------
	if( ($moderator_check == 1) and ($thiscat['cat_moderator_groups'] != '') )
	{
		// We can merge them now
		$groups_access[] = 'moderator';
	}

	if (empty($groups_access))
	{
		//
		// Function EXIT here
		//
		return $album_user_access;
	}


	// --------------------------------
	// Now we have the list of usergroups have PRIVATE/MODERATOR access
	// So we will check if this user is in these usergroups or not...
	// --------------------------------
	// upto (6 + 1) loops maximum when this user logged in and All Levels
	// are set to PRIVATE and this function was called to check all.
	// So avoiding PRIVATE will speed up your album. However, these queries are very fast
	for ($i = 0; $i < count($groups_access); $i++)
	{
		$access_group_ids = album_sql_id_list($thiscat['cat_'. $groups_access[$i] .'_groups']);
		$sql = "SELECT group_id, user_id
				FROM ". USER_GROUP_TABLE ."
				WHERE user_id = ". (int) $userdata['user_id'] ." AND user_pending = 0
					AND group_id IN ($access_group_ids)";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, 'Could not query User-Group information' ,'' , __LINE__, __FILE__, $sql);
		}

		if( $db->sql_numrows($result) > 0 )
		{
			$album_user_access[$groups_access[$i]] = 1;
		}
	}
	//
	// END check PRIVATE/MODERATOR groups
	//


	// --------------------------------
	// If $moderator_check was called and this user is a MODERATOR he
	// will be authorised for all accesses which were not set to ADMIN
	// --------------------------------
	if( ($album_user_access['moderator'] == 1) and ($moderator_check == 1) )
	{
		for ($i = 0; $i < count($album_user_access); $i++)
		{
			$level_key = 'cat_' . $album_user_access_keys[$i] . '_level';
			if ($album_user_access_keys[$i] != 'moderator' && isset($thiscat[$level_key]) && $thiscat[$level_key] != ALBUM_ADMIN)
			{
				$album_user_access[$album_user_access_keys[$i]] = 1;
			}
		}
	}
	//
	// END Moderator
	//


	// --------------------------------
	// Return result...
	// --------------------------------
	return $album_user_access;
}
//
// END function album_user_access()
// ----------------------------------------------------------------------------



// ----------------------------------------------------------------------------
// This function will check the access (VIEW, UPLOAD) of current user on
// any personal galleries
function personal_gallery_access($check_view, $check_upload)
{
	global $db, $userdata, $album_config;
	$logged_in = !empty($userdata['session_logged_in']) && isset($userdata['user_id']) && (int) $userdata['user_id'] > 0;

	// This array will contain the result
	$personal_gallery_access = array(
		'view' => 0,
		'upload' => 0,
	);

	// --------------------------------
	// Who can create personal gallery?
	// --------------------------------
	if ($check_upload)
	{
		switch ($album_config['personal_gallery'])
		{
			case ALBUM_USER:
				if ($logged_in)
				{
					$personal_gallery_access['upload'] = 1;
				}
				break;

			case ALBUM_PRIVATE:
				if( $logged_in and ($userdata['user_level'] == ADMIN) )
				{
					$personal_gallery_access['upload'] = 1;
				}
				else if(!empty($album_config['personal_gallery_private']) and $logged_in)
				{
					$private_group_ids = album_sql_id_list($album_config['personal_gallery_private']);
					$sql = "SELECT group_id, user_id
							FROM ". USER_GROUP_TABLE ."
							WHERE user_id = ". (int) $userdata['user_id'] ." AND user_pending = 0
								AND group_id IN ($private_group_ids)";
					if( !$result = $db->sql_query($sql) )
					{
						message_die(GENERAL_ERROR, 'Could not query User-Group information' ,'' , __LINE__, __FILE__, $sql);
					}

					if( $db->sql_numrows($result) > 0 )
					{
						$personal_gallery_access['upload'] = 1;
					}
				}
				break;

			case ALBUM_ADMIN:
				if( $logged_in and ($userdata['user_level'] == ADMIN) )
				{
					$personal_gallery_access['upload'] = 1;
				}
				break;
		}
	}

	// --------------------------------
	// Who can view other personal gallery?
	// --------------------------------
	if ($check_view)
	{
		switch ($album_config['personal_gallery_view'])
		{
			case ALBUM_GUEST:
				$personal_gallery_access['view'] = 1;
				break;

			case ALBUM_USER:
				if ($logged_in)
				{
					$personal_gallery_access['view'] = 1;
				}
				break;

			case ALBUM_PRIVATE:
				if( $logged_in and ($userdata['user_level'] == ADMIN) )
				{
					$personal_gallery_access['view'] = 1;
				}
				else if(!empty($album_config['personal_gallery_private']) and $logged_in)
				{
					$private_group_ids = album_sql_id_list($album_config['personal_gallery_private']);
					$sql = "SELECT group_id, user_id
							FROM ". USER_GROUP_TABLE ."
							WHERE user_id = ". (int) $userdata['user_id'] ." AND user_pending = 0
								AND group_id IN ($private_group_ids)";
					if( !$result = $db->sql_query($sql) )
					{
						message_die(GENERAL_ERROR, 'Could not query User-Group information' ,'' , __LINE__, __FILE__, $sql);
					}

					if( $db->sql_numrows($result) > 0 )
					{
						$personal_gallery_access['view'] = 1;
					}
				}
				break;
		}
	}

	return $personal_gallery_access;
}
//
// END function personal_gallery_access()
// ----------------------------------------------------------------------------


// ----------------------------------------------------------------------------
// Build up the array similar to $thiscat array
//
function init_personal_gallery_cat($user_id = 0)
{
	//--- Album Category Hierarchy : begin
	//--- version : 1.1.0beta6
	//--- added : ,$album_config
	global $userdata, $db, $lang, $album_config;
	//--- Album Category Hierarchy : end

	if ($user_id == 0)
	{
		$user_id = $userdata['user_id'];
	}

	//--- Album Category Heirarchy : begin
	//--- version : 1.1.0beta6
	//--- removed
	//$sql = "SELECT COUNT(pic_id) AS count
	//		FROM ". ALBUM_TABLE ."
	//		WHERE pic_cat_id = ". PERSONAL_GALLERY ."
	//			AND pic_user_id = ". $user_id;
	//--- added
	$sql = "SELECT COUNT(pic_id) AS count
			FROM ". ALBUM_TABLE .", ". ALBUM_CAT_TABLE . "
			WHERE pic_cat_id = cat_id
				AND cat_user_id = ". $user_id;
	//--- Album Category Heirarchy : end

	if( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not count pics for this personal gallery', '', __LINE__, __FILE__, $sql);
	}

	$row = $db->sql_fetchrow($result);

	$count = $row['count'];

	if ($user_id != $userdata['user_id'])
	{
		$sql = "SELECT user_id, username
				FROM ". USERS_TABLE ."
				WHERE user_id = $user_id";

		if( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not obtain user information', '', __LINE__, __FILE__, $sql);
		}

		$user_row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if (!$user_row)
		{
			message_die(GENERAL_MESSAGE, $lang['No_such_user']);
		}
		$username = $user_row['username'];
	}
	else
	{
		$username = $userdata['username'];
	}

	$thiscat = array(
		'cat_id' => 0,
		'cat_title' => sprintf($lang['Personal_Gallery_Of_User'], $username),
		'cat_desc' => '',
		'cat_order' => 0,
		'count' => $count,
		//--- Album Category Heirarchy : begin
		//--- version : 1.1.0beta6
		'personal' => 1,
		'cat_user_id' => $user_id,
		//--- Album Category Heirarchy : end
		'cat_view_level' => $album_config['personal_gallery_view'],
		'cat_upload_level' => $album_config['personal_gallery'],
		'cat_rate_level' => $album_config['personal_gallery_view'],
		'cat_comment_level' => $album_config['personal_gallery_view'],
		'cat_edit_level' => $album_config['personal_gallery'],
		'cat_delete_level' => $album_config['personal_gallery'],
		'cat_view_groups' => $album_config['personal_gallery_private'],
		'cat_upload_groups' => $album_config['personal_gallery_private'],
		'cat_rate_groups' => $album_config['personal_gallery_private'],
		'cat_comment_groups' => $album_config['personal_gallery_private'],
		'cat_edit_groups' => $album_config['personal_gallery_private'],
		'cat_delete_groups' => $album_config['personal_gallery_private'],
		'cat_delete_groups' => $album_config['personal_gallery_private'],
		'cat_moderator_groups' => '',
		'cat_approval' => 0
	);

	return $thiscat;
}
//
// END function init_personal_gallery_cat()
// ----------------------------------------------------------------------------


// Shared, best-effort cache I/O. It never changes picture or user metadata.
// Fit inside both configured edges without enlarging the original. Refuse bad
// settings before passing dimensions to GD (PHP 8 raises errors for bad sizes).
function album_thumbnail_dimensions($width, $height, $max_width, $max_height, $orientation = 1)
{
	foreach (array($width, $height, $max_width, $max_height) as $value)
	{
		if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]{1,5}$/D', (string) $value) || (int) $value < 1 || (int) $value > 10000) { return false; }
	}
	$width = (int) $width; $height = (int) $height;
	if (!is_int($orientation) || $orientation < 1 || $orientation > 8) { return false; }
	if ($orientation >= 5) { $swap = $width; $width = $height; $height = $swap; }
	$max_width = (int) $max_width; $max_height = (int) $max_height;
	if (!phpbb_image_dimensions_safe($width, $height)) { return false; }
	$scale = min(1, $max_width / $width, $max_height / $height);
	return array(max(1, min($max_width, $width, (int) round($width * $scale))), max(1, min($max_height, $height, (int) round($height * $scale))));
}

function album_cache_directory($directory)
{
	if (!is_string($directory) || !in_array($directory, array(ALBUM_CACHE_PATH, ALBUM_MED_CACHE_PATH), true)) { return false; }
	$path = rtrim($directory, '/\\');
	if ($path === '' || is_link($path) || !is_dir($path) || ($base = realpath($path)) === false || dirname($base) === $base) { return false; }
	return $base;
}

function album_cache_filename($filename, $type)
{
	return is_string($filename) && is_string($type) && in_array($type, array('.jpg', '.png'), true) && strlen($filename) <= 255 &&
		$filename !== '' && trim($filename, ' .') === $filename && strpos($filename, '/') === false && strpos($filename, chr(92)) === false &&
		strpos($filename, ':') === false && preg_match('/[\x00-\x1f\x7f]/', $filename) === 0 && preg_match('//u', $filename) === 1 &&
		!preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $filename) && strtolower(substr($filename, -4)) === $type;
}

// Read a bounded header/trailer, not an entire potentially large cached image.
// Writers below publish complete same-directory files atomically. This also
// rejects zero/truncated cache entries left by an older interrupted GD write.
function album_cache_image_complete($path, $type)
{
	if (!is_string($path) || !in_array($type, array('.jpg', '.png'), true) || is_link($path) || !is_file($path)) { return false; }
	$handle = @fopen($path, 'rb'); if (!$handle) { return false; }
	try
	{
		$before = fstat($handle); $tail_length = $type === '.jpg' ? 2 : 12;
		if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000 || $before['size'] < 16) { return false; }
		$head = fread($handle, 8); if (fseek($handle, -$tail_length, SEEK_END) !== 0) { return false; }
		$tail = fread($handle, $tail_length); $after = fstat($handle);
		if (!is_array($after) || $after['size'] !== $before['size'] || $after['mtime'] !== $before['mtime']) { return false; }
		if ($type === '.jpg' ? (substr($head, 0, 2) !== "\xff\xd8" || $tail !== "\xff\xd9") :
			($head !== "\x89PNG\r\n\x1a\n" || $tail !== "\x00\x00\x00\x00IEND\xae\x42\x60\x82")) { return false; }
		$info = @getimagesize($path);
		return is_array($info) && isset($info[0], $info[1], $info[2]) && $info[2] === ($type === '.jpg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG) &&
			phpbb_image_dimensions_safe($info[0], $info[1]);
	}
	finally { fclose($handle); }
}

// PNG cache metadata stays in bounded header chunks, never decoded pixels.
// Version automatic alpha previews so older black-composited caches renew once.
function album_png_read_info($read, $length)
{
	$info = array('alpha' => false, 'cache_version' => false);
	if (!is_callable($read) || !is_int($length) || $length < 33 || $length > 67108864 || call_user_func($read, 0, 8) !== "\x89PNG\r\n\x1a\n") { return $info; }
	$at = 8; $color = null;
	for ($chunks = 0; $chunks < 4096 && $at + 12 <= $length; $chunks++) {
		$head = call_user_func($read, $at, 8);
		if (!is_string($head) || strlen($head) !== 8) { break; }
		$size = unpack('Nvalue', substr($head, 0, 4)); $size = $size['value']; $kind = substr($head, 4, 4);
		if ($size < 0 || $size > $length - $at - 12) { break; }
		if ($chunks === 0) {
			if ($kind !== 'IHDR' || $size !== 13) { break; }
			$data = call_user_func($read, $at + 8, 13); if (!is_string($data) || strlen($data) !== 13) { break; }
			$color = ord($data[9]); if (!in_array($color, array(0,2,3,4,6), true)) { break; } $info['alpha'] = $color === 4 || $color === 6;
		} elseif ($kind === 'tRNS' && (($color === 0 && $size === 2) || ($color === 2 && $size === 6) || ($color === 3 && $size > 0 && $size <= 256))) {
			$data = call_user_func($read, $at + 8, (int) $size); if (!is_string($data) || strlen($data) !== $size) { break; }
			$info['alpha'] = $color !== 3 || strspn($data, "\xff") !== $size;
		} elseif ($kind === 'tEXt' && $size === 23) {
			$info['cache_version'] = $info['cache_version'] || call_user_func($read, $at + 8, 23) === "phpBB2PlusThumb\0alpha-1";
		}
		if ($kind === 'IDAT' || $kind === 'IEND') { break; }
		$at += 12 + $size;
	}
	return $info;
}

function album_png_data_info($data)
{
	return is_string($data) ? album_png_read_info(function($at, $size) use ($data) { return substr($data, $at, $size); }, strlen($data)) : array('alpha' => false, 'cache_version' => false);
}

function album_png_file_info($path)
{
	if (!is_string($path) || strpos($path, '://') !== false || is_link($path) || !is_file($path) || !($stream = @fopen($path, 'rb'))) { return false; }
	try {
		$before = fstat($stream); if (!$before || ($before['mode'] & 0170000) !== 0100000 || !is_int($before['size']) || $before['size'] > 67108864) { return false; }
		$info = album_png_read_info(function($at, $size) use ($stream) { return @fseek($stream, $at) === 0 ? ($size === 0 ? '' : @fread($stream, $size)) : false; }, $before['size']);
		$after = fstat($stream); foreach (array('dev','ino','size','mtime','ctime') as $key) { if (!isset($after[$key]) || $before[$key] !== $after[$key]) { return false; } } return $info;
	} finally { fclose($stream); }
}

function album_png_prepare($image, $clear = false)
{
	if (!is_bool($clear) || !((is_resource($image) && get_resource_type($image) === 'gd') || (is_object($image) && is_a($image, 'GdImage'))) || !function_exists('imagealphablending') || !function_exists('imagesavealpha') || !@imagealphablending($image, false) || !@imagesavealpha($image, true)) { return false; }
	if ($clear) { $transparent = @imagecolorallocatealpha($image, 0, 0, 0, 127); return $transparent !== false && @imagefill($image, 0, 0, $transparent); }
	$source_key = imagecolortransparent($image);
	if (!imageistruecolor($image) || $source_key < 0 || (($source_key >> 24) & 127) === 127) { return true; }
	// Truecolor PNG color keys are ignored by some GD resamplers. Expand the
	// key to real alpha one row at a time, with no second full-size allocation.
	if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopy')) { return false; }
	$width = imagesx($image); $height = imagesy($image); $row = @imagecreatetruecolor($width, 1); if (!$row) { return false; }
	try {
		if (!album_png_prepare($row, true)) { return false; } $transparent = imagecolorallocatealpha($row, 0, 0, 0, 127);
		for ($y = 0; $y < $height; $y++) { if (!@imagefill($row, 0, 0, $transparent) || !@imagecopy($row, $image, 0, 0, 0, $y, $width, 1) || !@imagecopy($image, $row, 0, $y, 0, 0, $width, 1)) { return false; } }
		// PHP's negative color argument queries rather than clears this flag.
		// Keep it on an already fully transparent alpha pixel instead.
		return imagecolortransparent($image, 0x7f000000) === 0x7f000000;
	} finally { phpbb_gd_release($row); }
}

function album_png_cache_chunk()
{
	$data = "tEXtphpBB2PlusThumb\0alpha-1";
	return pack('N', strlen($data) - 4) . $data . hash('crc32b', $data, true);
}

function album_png_mark_cache_data($data)
{
	if (!is_string($data) || strlen($data) < 33 || substr($data, 0, 16) !== "\x89PNG\r\n\x1a\n\0\0\0\x0dIHDR") { return false; }
	return substr($data, 0, 33) . album_png_cache_chunk() . substr($data, 33);
}

function album_png_mark_cache_file($path)
{
	if (!is_string($path) || strpos($path, '://') !== false || !preg_match('/^\.album-cache-[a-f0-9]{32}\.tmp$/D', basename(str_replace('\\', '/', $path))) || is_link($path) || !is_file($path)) { return false; }
	$stream = @fopen($path, 'r+b'); if (!$stream) { return false; }
	try {
		$before = fstat($stream);
		if (!$before || ($before['mode'] & 0170000) !== 0100000 || $before['size'] < 33 || $before['size'] > 67108864) { return false; }
		if (album_png_mark_cache_data(fread($stream, 33)) === false) { return false; }
		$chunk = album_png_cache_chunk(); $extra = strlen($chunk); $end = $before['size'];
		// Shift backwards inside the caller's unpublished stage. A failed or
		// killed write never touches the old cache or creates another orphan.
		if (!@ftruncate($stream, $end + $extra)) { return false; }
		while ($end > 33) {
			$size = min(65536, $end - 33); $at = $end - $size;
			if (@fseek($stream, $at) !== 0 || !is_string($bytes = @fread($stream, $size)) || strlen($bytes) !== $size || @fseek($stream, $at + $extra) !== 0 || @fwrite($stream, $bytes) !== $size) { return false; } $end = $at;
		}
		return @fseek($stream, 33) === 0 && @fwrite($stream, $chunk) === $extra && @fflush($stream);
	} finally { fclose($stream); }
}

function album_cached_image_valid($directory, $filename, $type, $max_width = null, $max_height = null, $orientation = 1, $png_alpha = false)
{
	$base = album_cache_directory($directory);
	if ($base === false || !album_cache_filename($filename, $type) || !album_cache_image_complete($base . DIRECTORY_SEPARATOR . $filename, $type)) { return false; }
	if (func_num_args() === 3) { return true; }
	if (!is_int($orientation) || $orientation < 1 || $orientation > 8) { return false; }
	if (!is_bool($png_alpha) || ($type !== '.png' && $png_alpha)) { return false; }
	if ($png_alpha) { $metadata = album_png_file_info($base . DIRECTORY_SEPARATOR . $filename); if ($metadata === false || !$metadata['cache_version']) { return false; } }
	if ($type === '.jpg' && $orientation !== 1) {
		$metadata = phpbb_jpeg_file_info($base . DIRECTORY_SEPARATOR . $filename);
		if ($metadata === false || $metadata['cache_orientation'] !== $orientation) { return false; }
	}
	$info = @getimagesize($base . DIRECTORY_SEPARATOR . $filename);
	if ($info === false) { return false; }
	$dimensions = album_thumbnail_dimensions($info[0], $info[1], $max_width, $max_height);
	return $dimensions !== false && $dimensions[0] === $info[0] && $dimensions[1] === $info[1];
}

// Picture renderers must supply their current owned publication capability.
function album_store_cached_image($image, $directory, $filename, $type, $quality = 80, $publication_guard = null, $orientation = 1, $png_alpha = false)
{
	if (($publication_guard !== null && !is_callable($publication_guard)) || !is_int($orientation) || $orientation < 1 || $orientation > 8 || ($type !== '.jpg' && $orientation !== 1) || !is_bool($png_alpha) || ($type !== '.png' && $png_alpha)) { return false; }
	$base = album_cache_directory($directory);
	if ($base === false || !album_cache_filename($filename, $type) || !is_int($quality) || $quality < 0 || $quality > 100 ||
		!function_exists($type === '.jpg' ? 'imagejpeg' : 'imagepng') ||
		!((is_resource($image) && get_resource_type($image) === 'gd') || (is_object($image) && is_a($image, 'GdImage')))) { return false; }
	$target = $base . DIRECTORY_SEPARATOR . $filename; $temporary = null;
	if (is_link($target) || is_dir($target)) { return false; }
	try
	{
		if ($publication_guard !== null && call_user_func($publication_guard) !== true) { return false; }
		for ($attempt = 0; $attempt < 4; $attempt++)
		{
			$path = $base . DIRECTORY_SEPARATOR . '.album-cache-' . bin2hex(phpbb_random_bytes(16)) . '.tmp';
			$handle = @fopen($path, 'x+b'); if (!$handle) { continue; }
			$temporary = $path;
			try { if (!@chmod($path, 0600)) { return false; } }
			finally { fclose($handle); }
			break;
		}
		if ($temporary === null || album_cache_directory($directory) !== $base || is_link($temporary)) { return false; }
		$written = $type === '.jpg' ? @imagejpeg($image, $temporary, $quality) : @imagepng($image, $temporary);
		if ($written && $type === '.jpg' && $orientation !== 1) { $written = phpbb_jpeg_mark_cache_file($temporary, $orientation); }
		if ($written && $png_alpha) { $written = album_png_mark_cache_file($temporary); }
		if (!$written || !album_cache_image_complete($temporary, $type) || album_cache_directory($directory) !== $base || is_link($target) || is_dir($target)) { return false; }
		if ($publication_guard !== null && call_user_func($publication_guard) !== true) { return false; }
		if (!@rename($temporary, $target)) { return false; }
		$temporary = null; return true;
	}
	catch (Exception $error) { return false; }
	catch (Error $error) { return false; }
	finally
	{
		// Never remove a prior/peer target if encoding or publication failed.
		if ($temporary !== null && (is_file($temporary) || is_link($temporary))) { @unlink($temporary); }
	}
}

// ----------------------------------------------------------------------------
// You must keep my copyright notice with its original content visible
// Do NOT modify anything!!!
function album_end()
{
	global $album_config;

	echo '<div align="center" style="font-family: Verdana; font-size: 10px; letter-spacing: -1px">Powered by Photo Album Addon 2' . $album_config['album_version'] . ' &copy; 2002, 2003 <a href="http://smartor.is-root.com" target="_blank" rel="noopener noreferrer">Smartor</a></div>';
}
//
// OR you can pay me for the copyright notice removal. Contact me!
// ----------------------------------------------------------------------------



// +------------------------------------------------------+
// |  Powered by Photo Album 2.x.x (c) 2002-2003 Smartor  |
// +------------------------------------------------------+


?>
