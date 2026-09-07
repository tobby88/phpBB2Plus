<?php
/** 
*
* @package attachment_mod
* @version $Id: functions_attach.php,v 1.5 2006/04/09 13:25:51 acydburn Exp $
* @copyright (c) 2002 Meik Sievertsen
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/

/**
* All Attachment Functions needed everywhere
*/

/**
* html_entity_decode replacement (from php manual)
*/
if (!function_exists('html_entity_decode'))
{
	function html_entity_decode($given_html, $quote_style = ENT_QUOTES)
	{
		$trans_table = array_flip(get_html_translation_table(HTML_SPECIALCHARS, $quote_style));
		$trans_table['&#39;'] = "'";
		return (strtr($given_html, $trans_table));
	}
}

/**
* A simple dectobase64 function
*/
function base64_pack($number) 
{ 
	$chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ+-';
	$base = strlen($chars);

	if ($number > 4096)
	{
		return;
	}
	else if ($number < $base)
	{
		return $chars[$number];
	}
	
	$hexval = '';
	
	while ($number > 0) 
	{ 
		$remainder = $number%$base;
	
		if ($remainder < $base)
		{
			$hexval = $chars[$remainder] . $hexval;
		}

		$number = floor($number/$base); 
	} 

	return $hexval; 
}

/**
* base64todec function
*/
function base64_unpack($string)
{
	$chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ+-';
	$base = strlen($chars);

	$length = strlen($string); 
	$number = 0; 

	for($i = 1; $i <= $length; $i++)
	{ 
		$pos = $length - $i; 
		$operand = strpos($chars, substr($string,$pos,1));
		$exponent = pow($base, $i-1); 
		$decValue = $operand * $exponent; 
		$number += $decValue; 
	} 

	return $number; 
}

/**
* Per Forum based Extension Group Permissions (Encode Number) -> Theoretically up to 158 Forums saveable. :)
* We are using a base of 64, but splitting it to one-char and two-char numbers. :)
*/
function auth_pack($auth_array)
{
	$one_char_encoding = '#';
	$two_char_encoding = '.';
	$one_char = $two_char = false;
	$auth_cache = '';
	
	for ($i = 0; $i < sizeof($auth_array); $i++)
	{
		$val = base64_pack(intval($auth_array[$i]));
		if (strlen($val) == 1 && !$one_char)
		{
			$auth_cache .= $one_char_encoding;
			$one_char = true;
		}
		else if (strlen($val) == 2 && !$two_char)
		{		
			$auth_cache .= $two_char_encoding;
			$two_char = true;
		}
		
		$auth_cache .= $val;
	}

	return $auth_cache;
}

/**
* Reverse the auth_pack process
*/
function auth_unpack($auth_cache)
{
	$one_char_encoding = '#';
	$two_char_encoding = '.';

	$auth = array();
	$auth_len = 1;
	
	for ($pos = 0; $pos < strlen($auth_cache); $pos += $auth_len)
	{
		$forum_auth = substr($auth_cache, $pos, 1);
		if ($forum_auth == $one_char_encoding)
		{
			$auth_len = 1;
			continue;
		}
		else if ($forum_auth == $two_char_encoding)
		{
			$auth_len = 2;
			$pos--;
			continue;
		}
		
		$forum_auth = substr($auth_cache, $pos, $auth_len);
		$forum_id = base64_unpack($forum_auth);
		$auth[] = intval($forum_id);
	}
	return $auth;
}

/**
* Used for determining if Forum ID is authed, please use this Function on all Posting Screens
*/
function is_forum_authed($auth_cache, $check_forum_id)
{
	$one_char_encoding = '#';
	$two_char_encoding = '.';

	if (trim($auth_cache) == '')
	{
		return true;
	}

	$auth = array();
	$auth_len = 1;
	
	for ($pos = 0; $pos < strlen($auth_cache); $pos+=$auth_len)
	{
		$forum_auth = substr($auth_cache, $pos, 1);
		if ($forum_auth == $one_char_encoding)
		{
			$auth_len = 1;
			continue;
		}
		else if ($forum_auth == $two_char_encoding)
		{
			$auth_len = 2;
			$pos--;
			continue;
		}
		
		$forum_auth = substr($auth_cache, $pos, $auth_len);
		$forum_id = (int) base64_unpack($forum_auth);
		if ($forum_id == $check_forum_id)
		{
			return true;
		}
	}
	return false;
}

