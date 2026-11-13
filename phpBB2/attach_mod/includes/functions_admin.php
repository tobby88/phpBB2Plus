<?php
/** 
*
* @package attachment_mod
* @version $Id: functions_admin.php,v 1.4 2006/04/22 16:21:09 acydburn Exp $
* @copyright (c) 2002 Meik Sievertsen
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/

/**
* All Attachment Functions only needed in Admin
*/

// Upload diagnostics must only remove files created by that diagnostic run.
function attach_admin_probe_error($key, $detail = '')
{
	global $lang;
	return sprintf($lang[$key], htmlspecialchars((string) $detail, ENT_QUOTES, 'UTF-8')) . '<br />';
}

function attach_admin_probe_local($directory, $create = false)
{
	if ($directory === '' || strpos($directory, "\0") !== false || preg_match('#^[a-z][a-z0-9+.-]*://#i', $directory))
	{
		return attach_admin_probe_error('Directory_does_not_exist', $directory);
	}
	if (!is_dir($directory) && $create) { @mkdir($directory, 0775); }
	if (!is_dir($directory)) { return attach_admin_probe_error('Directory_does_not_exist', $directory); }
	$directory = realpath($directory);
	if ($directory === false) { return attach_admin_probe_error('Attachment_test_temporary_failed'); }
	$handle = false; $owned_file = ''; $error = '';
	try
	{
		for ($attempt = 0; $attempt < 3; $attempt++)
		{
			$candidate = $directory . '/.phpbb-test-' . bin2hex(phpbb_random_bytes(16)) . '.tmp';
			$handle = @fopen($candidate, 'x+b');
			if ($handle !== false) { $owned_file = $candidate; break; }
		}
		if ($handle === false || @fwrite($handle, 'test') !== 4 || !@fflush($handle))
		{
			$error = attach_admin_probe_error('Directory_not_writeable', $directory);
		}
	}
	catch (Exception $exception) { $error = attach_admin_probe_error('Attachment_test_temporary_failed'); }
	catch (Error $exception) { $error = attach_admin_probe_error('Attachment_test_temporary_failed'); }
	finally
	{
		if (is_resource($handle)) { fclose($handle); }
		if ($owned_file !== '' && !@unlink($owned_file))
		{
			$error .= attach_admin_probe_error('Attachment_test_cleanup_failed', $owned_file);
		}
	}
	return $error;
}

