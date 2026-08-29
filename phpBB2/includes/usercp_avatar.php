<?php
/***************************************************************************
 *                             usercp_avatar.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: usercp_avatar.php,v 1.8.2.17 2003/03/04 21:02:36 acydburn Exp $
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
 *
 ***************************************************************************/

function check_image_type(&$type, &$error, &$error_msg)
{
	global $lang;

	switch( $type )
	{
		case 'jpeg':
		case 'pjpeg':
		case 'jpg':
			return '.jpg';
			break;
		case 'gif':
			return '.gif';
			break;
		case 'png':
			return '.png';
			break;
		default:
			$error = true;
			$error_msg = (!empty($error_msg)) ? $error_msg . '<br />' . $lang['Avatar_filetype'] : $lang['Avatar_filetype'];
			break;
	}

	return false;
}

function user_avatar_storage_directory()
{
	global $board_config, $phpbb_root_path;

	$root_path = isset($phpbb_root_path) ? $phpbb_root_path : './';
	$board_root = @realpath($root_path);
	$avatar_dir = @realpath($root_path . $board_config['avatar_path']);
	$normalized_root = $board_root ? rtrim(str_replace('\\', '/', $board_root), '/') . '/' : '';
	$normalized_dir = $avatar_dir ? rtrim(str_replace('\\', '/', $avatar_dir), '/') . '/' : '';

	return ($normalized_root !== '' && $normalized_dir !== '' && strpos($normalized_dir, $normalized_root) === 0) ? $avatar_dir : false;
}

function user_avatar_delete($avatar_type, $avatar_file)
{
	global $userdata;
	$avatar_file = basename($avatar_file);
	$avatar_dir = user_avatar_storage_directory();
	
	if ( $avatar_type == USER_AVATAR_UPLOAD && $avatar_file != '' && $avatar_dir !== false )
	{
		$stored_avatar = $avatar_dir . DIRECTORY_SEPARATOR . $avatar_file;
		if ( @is_file($stored_avatar) )
		{
			@unlink($stored_avatar);
		}
	}

	return ", user_avatar = '', user_avatar_type = " . USER_AVATAR_NONE;
}

function user_avatar_gallery($mode, &$error, &$error_msg, $avatar_filename, $avatar_category)
{
	global $board_config;

	$avatar_filename = phpbb_ltrim(basename($avatar_filename), "'");
	$avatar_category = phpbb_ltrim(basename($avatar_category), "'");
	
	if(!preg_match('/(\.gif$|\.png$|\.jpg|\.jpeg)$/is', $avatar_filename))
	{
		return '';
	}

	if ($avatar_filename == "" || $avatar_category == "")
	{
		return '';
	} 

	if ( file_exists(@phpbb_realpath($board_config['avatar_gallery_path'] . '/' . $avatar_category . '/' . $avatar_filename)) && ($mode == 'editprofile') )
	{
		$return = ", user_avatar = '" . str_replace("\'", "''", $avatar_category . '/' . $avatar_filename) . "', user_avatar_type = " . USER_AVATAR_GALLERY;
	}
	else
	{
		$return = '';
	}
	return $return;
}

function user_avatar_url($mode, &$error, &$error_msg, $avatar_filename)
{
	global $lang;
	$avatar_filename = html_entity_decode(trim($avatar_filename), ENT_QUOTES, 'UTF-8');
	if ( !preg_match('#^https?://#i', $avatar_filename) )
	{
		$avatar_filename = 'https://' . $avatar_filename;
	}

	$avatar_filename = substr($avatar_filename, 0, 100);
	$url_parts = @parse_url($avatar_filename);

	if ( !$url_parts || empty($url_parts['host']) || empty($url_parts['path']) ||
		!in_array(strtolower($url_parts['scheme']), array('http', 'https'), true) ||
		!preg_match('/\.(jpg|jpeg|gif|png)$/i', $url_parts['path']) ||
		preg_match('/[\x00-\x20\x7f]/', $avatar_filename) )
	{
		$error = true;
		$error_msg = ( !empty($error_msg) ) ? $error_msg . '<br />' . $lang['Wrong_remote_avatar_format'] : $lang['Wrong_remote_avatar_format'];
		return;
	}

	return ( $mode == 'editprofile' ) ? ", user_avatar = '" . str_replace("\'", "''", $avatar_filename) . "', user_avatar_type = " . USER_AVATAR_REMOTE : '';

}

