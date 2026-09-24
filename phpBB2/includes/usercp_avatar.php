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

function user_avatar_gallery_directory()
{
	global $board_config, $phpbb_root_path;

	$root_path = isset($phpbb_root_path) ? $phpbb_root_path : './';
	$board_root = @realpath($root_path);
	$gallery_dir = @realpath($root_path . $board_config['avatar_gallery_path']);
	$normalized_root = $board_root ? rtrim(str_replace('\\', '/', $board_root), '/') . '/' : '';
	$normalized_dir = $gallery_dir ? rtrim(str_replace('\\', '/', $gallery_dir), '/') . '/' : '';

	return ($normalized_root !== '' && $normalized_dir !== '' && strpos($normalized_dir, $normalized_root) === 0) ? $gallery_dir : false;
}

// Public profile validation must not remove bytes before its account write.
// This owns files only, not the profile's database/session transaction.
class PhpbbPublicAvatarScope
{
	var $database;
	var $directory;
	var $old_files = array();
	var $new_files = array();
	var $attempted = false;
	var $confirmed = false;
	var $finished = false;
	function __construct($database)
	{
		$this->database = $database;
		$this->directory = user_avatar_storage_directory();
		register_shutdown_function(array($this, 'release'));
	}
	function remember($file, $new)
	{
		if ($this->finished || !is_string($file) || $file === '' || basename($file) !== $file || strpos($file, '\\') !== false || strpos($file, "\0") !== false) { return; }
		if ($new) { $this->new_files[$file] = $file; } else { $this->old_files[$file] = $file; }
	}
	function write_attempted() { $this->attempted = true; }
	// Only an owning transaction with an acknowledged rollback BEFORE any
	// COMMIT attempt can prove that its new reference was never published.
	function rolled_back()
	{
		if ($this->confirmed || $this->finished) { return; }
		$this->attempted = false; $this->release();
	}
	function saved()
	{
		if (!$this->attempted || $this->finished) { return; }
		$this->confirmed = true;
		$this->release();
	}
	function release()
	{
		if ($this->finished) { return; }
		$this->finished = true;
		// A failed/lost SQL acknowledgement is not evidence of rollback. Keep
		// both files then. Before any write, only this request's new files can
		// be discarded. After success, keep any old file still used by a user.
		$files = $this->confirmed ? $this->old_files : ($this->attempted ? array() : $this->new_files);
		if ($this->directory === false || user_avatar_storage_directory() !== $this->directory) { return; }
		foreach ($files as $file)
		{
			try
			{
				if ($this->confirmed)
				{
					$r = $this->database->sql_query('SELECT COUNT(*) AS avatar_references FROM ' . USERS_TABLE . ' WHERE user_avatar_type=' . USER_AVATAR_UPLOAD . " AND user_avatar='" . $this->database->sql_escape($file) . "'");
					if (!$r) { continue; }
					$row = $this->database->sql_fetchrow($r);
					$this->database->sql_freeresult($r);
					if (!is_array($row) || !isset($row['avatar_references']) || (string)$row['avatar_references'] !== '0') { continue; }
				}
				$path = $this->directory . DIRECTORY_SEPARATOR . $file;
				if (!is_link($path) && is_file($path)) { @unlink($path); }
			}
			catch (Exception $e) {} catch (Error $e) {}
		}
	}
}

function user_avatar_delete($avatar_type, $avatar_file)
{
	global $userdata, $admin_profile_scope, $public_avatar_scope;
	$avatar_file = basename($avatar_file);
	if (isset($admin_profile_scope) && $admin_profile_scope instanceof PhpbbAdminProfileScope && $admin_profile_scope->ready && $avatar_type == USER_AVATAR_UPLOAD && $avatar_file !== '')
	{
		$admin_profile_scope->remember_avatar($avatar_file, false);
		return ", user_avatar = '', user_avatar_type = " . USER_AVATAR_NONE;
	}
	if (isset($public_avatar_scope) && $public_avatar_scope instanceof PhpbbPublicAvatarScope)
	{
		if ($avatar_type == USER_AVATAR_UPLOAD) { $public_avatar_scope->remember($avatar_file, false); }
		return ", user_avatar = '', user_avatar_type = " . USER_AVATAR_NONE;
	}
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
	$avatar_filename = phpbb_ltrim(basename($avatar_filename), "'");
	$avatar_category = phpbb_ltrim(basename($avatar_category), "'");
	$gallery_dir = user_avatar_gallery_directory();
	
	if(!preg_match('/(\.gif$|\.png$|\.jpg|\.jpeg)$/is', $avatar_filename))
	{
		return '';
	}

	if ($avatar_filename == "" || $avatar_category == "")
	{
		return '';
	} 

	if ( $gallery_dir !== false && is_file($gallery_dir . DIRECTORY_SEPARATOR . $avatar_category . DIRECTORY_SEPARATOR . $avatar_filename) && ($mode == 'editprofile') )
	{
		$return = ", user_avatar = '" . str_replace("'", "''", stripslashes($avatar_category . '/' . $avatar_filename)) . "', user_avatar_type = " . USER_AVATAR_GALLERY;
	}
	else
	{
		$return = '';
	}
	return $return;
}