/**
* Init FTP Session
*/
function attach_init_ftp($mode = false, $quiet = false, &$failure = null)
{
	global $lang, $attach_config;
	$failure = ''; $connection = false; $server = 'localhost';
	foreach (array('ftp_connect', 'ftp_login', 'ftp_pasv', 'ftp_chdir', 'ftp_close') as $function)
	{
		if (!function_exists($function)) { $failure = $lang['Attachment_test_ftp_unavailable']; break; }
	}
	foreach (array('ftp_server', 'ftp_path', 'ftp_user', 'ftp_pass', 'ftp_pasv_mode') as $key)
	{
		if (!isset($attach_config[$key]) || !is_string($attach_config[$key]) || preg_match('/[\\x00\\r\\n]/', $attach_config[$key]))
		{
			$failure = $lang['Attachment_test_ftp_invalid']; break;
		}
	}
	if ($failure === '')
	{
		$server = trim($attach_config['ftp_server']); $server = $server === '' ? 'localhost' : $server;
		$path = trim($attach_config['ftp_path']); $path = $path === '' ? '.' : $path;
		if ($mode == MODE_THUMBNAIL) { $path = rtrim($path, '/') . '/' . THUMB_DIR; }
		try
		{
			$connection = @ftp_connect($server, 21, 30);
			if ($connection === false) { $failure = sprintf($lang['Ftp_error_connect'], htmlspecialchars($server, ENT_QUOTES, 'UTF-8')); }
			elseif (!@ftp_login($connection, $attach_config['ftp_user'], $attach_config['ftp_pass']))
			{
				$failure = sprintf($lang['Ftp_error_login'], htmlspecialchars($attach_config['ftp_user'], ENT_QUOTES, 'UTF-8'));
			}
			elseif (!@ftp_pasv($connection, (bool) $attach_config['ftp_pasv_mode'])) { $failure = $lang['Ftp_error_pasv_mode']; }
			elseif (!@ftp_chdir($connection, $path)) { $failure = sprintf($lang['Ftp_error_path'], htmlspecialchars($path, ENT_QUOTES, 'UTF-8')); }
		}
		catch (Exception $exception) { $failure = sprintf($lang['Ftp_error_connect'], htmlspecialchars($server, ENT_QUOTES, 'UTF-8')); }
		catch (Error $exception) { $failure = sprintf($lang['Ftp_error_connect'], htmlspecialchars($server, ENT_QUOTES, 'UTF-8')); }
	}
	if ($failure !== '')
	{
		if ($connection !== false) { @ftp_close($connection); }
		if (!$quiet) { message_die(GENERAL_ERROR, $failure); }
		return false;
	}
	return $connection;
}

/**
* Deletes an Attachment
*/
function unlink_attach($filename, $mode = false, $quiet = false)
{
	global $upload_dir, $attach_config, $lang;
	if (!is_string($filename)) { return false; }
	$filename = basename($filename);
	if (attach_ftp_listing_entry($filename, '0') === false || in_array(strtolower($filename), array('index.php', '.htaccess', '.htpasswd'), true)) { return false; }
	if ($mode == MODE_THUMBNAIL) { $filename = 't_' . $filename; }
	if (!intval($attach_config['allow_ftp_upload']))
	{
		$directory = $upload_dir . ($mode == MODE_THUMBNAIL ? '/' . THUMB_DIR : '');
		return @unlink($directory . '/' . $filename);
	}
	if (!function_exists('ftp_delete'))
	{
		if (!$quiet) { message_die(GENERAL_ERROR, $lang['Attachment_test_ftp_unavailable']); }
		return false;
	}
	$connection = attach_init_ftp($mode, $quiet); $deleted = false;
	if ($connection === false) { return false; }
	try { $deleted = @ftp_delete($connection, $filename); }
	catch (Exception $exception) { $deleted = false; }
	catch (Error $exception) { $deleted = false; }
	finally { @ftp_close($connection); }
	if (!$deleted && ATTACH_DEBUG && !$quiet)
	{
		$path = $attach_config['ftp_path'] . ($mode == MODE_THUMBNAIL ? '/' . THUMB_DIR : '');
		message_die(GENERAL_ERROR, sprintf($lang['Ftp_error_delete'], htmlspecialchars($path, ENT_QUOTES, 'UTF-8')));
	}
	return (bool) $deleted;
}

