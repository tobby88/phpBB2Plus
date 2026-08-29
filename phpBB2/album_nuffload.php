<?php
/***************************************************************************
 *                             album_nuffload.php
 *                            -------------------
 *   Author                : Nuffmon
 *   Email                 : nuffmon@hotmail.com
 *   Version               : 1.4.2
 *   Last Update           : 30/01/2006
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
	die('Hacking attempt');
}

$path_to_bin = album_nuffload_base_path($album_config['path_to_bin']);
if ($path_to_bin === false || !is_dir($phpbb_root_path . $path_to_bin . 'tmp'))
{
	message_die(GENERAL_ERROR, 'The Nuffload temporary path is invalid or unavailable.');
}
album_nuffload_cleanup_temp($phpbb_root_path . $path_to_bin . 'tmp');
$show_progress_bar = $album_config['show_progress_bar'];
$close_on_finish = $album_config['close_on_finish'];
$max_pause = $album_config['max_pause'];
$multiple_uploads = $album_config['multiple_uploads'];
$zip_uploads = $album_config['zip_uploads'];
$resize_pic = $album_config['resize_pic'];
$resize_width = $album_config['resize_width'];
$resize_height = $album_config['resize_height'];
$resize_quality = $album_config['resize_quality'];
if (!$album_config['perl_uploader']) {$show_progress_bar = 0;}

fix_magic_quotes();

$multi_id = 0;
$multi_max = 0;
$multi_tag = '';
$pic_type_error = false;
$thumb_type_error = false;
$file = array('field' => array(), 'name' => array(), 'size' => array(), 'tmp_name' => array());

// This part handles files after the upload and passes variables across
if (isset($_REQUEST['psid']))
{
	if (!is_scalar($_REQUEST['psid']))
	{
		message_die(GENERAL_ERROR, 'Invalid upload session');
	}
	$psid = (string) $_REQUEST['psid'];
	if (!preg_match('/^[a-f0-9]{32}$/i', $psid))
	{
		message_die(GENERAL_ERROR, 'Invalid upload session');
	}
	$owner_file = $phpbb_root_path . $path_to_bin . 'tmp/' . $psid . '_owner';
	$stored_owner = is_file($owner_file) ? trim((string) @file_get_contents($owner_file)) : '';
	$expected_owner = album_nuffload_owner_token($userdata);
	if ($expected_owner === false || !preg_match('/^[a-f0-9]{64}$/i', $stored_owner) || !hash_equals($expected_owner, $stored_owner))
	{
		message_die(GENERAL_ERROR, 'Invalid upload session');
	}

	// Session id for this upload.
	// Check if this a multi upload so we transfer the correct upload file
	if (!empty($_GET['multi_id']) && is_scalar($_GET['multi_id']))
	{
		$multi_id = intval($_GET['multi_id']);
		$multi_tag = '-' . $multi_id;
	}

	// Routine for php uploading, save files to disk.
	// hmmm should probably check full compatibility with this.
	if (!$album_config['perl_uploader'] && !$multi_id)
	{
		$form_data = array_merge($_GET, $_POST);
		$qstr = http_build_query($form_data, '', '&');
		$qstr = ($qstr === '') ? '' : '&' . $qstr;
		$key_names = array_keys($_FILES);
		for($a=0;$a<count($key_names);$a++)
		{
			if (!isset($_FILES[$key_names[$a]]['tmp_name'], $_FILES[$key_names[$a]]['name'], $_FILES[$key_names[$a]]['size'])
				|| !is_uploaded_file($_FILES[$key_names[$a]]['tmp_name']))
			{
				message_die(GENERAL_ERROR, 'Invalid upload data');
			}
			$qstr .= "&file[field][$a]=" . rawurlencode($key_names[$a]);
			$qstr .= "&file[name][$a]=" . rawurlencode($_FILES[$key_names[$a]]['name']);
			$qstr .= "&file[size][$a]=" . intval($_FILES[$key_names[$a]]['size']);
			$qstr .= "&file[tmp_name][$a]=" . rawurlencode("tmp/" . $psid . "_actualdata" . $a);
			// Move this file to upload directory
			// Inefficient but works at the moment
			if (!move_uploaded_file($_FILES[$key_names[$a]]['tmp_name'], $path_to_bin . "tmp/" . $psid . "_actualdata" . $a))
			{
				message_die(GENERAL_ERROR, 'Could not store uploaded data');
			}
		}
		@unlink($path_to_bin . "tmp/" . $psid . "_qstring");
		$handle = @fopen($path_to_bin . "tmp/" . $psid . "_qstring", 'w');
		if ($handle === false)
		{
			message_die(GENERAL_ERROR, 'Could not initialize upload session');
		}
		fwrite($handle, $qstr);
		fclose($handle);
	}
	
	// Create variables from query string file.
	$qstr = @join("",@file($path_to_bin . "tmp/" . $psid . "_qstring"));
	$parsed_upload_data = array();
	parse_str(ltrim($qstr, '&'), $parsed_upload_data);
	if (isset($parsed_upload_data['file']) && is_array($parsed_upload_data['file']))
	{
		$file = $parsed_upload_data['file'];
	}
	foreach ($parsed_upload_data as $field_name => $field_value)
	{
		if ($field_name !== 'file')
		{
			if (!is_scalar($field_value))
			{
				message_die(GENERAL_ERROR, 'Invalid upload data');
			}
			$field_value = phpbb_addslashes_recursive($field_value);
			$_GET[$field_name] = $field_value;
			$_POST[$field_name] = $field_value;
		}
	}
	//print "Query string = " . $qstr . "<br />";


	// Needed for album hierarchy mod
	$album_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

	// Find the total number of file inputs from the form
	$multi_max = 0;
	$required_file_fields = array('field', 'name', 'size', 'tmp_name');
	foreach ($required_file_fields as $required_file_field)
	{
		if (!isset($file[$required_file_field]) || !is_array($file[$required_file_field]))
		{
			message_die(GENERAL_ERROR, 'Invalid upload data');
		}
	}
	$k = count($file['name']);
	for($i=0 ; $i < $k ; $i++)
	{
		if (!isset($file['field'][$i], $file['name'][$i], $file['size'][$i], $file['tmp_name'][$i])
			|| !is_scalar($file['field'][$i]) || !is_scalar($file['name'][$i])
			|| !is_scalar($file['size'][$i]) || !is_scalar($file['tmp_name'][$i])
			|| !preg_match('/^pic_(?:file|thumbnail)(?:-[0-9]+)?$/', (string) $file['field'][$i])
			|| !preg_match('#^tmp/' . preg_quote($psid, '#') . '_actualdata[0-9]+$#i', (string) $file['tmp_name'][$i]))
		{
			message_die(GENERAL_ERROR, 'Invalid upload data');
		}
		$multi_array = explode("-",$file['field'][$i]);
		$field_index = isset($multi_array[1]) ? intval($multi_array[1]) : 0;
		if ($field_index > $multi_max)
		{
			$multi_max = $field_index;
		}
	}
	//print "File inputs = " . $multi_max . "<br />";
	//exit;
	
	// Extract archives and save in variable list
	if($zip_uploads && !$multi_id)
	{
		require_once('album_pclzip_lib.' . $phpEx);
		$pfm = $multi_max;
		$ptm = $multi_max;
		$archive_input_count = $k;
		for($i=0 ; $i < $archive_input_count ; $i++)
		{
			if (!preg_match('/\.zip$/iD', (string) $file['name'][$i]))
			{
				continue;
			}
			$archive = new PclZip($path_to_bin . $file['tmp_name'][$i]);
			$archive_info = $archive->listContent();
			$archive_files = 0;
			$archive_size = 0;
			$archive_limit = max(1, min(50, intval($album_config['max_uploads'])));
			$archive_size_limit = min(104857600, max(1, intval($album_config['max_file_size'])) * $archive_limit);
			$archive_names = array();
			$list = false;
			if (is_array($archive_info))
			{
				foreach ($archive_info as $archive_entry)
				{
					if (empty($archive_entry['folder']))
					{
						$stored_name = str_replace('\\', '/', (string) $archive_entry['stored_filename']);
						$stored_name = basename($stored_name);
						$name_key = strtolower($stored_name);
						if ($stored_name === '' || !preg_match('/\.(?:gif|jpe?g|png)$/iD', $stored_name) || isset($archive_names[$name_key]))
						{
							message_die(GENERAL_ERROR, $lang['Not_allowed_file_type']);
						}
						$archive_names[$name_key] = $stored_name;
						$archive_files++;
						$archive_size += max(0, intval($archive_entry['size']));
					}
				}
				if ($archive_files < 1 || $archive_files > $archive_limit || $archive_size > $archive_size_limit)
				{
					message_die(GENERAL_ERROR, $lang['Upload_archive_too_large']);
				}
				$extract_dir = $path_to_bin . 'tmp/' . $psid . '_zip_' . $i;
				if (is_dir($extract_dir) || !@mkdir($extract_dir, 0700))
				{
					message_die(GENERAL_ERROR, 'Could not initialize archive extraction.');
				}
				$list = $archive->extract(PCLZIP_OPT_PATH, $extract_dir,
													PCLZIP_OPT_REMOVE_ALL_PATH);
			}
			if (is_array($list))
			{
				$extracted_files = array();
				$actual_archive_size = 0;
				foreach ($list as $extracted_entry)
				{
					if (!empty($extracted_entry['folder']))
					{
						continue;
					}
					$extracted_name = basename(str_replace('\\', '/', (string) $extracted_entry['filename']));
					$name_key = strtolower($extracted_name);
					$extracted_path = $extract_dir . '/' . $extracted_name;
					if (!isset($archive_names[$name_key]) || !is_file($extracted_path))
					{
						foreach ($archive_names as $cleanup_name)
						{
							@unlink($extract_dir . '/' . $cleanup_name);
						}
						@rmdir($extract_dir);
						message_die(GENERAL_ERROR, 'Invalid archive contents.');
					}
					$actual_archive_size += max(0, intval(@filesize($extracted_path)));
					$extracted_files[] = array('path' => $extracted_path, 'name' => $archive_names[$name_key]);
				}
				if (count($extracted_files) !== $archive_files || $actual_archive_size > $archive_size_limit)
				{
					foreach ($archive_names as $cleanup_name)
					{
						@unlink($extract_dir . '/' . $cleanup_name);
					}
					@rmdir($extract_dir);
					message_die(GENERAL_ERROR, $lang['Upload_archive_too_large']);
				}

				$original_filename = $file['tmp_name'][$i];
				$field_name = explode("-",$file['field'][$i]);
				$moved_destinations = array();
				foreach ($extracted_files as $j => $extracted_file)
				{
					$destination = $path_to_bin . $original_filename . $j;
					if (!@rename($extracted_file['path'], $destination))
					{
						foreach ($extracted_files as $cleanup_file)
						{
							@unlink($cleanup_file['path']);
						}
						foreach ($moved_destinations as $cleanup_file)
						{
							@unlink($cleanup_file);
						}
						@rmdir($extract_dir);
						message_die(GENERAL_ERROR, 'Could not store extracted upload data.');
					}
					$moved_destinations[] = $destination;
					$target_index = ($j === 0) ? $i : $k++;
					$file['size'][$target_index] = max(0, intval(@filesize($destination)));
					$file['name'][$target_index] = $extracted_file['name'];
					$file['tmp_name'][$target_index] = $original_filename . $j;
					if ($j > 0)
					{
						if($field_name[0]=="pic_file")
						{
							$pfm++;
							$file['field'][$target_index] = $field_name[0] . "-" . $pfm;
						}
						if($field_name[0]=="pic_thumbnail")
						{
							$ptm++;
							$file['field'][$target_index] = $field_name[0] . "-" . $ptm;
						}
					}
				}
				@rmdir($extract_dir);
				@unlink($path_to_bin . $original_filename);
				$file['tmp_name'][$i] = $original_filename . "0";
			}
			else
			{
				if (isset($extract_dir) && is_dir($extract_dir))
				{
					foreach ($archive_names as $cleanup_name)
					{
						@unlink($extract_dir . '/' . $cleanup_name);
					}
					@rmdir($extract_dir);
				}
				message_die(GENERAL_ERROR, 'Could not extract uploaded archive.');
			}
		}
		// Rebuild the hand-off data with proper encoding after ZIP expansion.
		$parsed_upload_data['file'] = $file;
		$qstr = '&' . http_build_query($parsed_upload_data, '', '&');
		$multi_max = ($pfm >= $ptm) ? $pfm : $ptm;
		unlink($path_to_bin . "tmp/" . $psid . "_qstring");
		$handle = @fopen($path_to_bin . "tmp/" . $psid . "_qstring", 'w');
		if ($handle === false)
		{
			message_die(GENERAL_ERROR, 'Could not update upload session');
		}
		fwrite($handle, $qstr);
		fclose($handle);
	}

	// Loop through array to find pic and thumbnail to insert.
	for($i=0 ; $i < $k ; $i++)
	{
		// Check for correct thumbnail and transfer variables
		if ($file['field'][$i] == 'pic_thumbnail' . $multi_tag)
		{
			$thumb_type_error = false;
			$HTTP_POST_FILES['pic_thumbnail']['tmp_name'] = $path_to_bin . $file['tmp_name'][$i];
			/*
			$split_name = explode("\\",$file['name'][$i]);
			$file_name = $split_name[count($split_name)-1];
			*/
			$file_name = addslashes(stripslashes(basename($file['name'][$i])));
			$HTTP_POST_FILES['pic_thumbnail']['name'] = $file_name;
			$HTTP_POST_FILES['pic_thumbnail']['size'] = $file['size'][$i];
			// Find image type and check if allowed
			$image_data = @getimagesize($path_to_bin . $file['tmp_name'][$i]);
			$image_type = ($image_data !== false && isset($image_data[2])) ? intval($image_data[2]) : 0;
			switch ($image_type)
			{
				case '1':
					if (!$album_config['gif_allowed'])
					{
						$thumb_type_error = true;
					}
					$HTTP_POST_FILES['pic_thumbnail']['type'] = 'image/gif';
					break;
				case '2':
					if (!$album_config['jpg_allowed'])
					{
						$thumb_type_error = true;
					}
					$HTTP_POST_FILES['pic_thumbnail']['type'] = 'image/jpeg';
					break;
				case '3':
					if (!$album_config['png_allowed'])
					{
						$thumb_type_error = true;
					}
					$HTTP_POST_FILES['pic_thumbnail']['type'] = 'image/png';
					break;
				default:
					$thumb_type_error = true;
			}
		}
		// Check for correct picture and transfer variables
		elseif ($file['field'][$i] == 'pic_file' . $multi_tag)
		{
			$pic_type_error = false;
			$HTTP_POST_FILES['pic_file']['tmp_name'] = $path_to_bin . $file['tmp_name'][$i];
			/*
			$split_name = explode("\\",$file['name'][$i]);
			$file_name = $split_name[count($split_name)-1];
			*/
			$file_name = addslashes(stripslashes(basename($file['name'][$i])));
			$HTTP_POST_FILES['pic_file']['name'] = $file_name;
			$HTTP_POST_FILES['pic_file']['size'] = $file['size'][$i];
			// Find image type and check if allowed
			$image_data = @getimagesize($path_to_bin . $file['tmp_name'][$i]);
			$pic_width = ($image_data !== false && isset($image_data[0])) ? intval($image_data[0]) : 0;
			$pic_height = ($image_data !== false && isset($image_data[1])) ? intval($image_data[1]) : 0;
			$image_type = ($image_data !== false && isset($image_data[2])) ? intval($image_data[2]) : 0;
			switch ($image_type)
			{
				case '1':
					if (!$album_config['gif_allowed'])
					{
						$pic_type_error = true;
					}
					$HTTP_POST_FILES['pic_file']['type'] = 'image/gif';
					break;
				case '2':
					if (!$album_config['jpg_allowed'])
					{
						$pic_type_error = true;
					}
					$HTTP_POST_FILES['pic_file']['type'] = 'image/jpeg';
					break;
				case '3':
					if (!$album_config['png_allowed'])
					{
						$pic_type_error = true;
					}
					$HTTP_POST_FILES['pic_file']['type'] = 'image/png';
					break;
				default:
					$pic_type_error = true;
			}
		}
	}

	if (!isset($HTTP_POST_FILES['pic_file']) || !is_array($HTTP_POST_FILES['pic_file'])
		|| !isset($HTTP_POST_FILES['pic_file']['name'], $HTTP_POST_FILES['pic_file']['size'], $HTTP_POST_FILES['pic_file']['tmp_name'], $HTTP_POST_FILES['pic_file']['type']))
	{
		message_die(GENERAL_ERROR, 'Invalid upload data');
	}
	if ($album_config['gd_version'] == 0 && (!isset($HTTP_POST_FILES['pic_thumbnail']) || !is_array($HTTP_POST_FILES['pic_thumbnail'])
		|| !isset($HTTP_POST_FILES['pic_thumbnail']['name'], $HTTP_POST_FILES['pic_thumbnail']['size'], $HTTP_POST_FILES['pic_thumbnail']['tmp_name'], $HTTP_POST_FILES['pic_thumbnail']['type'])))
	{
		message_die(GENERAL_ERROR, 'Invalid upload data');
	}

	// Build picture title
	if (!isset($_POST['pic_title']) || !is_scalar($_POST['pic_title']) || $_POST['pic_title'] == '')
	{
		$tmp_pic_file_name = explode(".", $HTTP_POST_FILES['pic_file']['name']);
		$_POST['pic_title'] = $tmp_pic_file_name[0];
		unset($tmp_pic_file_name);
	}
	elseif ($multi_max > 0)
	{
		$_POST['pic_title'] = (string) $_POST['pic_title'] . " - " . str_pad(($multi_id + 1), 3, "0", STR_PAD_LEFT);
	}

	// Handle no pic file error.
	if ($HTTP_POST_FILES['pic_file']['size'] == 0)
	{
		message_die(GENERAL_MESSAGE, multi_loop($lang['no_file_received']));
	}

	// Handle no thumbnail file error.
	if ($album_config['gd_version'] == 0 && $HTTP_POST_FILES['pic_thumbnail']['size'] == 0)
	{
		message_die(GENERAL_MESSAGE, multi_loop("no_thumbnail_file_recieved!!"));
	}

	// Handle pic filetype error.
	if ($pic_type_error)
	{
		message_die(GENERAL_MESSAGE, multi_loop($lang['Not_allowed_file_type']));
	}

	// Handle thumbnail filetype errors here...
	if ($thumb_type_error)
	{
		message_die(GENERAL_MESSAGE, multi_loop($lang['Not_allowed_file_type']));
	}

	// Resize image if option selected
	if ($resize_pic && ($pic_width > $album_config['max_width'] or $pic_height > $album_config['max_height']))
	{
		$HTTP_POST_FILES['pic_file']['type'] = resize_image($HTTP_POST_FILES['pic_file']['tmp_name'], $resize_width, $resize_height, $resize_quality);
		$HTTP_POST_FILES['pic_file']['size'] = filesize($HTTP_POST_FILES['pic_file']['tmp_name']);
	}

	// Handle large pic file error.
	if ($HTTP_POST_FILES['pic_file']['size'] > $album_config['max_file_size'])
	{
		message_die(GENERAL_MESSAGE, multi_loop($lang['file_too_big']));
	}

	// Handle large thumbnail file error.
	if ($album_config['gd_version'] == 0 && $HTTP_POST_FILES['pic_thumbnail']['size'] > $album_config['max_file_size'])
	{
		message_die(GENERAL_MESSAGE, multi_loop($lang['thumbnail_too_big']));
	}

	// Handle large resolution pic error.
	$image_data = getimagesize($HTTP_POST_FILES['pic_file']['tmp_name']);
	if ($image_data[0] > $album_config['max_width'] || $image_data[1] > $album_config['max_height'])
	{
		message_die(GENERAL_MESSAGE, multi_loop($lang['image_res_too_high']));
	}

	// Handle large resolution thumbnail error.
	if ($album_config['gd_version'] == 0)
	{
		$image_data = getimagesize($HTTP_POST_FILES['pic_thumbnail']['tmp_name']);
		if ($image_data[0] > $album_config['thumbnail_size'] || $image_data[1] > $album_config['thumbnail_size'])
		{
			message_die(GENERAL_MESSAGE, multi_loop($lang['thumb_res_too_high']));
		}
	}

	// Last pass? delete query string because we don't need it anymore...
	if ($multi_id >= $multi_max)
	{
		@unlink($path_to_bin . "tmp/" . $psid . "_qstring");
	}
	// ...otherwise block the email notification.
	else
	{
		$album_config['email_notification'] = 0;
	}
	
	// If idlevoids multi mod installed convert array.
	if (isset($album_config['max_files_to_upload']))
	{
		$tmp_tmp_name = $HTTP_POST_FILES['pic_file']['tmp_name'];
		$tmp_name = $HTTP_POST_FILES['pic_file']['name'];
		$tmp_size = $HTTP_POST_FILES['pic_file']['size'];
		$tmp_type = $HTTP_POST_FILES['pic_file']['type'];
		$ttmp_tmp_name = $HTTP_POST_FILES['pic_thumbnail']['tmp_name'];
		$ttmp_name = $HTTP_POST_FILES['pic_thumbnail']['name'];
		$ttmp_size = $HTTP_POST_FILES['pic_thumbnail']['size'];
		$ttmp_type = $HTTP_POST_FILES['pic_thumbnail']['type'];
		unset($HTTP_POST_FILES);
		$HTTP_POST_FILES['pic_file']['tmp_name'][0] = $tmp_tmp_name;
		$HTTP_POST_FILES['pic_file']['name'][0] = $tmp_name;
		$HTTP_POST_FILES['pic_file']['size'][0] = $tmp_size;
		$HTTP_POST_FILES['pic_file']['type'][0] = $tmp_type;
		$HTTP_POST_FILES['pic_thumbnail']['tmp_name'][0] = $ttmp_tmp_name;
		$HTTP_POST_FILES['pic_thumbnail']['name'][0] = $ttmp_name;
		$HTTP_POST_FILES['pic_thumbnail']['size'][0] = $ttmp_size;
		$HTTP_POST_FILES['pic_thumbnail']['type'][0] = $ttmp_type;
	}
}
// In an include with no session id we create a new session id
else
{
	$psid = bin2hex(phpbb_random_bytes(16));
	$owner_file = $phpbb_root_path . $path_to_bin . 'tmp/' . $psid . '_owner';
	$owner_token = album_nuffload_owner_token($userdata);
	if ($owner_token === false || @file_put_contents($owner_file, $owner_token, LOCK_EX) === false)
	{
		message_die(GENERAL_ERROR, 'Could not initialize upload session');
	}
	@chmod($owner_file, 0660);
	$cat_id = (isset($_REQUEST['cat_id']) && is_scalar($_REQUEST['cat_id'])) ? intval($_REQUEST['cat_id']) : 0;
	$user_id = (isset($_REQUEST['user_id']) && is_scalar($_REQUEST['user_id'])) ? intval($_REQUEST['user_id']) : 0;
	$album_user_id = $user_id;
	if($album_config['perl_uploader'])
	{
		$uploader = (function_exists('album_append_uid')) ? album_append_uid($path_to_bin . "nuffload.cgi?psid=$psid&cat_id=$cat_id") : $path_to_bin . "nuffload.cgi?psid=$psid&cat_id=$cat_id";
	}
	else
	{
		$uploader = (function_exists('album_append_uid'))? album_append_uid("album_upload.php?psid=$psid&cat_id=$cat_id") : "album_upload.php?psid=$psid&cat_id=$cat_id";
	}
	$uploader = append_sid($uploader);
}