function attach_admin_probe_ftp($config, $thumbnail = false)
{
	foreach (array('ftp_connect', 'ftp_login', 'ftp_pasv', 'ftp_chdir', 'ftp_mkdir', 'ftp_nlist', 'ftp_fput', 'ftp_delete', 'ftp_close') as $function)
	{
		if (!function_exists($function)) { return attach_admin_probe_error('Attachment_test_ftp_unavailable'); }
	}
	$server = trim($config['ftp_server']); $server = $server === '' ? 'localhost' : $server;
	$path = trim($config['ftp_path']); $path = $path === '' ? '.' : $path;
	foreach (array($server, $path, $config['ftp_user'], $config['ftp_pass']) as $value)
	{
		if (preg_match('/[\x00\r\n]/', $value)) { return attach_admin_probe_error('Attachment_test_ftp_invalid'); }
	}
	$connection = false; $stream = false; $attempted_file = ''; $error = '';
	try
	{
		do
		{
			$connection = @ftp_connect($server, 21, 10);
			if ($connection === false) { $error = attach_admin_probe_error('Ftp_error_connect', $server); break; }
			if (!@ftp_login($connection, $config['ftp_user'], $config['ftp_pass']))
			{
				$error = attach_admin_probe_error('Ftp_error_login', $config['ftp_user']); break;
			}
			if (!@ftp_pasv($connection, (bool) $config['ftp_pasv_mode']))
			{
				$error = attach_admin_probe_error('Ftp_error_pasv_mode'); break;
			}
			if (!@ftp_chdir($connection, $path)) { $error = attach_admin_probe_error('Ftp_error_path', $path); break; }
			if ($thumbnail)
			{
				$path = rtrim($path, '/') . '/' . THUMB_DIR;
				if (!@ftp_chdir($connection, THUMB_DIR))
				{
					@ftp_mkdir($connection, THUMB_DIR);
					if (!@ftp_chdir($connection, THUMB_DIR)) { $error = attach_admin_probe_error('Ftp_error_path', $path); break; }
				}
			}
			// FTP STOR is not exclusive. Use an unpredictable name and check the
			// listing for collisions; never fall back to a shared fixed filename.
			$listing = @ftp_nlist($connection, '.');
			if (!is_array($listing)) { $error = attach_admin_probe_error('Attachment_test_ftp_listing', $path); break; }
			$existing = array();
			foreach ($listing as $entry) { $existing[strtolower(basename(str_replace('\\', '/', rtrim($entry, '/'))))] = true; }
			$name = '';
			for ($attempt = 0; $attempt < 3; $attempt++)
			{
				$candidate = '.phpbb-test-' . bin2hex(phpbb_random_bytes(16)) . '.tmp';
				if (!isset($existing[$candidate])) { $name = $candidate; break; }
			}
			if ($name === '') { $error = attach_admin_probe_error('Attachment_test_temporary_failed'); break; }
			$stream = @fopen('php://temp', 'w+b');
			if ($stream === false || @fwrite($stream, 'test') !== 4 || !@rewind($stream))
			{
				$error = attach_admin_probe_error('Attachment_test_temporary_failed'); break;
			}
			$attempted_file = $name;
			if (!@ftp_fput($connection, $name, $stream, FTP_BINARY)) { $error = attach_admin_probe_error('Ftp_error_upload', $path); }
		}
		while (false);
	}
	catch (Exception $exception) { $error = attach_admin_probe_error('Attachment_test_temporary_failed'); }
	catch (Error $exception) { $error = attach_admin_probe_error('Attachment_test_temporary_failed'); }
	finally
	{
		if (is_resource($stream)) { fclose($stream); }
		if ($connection !== false)
		{
			try
			{
				// A failed transfer can still leave a partial file. Try cleanup in
				// that case too, and disclose when deletion cannot be confirmed.
				if ($attempted_file !== '' && !@ftp_delete($connection, $attempted_file))
				{
					$error .= attach_admin_probe_error('Attachment_test_cleanup_failed', rtrim($path, '/') . '/' . $attempted_file);
				}
			}
			catch (Exception $exception) { $error .= attach_admin_probe_error('Attachment_test_cleanup_failed', rtrim($path, '/') . '/' . $attempted_file); }
			catch (Error $exception) { $error .= attach_admin_probe_error('Attachment_test_cleanup_failed', rtrim($path, '/') . '/' . $attempted_file); }
			finally { @ftp_close($connection); }
		}
	}
	return $error;
}

function attach_admin_test_settings($config, $root, $thumbnail = false)
{
	if ($thumbnail && !intval($config['img_create_thumbnail'])) { return ''; }
	if (intval($config['allow_ftp_upload'])) { return attach_admin_probe_ftp($config, $thumbnail); }
	$directory = str_replace('\\', '/', $config['upload_dir']);
	if ($directory === '' || strpos($directory, "\0") !== false || preg_match('#^[a-z][a-z0-9+.-]*://#i', $directory))
	{
		return attach_admin_probe_error('Directory_does_not_exist', $directory);
	}
	if ($directory[0] !== '/' && !preg_match('#^[a-z]:/#i', $directory)) { $directory = $root . $directory; }
	if ($thumbnail) { $directory = rtrim($directory, '/') . '/' . THUMB_DIR; }
	return attach_admin_probe_local($directory, $thumbnail);
}

/**
* Set/Change Quotas
*/
function process_quota_settings($mode, $id, $quota_type, $quota_limit_id = 0)
{
	global $db;
	// Compatibility entry point for bundled/third-party ACP integrations.
	// Normal form saves submit both limits to the writer in a single call.
	require_once dirname(__FILE__) . '/functions_quota_storage.php';
	try
	{
		if (!(is_int($quota_limit_id) || is_string($quota_limit_id))) { phpbb_acl_error('Board_config_invalid'); }
		phpbb_attach_quota_assign($db, $mode, $id, array((int)$quota_type=>(string)$quota_limit_id), $_POST);
	}
	catch (PhpbbAclException $exception) { message_die(GENERAL_ERROR, $exception->getMessage()); }
}