/**
* FTP File to Location
*/
function ftp_file($source_file, $dest_file, $mimetype, $disable_error_mode = false)
{
	global $attach_config, $lang, $error, $error_msg;
	$failure = ''; $uploaded = false; $connection = false;
	// Callers upload either one generated basename or its thumbnail. Do not
	// permit FTP commands/path traversal through a destination parameter.
	$name = is_string($dest_file) && strpos($dest_file, THUMB_DIR . '/') === 0 ? substr($dest_file, strlen(THUMB_DIR) + 1) : $dest_file;
	if (!function_exists('ftp_put')) { $failure = $lang['Attachment_test_ftp_unavailable']; }
	elseif (attach_ftp_listing_entry($name, '0') === false || in_array(strtolower($name), array('index.php', '.htaccess', '.htpasswd'), true) ||
		!is_string($source_file) || strpos($source_file, "\0") !== false || strpos($source_file, '://') !== false ||
		!is_file($source_file) || !is_readable($source_file))
	{
		$failure = sprintf($lang['Ftp_error_upload'], htmlspecialchars($attach_config['ftp_path'], ENT_QUOTES, 'UTF-8'));
	}
	else { $connection = attach_init_ftp(false, true, $failure); }
	if ($connection !== false)
	{
		try
		{
			// Preserve bytes for text/HTML too: ASCII mode can change line
			// endings and make the stored size/checksum differ from the upload.
			$uploaded = @ftp_put($connection, $dest_file, $source_file, FTP_BINARY);
			if ($uploaded && function_exists('ftp_site'))
			{
				// chmod is optional on FTP servers; its failure does not mean
				// that a successfully transferred attachment was lost.
				try { @ftp_site($connection, 'CHMOD 0644 ' . $dest_file); }
				catch (Exception $exception) {}
				catch (Error $exception) {}
			}
		}
		catch (Exception $exception) { $uploaded = false; }
		catch (Error $exception) { $uploaded = false; }
		finally { @ftp_close($connection); }
	}
	if (!$uploaded && !$disable_error_mode)
	{
		$error = true;
		$error_msg = isset($error_msg) && is_string($error_msg) ? $error_msg : '';
		if (!empty($error_msg)) { $error_msg .= '<br />'; }
		$error_msg .= $failure !== '' ? $failure : sprintf($lang['Ftp_error_upload'], htmlspecialchars($attach_config['ftp_path'], ENT_QUOTES, 'UTF-8'));
	}
	return (bool) $uploaded;
}

/**
* Check if Attachment exist
*/
function attach_ftp_listing_entry($name, $size)
{
	if (!is_string($name) || $name === '' || $name === '.' || $name === '..' || preg_match('#[\\\\/\x00-\x1f\x7f]#', $name)) { return false; }
	if (!is_string($size) && !is_int($size)) { return false; }
	$size = (string) $size;
	if (!preg_match('/^[0-9]+$/D', $size)) { return false; }
	$size = ltrim($size, '0'); $size = $size === '' ? '0' : $size;
	$maximum = (string) PHP_INT_MAX;
	if (strlen($size) > strlen($maximum) || (strlen($size) === strlen($maximum) && strcmp($size, $maximum) > 0)) { return false; }
	return array('name' => $name, 'size' => (int) $size);
}