//******************************************************************************
// Function to produce messages for loop
//     usage : multi_loop(message as string, [success message as bool])
//     returns : Modified message as string
//******************************************************************************
function multi_loop($message, $success=false)
{
	global $multi_id, $multi_max, $template, $phpEx, $psid, $lang, $thiscat, $cat_id, $pic_thumbnail, $album_user_id, $path_to_bin;

	if ($multi_id >= $multi_max && preg_match('/^[a-f0-9]{32}$/i', $psid))
	{
		@file_put_contents($path_to_bin . 'tmp/' . $psid . '_complete', (string) time(), LOCK_EX);
	}

	if($success)
	{
		if ($thiscat['cat_approval'] == 0)
		{
			$message = $lang['Album_upload_successful'];
		}
		else
		{
			$message = $lang['Album_upload_need_approval'];
		}
		$message .= "<br /><br /><img src='" . ALBUM_CACHE_PATH . $pic_thumbnail . "'>";
	}
	if ($multi_id < $multi_max)
	{
		$multi_id++;
		$return_page = (function_exists('album_append_uid'))? album_append_uid("album_upload.$phpEx?psid=$psid&multi_id=$multi_id") : "album_upload.$phpEx?psid=$psid&multi_id=$multi_id";
		$template->assign_vars(array(
			'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid($return_page) . '">'
			)
		);
		$message .= "<br /><br /><span class=\"gen\">" . $lang['please_wait'] . "<br />" . str_replace("%multi_id%", $multi_id, str_replace("%multi_max%", $multi_max + 1, $lang['uploaded'])) . "</span><br /><br />";
	}
	else
	{
		$multi_id++;
		$message .= "<br /><br /><span class='gen'>" . str_replace("%multi_id%", $multi_id, str_replace("%multi_max%", $multi_max + 1, $lang['uploaded'])) . "</span><br /><br />";
		if ($cat_id != PERSONAL_GALLERY)
		{
			$return_page = (function_exists('album_append_uid'))? album_append_uid("album_cat.$phpEx?cat_id=$cat_id") : "album_cat.$phpEx?cat_id=$cat_id";
			if ($thiscat['cat_approval'] == 0)
			{
				$template->assign_vars(array(
					'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid($return_page) . '">'
					)
				);
			}

			$message .= "<br /><br />" . sprintf($lang['Click_return_category'], "<a href=\"" . append_sid($return_page) . "\">", "</a>");
		}
		else
		{
			if ($thiscat['cat_approval'] == 0)
			{
				$template->assign_vars(array(
					'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid("album_personal.$phpEx") . '">'
					)
				);
			}
			$message .= "<br /><br />" . sprintf($lang['Click_return_personal_gallery'], "<a href=\"" . append_sid("album_personal.$phpEx") . "\">", "</a>");
		}
		$message .= "<br /><br />" . sprintf($lang['Click_return_album_index'], "<a href=\"" . append_sid("album.$phpEx") . "\">", "</a>");
	}
	return $message;
}

