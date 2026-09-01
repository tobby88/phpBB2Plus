<?php
/***************************************************************************
 *                               functions.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: functions.php,v 1.133.2.31 2003/07/20 13:14:27 acydburn Exp $
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

define('STYLE_URL', 's');

if ( !defined('IN_PHPBB') )
{
   die('Hacking attempt');
}

function phpbb_profile_text($value)
{
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
}

function phpbb_profile_http_url($value)
{
	$value = html_entity_decode(trim((string) $value), ENT_QUOTES, 'UTF-8');
	$parts = @parse_url($value);
	if (!$parts || empty($parts['scheme']) || empty($parts['host']) ||
		isset($parts['user']) || isset($parts['pass']) || strpos($value, '\\') !== false ||
		!in_array(strtolower($parts['scheme']), array('http', 'https'), true) ||
		preg_match('/[\x00-\x20\x7f]/', $value))
	{
		return '';
	}
	return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
}

function phpbb_profile_contact($value)
{
	return rawurlencode(html_entity_decode(trim((string) $value), ENT_QUOTES, 'UTF-8'));
}

function phpbb_profile_image_name($value)
{
	$value = trim((string) $value);
	return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $value) ? $value : '';
}

function phpbb_profile_asset_path($value)
{
	$value = trim(str_replace('\\', '/', (string) $value), '/');
	if ($value === '')
	{
		return '';
	}

	$parts = explode('/', $value);
	foreach ($parts as $part)
	{
		if (phpbb_profile_image_name($part) === '')
		{
			return '';
		}
	}

	return implode('/', array_map('rawurlencode', $parts));
}

function phpbb_avatar_asset_url($avatar, $avatar_type, $path_prefix = '')
{
	global $board_config;

	$avatar_type = (int) $avatar_type;
	$path_prefix = (string) $path_prefix;
	if (!preg_match('#^(?:\.\./)*$#D', $path_prefix))
	{
		$path_prefix = '';
	}

	if ($avatar_type === USER_AVATAR_REMOTE)
	{
		return !empty($board_config['allow_avatar_remote']) ? phpbb_profile_http_url($avatar) : '';
	}

	if ($avatar_type === USER_AVATAR_UPLOAD)
	{
		$path = phpbb_profile_asset_path(isset($board_config['avatar_path']) ? $board_config['avatar_path'] : '');
		$name = phpbb_profile_image_name($avatar);
		return (!empty($board_config['allow_avatar_upload']) && $path !== '' && $name !== '') ? $path_prefix . $path . '/' . rawurlencode($name) : '';
	}

	if ($avatar_type === USER_AVATAR_GALLERY)
	{
		$path = phpbb_profile_asset_path(isset($board_config['avatar_gallery_path']) ? $board_config['avatar_gallery_path'] : '');
		$avatar = str_replace('\\', '/', trim((string) $avatar, '/'));
		$parts = explode('/', $avatar);
		if (empty($board_config['allow_avatar_local']) || $path === '' || count($parts) !== 2 ||
			phpbb_profile_image_name($parts[0]) === '' || phpbb_profile_image_name($parts[1]) === '')
		{
			return '';
		}
		return $path_prefix . $path . '/' . rawurlencode($parts[0]) . '/' . rawurlencode($parts[1]);
	}

	return '';
}

function phpbb_avatar_image($avatar, $avatar_type, $max_size = 0, $path_prefix = '')
{
	$url = phpbb_avatar_asset_url($avatar, $avatar_type, $path_prefix);
	if ($url === '')
	{
		return '';
	}

	$max_size = (int) $max_size;
	$size = ($max_size > 0) ? ' style="max-width: ' . $max_size . 'px; max-height: ' . $max_size . 'px;"' : '';
	return '<img src="' . $url . '"' . $size . ' alt="" border="0" />';
}

function phpbb_preg_replace_outside_tags($text, $patterns, $replacements)
{
	$segments = preg_split('#(<[^>]*>)#s', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);
	if (!is_array($segments))
	{
		return (string) $text;
	}

	foreach ($segments as $index => $segment)
	{
		if (($index % 2) === 0 && $segment !== '')
		{
			$replaced = @preg_replace($patterns, $replacements, $segment);
			if ($replaced !== null)
			{
				$segments[$index] = $replaced;
			}
		}
	}

	return implode('', $segments);
}

//-- mod : post icon -------------------------------------------------------------------------------
//-- add
function phpbb_icon_config_valid($config)
{
	if (!is_array($config) || !isset($config['icons'], $config['special']) || !is_array($config['icons']) || !is_array($config['special']) || count($config['icons']) > 256 || count($config['special']) > 64)
	{
		return false;
	}
	$ids = array();
	foreach ($config['icons'] as $icon)
	{
		if (!is_array($icon) || !isset($icon['ind'], $icon['img'], $icon['alt'], $icon['auth']) || !is_scalar($icon['ind']) || !is_string($icon['img']) || !is_string($icon['alt']) || !is_scalar($icon['auth']))
		{
			return false;
		}
		$id = (int) $icon['ind'];
		$auth = (int) $icon['auth'];
		if ($id < 0 || $id > 65535 || isset($ids[$id]) || !in_array($auth, array(AUTH_ALL, AUTH_REG, AUTH_MOD, AUTH_ADMIN), true) || !preg_match('#^(?:|[A-Za-z0-9_][A-Za-z0-9_./-]{0,254})$#D', $icon['img']) || strpos($icon['img'], '..') !== false || !preg_match('/^[A-Za-z0-9_]{1,100}$/D', $icon['alt']))
		{
			return false;
		}
		$ids[$id] = true;
	}
	if (!isset($ids[0]))
	{
		return false;
	}
	foreach ($config['special'] as $key => $special)
	{
		if (!is_string($key) || !preg_match('/^[A-Z_]{1,64}$/D', $key) || !is_array($special) || !isset($special['lang_key'], $special['icon']) || !is_string($special['lang_key']) || !preg_match('/^[A-Za-z0-9_]{1,100}$/D', $special['lang_key']) || !is_scalar($special['icon']) || !isset($ids[(int) $special['icon']]))
		{
			return false;
		}
	}
	foreach (array('POST_ATTACHMENT', 'POST_PICTURE', 'POST_CALENDAR', 'POST_BIRTHDAY', 'POST_GLOBAL_ANNOUNCE', 'POST_ANNOUNCE', 'POST_STICKY', 'POST_NORMAL') as $key)
	{
		if (!isset($config['special'][$key]))
		{
			return false;
		}
	}
	return true;
}

function phpbb_normalize_icon_config($icons, $special)
{
	$config = array('icons' => array(), 'special' => array());
	if (is_array($icons))
	{
		foreach ($icons as $icon)
		{
			if (is_array($icon) && isset($icon['ind'], $icon['img'], $icon['alt'], $icon['auth']))
			{
				$config['icons'][] = array('ind' => (int) $icon['ind'], 'img' => (string) $icon['img'], 'alt' => (string) $icon['alt'], 'auth' => (int) $icon['auth']);
			}
		}
	}
	if (is_array($special))
	{
		foreach ($special as $key => $data)
		{
			if (is_array($data) && isset($data['lang_key'], $data['icon']))
			{
				$config['special'][(string) $key] = array('lang_key' => (string) $data['lang_key'], 'icon' => (int) $data['icon']);
			}
		}
	}
	return $config;
}

function phpbb_save_icon_config($icons, $special)
{
	global $phpbb_root_path;
	$config = phpbb_normalize_icon_config($icons, $special);
	return phpbb_icon_config_valid($config) && phpbb_data_store_write($phpbb_root_path . 'data/icons.dat', $config);
}

function phpbb_load_icon_config()
{
	global $phpbb_root_path, $icones, $icon_defined_special;
	static $loaded = false;
	if ($loaded && is_array($icones) && is_array($icon_defined_special))
	{
		return true;
	}

	$config = phpbb_data_store_read($phpbb_root_path . 'data/icons.dat');
	if (!phpbb_icon_config_valid($config))
	{
		$legacy_file = $phpbb_root_path . 'includes/def_icons.php';
		if (is_file($legacy_file) && !is_link($legacy_file) && @filesize($legacy_file) <= 65536)
		{
			$icones = array();
			$icon_defined_special = array();
			include($legacy_file);
			$config = phpbb_normalize_icon_config($icones, $icon_defined_special);
		}
		if (!phpbb_icon_config_valid($config))
		{
			$icon_default_icons = array();
			$icon_default_special = array();
			include($phpbb_root_path . 'includes/icon_defaults.php');
			$config = phpbb_normalize_icon_config($icon_default_icons, $icon_default_special);
		}
		if (!phpbb_icon_config_valid($config))
		{
			return false;
		}
		phpbb_data_store_write($phpbb_root_path . 'data/icons.dat', $config);
	}

	$icones = $config['icons'];
	$icon_defined_special = $config['special'];
	$loaded = true;
	return true;
}

function get_icon_title($icon, $empty=0, $topic_type=-1, $admin=false)
{
	global $lang, $images, $phpEx, $phpbb_root_path, $icones, $icon_defined_special;

	// get icons parameters
	phpbb_load_icon_config();

	// admin path
	$admin_path = ($admin) ? '../' : './';

	// alignment
	switch ($empty)
	{
		case 1:
			$align= 'middle';
			break;
		case 2:
			$align= 'bottom';
			break;
		default:
			$align = 'absbottom';
			break;
	}

	// find the icon
	$found = false;
	$icon_map = -1;
	for ($i=0; ($i < count($icones)) && !$found; $i++)
	{
		if ($icones[$i]['ind'] == $icon)
		{
			$found = true;
			$icon_map = $i;
		}
	}

	// icon not found : try a default value
	if (!$found || ($found && empty($icones[$icon_map]['img'])))
	{
		$change = true;
		switch($topic_type)
		{
			case POST_NORMAL:
				$icon = $icon_defined_special['POST_NORMAL']['icon'];
				break;
			case POST_STICKY:
				$icon = $icon_defined_special['POST_STICKY']['icon'];
				break;
			case POST_ANNOUNCE:
				$icon = $icon_defined_special['POST_ANNOUNCE']['icon'];
				break;
			case POST_GLOBAL_ANNOUNCE:
				$icon = $icon_defined_special['POST_GLOBAL_ANNOUNCE']['icon'];
				break;
			case POST_BIRTHDAY:
				$icon = $icon_defined_special['POST_BIRTHDAY']['icon'];
				break;
			case POST_CALENDAR:
				$icon = $icon_defined_special['POST_CALENDAR']['icon'];
				break;
			case POST_PICTURE:
				$icon = $icon_defined_special['POST_PICTURE']['icon'];
				break;
			case POST_ATTACHMENT:
				$icon = isset($icon_defined_special['POST_ATTACHMENT']['icon']) ? $icon_defined_special['POST_ATTACHMENT']['icon'] : 0;
				break;
			default:
				$change=false;
				break;
		}

		// a default icon has been sat
		if ($change)
		{
			// find the icon
			$found = false;
			$icon_map = -1;
			for ($i=0; ($i < count($icones)) && !$found; $i++)
			{
				if ($icones[$i]['ind'] == $icon)
				{
					$found = true;
					$icon_map = $i;
				}
			}
		}
	}

	// build the icon image
	if (!$found || ($found && empty($icones[$icon_map]['img'])))
	{
		switch ($empty)
		{
			case 0:
				$res = '';
				break;
			case 1:
				$res = '<img width="20" align="' . $align . '" src="' . $admin_path . $images['spacer'] . '" alt="" border="0">';
				break;
			case 2:
				$res = ($icon_map >= 0 && isset($icones[$icon_map]['alt'])) ? (isset($lang[ $icones[$icon_map]['alt'] ]) ? $lang[ $icones[$icon_map]['alt'] ] : $icones[$icon_map]['alt']) : '';
				break;
		}
	}
	else
	{
		$res = '<img align="' . $align . '" src="' . ( isset($images[ $icones[$icon_map]['img'] ]) ? $admin_path . $images[ $icones[$icon_map]['img'] ] : $admin_path . $icones[$icon_map]['img'] ) . '" alt="' . ( isset($lang[ $icones[$icon_map]['alt'] ]) ? $lang[ $icones[$icon_map]['alt'] ] : $icones[$icon_map]['alt'] ) . '" border="0">';
	}

	return $res;
}
//-- fin mod : post icon ---------------------------------------------------------------------------

function get_db_stat($mode)
{
	global $db;

	switch( $mode )
	{
		case 'usercount':
			$sql = "SELECT COUNT(user_id) AS total
				FROM " . USERS_TABLE . "
				WHERE user_id <> " . ANONYMOUS;
			break;

		case 'newestuser':
			$sql = "SELECT user_id, username
				FROM " . USERS_TABLE . "
				WHERE user_id <> " . ANONYMOUS . "
				ORDER BY user_id DESC
				LIMIT 1";
			break;

		case 'postcount':
		case 'topiccount':
			$sql = "SELECT SUM(forum_topics) AS topic_total, SUM(forum_posts) AS post_total
				FROM " . FORUMS_TABLE;
			break;
	}

	if ( !($result = $db->sql_query($sql)) )
	{
		return false;
	}

	$row = $db->sql_fetchrow($result);

	switch ( $mode )
	{
		case 'usercount':
			return $row['total'];
			break;
		case 'newestuser':
			return $row;
			break;
		case 'postcount':
			return $row['post_total'];
			break;
		case 'topiccount':
			return $row['topic_total'];
			break;
	}

	return false;
}
// added at phpBB 2.0.11 to properly format the username
function phpbb_clean_username($username)
{
	$username = substr(htmlspecialchars(str_replace("\'", "'", trim($username))), 0, 25);
	$username = phpbb_rtrim($username, "\\");
	$username = str_replace("'", "\'", $username);

	return $username;
}

/**
 * Keep legacy MOD tables which duplicate a display name in sync after an
 * account rename. The users table remains authoritative; the snapshots are
 * retained for guest/deleted-user history and old reports which lack a join.
 */