// Return complete regular-file metadata or false. Never reuse metadata from
// another LIST row, or turn an unsupported/failed listing into an empty one.
function attach_ftp_parse_file_entries($rows, $structured)
{
	$files = array(); $seen = array();
	if (!is_array($rows)) { return false; }
	foreach ($rows as $row)
	{
		if ($structured)
		{
			if (!is_array($row) || !isset($row['type']) || !is_string($row['type'])) { return false; }
			$type = strtolower($row['type']);
			if (in_array($type, array('dir', 'cdir', 'pdir', 'os.unix=slink', 'os.unix=symlink'), true) || strpos($type, 'os.unix=slink:') === 0) { continue; }
			if ($type !== 'file') { return false; }
			if (!isset($row['name'], $row['size'])) { return false; }
			$entry = attach_ftp_listing_entry($row['name'], $row['size']);
		}
		else
		{
			if (!is_string($row)) { return false; }
			if ($row === '' || preg_match('/^total\s+[0-9]+\s*$/iD', $row)) { continue; }
			if (preg_match('/^([-dlbcps])[rwxstST-]{9}[+@.]?\s+[0-9]+\s+\S+\s+\S+\s+([0-9]+)\s+\S+\s+[0-9]{1,2}\s+(?:[0-9]{1,2}:[0-9]{2}(?::[0-9]{2})?|[0-9]{4}) (.*)$/D', $row, $match))
			{
				if ($match[1] !== '-') { continue; }
				$entry = attach_ftp_listing_entry($match[3], $match[2]);
			}
			elseif (preg_match('/^[0-9]{2}-[0-9]{2}-[0-9]{2,4}\s+[0-9]{1,2}:[0-9]{2}(?:AM|PM)\s+(<DIR>|[0-9]+)\s+(.+)$/iD', $row, $match))
			{
				if (strtoupper($match[1]) === '<DIR>') { continue; }
				$entry = attach_ftp_listing_entry($match[2], $match[1]);
			}
			else { return false; }
		}
		if ($entry === false || isset($seen['file:' . $entry['name']])) { return false; }
		$seen['file:' . $entry['name']] = true; $files[] = $entry;
	}
	return $files;
}

function attach_ftp_list_files($connection)
{
	if (function_exists('ftp_mlsd'))
	{
		$files = attach_ftp_parse_file_entries(@ftp_mlsd($connection, '.'), true);
		if ($files !== false) { return $files; }
	}
	return attach_ftp_parse_file_entries(@ftp_rawlist($connection, ''), false);
}

function attach_storage_file_entries($mode = false, $quiet = false)
{
	global $upload_dir, $attach_config;
	if (intval($attach_config['allow_ftp_upload']))
	{
		$connection = attach_init_ftp($mode, $quiet);
		if ($connection === false) { return false; }
		try { return attach_ftp_list_files($connection); }
		catch (Exception $exception) { if (!$quiet) { throw $exception; } return false; }
		catch (Error $exception) { if (!$quiet) { throw $exception; } return false; }
		finally { @ftp_close($connection); }
	}
	$directory = $upload_dir . ($mode == MODE_THUMBNAIL ? '/' . THUMB_DIR : '');
	$handle = @opendir($directory);
	if ($handle === false) { return false; }
	$files = array();
	try
	{
		while (($name = readdir($handle)) !== false)
		{
			$path = $directory . '/' . $name;
			if (is_link($path) || is_dir($path)) { continue; }
			if (!is_file($path)) { return false; }
			$size = @filesize($path);
			if ($size === false || $size < 0) { return false; }
			$files[] = array('name' => $name, 'size' => $size);
		}
	}
	finally { closedir($handle); }
	return $files;
}

function attach_storage_file_exists($filename, $mode = false)
{
	global $lang;
	$files = attach_storage_file_entries($mode);
	if ($files === false) { message_die(GENERAL_ERROR, $lang['Attachment_listing_failed']); }
	foreach ($files as $entry) { if ($entry['name'] === $filename) { return true; } }
	return false;
}