function user_avatar_upload($mode, $avatar_mode, &$current_avatar, &$current_type, &$error, &$error_msg, $avatar_filename, $avatar_realname, $avatar_filesize, $avatar_filetype)
{
	global $board_config, $db, $lang;

	$avatar_sql = '';
	if ($avatar_mode == 'remote')
	{
		$error = true;
		$error_msg = (!empty($error_msg) ? $error_msg . '<br />' : '') . $lang['Remote_avatar_upload_disabled'];
		return;
	}

	$avatar_realname = basename(str_replace('\\', '/', (string) $avatar_realname));
	$avatar_dir = user_avatar_storage_directory();
	if ($avatar_dir === false || !is_uploaded_file($avatar_filename))
	{
		$error = true;
		$error_msg = (!empty($error_msg) ? $error_msg . '<br />' : '') . $lang['Avatar_filetype'];
		return '';
	}

	$actual_filesize = @filesize($avatar_filename);
	if ($actual_filesize === false || $actual_filesize < 1 || $actual_filesize > intval($board_config['avatar_filesize']))
	{
		$l_avatar_size = sprintf($lang['Avatar_filesize'], round($board_config['avatar_filesize'] / 1024));
		$error = true;
		$error_msg = (!empty($error_msg) ? $error_msg . '<br />' : '') . $l_avatar_size;
		return '';
	}

	$image_info = @getimagesize($avatar_filename);
	$allowed_types = array(
		IMAGETYPE_GIF => array('extension' => '.gif', 'names' => array('gif')),
		IMAGETYPE_JPEG => array('extension' => '.jpg', 'names' => array('jpg', 'jpeg')),
		IMAGETYPE_PNG => array('extension' => '.png', 'names' => array('png'))
	);
	$image_type = ($image_info !== false && isset($image_info[2])) ? intval($image_info[2]) : 0;
	$real_extension = strtolower(pathinfo($avatar_realname, PATHINFO_EXTENSION));
	if (!isset($allowed_types[$image_type]) || !in_array($real_extension, $allowed_types[$image_type]['names'], true))
	{
		$error = true;
		$error_msg = (!empty($error_msg) ? $error_msg . '<br />' : '') . $lang['Avatar_filetype'];
		return '';
	}
	$imgtype = $allowed_types[$image_type]['extension'];
	$width = intval($image_info[0]);
	$height = intval($image_info[1]);

	if ( $width > 0 && $height > 0 && $width <= $board_config['avatar_max_width'] && $height <= $board_config['avatar_max_height'] )
	{
		$new_filename = md5(dss_rand() . dss_rand()) . $imgtype;

		$destination = $avatar_dir . DIRECTORY_SEPARATOR . $new_filename;
		if (!@move_uploaded_file($avatar_filename, $destination))
		{
			message_die(GENERAL_ERROR, 'Unable to upload file', '', __LINE__, __FILE__);
		}

		@chmod($destination, 0664);
		if ( $mode == 'editprofile' && $current_type == USER_AVATAR_UPLOAD && $current_avatar != '' )
		{
			user_avatar_delete($current_type, $current_avatar);
		}

		$avatar_sql = ( $mode == 'editprofile' ) ? ", user_avatar = '$new_filename', user_avatar_type = " . USER_AVATAR_UPLOAD : "'$new_filename', " . USER_AVATAR_UPLOAD;
	}
	else
	{
		$l_avatar_size = sprintf($lang['Avatar_imagesize'], $board_config['avatar_max_width'], $board_config['avatar_max_height']);

		$error = true;
		$error_msg = ( !empty($error_msg) ) ? $error_msg . '<br />' . $l_avatar_size : $l_avatar_size;
	}

	return $avatar_sql;
}