function phpbb_sync_username_references($user_id, $old_username, $new_username)
{
	global $db, $phpbb_root_path, $table_prefix;

	$user_id = (int) $user_id;
	$old_username = (string) $old_username;
	$new_username = (string) $new_username;
	if ($user_id <= 0 || $old_username === $new_username || $new_username === '')
	{
		return;
	}

	$new_username_sql = $db->sql_escape($new_username);
	$old_username_sql = $db->sql_escape($old_username);
	$updates = array(
		"UPDATE " . $table_prefix . "album SET pic_username = '$new_username_sql' WHERE pic_user_id = $user_id",
		"UPDATE " . $table_prefix . "album_comment SET comment_username = '$new_username_sql' WHERE comment_user_id = $user_id",
		"UPDATE " . iNA_GAMES_COMMENT . " SET comment_username = '$new_username_sql' WHERE comment_user_id = $user_id",
		"UPDATE " . iNA_AT_SCORES . " SET player_name = '$new_username_sql' WHERE player_id = $user_id",
		"UPDATE " . SHOUTBOX_TABLE . " SET shout_username = '$new_username_sql' WHERE shout_user_id = $user_id",
		// Monthly highscores predate stable user IDs, so the former unique
		// username is the only reliable key available during the rename.
		"UPDATE " . iNA_HIGHSCORES . " SET highscore_user_id = $user_id, highscore_player = '$new_username_sql' WHERE highscore_user_id = $user_id OR (highscore_user_id = 0 AND highscore_player = '$old_username_sql')"
	);

	foreach ($updates as $sql)
	{
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Could not synchronize renamed user references', '', __LINE__, __FILE__, $sql);
		}
	}

	// A personal group is identified by its membership and flag, never by a
	// possibly colliding group name.
	$sql = "SELECT g.group_id
		FROM " . GROUPS_TABLE . " g, " . USER_GROUP_TABLE . " ug
		WHERE ug.user_id = $user_id
			AND ug.group_id = g.group_id
			AND g.group_single_user = 1";
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not find users group', '', __LINE__, __FILE__, $sql);
	}
	while ($row = $db->sql_fetchrow($result))
	{
		$group_id = (int) $row['group_id'];
		$sql = "UPDATE " . GROUPS_TABLE . "
			SET group_name = '$new_username_sql'
			WHERE group_id = $group_id";
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Could not rename users group', '', __LINE__, __FILE__, $sql);
		}
	}
	$db->sql_freeresult($result);

	foreach (array('cg_users.cache', 'arcade_best_player.cache', 'arcade_best_at_player.cache') as $cache_file)
	{
		@unlink($phpbb_root_path . 'cache/' . $cache_file);
	}
}

/**
* This function is a wrapper for ltrim, as charlist is only supported in php >= 4.1.0
* Added in phpBB 2.0.18
*/
function phpbb_ltrim($str, $charlist = false)
{
	if ($charlist === false)
	{
		return ltrim($str);
	}

	$php_version = explode('.', PHP_VERSION);

	// php version < 4.1.0
	if ((int) $php_version[0] < 4 || ((int) $php_version[0] == 4 && (int) $php_version[1] < 1))
	{
		while ($str[0] == $charlist)
		{
			$str = substr($str, 1);
		}
	}
	else
	{
		$str = ltrim($str, $charlist);
	}

	return $str;
}

/**
* Our own generator of random values
* This uses a constantly changing value as the base for generating the values
* The board wide setting is updated once per page if this code is called
* With thanks to Anthrax101 for the inspiration on this one
* Added in phpBB 2.0.20
*/
function dss_rand()
{
	global $db, $board_config, $dss_seeded;

	// Preserve the historical 16-character hexadecimal return format while
	// using the operating system CSPRNG on supported PHP versions.
	if (function_exists('phpbb_random_bytes'))
	{
		return bin2hex(phpbb_random_bytes(8));
	}

	$val = $board_config['rand_seed'] . microtime();
	$val = md5($val);
	$board_config['rand_seed'] = md5($board_config['rand_seed'] . $val . 'a');

	if($dss_seeded !== true)
	{
		$sql = "UPDATE " . CONFIG_TABLE . " SET
			config_value = '" . $board_config['rand_seed'] . "'
			WHERE config_name = 'rand_seed'";

		if( !$db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, "Unable to reseed PRNG", "", __LINE__, __FILE__, $sql);
		}

		$dss_seeded = true;
	}

	return substr($val, 4, 16);
}

/**
 * Bound decoded raster allocations before GD opens attacker-controlled files.
 * Compressed PNG/JPEG/GIF files can be tiny while requiring hundreds of
 * megabytes once expanded.  Keep the check multiplication-free so it also
 * behaves correctly on 32-bit PHP 5.6 builds.
 */
function phpbb_image_dimensions_safe($width, $height, $max_pixels = 20000000, $max_dimension = 10000)
{
	$width = (int) $width;
	$height = (int) $height;
	$max_pixels = max(1, (int) $max_pixels);
	$max_dimension = max(1, (int) $max_dimension);

	return $width > 0 && $height > 0 && $width <= $max_dimension && $height <= $max_dimension
		&& $width <= floor($max_pixels / $height);
}

// added at phpBB 2.0.12 to fix a bug in PHP 4.3.10 (only supporting charlist in php >= 4.1.0)
function phpbb_rtrim($str, $charlist = false)
{
	if ($charlist === false)
	{
		return rtrim($str);
	}

	$php_version = explode('.', PHP_VERSION);

	// php version < 4.1.0
	if ((int) $php_version[0] < 4 || ((int) $php_version[0] == 4 && (int) $php_version[1] < 1))
	{
		while ($str[strlen($str)-1] == $charlist)
		{
			$str = substr($str, 0, strlen($str)-1);
		}
	}
	else
	{
		$str = rtrim($str, $charlist);
	}

	return $str;
}

//
// Get Userdata, $user can be username or user_id. If force_str is true, the username will be forced.
//
function get_userdata($user, $force_str = false)
{
	global $db;

	if (!is_numeric($user) || $force_str)
	{
		$user = phpbb_clean_username($user);
	}
	else
	{
		$user = intval($user);
	}

	$sql = "SELECT *
		FROM " . USERS_TABLE . "
		WHERE ";
	$sql .= ( ( is_integer($user) ) ? "user_id = $user" : "username = '" .  str_replace("\'", "''", $user) . "'" ) . " AND user_id <> " . ANONYMOUS;
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Tried obtaining data for a non-existent user', '', __LINE__, __FILE__, $sql);
	}

	return ( $row = $db->sql_fetchrow($result) ) ? $row : false;
}