function attachment_exists($filename)
{
	return attach_storage_file_exists(basename($filename));
}

/**
* Check if Thumbnail exist
*/
function thumbnail_exists($filename)
{
	return attach_storage_file_exists('t_' . basename($filename), MODE_THUMBNAIL);
}

/**
* Physical Filename stored already ?
*/
function physical_filename_already_stored($filename)
{
	global $db;

	if ($filename == '')
	{
		return false;
	}

	$filename = basename($filename);

	$sql = 'SELECT attach_id 
		FROM ' . ATTACHMENTS_DESC_TABLE . "
		WHERE physical_filename = '" . attach_mod_sql_escape($filename) . "' 
		LIMIT 1";

	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not get attachment information for filename: ' . htmlspecialchars($filename), '', __LINE__, __FILE__, $sql);
	}
	$num_rows = $db->sql_numrows($result);
	$db->sql_freeresult($result);

	return ($num_rows == 0) ? false : true;
}

/**
* Determine if an Attachment exist in a post/pm
*/
function attachment_exists_db($post_id, $page = 0)
{
	global $db;

	$post_id = (int) $post_id;

	if ($page == PAGE_PRIVMSGS)
	{
		$sql_id = 'privmsgs_id';
	}
	else
	{
		$sql_id = 'post_id';
	}

	$sql = 'SELECT attach_id
		FROM ' . ATTACHMENTS_TABLE . "
		WHERE $sql_id = $post_id 
		LIMIT 1";

	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not get attachment informations for specific posts', '', __LINE__, __FILE__, $sql);
	}
	
	$num_rows = $db->sql_numrows($result);
	$db->sql_freeresult($result);

	if ($num_rows > 0)
	{
		return true;
	}
	else
	{
		return false;
	}
}

/**
* get all attachments from a post (could be an post array too)
*/
function get_attachments_from_post($post_id_array)
{
	global $db, $attach_config;

	$attachments = array();

	if (!is_array($post_id_array))
	{
		if (empty($post_id_array))
		{
			return $attachments;
		}

		$post_id = intval($post_id_array);

		$post_id_array = array();
		$post_id_array[] = $post_id;
	}

	$post_id_array = implode(', ', array_map('intval', $post_id_array));

	if ($post_id_array == '')
	{
		return $attachments;
	}

	$display_order = (intval($attach_config['display_order']) == 0) ? 'DESC' : 'ASC';
	
	$sql = 'SELECT a.post_id, d.*
		FROM ' . ATTACHMENTS_TABLE . ' a, ' . ATTACHMENTS_DESC_TABLE . " d
		WHERE a.post_id IN ($post_id_array)
			AND a.attach_id = d.attach_id
		ORDER BY d.filetime $display_order";

	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not get Attachment Informations for post number ' . $post_id_array, '', __LINE__, __FILE__, $sql);
	}
	
	$num_rows = $db->sql_numrows($result);
	$attachments = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);

	if ($num_rows == 0)
	{
		return array();
	}
		
	return $attachments;
}

/**
* get all attachments from a pm
*/
function get_attachments_from_pm($privmsgs_id_array)
{
	global $db, $attach_config;

	$attachments = array();

	if (!is_array($privmsgs_id_array))
	{
		if (empty($privmsgs_id_array))
		{
			return $attachments;
		}

		$privmsgs_id = intval($privmsgs_id_array);

		$privmsgs_id_array = array();
		$privmsgs_id_array[] = $privmsgs_id;
	}

	$privmsgs_id_array = implode(', ', array_map('intval', $privmsgs_id_array));

	if ($privmsgs_id_array == '')
	{
		return $attachments;
	}

	$display_order = (intval($attach_config['display_order']) == 0) ? 'DESC' : 'ASC';
	
	$sql = 'SELECT a.privmsgs_id, d.*
		FROM ' . ATTACHMENTS_TABLE . ' a, ' . ATTACHMENTS_DESC_TABLE . " d
		WHERE a.privmsgs_id IN ($privmsgs_id_array) 
			AND a.attach_id = d.attach_id
		ORDER BY d.filetime $display_order";

	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not get Attachment Informations for private message number ' . $privmsgs_id_array, '', __LINE__, __FILE__, $sql);
	}
	
	$num_rows = $db->sql_numrows($result);
	$attachments = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);

	if ($num_rows == 0 )
	{
		return array();
	}

	return $attachments;
}