function user_avatar_url($mode, &$error, &$error_msg, $avatar_filename)
{
	global $lang, $db;
	$avatar_filename = html_entity_decode(trim($avatar_filename), ENT_QUOTES, 'UTF-8');
	if ( !preg_match('#^https?://#i', $avatar_filename) )
	{
		$avatar_filename = 'https://' . $avatar_filename;
	}

	$url_parts = @parse_url($avatar_filename);

	if ( strlen($avatar_filename) > 100 || !$url_parts || empty($url_parts['host']) || empty($url_parts['path']) ||
		!in_array(strtolower($url_parts['scheme']), array('http', 'https'), true) ||
		isset($url_parts['user']) || isset($url_parts['pass']) || strpos($avatar_filename, '\\') !== false ||
		!preg_match('/\.(jpg|jpeg|gif|png)$/i', $url_parts['path']) ||
		preg_match('/[\x00-\x20\x7f<>"\'`]/', $avatar_filename) )
	{
		$error = true;
		$error_msg = ( !empty($error_msg) ) ? $error_msg . '<br />' . $lang['Wrong_remote_avatar_format'] : $lang['Wrong_remote_avatar_format'];
		return;
	}

	return ( $mode == 'editprofile' ) ? ", user_avatar = '" . $db->sql_escape($avatar_filename) . "', user_avatar_type = " . USER_AVATAR_REMOTE : '';

}