function make_jumpbox($action, $match_forum_id = 0)
{
	global $template, $userdata, $lang, $db, $nav_links, $phpEx, $SID;
//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
	return jumpbox($action, $match_forum_id);
//-- fin mod : categories hierarchy ----------------------------------------------------------------

//	$is_auth = auth(AUTH_VIEW, AUTH_LIST_ALL, $userdata);

	$sql = "SELECT c.cat_id, c.cat_title, c.cat_order
		FROM " . CATEGORIES_TABLE . " c, " . FORUMS_TABLE . " f
		WHERE f.cat_id = c.cat_id
		GROUP BY c.cat_id, c.cat_title, c.cat_order
		ORDER BY c.cat_order";
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, "Couldn't obtain category list.", "", __LINE__, __FILE__, $sql);
	}

	$category_rows = array();
	while ( $row = $db->sql_fetchrow($result) )
	{
		$category_rows[] = $row;
	}

	if ( $total_categories = count($category_rows) )
	{
		$sql = "SELECT *
			FROM " . FORUMS_TABLE . "
			ORDER BY cat_id, forum_order";
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not obtain forums information', '', __LINE__, __FILE__, $sql);
		}

		$boxstring = '<select name="' . POST_FORUM_URL . '" onchange="if(this.options[this.selectedIndex].value != -1){ forms[\'jumpbox\'].submit() }"><option value="-1">' . $lang['Select_forum'] . '</option>';

		$forum_rows = array();
		while ( $row = $db->sql_fetchrow($result) )
		{
			$forum_rows[] = $row;
		}

		if ( $total_forums = count($forum_rows) )
		{
			for($i = 0; $i < $total_categories; $i++)
			{
				$boxstring_forums = '';
				for($j = 0; $j < $total_forums; $j++)
				{
					if ( $forum_rows[$j]['cat_id'] == $category_rows[$i]['cat_id'] && $forum_rows[$j]['auth_view'] <= AUTH_REG )
					{

//					if ( $forum_rows[$j]['cat_id'] == $category_rows[$i]['cat_id'] && $is_auth[$forum_rows[$j]['forum_id']]['auth_view'] )
//					{
						$selected = ( $forum_rows[$j]['forum_id'] == $match_forum_id ) ? 'selected="selected"' : '';
						$boxstring_forums .=  '<option value="' . $forum_rows[$j]['forum_id'] . '"' . $selected . '>' . $forum_rows[$j]['forum_name'] . '</option>';

						//
						// Add an array to $nav_links for the Mozilla navigation bar.
						// 'chapter' and 'forum' can create multiple items, therefore we are using a nested array.
						//
						$nav_links['chapter forum'][$forum_rows[$j]['forum_id']] = array (
							'url' => append_sid("viewforum.$phpEx?" . POST_FORUM_URL . "=" . $forum_rows[$j]['forum_id']),
							'title' => $forum_rows[$j]['forum_name']
						);

					}
				}

				if ( $boxstring_forums != '' )
				{
					$boxstring .= '<option value="-1">&nbsp;</option>';
					$boxstring .= '<option value="-1">' . $category_rows[$i]['cat_title'] . '</option>';
					$boxstring .= '<option value="-1">----------------</option>';
					$boxstring .= $boxstring_forums;
				}
			}
		}

		$boxstring .= '</select>';
	}
	else
	{
		$boxstring .= '<select name="' . POST_FORUM_URL . '" onchange="if(this.options[this.selectedIndex].value != -1){ forms[\'jumpbox\'].submit() }"></select>';
	}
	// Let the jumpbox work again in sites having additional session id checks.
	//if ( !empty($SID) )
	//{
		$boxstring .= '<input type="hidden" name="sid" value="' . $userdata['session_id'] . '" />';
	//}

	$template->set_filenames(array(
		'jumpbox' => 'jumpbox.tpl')
	);
	$template->assign_vars(array(
		'L_GO' => $lang['Go'],
		'L_JUMP_TO' => $lang['Jump_to'],
		'L_SELECT_FORUM' => $lang['Select_forum'],

		'S_JUMPBOX_SELECT' => $boxstring,
		'S_JUMPBOX_ACTION' => append_sid($action))
	);
	$template->assign_var_from_handle('JUMPBOX', 'jumpbox');

	return;
}

//
// Initialise user settings on page load
function phpbb_normalize_language($language, $fallback = 'english')
{
	global $phpbb_root_path, $phpEx;

	$language = strtolower(trim((string) $language));
	$fallback = strtolower(trim((string) $fallback));
	if (!preg_match('/^[a-z0-9_-]{1,30}$/D', $fallback))
	{
		$fallback = 'english';
	}

	$candidates = array($language, $fallback, 'english');
	foreach ($candidates as $candidate)
	{
		if (preg_match('/^[a-z0-9_-]{1,30}$/D', $candidate) &&
			is_file($phpbb_root_path . 'language/lang_' . $candidate . '/lang_main.' . $phpEx))
		{
			return $candidate;
		}
	}

	message_die(CRITICAL_ERROR, 'Could not locate valid language pack');
}