/**
* Count Filesize of Attachments in Database based on the attachment id
*/
function get_total_attach_filesize($attach_ids)
{
	global $db;

	if (!is_array($attach_ids) || !sizeof($attach_ids))
	{
		return 0;
	}

	$attach_ids = implode(', ', array_map('intval', $attach_ids));

	if (!$attach_ids)
	{
		return 0;
	}

	$sql = 'SELECT filesize
		FROM ' . ATTACHMENTS_DESC_TABLE . "
		WHERE attach_id IN ($attach_ids)";

	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not query Total Filesize', '', __LINE__, __FILE__, $sql);
	}

	$total_filesize = 0;

	while ($row = $db->sql_fetchrow($result))
	{
		$total_filesize += (int) $row['filesize'];
	}
	$db->sql_freeresult($result);

	return $total_filesize;
}

/**
* Count Filesize for Attachments in Users PM Boxes (Do not count the SENT Box)
*/
function get_total_attach_pm_filesize($direction, $user_id)
{
	global $db;

	if ($direction != 'from_user' && $direction != 'to_user')
	{
		return 0;
	}
	else
	{
		$user_sql = ($direction == 'from_user') ? '(a.user_id_1 = ' . intval($user_id) . ')' : '(a.user_id_2 = ' . intval($user_id) . ')';
	}

	$sql = 'SELECT a.attach_id
		FROM ' . ATTACHMENTS_TABLE . ' a, ' . PRIVMSGS_TABLE . " p
		WHERE $user_sql 
			AND a.privmsgs_id <> 0 AND a.privmsgs_id = p.privmsgs_id
			AND p.privmsgs_type <> " . PRIVMSGS_SENT_MAIL;

	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not query Attachment Informations', '', __LINE__, __FILE__, $sql);
	}
				
	$pm_filesize_total = 0;
	$attach_id = array();
	$num_rows = $db->sql_numrows($result);

	if ($num_rows == 0)
	{
		$db->sql_freeresult($result);
		return $pm_filesize_total;
	}
	
	while ($row = $db->sql_fetchrow($result))
	{
		$attach_id[] = $row['attach_id'];
	}
	$db->sql_freeresult($result);

	$pm_filesize_total = get_total_attach_filesize($attach_id);
	return $pm_filesize_total;
}

/**
* Get allowed Extensions and their respective Values
*/
function get_extension_informations()
{
	global $db;

	$extensions = array();

	// Don't count on forbidden extensions table, because it is not allowed to allow forbidden extensions at all
	$sql = 'SELECT e.extension, g.cat_id, g.download_mode, g.upload_icon
		FROM ' . EXTENSIONS_TABLE . ' e, ' . EXTENSION_GROUPS_TABLE . ' g
		WHERE e.group_id = g.group_id
			AND g.allow_group = 1';
	
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not query Allowed Extensions.', '', __LINE__, __FILE__, $sql);
	}

	$extensions = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $extensions;
}