function user_avatar_upload($mode, $avatar_mode, &$current_avatar, &$current_type, &$error, &$error_msg, $avatar_filename, $avatar_realname, $avatar_filesize, $avatar_filetype)
{
	global $board_config, $db, $lang, $admin_profile_scope, $public_avatar_scope;

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

	if ( phpbb_image_dimensions_safe($width, $height) && $width <= $board_config['avatar_max_width'] && $height <= $board_config['avatar_max_height'] )
	{
		$new_filename = bin2hex(phpbb_random_bytes(16)) . $imgtype;

		$destination = $avatar_dir . DIRECTORY_SEPARATOR . $new_filename;
		if (!@move_uploaded_file($avatar_filename, $destination))
		{
			message_die(GENERAL_ERROR, 'Unable to upload file', '', __LINE__, __FILE__);
		}

		@chmod($destination, 0664);
		if (isset($admin_profile_scope) && $admin_profile_scope instanceof PhpbbAdminProfileScope && $admin_profile_scope->ready) { $admin_profile_scope->remember_avatar($new_filename, true); }
		elseif (isset($public_avatar_scope) && $public_avatar_scope instanceof PhpbbPublicAvatarScope) { $public_avatar_scope->remember($new_filename, true); }
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

	$gallery_dir = user_avatar_gallery_directory();
	if ($gallery_dir === false || !($dir = @opendir($gallery_dir)))
	{
		return;
	}
	$gallery_url = rtrim($phpbb_root_path . $board_config['avatar_gallery_path'], '/\\');

	$avatar_images = array();
	while( $file = @readdir($dir) )
	{
		if( $file != '.' && $file != '..' && !is_file($gallery_dir . '/' . $file) && !is_link($gallery_dir . '/' . $file) )
		{
			$sub_dir = @opendir($gallery_dir . '/' . $file);
			if (!$sub_dir)
			{
				continue;
			}

			$avatar_row_count = 0;
			$avatar_col_count = 0;
			while( ($sub_file = @readdir($sub_dir)) !== false )
			{
				if( preg_match('/\.(?:gif|png|jpe?g)$/iD', $sub_file) && is_file($gallery_dir . '/' . $file . '/' . $sub_file) )
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
			@closedir($sub_dir);
		}
	}

	@closedir($dir);

	@ksort($avatar_images);
	$avatar_categories = array_keys($avatar_images);
	if( empty($category) || !isset($avatar_images[$category]) )
	{
		$category = !empty($avatar_categories) ? $avatar_categories[0] : '';
	}

	$s_categories = '<select name="avatarcategory">';
	foreach ($avatar_images as $key => $category_images)
	{
		$selected = ( $key == $category ) ? ' selected="selected"' : '';
		if( count($category_images) )
		{
			$s_categories .= '<option value="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars(ucfirst($key), ENT_QUOTES, 'UTF-8') . '</option>';
		}
	}
	$s_categories .= '</select>';

	$s_colspan = 0;
	$current_images = isset($avatar_images[$category]) ? $avatar_images[$category] : array();
	for($i = 0; $i < count($current_images); $i++)
	{
		$template->assign_block_vars("avatar_row", array());

		$s_colspan = max($s_colspan, count($current_images[$i]));

		for($j = 0; $j < count($current_images[$i]); $j++)
		{
			$template->assign_block_vars('avatar_row.avatar_column', array(
				"AVATAR_IMAGE" => $gallery_url . '/' . rawurlencode($category) . '/' . rawurlencode($current_images[$i][$j]),
				"AVATAR_NAME" => htmlspecialchars($avatar_name[$category][$i][$j], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
			);

			$template->assign_block_vars('avatar_row.avatar_option_column', array(
				"S_OPTIONS_AVATAR" => htmlspecialchars($current_images[$i][$j], ENT_QUOTES, 'UTF-8'))
			);
		}
	}

	$params = array('coppa', 'user_id', 'username', 'email', 'current_email', 'icq', 'aim', 'msn', 'yim', 'fb', 'ig', 'pt', 'twr', 'skp', 'tg', 'li', 'tt', 'dc', 'signal', 'threema', 'website', 'location', 'user_flag', 'occupation', 'interests', 'signature', 'viewemail', 'notifypm', 'games_block_pm', 'popup_pm', 'notifyreply', 'attachsig', 'setbm', 'allowhtml', 'allowbbcode', 'allowsmilies', 'hideonline', 'style', 'language', 'timezone', 'dateformat', 'user_absence_mode', 'user_absence', 'user_absence_text', 'birthday', 'gender');

	$s_hidden_vars = '<input type="hidden" name="sid" value="' . htmlspecialchars($session_id, ENT_QUOTES, 'UTF-8') . '" /><input type="hidden" name="agreed" value="true" /><input type="hidden" name="avatarcatname" value="' . htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . '" />';

	for($i = 0; $i < count($params); $i++)
	{
		$param_name = $params[$i];
		$hidden_value = isset($$param_name) && is_scalar($$param_name) ? (string) $$param_name : '';
		// Preserve raw editable form text once; owner-derived identity/date values
		// use the controller state. Validation still happens on the final submit.
		if (!in_array($param_name, array('user_id', 'coppa', 'birthday'), true) && array_key_exists($param_name, $_POST))
		{
			$hidden_value = is_scalar($_POST[$param_name]) ? (string) $_POST[$param_name] : '';
		}
		$s_hidden_vars .= '<input type="hidden" name="' . htmlspecialchars($param_name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($hidden_value, ENT_QUOTES, 'UTF-8') . '" />';
	}
	//
	// Custom Profile Fields MOD
	//
	$reserved = array_merge($params, array('sid','mode','agreed','submit','avatargallery','submitavatar','cancelavatar','avatarselect','avatarcatname','avatarlocal','avatar','avatarurl','avatarremoteurl','avatardel','avatarcategory','cur_password','new_password','password_confirm'));
	$profile_data = get_fields('WHERE users_can_view = '.ALLOW_VIEW);
	foreach($profile_data as $field) {
		$name = phpbb_profile_field_column($field);
		if ($name === '' || in_array($name, $reserved, true)) { continue; }
		$field_value = isset($_POST[$name]) ? $_POST[$name] : '';
		if (($field['field_type'] == CHECKBOX) && is_array($field_value)) {
			foreach (array_slice($field_value, 0, 100) as $checkbox_value) {
				if (!is_scalar($checkbox_value)) {
					continue;
				}
				$s_hidden_vars .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '[]" value="' . htmlspecialchars((string) $checkbox_value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
			}
		}
		else {
			$value = is_scalar($field_value) ? (string) $field_value : '';
			$s_hidden_vars .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" />';
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
		'L_GALLERY_FORM_NOTICE' => $lang['Avatar_gallery_form_notice'],

		'S_CATEGORY_SELECT' => $s_categories, 
		'S_COLSPAN' => max(1, $s_colspan),
		'S_PROFILE_ACTION' => append_sid("profile.$phpEx?mode=$mode"), 
		'S_HIDDEN_FIELDS' => $s_hidden_vars)
	);

	return;
}

?>