/**
* sort multi-dimensional Array
*/
function sort_multi_array ($sort_array, $key, $sort_order, $pre_string_sort = 0) 
{
	if (!is_array($sort_array) || count($sort_array) < 2)
	{
		return is_array($sort_array) ? $sort_array : array();
	}

	usort($sort_array, function ($left, $right) use ($key, $sort_order, $pre_string_sort) {
		$left_value = isset($left[$key]) ? $left[$key] : '';
		$right_value = isset($right[$key]) ? $right[$key] : '';
		$string_sort = $pre_string_sort || !is_numeric($left_value) || !is_numeric($right_value);
		$comparison = $string_sort ? strcasecmp((string) $left_value, (string) $right_value) : (($left_value == $right_value) ? 0 : (($left_value < $right_value) ? -1 : 1));
		return ($sort_order == 'DESC') ? -$comparison : $comparison;
	});

	return $sort_array;
}

// A valid post OR PM reference keeps a description in use. Zero placeholders
// must not reuse the preceding query or count as an existing message.
function entry_exists($attach_id, $database = null)
{
	$db = $database === null ? $GLOBALS['db'] : $database;
	if ((int) $attach_id <= 0) { return false; }
	$sql = 'SELECT a.attach_id FROM ' . ATTACHMENTS_TABLE . ' a
		LEFT JOIN ' . POSTS_TABLE . ' p ON p.post_id = a.post_id AND a.post_id > 0
		LEFT JOIN ' . PRIVMSGS_TABLE . ' m ON m.privmsgs_id = a.privmsgs_id AND a.privmsgs_id > 0
		WHERE a.attach_id = ' . (int) $attach_id . ' AND (p.post_id IS NOT NULL OR m.privmsgs_id IS NOT NULL) LIMIT 1';
	$result = $db->sql_query($sql);
	if (!$result) { message_die(GENERAL_ERROR, 'Could not check attachment references'); }
	$exists = $db->sql_numrows($result) > 0;
	$db->sql_freeresult($result);
	return $exists;
}

/**
* Collect all Attachments in Filesystem
*/
function attach_inventory_file($name)
{
	$name = strtolower($name);
	return !in_array($name, array('index.php', '.htaccess', '.htpasswd'), true) && strpos($name, '.phpbb-test-') !== 0;
}

function collect_attachments()
{
	global $lang;
	$entries = attach_storage_file_entries();
	if ($entries === false) { message_die(GENERAL_ERROR, $lang['Attachment_listing_failed']); }
	$files = array();
	foreach ($entries as $entry) { if (attach_inventory_file($entry['name'])) { $files[] = $entry['name']; } }
	return $files;
}

function attach_shadow_selected_files($posted)
{
	global $lang;
	if (!is_array($posted)) { message_die(GENERAL_ERROR, $lang['Attachment_selection_invalid']); }
	if (!$posted) { return array(); }
	$available = collect_attachments(); $selected = array();
	foreach ($posted as $value)
	{
		// common.php added slashes, but names are not HTML and must not be
		// trimmed: a different file may exist without those spaces/characters.
		$name = is_string($value) ? stripslashes($value) : '';
		if (attach_ftp_listing_entry($name, '0') === false || !in_array($name, $available, true))
		{
			message_die(GENERAL_ERROR, $lang['Attachment_selection_invalid']);
		}
		if (!in_array($name, $selected, true)) { $selected[] = $name; }
	}
	return $selected;
}

/**
* Returns the filesize of the upload directory in human readable format
*/
function get_formatted_dirsize()
{
	global $lang;
	$entries = attach_storage_file_entries();
	if ($entries === false) { return $lang['Not_available']; }
	$upload_dir_size = 0;
	foreach ($entries as $entry) { if (attach_inventory_file($entry['name'])) { $upload_dir_size += $entry['size']; } }

	if ($upload_dir_size >= 1048576)
	{
		$upload_dir_size = round($upload_dir_size / 1048576 * 100) / 100 . ' ' . $lang['MB'];
	}
	else if ($upload_dir_size >= 1024)
	{
		$upload_dir_size = round($upload_dir_size / 1024 * 100) / 100 . ' ' . $lang['KB'];
	}
	else
	{
		$upload_dir_size = $upload_dir_size . ' ' . $lang['Bytes'];
	}

	return $upload_dir_size;
}