/**
* Sync Topic (includes/functions_admin.php)
*/
function attachment_sync_topic($topic_id)
{
	global $db;

	if (!$topic_id)
	{
		return;
	}

	$topic_id = (int) $topic_id;

	$sql = 'SELECT post_id 
		FROM ' . POSTS_TABLE . " 
		WHERE topic_id = $topic_id
		GROUP BY post_id";
		
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Couldn\'t select Post ID\'s', '', __LINE__, __FILE__, $sql);
	}

	$post_list = $db->sql_fetchrowset($result);
	$num_posts = $db->sql_numrows($result);
	$db->sql_freeresult($result);

	if ($num_posts == 0)
	{
		return;
	}
	
	$post_ids = array();

	for ($i = 0; $i < $num_posts; $i++)
	{
		$post_ids[] = intval($post_list[$i]['post_id']);
	}

	$post_id_sql = implode(', ', $post_ids);
	
	if ($post_id_sql == '')
	{
		return;
	}
	
	$sql = 'SELECT attach_id 
		FROM ' . ATTACHMENTS_TABLE . " 
		WHERE post_id IN ($post_id_sql) 
		LIMIT 1";
		
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Couldn\'t select Attachment ID\'s', '', __LINE__, __FILE__, $sql);
	}

	$set_id = ($db->sql_numrows($result) == 0) ? 0 : 1;

	$sql = 'UPDATE ' . TOPICS_TABLE . " SET topic_attachment = $set_id WHERE topic_id = $topic_id";

	if ( !($db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Couldn\'t update Topics Table', '', __LINE__, __FILE__, $sql);
	}
		
	for ($i = 0; $i < sizeof($post_ids); $i++)
	{
		$sql = 'SELECT attach_id 
			FROM ' . ATTACHMENTS_TABLE . ' 
			WHERE post_id = ' . $post_ids[$i] . '
			LIMIT 1';

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Couldn\'t select Attachment ID\'s', '', __LINE__, __FILE__, $sql);
		}

		$set_id = ( $db->sql_numrows($result) == 0) ? 0 : 1;
		
		$sql = 'UPDATE ' . POSTS_TABLE . " SET post_attachment = $set_id WHERE post_id = {$post_ids[$i]}";

		if ( !($db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Couldn\'t update Posts Table', '', __LINE__, __FILE__, $sql);
		}
	}
}

/**
* Get Extension
*/
function get_extension($filename)
{
	if (!stristr($filename, '.'))
	{
		return '';
	}

	$extension = strrchr(strtolower($filename), '.');
	$extension[0] = ' ';
	$extension = strtolower(trim($extension));
	
	if (is_array($extension))
	{
		return '';
	}
	else
	{
		return $extension;
	}
}

/**
* Delete Extension
*/
function delete_extension($filename)
{
	return substr($filename, 0, strrpos(strtolower(trim($filename)), '.'));
}

/** 
* Check if a user is within Group
*/
function user_in_group($user_id, $group_id)
{
	global $db;

	$user_id = (int) $user_id;
	$group_id = (int) $group_id;

	if (!$user_id || !$group_id)
	{
		return false;
	}
	
	$sql = 'SELECT u.group_id 
		FROM ' . USER_GROUP_TABLE . ' u, ' . GROUPS_TABLE . " g 
		WHERE g.group_single_user = 0
			AND u.user_pending = 0
			AND u.group_id = g.group_id
			AND u.user_id = $user_id 
			AND g.group_id = $group_id
		LIMIT 1";
			
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not get User Group', '', __LINE__, __FILE__, $sql);
	}

	$num_rows = $db->sql_numrows($result);
	$db->sql_freeresult($result);

	if ($num_rows == 0)
	{
		return false;
	}
	
	return true;
}

/**
* Realpath replacement for attachment mod
*/
function amod_realpath($path)
{
	return (function_exists('realpath')) ? realpath($path) : $path;
}

/**
* _set_var
*
* Set variable, used by {@link get_var the get_var function}
*
* @private
*/
function _set_var(&$result, $var, $type, $multibyte = false)
{
	settype($var, $type);
	$result = $var;

	if ($type == 'string')
	{
		$result = trim(htmlspecialchars(str_replace(array("\r\n", "\r", '\xFF'), array("\n", "\n", ' '), $result)));
		// 2.0.x is doing addslashes on all variables
		$result = stripslashes($result);
		if ($multibyte)
		{
			$result = preg_replace('#&amp;(\#[0-9]+;)#', '&\1', $result);
		}
	}
}