//******************************************************************************
// Function to resize image
//     usage : resize_image(filename as string, width as integer, 
//                          height as integer, quality as integer)
//     Returns : Mime Image type as string or FALSE on error
//******************************************************************************
function resize_image($image_file_name, $resize_width, $resize_height, $resize_quality)
{
	// Check file and read into memory
	$image_data = getimagesize($image_file_name);
	$pic_width = $image_data[0];
	$pic_height = $image_data[1];
	switch ($image_data[2])
	{
		case '1':
			$read_function = 'imagecreatefromgif';
			$type = 'image/gif';
			break;
		case '2':
			$read_function = 'imagecreatefromjpeg';
			$type = 'image/jpeg';
			break;
		case '3':
			$read_function = 'imagecreatefrompng';
			$type = 'image/png';
			break;
		default:
			return false;
	}
	$src = @$read_function($image_file_name);

	// Resize image
	if (!$src)
	{
		return false;
	}
	if (($pic_width / $pic_height) > ($resize_width / $resize_height))
	{
		$resize_height = $resize_width * ($pic_height/$pic_width);
	}
	else
	{
		$resize_width = $resize_height * ($pic_width/$pic_height);
	}
	$resize = (gdVersion() == 1) ? @imagecreate($resize_width, $resize_height) : @imagecreatetruecolor($resize_width, $resize_height);
	$resize_function = (gdVersion() == 1) ? 'imagecopyresized' : 'imagecopyresampled';
	@$resize_function($resize, $src, 0, 0, 0, 0, $resize_width, $resize_height, $pic_width, $pic_height);

	// Write file to disk
	switch ($image_data[2]){
		case '1':
			@unlink($image_file_name);
			// Check gif support and use convert to jpeg if not possible
			if (imagetypes() & IMG_GIF)
			{
				@imagegif($resize, $image_file_name);
				$type = 'image/gif';
			}
			else
			{
				@imagejpeg($resize, $image_file_name, $resize_quality);
				$type = 'image/jpeg';
			}
			break;
		case '2':
			@unlink($image_file_name);
			@imagejpeg($resize, $image_file_name, $resize_quality);
			$type = 'image/jpeg';
			break;
		case '3':
			@unlink($image_file_name);
			@imagepng($resize, $image_file_name);
			$type = 'image/png';
			break;
	}
	@chmod($image_file_name, 0664);
	imagedestroy($src);
	imagedestroy($resize);
	return $type;
}