function init_userprefs($userdata)
{
	global $board_config, $theme, $images;
	global $template, $lang, $phpEx, $phpbb_root_path, $db;
	global $nav_links;
	global $phpbb_original_default_lang;
	//-- mod : mods settings ---------------------------------------------------------------------------
//-- add
	global $mods, $list_yes_no, $userdata;

	//	get all the mods settings
	$dir = @opendir($phpbb_root_path . 'includes/mods_settings');
	while( $dir !== false && ($file = @readdir($dir)) !== false )
	{
		if( preg_match("/^mod_.*?\." . $phpEx . "$/", $file) )
		{
			include_once($phpbb_root_path . 'includes/mods_settings/' . $file);
		}
	}
	if ($dir !== false)
	{
		@closedir($dir);
	}
//-- fin mod : mods settings -----------------------------------------------------------------------

	$default_lang = phpbb_normalize_language(
		isset($board_config['default_lang']) ? $board_config['default_lang'] : '',
		'english'
	);

	if ( $userdata['user_id'] != ANONYMOUS )
	{
		if ( !empty($userdata['user_lang']))
		{
			$default_lang = phpbb_normalize_language($userdata['user_lang'], $default_lang);
		}

		if ( !empty($userdata['user_dateformat']) )
		{
			$board_config['default_dateformat'] = $userdata['user_dateformat'];
		}

		if ( isset($userdata['user_timezone']) )
		{
			$board_config['board_timezone'] = $userdata['user_timezone'];
		}
	}
	// If we've had to change the value in any way then let's write it back to the database
	// before we go any further since it means there is something wrong with it
	if ( $userdata['user_id'] != ANONYMOUS && (!isset($userdata['user_lang']) || $userdata['user_lang'] !== $default_lang) )
	{
		$sql = 'UPDATE ' . USERS_TABLE . "
			SET user_lang = '" . $default_lang . "'
			WHERE user_id = " . intval($userdata['user_id']);

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(CRITICAL_ERROR, 'Could not update user language info');
		}

		$userdata['user_lang'] = $default_lang;
	}
	elseif ( $userdata['user_id'] === ANONYMOUS && isset($phpbb_original_default_lang) && $phpbb_original_default_lang !== $default_lang )
	{
		$sql = 'UPDATE ' . CONFIG_TABLE . "
			SET config_value = '" . $default_lang . "'
			WHERE config_name = 'default_lang'";

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(CRITICAL_ERROR, 'Could not update user language info');
		}
		@unlink($phpbb_root_path . 'cache/config_data.cache');
	}

	$board_config['default_lang'] = $default_lang;

	include($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_main.' . $phpEx);
	include($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_cback_ctracker.' . $phpEx);
	include($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_news.' . $phpEx);
	if ( defined('IN_ADMIN') )
	{
		if( !file_exists(@phpbb_realpath($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_admin.'.$phpEx)) )
		{
			$board_config['default_lang'] = 'english';
		}

		include($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_admin.' . $phpEx);
		include($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_admin_captcha.' . $phpEx);
	}
	//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
	global $tree;
	if (empty($tree['auth'])) get_user_tree($userdata);
//-- fin mod : categories hierarchy ----------------------------------------------------------------
//-- mod : language settings -----------------------------------------------------------------------
//-- add
	include($phpbb_root_path . './includes/lang_extend_mac.' . $phpEx);
//-- fin mod : language settings -------------------------------------------------------------------
	include_attach_lang();
	//
	// Mozilla navigation bar
	// Default items that should be valid on all pages.
	// Defined here to correctly assign the Language Variables
	// and be able to change the variables within code.
	//
	$nav_links['top'] = array (
		'url' => append_sid($phpbb_root_path . 'index.' . $phpEx),
		'title' => sprintf($lang['Forum_Index'], $board_config['sitename'])
	);
	$nav_links['search'] = array (
		'url' => append_sid($phpbb_root_path . 'search.' . $phpEx),
		'title' => $lang['Search']
	);
	$nav_links['help'] = array (
		'url' => append_sid($phpbb_root_path . 'faq.' . $phpEx),
		'title' => $lang['FAQ']
	);
	$nav_links['author'] = array (
		'url' => append_sid($phpbb_root_path . 'memberlist.' . $phpEx),
		'title' => $lang['Memberlist']
	);
	//
	// Add bookmarks to Navigation bar
	//
	if ($userdata['session_logged_in'] && $board_config['max_link_bookmarks'] > 0)
	{
		$auth_sql = '';
		$is_auth_ary = auth(AUTH_READ, AUTH_LIST_ALL, $userdata);

		$ignore_forum_sql = '';
		foreach ($is_auth_ary as $key => $value)
		{
			if ( !$value['auth_read'] )
			{
				$ignore_forum_sql .= ( ( $ignore_forum_sql != '' ) ? ', ' : '' ) . $key;
			}
		}

		if ( $ignore_forum_sql != '' )
		{
			$auth_sql .= ( $auth_sql != '' ) ? " AND f.forum_id NOT IN ($ignore_forum_sql) " : "f.forum_id NOT IN ($ignore_forum_sql) ";
		}
		if ( $auth_sql != '' )
		{
			$sql = "SELECT t.topic_id, t.topic_title, f.forum_id
				FROM " . TOPICS_TABLE . "  t, " . BOOKMARK_TABLE . " b, " . FORUMS_TABLE . " f, " . POSTS_TABLE . " p
				WHERE t.topic_id = b.topic_id
					AND t.forum_id = f.forum_id
					AND t.topic_last_post_id = p.post_id
					AND b.user_id = " . $userdata['user_id'] . "
					AND $auth_sql
				ORDER BY p.post_time DESC
				LIMIT " . (intval($board_config['max_link_bookmarks']) + 1);
		}
		else
		{
			$sql = "SELECT t.topic_id, t.topic_title
				FROM " . TOPICS_TABLE . " t, " . BOOKMARK_TABLE . " b, " . POSTS_TABLE . " p
				WHERE t.topic_id = b.topic_id
					AND t.topic_last_post_id = p.post_id
					AND b.user_id = " . $userdata['user_id'] . "
				ORDER BY p.post_time DESC
				LIMIT " . (intval($board_config['max_link_bookmarks']) + 1);
		}
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Could not obtain post ids', '', __LINE__, __FILE__, $sql);
		}
		$post_rows = array();
		while ( $row = $db->sql_fetchrow($result) )
		{
			$post_rows[] = $row;
		}
		$db->sql_freeresult($result);

		if ( $total_posts = count($post_rows) )
		{
			//
			// Define censored word matches
			//
			$orig_word = array();
			$replacement_word = array();
			obtain_word_list($orig_word, $replacement_word);

			for($i = 0; $i < min($total_posts, $board_config['max_link_bookmarks']); $i++)
			{
				$topic_title = ( count($orig_word) ) ? preg_replace($orig_word, $replacement_word, $post_rows[$i]['topic_title']) : $post_rows[$i]['topic_title'];
				//
				// Add an array to $nav_links for the Mozilla navigation bar.
				// 'bookmarks' can create multiple items, therefore we are using a nested array.
				//
				$nav_links['bookmark'][$i] = array (
					'url' => append_sid("viewtopic.$phpEx?" . POST_TOPIC_URL . "=" . $post_rows[$i]['topic_id']),
					'title' => $topic_title
				);
			}
			if ($total_posts > $board_config['max_link_bookmarks'])
			{
				$start = intval($board_config['max_link_bookmarks'] / $board_config['topics_per_page']) * $board_config['topics_per_page'];
				$nav_links['bookmark'][$i] = array (
					'url' => append_sid("search.$phpEx?search_id=bookmarks&start=$start"),
					'title' => $lang['More_bookmarks']
				);
			}
		}
	}

	//
	// Set up style
	//
	if ( !$board_config['override_user_style'] )
	{
		if ( $userdata['user_id'] != ANONYMOUS && $userdata['user_style'] > 0 )
		{
			if ( $theme = setup_style($userdata['user_style']) )
			{
				return;
			}
		}
	}

	$theme = setup_style($board_config['default_style']);

	return;
}

/**
 * Validate the small, legacy PHP assignment grammar used by <template>.cfg.
 *
 * The file must remain compatible with existing phpBB2 styles, but a style
 * archive must not be able to turn this include into arbitrary PHP execution.
 */
function phpbb_template_config_is_safe($filename, $templates_root)
{
	$root = @realpath($templates_root);
	$file = @realpath($filename);
	if ($root === false || $file === false || !@is_file($file) || @is_link($file))
	{
		return false;
	}

	$root = rtrim(str_replace('\\', '/', $root), '/') . '/';
	$file_normalized = str_replace('\\', '/', $file);
	if (strpos($file_normalized, $root) !== 0 || @filesize($file) > 1048576)
	{
		return false;
	}

	$lines = @file($file);
	if (!is_array($lines) || count($lines) > 5000)
	{
		return false;
	}

	$allowed_scalars = array(
		'current_template_images', 'topic_iw', 'topic_ih', 'post_iw', 'post_ih',
		'icon_iw', 'icon_ih', 'folder_iw', 'folder_ih', 'folderbig_iw',
		'folderbig_ih', 'ifade'
	);
	$variable = '\\$(?:current_template_path|current_template_images|topic_iw|topic_ih|post_iw|post_ih|icon_iw|icon_ih|folder_iw|folder_ih|folderbig_iw|folderbig_ih|ifade)';
	$quoted = '(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')';
	$term = '(?:' . $variable . '|-?[0-9]+(?:\\.[0-9]+)?|TRUE|FALSE|' . $quoted . ')';
	$expression = $term . '(?:\\s*\\.\\s*' . $term . ')*';
	$defined = false;

	foreach ($lines as $source_line)
	{
		$line = trim($source_line);
		if ($line === '' || $line === '<?php' || $line === '?>' || strpos($line, '//') === 0)
		{
			continue;
		}
		if (preg_match('/^define\\(\\s*[\'\"]TEMPLATE_CONFIG[\'\"]\\s*,\\s*TRUE\\s*\\);$/D', $line))
		{
			$defined = true;
			continue;
		}
		if (!preg_match('/^\\$([A-Za-z_][A-Za-z0-9_]*)(.*?)\\s*=\\s*(' . $expression . ')\\s*;$/D', $line, $match))
		{
			return false;
		}

		$base = $match[1];
		$indexes = $match[2];
		if ($base === 'images')
		{
			if (!preg_match('/^\\[[\'\"][A-Za-z0-9_]+[\'\"]\\](?:\\[[0-9]+\\])?$/D', $indexes))
			{
				return false;
			}
		}
		elseif ($base === 'board_config')
		{
			if (!preg_match('/^\\[[\'\"](?:vote_graphic_length|privmsg_graphic_length)[\'\"]\\]$/D', $indexes))
			{
				return false;
			}
		}
		elseif (!in_array($base, $allowed_scalars, true) || $indexes !== '')
		{
			return false;
		}
	}

	return $defined;
}

/**
 * Read the image map from a validated template configuration in isolation.
 *
 * phpBB2 Plus extensions expect a much larger image map than several of the
 * later bundled styles provide.  Loading the complete preservation style as a
 * fallback keeps those extensions functional without forcing every style to
 * duplicate hundreds of legacy image declarations.
 */
function phpbb_template_image_map($filename, $templates_root, $template_path)
{
	if (!phpbb_template_config_is_safe($filename, $templates_root))
	{
		return array();
	}

	$images = array();
	$current_template_path = $template_path;
	$board_config = array();

	// TEMPLATE_CONFIG may already have been defined by the active style.  The
	// validated file contains assignments only; suppress the duplicate define.
	@include($filename);

	return is_array($images) ? $images : array();
}

function phpbb_serialized_data_read($filename, $allowed_root)
{
	$data_root = @realpath($allowed_root);
	$parent = @realpath(dirname($filename));
	if ($data_root === false || $parent === false || $data_root !== $parent ||
		!@is_file($filename) || @is_link($filename))
	{
		return false;
	}
	$size = @filesize($filename);
	if ($size === false || $size < 0 || $size > 4194304)
	{
		return false;
	}
	$serialized = @file_get_contents($filename);
	$data = phpbb_safe_unserialize($serialized);
	return is_array($data) ? $data : false;
}

function phpbb_serialized_data_write($filename, $data, $allowed_root)
{
	$data_root = @realpath($allowed_root);
	$parent = @realpath(dirname($filename));
	if ($data_root === false || $parent === false || $data_root !== $parent || @is_link($filename))
	{
		return false;
	}
	$serialized = serialize($data);
	if (strlen($serialized) > 4194304)
	{
		return false;
	}
	$temp = @tempnam($data_root, 'data_');
	if ($temp === false)
	{
		return false;
	}
	$written = @file_put_contents($temp, $serialized, LOCK_EX);
	if ($written !== strlen($serialized))
	{
		@unlink($temp);
		return false;
	}
	@chmod($temp, 0644);
	if (!@rename($temp, $filename))
	{
		@unlink($temp);
		return false;
	}
	return true;
}

function phpbb_data_cache_read($filename)
{
	global $phpbb_root_path;
	return phpbb_serialized_data_read($filename, $phpbb_root_path . 'cache');
}

function phpbb_data_cache_write($filename, $data)
{
	global $phpbb_root_path;
	return phpbb_serialized_data_write($filename, $data, $phpbb_root_path . 'cache');
}

function phpbb_data_store_read($filename)
{
	global $phpbb_root_path;
	return phpbb_serialized_data_read($filename, $phpbb_root_path . 'data');
}

function phpbb_data_store_write($filename, $data)
{
	global $phpbb_root_path;
	return phpbb_serialized_data_write($filename, $data, $phpbb_root_path . 'data');
}

function setup_style($style)
{
	global $db, $board_config, $template, $images, $phpbb_root_path;
	//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
	global $themes_style;

	if ( defined('CACHE_THEMES') )
	{
		$themes_style = phpbb_data_cache_read($phpbb_root_path . 'cache/themes.cache');
		$valid_themes = is_array($themes_style);
		if ($valid_themes)
		{
			foreach ($themes_style as $theme_id => $theme_data)
			{
				if (!is_array($theme_data) || !isset($theme_data['themes_id']) || (int) $theme_data['themes_id'] !== (int) $theme_id)
				{
					$valid_themes = false;
					break;
				}
			}
		}
		if (!$valid_themes)
		{
			$themes_style = cache_themes();
		}
	}
	if ( isset($themes_style[(int) $style]) && is_array($themes_style[(int) $style]) )
	{
		$row = $themes_style[(int) $style];
	}
	else
	{
//-- fin mod : categories hierarchy ----------------------------------------------------------------

	$sql = 'SELECT *
		FROM ' . THEMES_TABLE . '
		WHERE themes_id = ' . (int) $style;
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(CRITICAL_ERROR, 'Could not query database for theme info');
	}

	if ( !($row = $db->sql_fetchrow($result)) )
	{
		// We are trying to setup a style which does not exist in the database
		// Try to fallback to the board default (if the user had a custom style)
		// and then any users using this style to the default if it succeeds
		if ( $style != $board_config['default_style'])
		{
			$sql = 'SELECT *
				FROM ' . THEMES_TABLE . '
				WHERE themes_id = ' . (int) $board_config['default_style'];
			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(CRITICAL_ERROR, 'Could not query database for theme info');
			}

			if ( $row = $db->sql_fetchrow($result) )
			{
				$db->sql_freeresult($result);

				$sql = 'UPDATE ' . USERS_TABLE . '
					SET user_style = ' . (int) $board_config['default_style'] . "
					WHERE user_style = $style";
				if ( !($result = $db->sql_query($sql)) )
				{
					message_die(CRITICAL_ERROR, 'Could not update user theme info');
				}
			}
			else
			{
				message_die(CRITICAL_ERROR, "Could not get theme data for themes_id [$style]");
			}
		}
		else
		{
			message_die(CRITICAL_ERROR, "Could not get theme data for themes_id [$style]");
		}
	}
	//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
	}
//-- fin mod : categories hierarchy ----------------------------------------------------------------

	$template_path = 'templates/' ;
	$template_name = $row['template_name'] ;

	$template = new Template($phpbb_root_path . $template_path . $template_name);

	if ( $template )
	{
		$current_template_path = $template_path . $template_name;
		$template_config = $phpbb_root_path . $template_path . $template_name . '/' . $template_name . '.cfg';
		if (phpbb_template_config_is_safe($template_config, $phpbb_root_path . $template_path))
		{
			include($template_config);
		}

		if ( !defined('TEMPLATE_CONFIG') )
		{
			message_die(CRITICAL_ERROR, "Could not open $template_name template config file", '', __LINE__, __FILE__);
		}

		if ($template_name !== 'fisubsilversh')
		{
			$fallback_template_path = $template_path . 'fisubsilversh';
			$fallback_config = $phpbb_root_path . $fallback_template_path . '/fisubsilversh.cfg';
			$fallback_images = phpbb_template_image_map(
				$fallback_config,
				$phpbb_root_path . $template_path,
				$fallback_template_path
			);
			$images = array_merge($fallback_images, $images);
		}

		$img_lang = ( file_exists(@phpbb_realpath($phpbb_root_path . $current_template_path . '/images/lang_' . $board_config['default_lang'])) ) ? $board_config['default_lang'] : 'english';

		foreach ($images as $key => $value)
		{
			if ( !is_array($value) )
			{
				$images[$key] = str_replace('{LANG}', 'lang_' . $img_lang, $value);
			}
		}
	}

	return $row;
}

function encode_ip($dotquad_ip)
{
	$packed_ip = @inet_pton(trim((string) $dotquad_ip));
	if ($packed_ip === false)
	{
		return '00000000';
	}
	if (strlen($packed_ip) === 4)
	{
		return bin2hex($packed_ip);
	}

	// Legacy phpBB2 schemas can only retain 32 bits. Keep a stable,
	// non-empty session identifier for IPv6 clients without raising warnings.
	return substr(hash('sha256', $packed_ip), 0, 8);
}

function decode_ip($int_ip)
{
	$int_ip = strtolower(trim((string) $int_ip));
	if (!preg_match('/^[0-9a-f]{8}$/', $int_ip))
	{
		return '0.0.0.0';
	}
	$hexipbang = str_split($int_ip, 2);
	return hexdec($hexipbang[0]). '.' . hexdec($hexipbang[1]) . '.' . hexdec($hexipbang[2]) . '.' . hexdec($hexipbang[3]);
}

// Return a translated legacy GMT label without assuming every stored offset
// still has a matching language-array key.
function phpbb_timezone_label($offset)
{
	global $lang;

	$offset = is_numeric($offset) ? (float) $offset : 0.0;
	$key = rtrim(rtrim(sprintf('%.2f', $offset), '0'), '.');
	if ($key === '-0')
	{
		$key = '0';
	}
	if (isset($lang[$key]) && is_scalar($lang[$key]))
	{
		return (string) $lang[$key];
	}

	return ($offset == 0.0) ? 'GMT' : 'GMT ' . (($offset > 0) ? '+ ' : '- ') . rtrim(rtrim(sprintf('%.2f', abs($offset)), '0'), '.') . ' hours';
}

//
// Create date/time from format and timezone
//
function create_date($format, $gmepoch, $tz)
{
	global $board_config, $lang;
	static $translate;

	if ( empty($translate) && $board_config['default_lang'] != 'english' )
	{
		foreach ($lang['datetime'] as $match => $replace)
		{
			$translate[$match] = $replace;
		}
	}

	return ( !empty($translate) ) ? strtr(@gmdate($format, $gmepoch + (3600 * ($tz+date("I")))), $translate) : @gmdate($format, $gmepoch + (3600 * ($tz+date("I"))));

}
//-- mod : today at   yesterday at ------------------------------------------------------------------------
//-- add
//
// Create date/time/day from format and timezone
//
function create_date_day($format, $gmepoch, $tz)
{
   global $board_config, $lang;

   $date_day = create_date($format, $gmepoch, $tz);
    if ( $board_config['time_today'] < $gmepoch)
    {
       $date_day = sprintf($lang['Today_at'], create_date($board_config['default_timeformat'], $gmepoch, $tz));
    }
      else if ( $board_config['time_yesterday'] < $gmepoch)
    {
       $date_day = sprintf($lang['Yesterday_at'], create_date($board_config['default_timeformat'], $gmepoch, $tz));
    }

   return $date_day;
}
//-- end mod : today at   yesterday at ------------------------------------------------------------------------
//
// Pagination routine, generates
// page number sequence
//
function generate_pagination($base_url, $num_items, $per_page, $start_item, $add_prevnext_text = TRUE)
{
	global $lang;
	$per_page = max(1, intval($per_page));

	$total_pages = ceil($num_items/$per_page);

	if ( $total_pages == 1 )
	{
		return '';
	}

	$on_page = floor($start_item / $per_page) + 1;

	$page_string = '';
	if ( $total_pages > 10 )
	{
		$init_page_max = ( $total_pages > 3 ) ? 3 : $total_pages;

		for($i = 1; $i < $init_page_max + 1; $i++)
		{
			$page_string .= ( $i == $on_page ) ? '<b>' . $i . '</b>' : '<a href="' . append_sid($base_url . "&amp;start=" . ( ( $i - 1 ) * $per_page ) ) . '">' . $i . '</a>';
			if ( $i <  $init_page_max )
			{
				$page_string .= ", ";
			}
		}

		if ( $total_pages > 3 )
		{
			if ( $on_page > 1  && $on_page < $total_pages )
			{
				$page_string .= ( $on_page > 5 ) ? ' ... ' : ', ';

				$init_page_min = ( $on_page > 4 ) ? $on_page : 5;
				$init_page_max = ( $on_page < $total_pages - 4 ) ? $on_page : $total_pages - 4;

				for($i = $init_page_min - 1; $i < $init_page_max + 2; $i++)
				{
					$page_string .= ($i == $on_page) ? '<b>' . $i . '</b>' : '<a href="' . append_sid($base_url . "&amp;start=" . ( ( $i - 1 ) * $per_page ) ) . '">' . $i . '</a>';
					if ( $i <  $init_page_max + 1 )
					{
						$page_string .= ', ';
					}
				}

				$page_string .= ( $on_page < $total_pages - 4 ) ? ' ... ' : ', ';
			}
			else
			{
				$page_string .= ' ... ';
			}

			for($i = $total_pages - 2; $i < $total_pages + 1; $i++)
			{
				$page_string .= ( $i == $on_page ) ? '<b>' . $i . '</b>'  : '<a href="' . append_sid($base_url . "&amp;start=" . ( ( $i - 1 ) * $per_page ) ) . '">' . $i . '</a>';
				if( $i <  $total_pages )
				{
					$page_string .= ", ";
				}
			}
		}
	}
	else
	{
		for($i = 1; $i < $total_pages + 1; $i++)
		{
			$page_string .= ( $i == $on_page ) ? '<b>' . $i . '</b>' : '<a href="' . append_sid($base_url . "&amp;start=" . ( ( $i - 1 ) * $per_page ) ) . '">' . $i . '</a>';
			if ( $i <  $total_pages )
			{
				$page_string .= ', ';
			}
		}
	}

	if ( $add_prevnext_text )
	{
		if ( $on_page > 1 )
		{
			$page_string = ' <a href="' . append_sid($base_url . "&amp;start=" . ( ( $on_page - 2 ) * $per_page ) ) . '">' . $lang['Previous'] . '</a>&nbsp;&nbsp;' . $page_string;
		}

		if ( $on_page < $total_pages )
		{
			$page_string .= '&nbsp;&nbsp;<a href="' . append_sid($base_url . "&amp;start=" . ( $on_page * $per_page ) ) . '">' . $lang['Next'] . '</a>';
		}

	}

	$page_string = ($page_string != '') ? $lang['Goto_page'] . ' ' . $page_string : '';

	return $page_string;
}

//
// This does exactly what preg_quote() does in PHP 4-ish
// If you just need the 1-parameter preg_quote call, then don't bother using this.
//
function phpbb_preg_quote($str, $delimiter)
{
	$text = preg_quote($str);
	$text = str_replace($delimiter, '\\' . $delimiter, $text);

	return $text;
}

//
// Obtain list of naughty words and build preg style replacement arrays for use by the
// calling script, note that the vars are passed as references this just makes it easier
// to return both sets of arrays
//
function obtain_word_list(&$orig_word, &$replacement_word)
{
	global $db;
	//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
	global $global_orig_word, $global_replacement_word;

	global $phpbb_root_path;
	if (isset($global_orig_word))
	{
		$orig_word			= $global_orig_word;
		$replacement_word	= $global_replacement_word;
	}
	else
	{
		if ( defined('CACHE_WORDS') )
		{
			$word_replacement = phpbb_data_cache_read($phpbb_root_path . 'cache/words.cache');
			$valid_words = is_array($word_replacement);
			if ($valid_words)
			{
				foreach ($word_replacement as $word => $replacement)
				{
					if ((!is_string($word) && !is_int($word)) || !is_string($replacement))
					{
						$valid_words = false;
						break;
					}
				}
			}
			if (!$valid_words)
			{
				$word_replacement = cache_words();
			}
		}
		if ( isset($word_replacement) )
		{
			$orig_word = array();
			$replacement_word = array();
			foreach ($word_replacement as $word => $replacement)
			{
				$orig_word[] = '#\b(' . str_replace('\*', '\w*?', preg_quote(stripslashes($word), '#')) . ')\b#i';
				$replacement_word[] = $replacement;
			}
		}
		else
		{
//-- fin mod : categories hierarchy ----------------------------------------------------------------

	//
	// Define censored word matches
	//
	$sql = "SELECT word, replacement
		FROM  " . WORDS_TABLE;
	if( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not get censored words from database', '', __LINE__, __FILE__, $sql);
	}

	if ( $row = $db->sql_fetchrow($result) )
	{
		do
		{
			$orig_word[] = '#\b(' . str_replace('\*', '\w*?', preg_quote($row['word'], '#')) . ')\b#i';
			$replacement_word[] = $row['replacement'];
		}
		while ( $row = $db->sql_fetchrow($result) );
	}
	$db->sql_freeresult($result);
	//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
		}
		$global_orig_word			= $orig_word;
		$global_replacement_word	= $replacement_word;
	}
//-- fin mod : categories hierarchy ----------------------------------------------------------------

	return true;
}

//
// This is general replacement for die(), allows templated
// output in users (or default) language, etc.
//
// $msg_code can be one of these constants:
//
// GENERAL_MESSAGE : Use for any simple text message, eg. results
// of an operation, authorisation failures, etc.
//
// GENERAL ERROR : Use for any error which occurs _AFTER_ the
// common.php include and session code, ie. most errors in
// pages/functions
//
// CRITICAL_MESSAGE : Used when basic config data is available but
// a session may not exist, eg. banned users
//
// CRITICAL_ERROR : Used when config data cannot be obtained, eg
// no database connection. Should _not_ be used in 99.5% of cases
//
function phpbb_debug_details_allowed()
{
	global $userdata;
	return defined('DEBUG') && DEBUG && defined('IN_ADMIN') && is_array($userdata)
		&& !empty($userdata['session_logged_in']) && isset($userdata['user_level'])
		&& intval($userdata['user_level']) === ADMIN;
}

function message_die($msg_code, $msg_text = '', $msg_title = '', $err_line = '', $err_file = '', $sql = '')
{
	global $db, $template, $board_config, $theme, $lang, $phpEx, $phpbb_root_path, $nav_links, $gen_simple_header, $images;
	global $userdata, $user_ip, $session_length;
	global $starttime, $plus_config;
	global $HTTP_COOKIE_VARS;
	//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
	global $tree;
//-- fin mod : categories hierarchy ----------------------------------------------------------------

//+MOD: Fix message_die for multiple errors MOD
	static $msg_history;
	if( !isset($msg_history) )
	{
		$msg_history = array();
	}
	$msg_history[] = array(
		'msg_code'	=> $msg_code,
		'msg_text'	=> $msg_text,
		'msg_title'	=> $msg_title,
		'err_line'	=> $err_line,
		'err_file'	=> $err_file,
		'sql'		=> $sql
	);
//-MOD: Fix message_die for multiple errors MOD

	if(defined('HAS_DIED'))
	{
		//+MOD: Fix message_die for multiple errors MOD

		//
		// This message is printed at the end of the report.
		// Of course, you can change it to suit your own needs. ;-)
		//
		$custom_error_message = 'Please, contact the %swebmaster%s. Thank you.';
		if ( !empty($board_config) && !empty($board_config['board_email']) )
		{
			$safe_board_email = htmlspecialchars((string) $board_config['board_email'], ENT_QUOTES, 'UTF-8');
			$custom_error_message = sprintf($custom_error_message, '<a href="mailto:' . $safe_board_email . '">', '</a>');
		}
		else
		{
			$custom_error_message = sprintf($custom_error_message, '', '');
		}
		echo "<html>\n<body>\n<b>Critical Error!</b><br />\nmessage_die() was called multiple times.<br />&nbsp;<hr />";
		if (phpbb_debug_details_allowed())
		{
			for( $i = 0; $i < count($msg_history); $i++ )
			{
				echo '<b>Error #' . ($i+1) . "</b>\n<br />\n";
				if( !empty($msg_history[$i]['msg_title']) )
				{
					echo '<b>' . $msg_history[$i]['msg_title'] . "</b>\n<br />\n";
				}
				echo $msg_history[$i]['msg_text'] . "\n<br /><br />\n";
				if( !empty($msg_history[$i]['err_line']) )
				{
					echo '<b>Line :</b> ' . intval($msg_history[$i]['err_line']) . '<br /><b>File :</b> ' . htmlspecialchars(basename($msg_history[$i]['err_file']), ENT_QUOTES, 'UTF-8') . "</b>\n<br />\n";
				}
				if( !empty($msg_history[$i]['sql']) )
				{
					echo '<b>SQL :</b> ' . htmlspecialchars($msg_history[$i]['sql'], ENT_QUOTES, 'UTF-8') . "\n<br />\n";
				}
				echo "&nbsp;<hr />\n";
			}
		}
		echo $custom_error_message . '<hr /><br clear="all">';
		die("</body>\n</html>");
//-MOD: Fix message_die for multiple errors MOD

	}

	define('HAS_DIED', 1);


	$sql_store = $sql;
	$debug_text = '';

	//
	// Get SQL error if we are debugging. Do this as soon as possible to prevent
	// subsequent queries from overwriting the status of sql_error()
	//
	if ( phpbb_debug_details_allowed() && ( $msg_code == GENERAL_ERROR || $msg_code == CRITICAL_ERROR ) )
	{
		$sql_error = $db->sql_error();

		if ( $sql_error['message'] != '' )
		{
			$debug_text .= '<br /><br />SQL Error : ' . $sql_error['code'] . ' ' . $sql_error['message'];
		}

		if ( $sql_store != '' )
		{
			$debug_text .= "<br /><br />$sql_store";
		}

		if ( $err_line != '' && $err_file != '' )
		{
			$debug_text .= '<br /><br />Line : ' . $err_line . '<br />File : ' . basename($err_file);
		}
	}

	if( empty($userdata) && ( $msg_code == GENERAL_MESSAGE || $msg_code == GENERAL_ERROR ) )
	{
		$userdata = session_pagestart($user_ip, PAGE_INDEX);
		init_userprefs($userdata);
	}

	//
	// If the header hasn't been output then do it
	//
	if ( !defined('HEADER_INC') && $msg_code != CRITICAL_ERROR )
	{
		if ( empty($lang) )
		{
			if ( !empty($board_config['default_lang']) )
			{
				include($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_main.'.$phpEx);
			}
			else
			{
				include($phpbb_root_path . 'language/lang_english/lang_main.'.$phpEx);
			}
			//-- mod : language settings -----------------------------------------------------------------------
//-- add
			include($phpbb_root_path . './includes/lang_extend_mac.' . $phpEx);
//-- fin mod : language settings -------------------------------------------------------------------

		}

		if ( empty($template) || empty($theme) )
		{
			$theme = setup_style($board_config['default_style']);
		}

		//
		// Load the Page Header
		//
		if ( !defined('IN_ADMIN') )
		{
			include($phpbb_root_path . 'includes/page_header.'.$phpEx);
		}
		else
		{
			include($phpbb_root_path . 'admin/page_header_admin.'.$phpEx);
		}
	}

	switch($msg_code)
	{
		case GENERAL_MESSAGE:
			if ( $msg_title == '' )
			{
				$msg_title = $lang['Information'];
			}
			break;

		case CRITICAL_MESSAGE:
			if ( $msg_title == '' )
			{
				$msg_title = $lang['Critical_Information'];
			}
			break;

		case GENERAL_ERROR:
			if ( $msg_text == '' )
			{
				$msg_text = $lang['An_error_occured'];
			}

			if ( $msg_title == '' )
			{
				$msg_title = $lang['General_Error'];
			}
			break;

		case CRITICAL_ERROR:
			//
			// Critical errors mean we cannot rely on _ANY_ DB information being
			// available so we're going to dump out a simple echo'd statement
			//
			include($phpbb_root_path . 'language/lang_english/lang_main.'.$phpEx);

			if ( $msg_text == '' )
			{
				$msg_text = $lang['A_critical_error'];
			}

			if ( $msg_title == '' )
			{
				$msg_title = 'phpBB : <b>' . $lang['Critical_Error'] . '</b>';
			}
			break;
	}

	//
	// Add on DEBUG info if we've enabled debug mode and this is an error. This
	// prevents debug info being output for general messages should DEBUG be
	// set TRUE by accident (preventing confusion for the end user!)
	//
	if ( phpbb_debug_details_allowed() && ( $msg_code == GENERAL_ERROR || $msg_code == CRITICAL_ERROR ) )
	{
		if ( $debug_text != '' )
		{
			$msg_text = $msg_text . '<br /><br /><b><u>DEBUG MODE</u></b>' . $debug_text;
		}
	}

	if ( $msg_code != CRITICAL_ERROR )
	{
		if ( !empty($lang[$msg_text]) )
		{
			$msg_text = $lang[$msg_text];
		}

		if ( !defined('IN_ADMIN') )
		{
			$template->set_filenames(array(
				'message_body' => 'message_body.tpl')
			);
		}
		else
		{
			$template->set_filenames(array(
				'message_body' => 'admin/admin_message_body.tpl')
			);
		}

		$template->assign_vars(array(
			'PLUS_VERSION' => $plus_config['plus_version'],
			'MESSAGE_TITLE' => $msg_title,
			'MESSAGE_TEXT' => $msg_text)
		);
		$template->pparse('message_body');

		if ( !defined('IN_ADMIN') )
		{
			include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
		}
		else
		{
			include($phpbb_root_path . 'admin/page_footer_admin.'.$phpEx);
		}
	}
	else
	{
		echo "<html>\n<body>\n" . $msg_title . "\n<br /><br />\n" . $msg_text . "</body>\n</html>";
	}

	exit;
}

//
// This function is for compatibility with PHP 4.x's realpath()
// function.  In later versions of PHP, it needs to be called
// to do checks with some functions.  Older versions of PHP don't
// seem to need this, so we'll just return the original value.
// dougk_ff7 <October 5, 2002>
function phpbb_realpath($path)
{
	global $phpbb_root_path, $phpEx;

	return (!@function_exists('realpath') || !@realpath($phpbb_root_path . 'includes/functions.'.$phpEx)) ? $path : @realpath($path);
}

function redirect($url)
{
	global $db, $board_config;

	if (!empty($db))
	{
		$db->sql_close();
	}

	$decoded_url = rawurldecode((string) $url);
	if (preg_match('/[\x00\r\n]/', $decoded_url) || stripos($decoded_url, ';url') !== false)
	{
		message_die(GENERAL_ERROR, 'Tried to redirect to potentially insecure url.');
	}

	$redirect_url = phpbb_board_url(ltrim(trim((string) $url), '/\\'));

	// Redirect via an HTML form for PITA webservers
	if (@preg_match('/Microsoft|WebSTAR|Xitami/', getenv('SERVER_SOFTWARE')))
	{
		header('Refresh: 0; URL=' . $redirect_url);
		$safe_redirect_url = htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8');
		echo '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta http-equiv="refresh" content="0; url=' . $safe_redirect_url . '"><title>Redirect</title></head><body><div align="center">If your browser does not support meta redirection please click <a href="' . $safe_redirect_url . '">HERE</a> to be redirected</div></body></html>';
		exit;
	}

	// Behave as per HTTP/1.1 spec for others
	header('Location: ' . $redirect_url);
	exit;
}
//--- Album Category Hierarchy : begin
//--- version : 1.1.0
//--- FLAG operation functions
function setFlag($flags, $flag)
{
	return $flags | $flag;
}
function clearFlag($flags, $flag)
{
	return ($flags & ~$flag);
}
function checkFlag($flags, $flag)
{
	return (($flags & $flag) == $flag) ? true : false;
}
//--- Album Category Hierarchy : end

// Add function mkrealdate for Birthday MOD
// the originate php "mktime()", does not work proberly on all OS, especially when going back in time
// before year 1970 (year 0), this function "mkrealtime()", has a mutch larger valid date range,
// from 1901 - 2099. it returns a "like" UNIX timestamp divided by 86400, so
// calculation from the originate php date and mktime is easy.
// mkrealdate, returns the number of day (with sign) from 1.1.1970.

function mkrealdate($day,$month,$birth_year)
{
	$day = (int) $day;
	$month = (int) $month;
	$birth_year = (int) $birth_year;
	$epoch = 0;
	if ($day < 1 || $birth_year < 1901 || $birth_year > 2099) return "error";
	// range check months
	if ($month<1 || $month>12) return "error";
	// range check days
	switch ($month)
	{
		case 1: if ($day>31) return "error";break;
		case 2: if ($day>29) return "error";
			$epoch=$epoch+31;break;
		case 3: if ($day>31) return "error";
			$epoch=$epoch+59;break;
		case 4: if ($day>30) return "error" ;
			$epoch=$epoch+90;break;
		case 5: if ($day>31) return "error";
			$epoch=$epoch+120;break;
		case 6: if ($day>30) return "error";
			$epoch=$epoch+151;break;
		case 7: if ($day>31) return "error";
			$epoch=$epoch+181;break;
		case 8: if ($day>31) return "error";
			$epoch=$epoch+212;break;
		case 9: if ($day>30) return "error";
			$epoch=$epoch+243;break;
		case 10: if ($day>31) return "error";
			$epoch=$epoch+273;break;
		case 11: if ($day>30) return "error";
			$epoch=$epoch+304;break;
		case 12: if ($day>31) return "error";
			$epoch=$epoch+334;break;
	}
	$epoch=$epoch+$day;
	$epoch_Y=sqrt(($birth_year-1970)*($birth_year-1970));
	$leapyear=round((($epoch_Y+2) / 4)-.5);
	if (($epoch_Y+2)%4==0)
	{// curent year is leapyear
		$leapyear--;
		if ($birth_year >1970 && $month>=3) $epoch=$epoch+1;
		if ($birth_year <1970 && $month<3) $epoch=$epoch-1;
	} else if ($month==2 && $day>28) return "error";//only 28 days in feb.
	//year
	if ($birth_year>1970)
		$epoch=$epoch+$epoch_Y*365-1+$leapyear;
	else
		$epoch=$epoch-$epoch_Y*365-1-$leapyear;
	return $epoch;
}

// Add function realdate for Birthday MOD
// the originate php "date()", does not work proberly on all OS, especially when going back in time
// before year 1970 (year 0), this function "realdate()", has a mutch larger valid date range,
// from 1901 - 2099. it returns a "like" UNIX date format (only date, related letters may be used, due to the fact that
// the given date value should already be divided by 86400 - leaving no time information left)
// a input like a UNIX timestamp divided by 86400 is expected, so
// calculation from the originate php date and mktime is easy.
// e.g. realdate ("m d Y", 3) returns the string "1 3 1970"

// UNIX users should replace this function with the below code, since this should be faster
//
//function realdate($date_syntax="Ymd",$date=0)
//{ return create_date($date_syntax,$date*86400+1,0); }

function realdate($date_syntax="Ymd",$date=0)
{
	global $lang;
	$i=2;
	if ($date>=0)
	{
	 	return create_date($date_syntax,$date*86400+1,0);
	} else
	{
		// Convert whole days relative to 1970-01-01 to a Gregorian date without
		// relying on platform support for negative Unix timestamps.
		$absolute_day = (int) floor($date);
		$shifted_day = $absolute_day + 719468;
		$era = (int) floor($shifted_day / 146097);
		$day_of_era = $shifted_day - ($era * 146097);
		$year_of_era = (int) floor(($day_of_era - floor($day_of_era / 1460) +
			floor($day_of_era / 36524) - floor($day_of_era / 146096)) / 365);
		$civil_year = $year_of_era + ($era * 400);
		$day_of_year_march = $day_of_era - (365 * $year_of_era +
			floor($year_of_era / 4) - floor($year_of_era / 100));
		$month_march = (int) floor((5 * $day_of_year_march + 2) / 153);
		$day = $day_of_year_march - (int) floor((153 * $month_march + 2) / 5) + 1;
		$month = $month_march + (($month_march < 10) ? 3 : -9);
		$civil_year += ($month <= 2) ? 1 : 0;
		$year = $civil_year - 1970;
	}
	$leap_year = (($civil_year % 4 == 0) && ($civil_year % 100 != 0 || $civil_year % 400 == 0));
	$months_array = ($leap_year) ?
		array (0,31,60,91,121,152,182,213,244,274,305,335,366) :
		array (0,31,59,90,120,151,181,212,243,273,304,334,365);
	$days = $months_array[$month-1] + $day - 1;
	//you may gain speed performance by remove som of the below entry's if they are not needed/used
	$weekday = (($date - 3) % 7 + 7) % 7;
	return strtr ($date_syntax, array(
		'a' => '',
		'A' => '',
		'\\d' => 'd',
		'd' => ($day>9) ? $day : '0'.$day,
		'\\D' => 'D',
		'D' => $lang['day_short'][$weekday],
		'\\F' => 'F',
		'F' => $lang['month_long'][$month-1],
		'g' => '',
		'G' => '',
		'H' => '',
		'h' => '',
		'i' => '',
		'I' => '',
		'\\j' => 'j',
		'j' => $day,
		'\\l' => 'l',
		'l' => $lang['day_long'][$weekday],
		'\\L' => 'L',
		'L' => $leap_year,
		'\\m' => 'm',
		'm' => ($month>9) ? $month : '0'.$month,
		'\\M' => 'M',
		'M' => $lang['month_short'][$month-1],
		'\\n' => 'n',
		'n' => $month,
		'O' => '',
		's' => '',
		'S' => '',
		'\\t' => 't',
		't' => $months_array[$month]-$months_array[$month-1],
		'w' => '',
		'\\y' => 'y',
		'y' => ($year>29) ? $year-30 : $year+70,
		'\\Y' => 'Y',
		'Y' => $year+1970,
		'\\z' => 'z',
		'z' => $days,
		'\\W' => '',
		'W' => '') );
}
// End add - Birthday MOD
// Start add - Last visit MOD
function make_hours($base_time)
{
	global $lang;
	$base_time = intval($base_time);
	$years = floor($base_time/31536000);
	$base_time = $base_time - ($years*31536000);
	$weeks = floor($base_time/604800);
	$base_time = $base_time - ($weeks*604800);
	$days = floor($base_time/86400);
	$base_time = $base_time - ($days*86400);
	$hours = floor($base_time/3600);
	$base_time = $base_time - ($hours*3600);
	$min = floor($base_time/60);
	$sek = $base_time - ($min*60);
	if ($sek<10) $sek ='0'.$sek;
	if ($min<10) $min ='0'.$min;
	if ($hours<10) $hours ='0'.$hours;
	$result=(($years)?$years.' '.(($years==1)?$lang['Year']:$lang['Years']).', ':'').
	(($years || $weeks)?$weeks.' '.(($weeks==1)?$lang['Week']:$lang['Weeks']).', ':'').
	(($years || $weeks || $days) ? $days.' '.(($days==1)?$lang['Day']:$lang['Days']).', ':'').
	(($hours)?$hours.':':'00:').(($min)?$min.':' :'00:').$sek;
	return ($result)?$result:$lang['None'];
}
// End add - Last visit MOD
function create_absence_mode($absence_mode, &$pm_img, &$pm, &$email_img, &$email, &$username, $absent_button = 0)
{
	global $lang, $board_config, $images, $userdata;

	if ( !$userdata['user_lang'] )
	{
		$userdata['user_lang'] = $board_config['default_lang'];
	}

	$button_pos = ( $absent_button == 0 ) ? $board_config['absent_button'] : $absent_button;
	$button_pos = ( $absent_button == 2 ) ? 0 : $button_pos;

	switch($absence_mode)
	{
		case 1:
			$absence_img = $images['On_holidays'];
			break;

		case 2:
			$absence_img = $images['User_ill'];
			break;

		case 3:
			$absence_img = $images['Longer_absenct'];
			break;

		default:
			$absence_img = '';
	}

	$absence_img = str_replace('{LANG}', 'lang_'.$userdata['user_lang'], $absence_img);
	$absence_mode = ( $absence_img != '' ) ? '<img src='.$absence_img.' border=0 />' : '';

	$allow_send = allow_send_to_absent();

	if ( $allow_send == FALSE )
	{
		$pm_img = '';
		$pm = '';
		$email_img = ( $button_pos == 0 ) ? ' '.$absence_mode : '';
		$email = '';
	}
	else
	{
		$email_img = ' '.str_replace($images['icon_email'], $absence_img, $email_img);
	}

	if ( $username != '' )
	{
		$username = $username . (($button_pos == 1 && $allow_send == FALSE) ? '<br />'.$absence_mode : '');
	}

	return $absence_mode;
}

function allow_send_to_absent()
{
	global $userdata;
	switch ( $userdata['user_level'] )
	{
		case ADMIN:
			$allow_send = TRUE;
			break;
		case MOD:
			$allow_send = ( $board_config['mod_able_sent_absent'] == TRUE ) ? TRUE : FALSE;
			break;
		default:
			$allow_send = FALSE;
			break;
	}
	return $allow_send;
}

function check_avatar_size($avatar, $max_avatar_size, $remote = FALSE)
{
	if ( $remote )
	{
		$max_avatar_size = max(1, intval($max_avatar_size));
		return 'style="max-width: ' . $max_avatar_size . 'px; max-height: ' . $max_avatar_size . 'px;"';
	}

	$pic_size = @getimagesize($avatar);
	if ( $pic_size !== FALSE )
	{
		$pic_width = $pic_size[0];
		$pic_height = $pic_size[1];

		if ( $pic_width > $max_avatar_size )
		{
			if ($pic_width > $pic_height)
			{
				$width = $max_avatar_size;
				$height = $max_avatar_size * ($pic_height/$pic_width);
			}
			else
			{
				$height = $max_avatar_size;
				$width = $max_avatar_size * ($pic_width/$pic_height);
			}

			$size = 'width="'.$width.'" height="'.$height.'"';
		}
		else
		{
			$size = '';
		}
	}
	else
	{
		$size = '';
	}

	return $size;
}

function AJAX_headers()
{
	//No caching whatsoever
	header('Content-Type: application/xml; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
	header('X-Frame-Options: SAMEORIGIN');
	header('Expires: Thu, 15 Aug 1984 13:30:00 GMT');
	header('Last-Modified: '. gmdate('D, d M Y H:i:s') .' GMT');
	header('Cache-Control: no-cache, must-revalidate');  // HTTP/1.1
	header('Pragma: no-cache');                          // HTTP/1.0
}

function AJAX_message_die($data_ar)
{
	global $template, $db;

	if (!headers_sent())
	{
		AJAX_headers();
	}

	$template->set_filenames(array(
		'ajax_result' => 'ajax_result.tpl')
	);

	foreach($data_ar as $key => $value)
	{
		if ($value !== '')
		{
			$value = htmlspecialchars($value, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');
			// Get special characters in posts back ;)
			$value = preg_replace('#&amp;\#(\d{1,4});#i', '&#\1;', $value);

			$template->assign_block_vars('tag', array(
				'TAGNAME' => $key,
				'VALUE' => $value)
			);
		}
	}

	$template->pparse('ajax_result');

	$db->sql_close();
	exit;
}

// This function is taken from includes/bbcode.php and renamed
// We need this in special occasions
function unhtmlspecialchars($text)
{
	$text = preg_replace("/&gt;/i", ">", $text);
	$text = preg_replace("/&lt;/i", "<", $text);
	$text = preg_replace("/&quot;/i", "\"", $text);
	$text = preg_replace("/&amp;/i", "&", $text);

	return $text;
}

/**
* Normalize an AJAX form value while retaining phpBB2's historic slashed-input
* convention. URL decoding has already been performed by PHP before this code
* runs; decoding percent sequences a second time would corrupt literal values
* such as "%C3%A4" and was the reason the old escape()-based client corrupted
* UTF-8 text.
*/
function utf8_rawurldecode($source)
{
	return addslashes(stripslashes((string) $source));
}

// Used to escape AJAX data correctly.
// functions_post.php must be included before calling this function
function ajax_htmlspecialchars($text)
{
	global $html_entities_match, $html_entities_replace;

	return preg_replace($html_entities_match, $html_entities_replace, $text);
}

/**
 * Accept a complete profile/share URL only when it belongs to the service in
 * question. This keeps user-supplied profile data from becoming a javascript:
 * link or an apparently trusted link to an unrelated host.
 */
function phpbb_social_profile_allowed_url($value, $allowed_hosts)
{
	$value = html_entity_decode(trim((string) $value), ENT_QUOTES, 'UTF-8');
	if (!preg_match('~^https?://~i', $value) && preg_match('~^(?:www\.)?[a-z0-9.-]+/~i', $value))
	{
		$value = 'https://' . $value;
	}
	if (!preg_match('~^https?://~i', $value))
	{
		return '';
	}

	$parts = @parse_url($value);
	if (!is_array($parts) || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass']))
	{
		return '';
	}
	$host = strtolower(rtrim($parts['host'], '.'));
	foreach ($allowed_hosts as $allowed_host)
	{
		$allowed_host = strtolower($allowed_host);
		if ($host === $allowed_host || substr($host, -strlen('.' . $allowed_host)) === '.' . $allowed_host)
		{
			return preg_replace('~^http://~i', 'https://', $value);
		}
	}

	return '';
}

/**
 * Build a safe contact URL from a service-specific account name, ID or share
 * URL. Signal usernames intentionally have no derivable public URL; Signal's
 * opaque share link must be copied from the app if a clickable link is wanted.
 */
function phpbb_social_profile_url($service, $value, $allowed_hosts)
{
	$complete_url = phpbb_social_profile_allowed_url($value, $allowed_hosts);
	if ($complete_url !== '')
	{
		return $complete_url;
	}

	$value = trim(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
	$value = ltrim($value, '@');
	if ($value === '')
	{
		return '';
	}

	switch ($service)
	{
		case 'FB':
			return 'https://www.facebook.com/' . rawurlencode($value);
		case 'IG':
			return 'https://www.instagram.com/' . rawurlencode($value);
		case 'TWR':
			return 'https://x.com/' . rawurlencode($value);
		case 'TG':
			return 'https://t.me/' . rawurlencode($value);
		case 'LI':
			$value = preg_replace('~^in/~i', '', $value);
			return 'https://www.linkedin.com/in/' . rawurlencode($value);
		case 'TT':
			return 'https://www.tiktok.com/@' . rawurlencode($value);
		case 'DC':
			return preg_match('/^[0-9]{5,30}$/', $value) ? 'https://discord.com/users/' . $value : '';
		case 'THREEMA':
			$value = strtoupper($value);
			return preg_match('/^[A-Z0-9]{8}$/', $value) ? 'https://threema.id/' . $value : '';
		case 'SIGNAL':
		default:
			return '';
	}
}

/**
 * Build the current contact/profile links. Retired messenger columns remain in
 * the database so historic user data is not destroyed, but are deliberately
 * no longer rendered here.
 */
function phpbb_social_profile_links($row)
{
	global $images, $lang;

	$definitions = array(
		'FB'      => array('user_fb',      array('facebook.com')),
		'IG'      => array('user_ig',      array('instagram.com')),
		'TWR'     => array('user_twr',     array('x.com', 'twitter.com')),
		'TG'      => array('user_tg',      array('t.me', 'telegram.me')),
		'LI'      => array('user_li',      array('linkedin.com')),
		'TT'      => array('user_tt',      array('tiktok.com')),
		'DC'      => array('user_dc',      array('discord.com', 'discordapp.com')),
		'SIGNAL'  => array('user_signal',  array('signal.me', 'signal.link')),
		'THREEMA' => array('user_threema', array('threema.id'))
	);
	$links = array(
		'PT' => '', 'PT_IMG' => '', 'SKP' => '', 'SKP_IMG' => '', 'PROFILE_ROWS' => ''
	);
	$rows = array();

	foreach ($definitions as $name => $definition)
	{
		$field = $definition[0];
		$value = isset($row[$field]) ? trim((string) $row[$field]) : '';
		$label = isset($lang[$name]) ? $lang[$name] : $name;
		$links[$name] = '';
		$links[$name . '_IMG'] = '';

		if ($value === '')
		{
			continue;
		}

		$plain_value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
		$escaped_value = htmlspecialchars($plain_value, ENT_QUOTES, 'UTF-8');
		$escaped_label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
		$provided_url = phpbb_social_profile_allowed_url($plain_value, $definition[1]);
		$url = phpbb_social_profile_url($name, $plain_value, $definition[1]);
		$button = '';
		$image_key = 'icon_' . strtolower($name);
		if ($url !== '')
		{
			$escaped_url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
			$links[$name] = '<a href="' . $escaped_url . '" target="_blank" rel="noopener noreferrer">' . $escaped_label . '</a>';
			if (!empty($images[$image_key]))
			{
				$title = $escaped_label . ': ' . $escaped_value;
				$image = '<img src="' . $images[$image_key] . '" alt="' . $escaped_label . '" title="' . $title . '" border="0" />';
				$button = '<a href="' . $escaped_url . '" target="_blank" rel="noopener noreferrer">' . $image . '</a>';
				$links[$name . '_IMG'] = $button . '&nbsp;';
			}
			else
			{
				$links[$name . '_IMG'] = '<a href="' . $escaped_url . '" target="_blank" rel="noopener noreferrer" title="' . $escaped_label . '"><span class="gensmall">' . $escaped_label . '</span></a>&nbsp;';
			}
			$display_text = ($provided_url !== '') ? $escaped_label : $escaped_value;
			$display = '<a href="' . $escaped_url . '" target="_blank" rel="noopener noreferrer">' . $display_text . '</a>';
		}
		else
		{
			$links[$name] = $escaped_value;
			$display = $escaped_value;
			if (!empty($images[$image_key]))
			{
				$title = $escaped_label . ': ' . $escaped_value;
				$image = '<img src="' . $images[$image_key] . '" alt="' . $escaped_label . '" title="' . $title . '" border="0" />';
				$button = '<span title="' . $title . '">' . $image . '</span>';
				$links[$name . '_IMG'] = $button . '&nbsp;';
			}
		}
		if ($button !== '')
		{
			$display = $button . (($url === '') ? '&nbsp;' . $escaped_value : '');
		}
		$rows[] = '<tr><td align="right" nowrap="nowrap" class="explaintitle">' . $escaped_label . ':</td><td class="genmed">' . $display . '</td></tr>';
	}

	$links['PROFILE_ROWS'] = implode("\n", $rows);
	return $links;
}

?>