/**
* get_var
*
* Used to get passed variable
*/
function get_var($var_name, $default, $multibyte = false)
{
	global $HTTP_POST_VARS, $HTTP_GET_VARS;

	$request_var = (isset($HTTP_POST_VARS[$var_name])) ? $HTTP_POST_VARS : $HTTP_GET_VARS;

	if (!isset($request_var[$var_name]) || (is_array($request_var[$var_name]) && !is_array($default)) || (is_array($default) && !is_array($request_var[$var_name])))
	{
		return (is_array($default)) ? array() : $default;
	}

	$var = $request_var[$var_name];

	if (!is_array($default))
	{
		$type = gettype($default);
	}
	else
	{
		$key_sample = null;
		$type_sample = null;
		foreach ($default as $key_sample => $type_sample)
		{
			break;
		}
		$type = gettype($type_sample);
		$key_type = gettype($key_sample);
	}

	if (is_array($var))
	{
		$_var = $var;
		$var = array();

		foreach ($_var as $k => $v)
		{
			if (is_array($v))
			{
				foreach ($v as $_k => $_v)
				{
					_set_var($k, $k, $key_type);
					_set_var($_k, $_k, $key_type);
					_set_var($var[$k][$_k], $_v, $type, $multibyte);
				}
			}
			else
			{
				_set_var($k, $k, $key_type);
				_set_var($var[$k], $v, $type, $multibyte);
			}
		}
	}
	else
	{
		_set_var($var, $var, $type, $multibyte);
	}
		
	return $var;
}

/**
* Escaping SQL
*/
function attach_mod_sql_escape($text)
{
	global $db;
	return $db->sql_escape((string) $text);
}

/**
* Build sql statement from array for insert/update/select statements
*
* Idea for this from Ikonboard
* Possible query values: INSERT, INSERT_SELECT, MULTI_INSERT, UPDATE, SELECT
*/
function attach_mod_sql_build_array($query, $assoc_ary = false)
{
	if (!is_array($assoc_ary))
	{
		return false;
	}

	$fields = array();
	$values = array();
	if ($query == 'INSERT' || $query == 'INSERT_SELECT')
	{
		foreach ($assoc_ary as $key => $var)
		{
			$fields[] = $key;

			if (is_null($var))
			{
				$values[] = 'NULL';
			}
			else if (is_string($var))
			{
				$values[] = "'" . attach_mod_sql_escape($var) . "'";
			}
			else if (is_array($var) && is_string($var[0]))
			{
				$values[] = $var[0];
			}
			else
			{
				$values[] = (is_bool($var)) ? intval($var) : $var;
			}
		}

		$query = ($query == 'INSERT') ? ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')' : ' (' . implode(', ', $fields) . ') SELECT ' . implode(', ', $values) . ' ';
	}
	else if ($query == 'MULTI_INSERT')
	{
		$ary = array();
		foreach ($assoc_ary as $id => $sql_ary)
		{
			$values = array();
			foreach ($sql_ary as $key => $var)
			{
				if (is_null($var))
				{
					$values[] = 'NULL';
				}
				elseif (is_string($var))
				{
					$values[] = "'" . attach_mod_sql_escape($var) . "'";
				}
				else
				{
					$values[] = (is_bool($var)) ? intval($var) : $var;
				}
			}
			$ary[] = '(' . implode(', ', $values) . ')';
		}

		$query = ' (' . implode(', ', array_keys($assoc_ary[0])) . ') VALUES ' . implode(', ', $ary);
	}
	else if ($query == 'UPDATE' || $query == 'SELECT')
	{
		$values = array();
		foreach ($assoc_ary as $key => $var)
		{
			if (is_null($var))
			{
				$values[] = "$key = NULL";
			}
			elseif (is_string($var))
			{
				$values[] = "$key = '" . attach_mod_sql_escape($var) . "'";
			}
			else
			{
				$values[] = (is_bool($var)) ? "$key = " . intval($var) : "$key = $var";
			}
		}
		$query = implode(($query == 'UPDATE') ? ', ' : ' AND ', $values);
	}

	return $query;
}

?>