//******************************************************************************
// Function to find version (1 or 2) of the GD extension.
//   Usage : gdVersion()
//   Returns : version number as integer
//******************************************************************************
function gdVersion($user_ver = 0)
{
	if (! extension_loaded('gd'))
	{
		return;
	}
	static $gd_ver = 0;
	if ($user_ver == 1)
	{
		$gd_ver = 1;
		return 1;
	}
	if ($user_ver !=2 && $gd_ver > 0 )
	{
		return $gd_ver;
	}
	if (function_exists('gd_info'))
	{
		$ver_info = gd_info();
		preg_match('/\d/', $ver_info['GD Version'], $match);
		$gd_ver = $match[0];
		return $match[0];
	}
	if (preg_match('/phpinfo/', ini_get('disable_functions')))
	{
		if ($user_ver == 2)
		{
			$gd_ver = 2;
			return 2;
		}
		else
		{
			$gd_ver = 1;
			return 1;
		}
	}
	ob_start();
	phpinfo(8);
	$info = ob_get_contents();
	ob_end_clean();
	$info = stristr($info, 'gd version');
	preg_match('/\d/', $info, $match);
	$gd_ver = $match[0];
	return $match[0];
}

//******************************************************************************
// Function to emulate magic quotes being turned off
//   Usage : fix_magic_quotes ($var = NULL, $sybase = NULL)
//   Returns : specified var $VAR or converts all superglobals
//******************************************************************************
function fix_magic_quotes ($var = NULL, $sybase = NULL)
{
	// if sybase style quoting isn't specified, use ini setting
	if ( !isset ($sybase) )
	{
		$sybase = ini_get ('magic_quotes_sybase');
	}

	// if no var is specified, fix all affected superglobals
	if ( !isset ($var) )
	{
		// if magic quotes is enabled
		if ( get_magic_quotes_gpc () )
		{
			// workaround because magic_quotes does not change $_SERVER['argv']
			$argv = isset($_SERVER['argv']) ? $_SERVER['argv'] : NULL;

			// fix all affected arrays
			foreach ( array ('_ENV', '_REQUEST', '_GET', '_POST', '_COOKIE', '_SERVER') as $var )
			{
				$GLOBALS[$var] = fix_magic_quotes ($GLOBALS[$var], $sybase);
			}

			$_SERVER['argv'] = $argv;

			// turn off magic quotes, this is so scripts which
			// are sensitive to the setting will work correctly
			ini_set ('magic_quotes_gpc', 0);
		}

		// disable magic_quotes_sybase
		if ( $sybase )
		{
			ini_set ('magic_quotes_sybase', 0);
		}

		// disable magic_quotes_runtime
		set_magic_quotes_runtime (0);
		return TRUE;
	}

	// if var is an array, fix each element
	if ( is_array ($var) )
	{
		foreach ( $var as $key => $val )
		{
			$var[$key] = fix_magic_quotes ($val, $sybase);
		}

		return $var;
	}

	// if var is a string, strip slashes
	if ( is_string ($var) )
	{
		return $sybase ? str_replace ('\'\'', '\'', $var) : stripslashes ($var);
	}

	// otherwise ignore
	return $var;
}
?>