/*
* Build SQL-Statement for the search feature
*/
function attach_admin_search_fields()
{
	return array('search_keyword_fname','search_keyword_comment','search_author','search_size_smaller','search_size_greater','search_count_smaller','search_count_greater','search_days_greater','search_forum','search_time');
}
function attach_admin_search_invalid()
{
	global $lang; message_die(GENERAL_MESSAGE, $lang['Attachment_search_invalid']);
}
function attach_admin_search_values($values)
{
	if (!is_array($values)) { attach_admin_search_invalid(); }
	$out = array();
	foreach (attach_admin_search_fields() as $field)
	{
		$value = isset($values[$field]) ? $values[$field] : '';
		if (!is_string($value) || strlen($value) > 2048 || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value)) { attach_admin_search_invalid(); }
		$value = trim($value);
		if (in_array($field, array('search_keyword_fname','search_keyword_comment','search_author'), true))
		{
			if (preg_match_all('/./us', $value, $characters) > ($field === 'search_author' ? 25 : 512)) { attach_admin_search_invalid(); }
		}
		else if ($value !== '')
		{
			$max = strpos($field, 'size_') !== false ? 2147483647 : ($field === 'search_days_greater' ? 1000000 : ($field === 'search_time' ? 9999999999.0 : 16777215));
			if (preg_match('/^[0-9]{1,10}$/D', $value) !== 1 || (float) $value > $max) { attach_admin_search_invalid(); }
			$value = ltrim($value, '0'); if ($value === '') { $value = '0'; }
		}
		$out[$field] = $value;
	}
	if ($out['search_days_greater'] === '') { $out['search_time'] = ''; }
	else if ($out['search_time'] === '') { $out['search_time'] = (string) time(); }
	$wire_length = 0;
	foreach ($out as $field => $value) { if ($value !== '') { $wire_length += strlen($field) + strlen(rawurlencode($value)) + 2; } }
	if ($wire_length > 6000) { attach_admin_search_invalid(); }
	return $out;
}
function attach_admin_search_input($fresh = false)
{
	global $HTTP_POST_VARS, $HTTP_GET_VARS;
	$values = array();
	foreach (attach_admin_search_fields() as $field)
	{
		$value = isset($HTTP_POST_VARS[$field]) ? $HTTP_POST_VARS[$field] : (isset($HTTP_GET_VARS[$field]) ? $HTTP_GET_VARS[$field] : '');
		$values[$field] = phpbb_request_raw_value($value);
	}
	if ($fresh) { $values['search_time'] = ''; }
	return attach_admin_search_values($values);
}
function attach_admin_search_hidden($values)
{
	$values = attach_admin_search_values($values);
	$html = '<input type="hidden" name="searching" value="1" />';
	foreach ($values as $field => $value)
	{
		if ($value !== '') { $html .= '<input type="hidden" name="' . $field . '" value="' . phpbb_profile_text($value) . '" />'; }
	}
	return $html;
}
function attach_admin_search_query($values, $html = true)
{
	$values = attach_admin_search_values($values);
	$separator = $html ? '&amp;' : '&'; $query = $separator . 'searching=1';
	foreach ($values as $field => $value) { if ($value !== '') { $query .= $separator . $field . '=' . rawurlencode($value); } }
	return $query;
}
function attach_admin_search_pattern($database, $column, $value)
{
	if (!in_array($column, array('a.real_filename','a.comment'), true)) { attach_admin_search_invalid(); }
	$parts = array();
	foreach (array(ENT_QUOTES, ENT_COMPAT) as $flags)
	{
		$pattern = htmlspecialchars($value, $flags, 'UTF-8');
		$pattern = str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), $pattern);
		$pattern = str_replace('*', '%', $pattern);
		$parts[$pattern] = $column . " LIKE '" . $database->sql_escape($pattern) . "' ESCAPE '!'";
	}
	return '(' . implode(' OR ', array_values($parts)) . ')';
}
function search_attachments($order_by, &$total_rows, $values = null, &$effective_start = null)
{
	global $db, $lang;
	// Keep the old two-argument helper available, but accept only its known
	// column/direction/LIMIT grammar. Rebuild a stable order after counting.
	if (!is_string($order_by) || preg_match('/^ORDER BY a\.(real_filename|comment|extension|filesize|download_count|filetime|attach_id) (ASC|DESC)(?:, a\.attach_id \2)? LIMIT ([0-9]+),\s*([0-9]+)$/D', trim($order_by), $sort) !== 1
		|| strlen($sort[3]) > 7 || (float)$sort[3] > 1000000 || strlen($sort[4]) > 4 || (int)$sort[4] < 1 || (int)$sort[4] > 1000) { attach_admin_search_invalid(); }
	$start = (int)$sort[3]; $size = (int)$sort[4]; $values = $values === null ? attach_admin_search_input() : attach_admin_search_values($values);
	$where = array(); $parent = array('t.attach_id=a.attach_id','t.post_id>0','t.privmsgs_id=0');
	if ($values['search_author'] !== '')
	{
		require_once dirname(dirname(__DIR__)) . '/includes/functions_username.php';
		$parent[] = 'EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' u WHERE u.user_id=t.user_id_1 AND u.user_id>0 AND ' . phpbb_username_search_sql($db, 'u.username', $values['search_author']) . ')';
	}
	foreach (array('search_keyword_fname'=>'real_filename','search_keyword_comment'=>'comment') as $field => $column)
	{
		if ($values[$field] !== '') { $where[] = attach_admin_search_pattern($db, 'a.' . $column, $values[$field]); }
	}
	foreach (array('search_size_smaller'=>array('filesize','<'),'search_size_greater'=>array('filesize','>'),'search_count_smaller'=>array('download_count','<'),'search_count_greater'=>array('download_count','>')) as $field => $spec)
	{
		if ($values[$field] !== '') { $where[] = 'a.' . $spec[0] . $spec[1] . $values[$field]; }
	}
	if ($values['search_days_greater'] !== '') { $where[] = 'a.filetime < ' . sprintf('%.0f', (float)$values['search_time'] - (float)$values['search_days_greater'] * 86400.0); }
	if ($values['search_forum'] !== '' && $values['search_forum'] !== '0') { $parent[] = 'p.forum_id=' . $values['search_forum']; }
	$where[] = 'EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' t JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.post_id WHERE ' . implode(' AND ', $parent) . ')';
	$from = ' FROM ' . ATTACHMENTS_DESC_TABLE . ' a WHERE ' . implode(' AND ', $where);
	$sql = 'SELECT COUNT(*) AS total' . $from;
	if (!($result = $db->sql_query($sql))) { message_die(GENERAL_ERROR, 'Could not count matching attachments', '', __LINE__, __FILE__, $sql); }
	$count = $db->sql_fetchrow($result); $db->sql_freeresult($result); $total_rows = (int)$count['total'];
	if (!$total_rows) { message_die(GENERAL_MESSAGE, $lang['No_attach_search_match']); }
	if ($start >= $total_rows) { $start = (int)(floor(($total_rows-1)/$size)*$size); }
	$effective_start = $start;
	$sql = 'SELECT a.*' . $from . ' ORDER BY a.' . $sort[1] . ' ' . $sort[2] . ', a.attach_id ' . $sort[2] . ' LIMIT ' . $start . ', ' . $size;
	if (!($result = $db->sql_query($sql))) { message_die(GENERAL_ERROR, 'Could not query matching attachments', '', __LINE__, __FILE__, $sql); }
	$rows = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
	if (!$rows) { message_die(GENERAL_MESSAGE, $lang['No_attach_search_match']); }
	return $rows;
}

/**
* perform LIMIT statement on arrays
*/
function limit_array($array, $start, $pagelimit)
{
	// array from start - start+pagelimit
	$limit = (sizeof($array) < ($start + $pagelimit)) ? sizeof($array) : $start + $pagelimit;

	$limit_array = array();

	for ($i = $start; $i < $limit; $i++)
	{
		$limit_array[] = $array[$i];
	}

	return $limit_array;
}

?>