function display_avatar_gallery($mode, &$category, &$user_id, &$email, &$current_email, &$coppa, &$username, &$new_password, &$cur_password, &$password_confirm, &$icq, &$aim, &$msn, &$yim, &$fb, &$ig, &$pt, &$twr, &$skp, &$tg, &$li, &$tt, &$dc, &$website, &$location, &$user_flag, &$occupation, &$interests, &$signature, &$viewemail, &$notifypm, &$games_block_pm, &$popup_pm, &$notifyreply, &$attachsig, &$setbm, &$allowhtml, &$allowbbcode, &$allowsmilies, &$hideonline, &$style, &$language, &$timezone, &$dateformat, &$user_absence_mode, &$user_absence, &$user_absence_text, &$session_id, &$birthday, &$gender)
{
	global $board_config, $db, $template, $lang, $images, $theme;
	global $phpbb_root_path, $phpEx;
	global $HTTP_POST_VARS;

	$dir = @opendir($board_config['avatar_gallery_path']);

	$avatar_images = array();
	while( $file = @readdir($dir) )
	{
		if( $file != '.' && $file != '..' && !is_file($board_config['avatar_gallery_path'] . '/' . $file) && !is_link($board_config['avatar_gallery_path'] . '/' . $file) )
		{
			$sub_dir = @opendir($board_config['avatar_gallery_path'] . '/' . $file);

			$avatar_row_count = 0;
			$avatar_col_count = 0;
			while( $sub_file = @readdir($sub_dir) )
			{
				if( preg_match('/(\.gif$|\.png$|\.jpg|\.jpeg)$/is', $sub_file) )
				{
					$avatar_images[$file][$avatar_row_count][$avatar_col_count] = $sub_file; 
					$avatar_name[$file][$avatar_row_count][$avatar_col_count] = ucfirst(str_replace("_", " ", preg_replace('/^(.*)\..*$/', '\1', $sub_file)));

					$avatar_col_count++;
					if( $avatar_col_count == 5 )
					{
						$avatar_row_count++;
						$avatar_col_count = 0;
					}
				}
			}
		}
	}

	@closedir($dir);

	@ksort($avatar_images);
	@reset($avatar_images);

	if( empty($category) )
	{
		list($category, ) = each($avatar_images);
	}
	@reset($avatar_images);

	$s_categories = '<select name="avatarcategory">';
	while( list($key) = each($avatar_images) )
	{
		$selected = ( $key == $category ) ? ' selected="selected"' : '';
		if( count($avatar_images[$key]) )
		{
			$s_categories .= '<option value="' . $key . '"' . $selected . '>' . ucfirst($key) . '</option>';
		}
	}
	$s_categories .= '</select>';

	$s_colspan = 0;
	for($i = 0; $i < count($avatar_images[$category]); $i++)
	{
		$template->assign_block_vars("avatar_row", array());

		$s_colspan = max($s_colspan, count($avatar_images[$category][$i]));

		for($j = 0; $j < count($avatar_images[$category][$i]); $j++)
		{
			$template->assign_block_vars('avatar_row.avatar_column', array(
				"AVATAR_IMAGE" => $board_config['avatar_gallery_path'] . '/' . $category . '/' . $avatar_images[$category][$i][$j], 
				"AVATAR_NAME" => $avatar_name[$category][$i][$j])
			);

			$template->assign_block_vars('avatar_row.avatar_option_column', array(
				"S_OPTIONS_AVATAR" => $avatar_images[$category][$i][$j])
			);
		}
	}

	$params = array('coppa', 'user_id', 'username', 'email', 'current_email', 'cur_password', 'new_password', 'password_confirm', 'icq', 'aim', 'msn', 'yim', 'fb', 'ig', 'pt', 'twr', 'skp', 'tg', 'li', 'tt', 'dc', 'website', 'location', 'user_flag', 'occupation', 'interests', 'signature', 'viewemail', 'notifypm', 'games_block_pm', 'popup_pm', 'notifyreply', 'attachsig', 'setbm', 'allowhtml', 'allowbbcode', 'allowsmilies', 'hideonline', 'style', 'language', 'timezone', 'dateformat', 'user_absence_mode', 'user_absence', 'user_absence_text', 'birthday', 'gender');

	$s_hidden_vars = '<input type="hidden" name="sid" value="' . $session_id . '" /><input type="hidden" name="agreed" value="true" /><input type="hidden" name="avatarcatname" value="' . $category . '" />';

	for($i = 0; $i < count($params); $i++)
	{
		$s_hidden_vars .= '<input type="hidden" name="' . $params[$i] . '" value="' . str_replace('"', '&quot;', $$params[$i]) . '" />';
	}
	//
	// Custom Profile Fields MOD
	//
	$profile_data = get_fields('WHERE users_can_view = '.ALLOW_VIEW);
	foreach($profile_data as $field) {
		$name = text_to_column($field['field_name']);
		$required = ($field['is_required'] == REQUIRED) ? true : false;
		$checkbox_tally = count($HTTP_POST_VARS[$name]);
		if (($field['field_type'] == CHECKBOX) && ($checkbox_tally > 1)) {
			foreach ($HTTP_POST_VARS[$name] as $checkbox_value) {
				$checkbox_value = stripslashes($checkbox_value);
				$s_hidden_vars .= '<input type="hidden" name="' . $name . '[]" value="' . str_replace('"', '&quot;', $checkbox_value) . '" />';
			}
		}
		else {
			$value = $HTTP_POST_VARS[$name];
			$value = stripslashes($value);
			$s_hidden_vars .= "<input type=\"hidden\" name=\"$name\" value=\"" . str_replace('"', '&quot;', $value) . "\" />";
		}
	}
	//
	// END Custom Profile Fields MOD
	//
	
	$template->assign_vars(array(
		'L_AVATAR_GALLERY' => $lang['Avatar_gallery'], 
		'L_SELECT_AVATAR' => $lang['Select_avatar'], 
		'L_RETURN_PROFILE' => $lang['Return_profile'], 
		'L_CATEGORY' => $lang['Select_category'], 

		'S_CATEGORY_SELECT' => $s_categories, 
		'S_COLSPAN' => $s_colspan, 
		'S_PROFILE_ACTION' => append_sid("profile.$phpEx?mode=$mode"), 
		'S_HIDDEN_FIELDS' => $s_hidden_vars)
	);

	return;
}

?>
