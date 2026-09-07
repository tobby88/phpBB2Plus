<?php
namespace AttachmentInventoryFixture;
use RuntimeException;
use Exception;
use Error;
const MODE_THUMBNAIL = 1;
const THUMB_DIR = 'thumbs';
const GENERAL_ERROR = 202;
const ATTACHMENTS_DESC_TABLE = 'fixture_attachments';
class InventoryFailure extends RuntimeException {}
function inventory_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function message_die($type, $message) { throw new InventoryFailure($message); }
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
$attach_source = file_get_contents($forum_root . 'attach_mod/includes/functions_attach.php');
$start = strpos($attach_source, 'function attach_ftp_listing_entry(');
$end = strpos($attach_source, '/**' . "\n" . '* Physical Filename stored already', $start);
inventory_check($start !== false && $end > $start, 'Locate actual listing and existence helpers');
eval('namespace AttachmentInventoryFixture; use Exception; use Error; ' . substr($attach_source, $start, $end - $start));
$admin_source = file_get_contents($forum_root . 'attach_mod/includes/functions_admin.php');
$start = strpos($admin_source, 'function attach_inventory_file(');
$end = strpos($admin_source, '/*' . "\n" . '* Build SQL-Statement', $start);
inventory_check($start !== false && $end > $start, 'Locate actual inventory and size consumers');
eval('namespace AttachmentInventoryFixture; ' . substr($admin_source, $start, $end - $start));

function function_exists($name) { return $name === 'ftp_mlsd' ? $GLOBALS['inventory_mlsd_available'] : \function_exists($name); }
function attach_init_ftp($mode = false, $quiet = false)
{
	$GLOBALS['inventory_calls'][] = 'open:' . (int) $mode;
	if (!empty($GLOBALS['inventory_init_fail']))
	{
		if (!$quiet) { throw new InventoryFailure('Setup failed'); }
		return false;
	}
	return new \stdClass();
}
function ftp_mlsd($connection, $path) { $GLOBALS['inventory_calls'][] = 'mlsd'; return $GLOBALS['inventory_mlsd']; }
function ftp_rawlist($connection, $path)
{
	$GLOBALS['inventory_calls'][] = 'list';
	if (!empty($GLOBALS['inventory_list_throw'])) { throw new RuntimeException('Private connection detail'); }
	return $GLOBALS['inventory_list'];
}
function ftp_close($connection) { $GLOBALS['inventory_calls'][] = 'close'; return true; }
function unlink_attach($filename, $mode = false) { $GLOBALS['inventory_deletes'][] = array($filename, $mode); return true; }
function reset_inventory($rows, $structured = false)
{
	$GLOBALS['inventory_init_fail'] = false; $GLOBALS['inventory_list_throw'] = false;
	$GLOBALS['inventory_calls'] = array(); $GLOBALS['inventory_deletes'] = array();
	$GLOBALS['inventory_mlsd_available'] = $structured;
	$GLOBALS['inventory_mlsd'] = $structured ? $rows : false;
	$GLOBALS['inventory_list'] = $structured ? false : $rows;
}
$unix = array(
	'total 32',
	'drwxr-xr-x 2 owner group 4096 Sep 7 12:34 thumbs',
	'-rw-r--r-- 1 owner group 3 Sep 7 12:34 0',
	'-rw-r--r--+ 1 owner group 4 Jan 1 2025 space name.txt',
	'-rw-r--r-- 1 owner group 2 Sep 7 12:34  leading ',
	'lrwxrwxrwx 1 owner group 8 Sep 7 12:34 link -> 0',
	'-rw-r--r-- 1 owner group 10 Sep 7 12:34 index.php',
	'-rw-r--r-- 1 owner group 10 Sep 7 12:34 .htaccess',
	'-rw-r--r-- 1 owner group 4 Sep 7 12:34 .phpbb-test-active.tmp'
);
$attach_config = array('allow_ftp_upload' => '1');
foreach (array('INDEX.PHP', '.HTACCESS', '.htpasswd', '.PHPBB-TEST-active.tmp') as $protected) { inventory_check(!attach_inventory_file($protected), 'Protected files are never offered for orphan deletion'); }
$lang = array('Attachment_listing_failed' => 'Cannot read inventory', 'Attachment_selection_invalid' => 'Invalid selection', 'Not_available' => 'N/A', 'Bytes' => 'Bytes', 'KB' => 'KB', 'MB' => 'MB');
reset_inventory($unix);
inventory_check(collect_attachments() === array('0', 'space name.txt', ' leading '), 'Directory rows must not hide following files; retain exact names and skip protection/probe files');
inventory_check(end($GLOBALS['inventory_calls']) === 'close', 'Inventory closes FTP session');
reset_inventory($unix); inventory_check(get_formatted_dirsize() === '9 Bytes', 'Do not count directories, symlinks or protection/probe files');
reset_inventory($unix); inventory_check(attachment_exists('0') && !attachment_exists('missing'), 'File existence checks compare exact names');
reset_inventory(array('-rw-r--r-- 1 owner group 2 Sep 7 12:34 t_a.jpg'));
inventory_check(thumbnail_exists('a.jpg') && $GLOBALS['inventory_calls'][0] === 'open:1', 'Thumbnail lookup uses thumbnail directory and prefix');

$structured = array(array('type' => 'cdir', 'name' => '.'), array('type' => 'dir', 'name' => 'thumbs'), array('type' => 'file', 'name' => '0', 'size' => '0'), array('type' => 'file', 'name' => 'Grüße 😀.txt', 'size' => '0012'), array('type' => 'OS.unix=slink:/target', 'name' => 'link'));
reset_inventory($structured, true);
inventory_check(collect_attachments() === array('0', 'Grüße 😀.txt') && !in_array('list', $GLOBALS['inventory_calls']), 'Prefer structured MLSD without losing Unicode or zero-size files');
reset_inventory(array(array('type' => 'file', 'name' => 'a')), true); $GLOBALS['inventory_list'] = $unix;
inventory_check(get_formatted_dirsize() === '9 Bytes' && in_array('list', $GLOBALS['inventory_calls']), 'Missing optional MLSD size facts fall back to a complete LIST result');
reset_inventory(array('09-07-26  01:22PM       <DIR> thumbs', '09-07-2026  01:22PM       17 report file.txt'));
inventory_check(collect_attachments() === array('report file.txt') && get_formatted_dirsize() === '17 Bytes', 'DOS listings do not reuse a directory flag');
foreach (array(false, array('unrecognized format'), array($unix[2], 'unrecognized format'), array($unix[2], $unix[2]), array('-rw-r--r-- 1 owner group 999999999999999999999 Sep 7 12:34 huge')) as $bad)
{
	reset_inventory($bad); inventory_check(get_formatted_dirsize() === 'N/A' && end($GLOBALS['inventory_calls']) === 'close', 'Incomplete/unreadable/overflowed inventory is unavailable, not partial or zero');
	$caught = false; try { collect_attachments(); } catch (InventoryFailure $error) { $caught = true; }
	inventory_check($caught, 'Shadow inventory must stop on incomplete metadata');
	$caught = false; try { thumbnail_exists('a.jpg'); } catch (InventoryFailure $error) { $caught = true; }
	inventory_check($caught, 'Read failure must not become missing thumbnail');
}
foreach (array('../escape', 'sub/file', 'sub\\file', '.', '..', "bad\0name", "bad\nname") as $name)
{
	inventory_check(attach_ftp_listing_entry($name, '1') === false, 'Reject non-flat or control-character FTP names');
}
foreach (array('', '-1', '1.5', '1e3', array(1)) as $size) { inventory_check(attach_ftp_listing_entry('valid', $size) === false, 'Reject invalid sizes'); }
reset_inventory(array()); inventory_check(collect_attachments() === array() && get_formatted_dirsize() === '0 Bytes', 'A confirmed empty LIST is valid');
reset_inventory(array(), true); inventory_check(collect_attachments() === array() && !in_array('list', $GLOBALS['inventory_calls']), 'A confirmed empty MLSD is valid');

$temporary = sys_get_temp_dir() . '/phpbb-inventory-' . uniqid('', true);
inventory_check(mkdir($temporary . '/thumbs', 0700, true), 'Create isolated local inventory');
$fixtures = array('0' => 'abc', 'space name.txt' => 'data', ' leading' => 'zz', 'index.php' => 'protect', '.htaccess' => 'protect', '.phpbb-test-active.tmp' => 'test');
$upload_dir = $temporary; $attach_config['allow_ftp_upload'] = '0';
try
{
	foreach ($fixtures as $name => $value) { file_put_contents($temporary . '/' . $name, $value); }
	$actual = collect_attachments(); sort($actual); $expected = array('0', 'space name.txt', ' leading'); sort($expected);
	inventory_check($actual === $expected && get_formatted_dirsize() === '9 Bytes', 'Local iteration includes filename zero and all following files with exact names');
	inventory_check(attachment_exists('0') && !attachment_exists('thumbs'), 'Directories are not regular attachments');
	file_put_contents($temporary . '/thumbs/t_a.jpg', 'thumb');
	inventory_check(thumbnail_exists('a.jpg') && !thumbnail_exists('missing'), 'Local thumbnail checks');
	if (@symlink($temporary . '/0', $temporary . '/linked')) { inventory_check(!attachment_exists('linked'), 'Local symbolic links excluded'); unlink($temporary . '/linked'); }
	$upload_dir = $temporary . '/absent'; inventory_check(get_formatted_dirsize() === 'N/A', 'Missing local directory is unavailable');
}
finally
{
	foreach ($fixtures as $name => $value) { if (file_exists($temporary . '/' . $name)) { unlink($temporary . '/' . $name); } }
	if (is_link($temporary . '/linked')) { unlink($temporary . '/linked'); }
	if (file_exists($temporary . '/thumbs/t_a.jpg')) { unlink($temporary . '/thumbs/t_a.jpg'); }
	rmdir($temporary . '/thumbs'); rmdir($temporary);
}

class SyncDatabase
{
	var $queries = array();
	function sql_query($sql)
	{
		$this->queries[] = $sql; $result = new \stdClass();
		$result->rows = strpos($sql, 'WHERE thumbnail = 1') !== false ? array(array('attach_id' => 1, 'physical_filename' => 'a.jpg'), array('attach_id' => 2, 'physical_filename' => 'missing.jpg')) : array(array('attach_id' => 3, 'physical_filename' => 'unused.jpg'));
		return $result;
	}
	function sql_fetchrow($result) { return $result->rows ? array_shift($result->rows) : false; }
	function sql_freeresult($result) {}
}
$controller = file_get_contents($forum_root . 'admin/admin_attachments.php');
inventory_check(strpos($controller, 'attach_shadow_cleanup(') !== false, 'ACP delegates cleanup to the shared locked helper');
// Exact-name validation stays isolated here; full deletion/DB coordination and
// all orphan variants are executed by check-attachment-shadow.php.
$attach_config['allow_ftp_upload'] = '1';
foreach (array(array(' leading '), array('0', 'missing'), array('../0'), array('.htaccess'), array(array('0')), 'invalid') as $selection)
{
	reset_inventory($unix); $HTTP_POST_VARS = array('attach_file_list' => $selection); $caught = false;
	$selected = array();
	try { $selected = attach_shadow_selected_files($selection); } catch (InventoryFailure $error) { $caught = true; }
	if ($selection === array(' leading ')) { inventory_check(!$caught && $selected === array(' leading '), 'Selection preserves the exact displayed filename, without trimming'); }
	else { inventory_check($caught && !$GLOBALS['inventory_deletes'], 'Validate the whole submitted selection before any file deletion'); }
}
reset_inventory(array('-rw-r--r-- 1 owner group 2 Sep 7 12:34 name&quote\'.txt'));
inventory_check(attach_shadow_selected_files(array(addslashes("name&quote'.txt"), addslashes("name&quote'.txt"))) === array("name&quote'.txt"), 'Decode bootstrap slashes once, not HTML; deduplicate exact names');
// Use the literal assignment after collecting files, not the earlier UI setup.
$start = strpos($controller, '$shadow_attachments = array();', strpos($controller, '$file_attachments = collect_attachments();'));
$end = strpos($controller, '// Now look at the missing posts and PM', $start);
inventory_check($start !== false && $end > $start, 'Locate real shadow classification');
$file_attachments = array('0', '0e1', ' leading ');
$table_attachments = array('attach_id' => array(1, 2, 3), 'physical_filename' => array('0', '0e2', ' leading '), 'comment' => array('', '', ''));
$assign_attachments = array();
eval('namespace AttachmentInventoryFixture; ' . substr($controller, $start, $end - $start));
inventory_check($shadow_attachments === array('0e1') && $shadow_row['physical_filename'] === array('0e2'), 'Shadow classification compares complete names strictly, not trimmed or numeric-equivalent strings');
$start = strpos($controller, '// Sync Thumbnails (if a thumbnail is no longer there, delete it)');
$end = strpos($controller, "\tdie('<br /><br /><br />'", $start);
inventory_check($start !== false && $end > $start, 'Locate actual thumbnail synchronization block');
$sync = substr($controller, $start, $end - $start);
$attach_config['allow_ftp_upload'] = '1'; $lang['Sync_thumbnail_resetted'] = '%s';
foreach (array(false, array('-rw-r--r-- 1 owner group 2 Sep 7 12:34 t_a.jpg', '-rw-r--r-- 1 owner group 2 Sep 7 12:34 t_unused.jpg')) as $rows)
{
	reset_inventory($rows); $db = new SyncDatabase(); $info = ''; $caught = false;
	ob_start();
	try { eval('namespace AttachmentInventoryFixture; ' . $sync); }
	catch (InventoryFailure $error) { $caught = true; }
	finally { ob_end_clean(); }
	if ($rows === false) { inventory_check($caught && !$db->queries && !$GLOBALS['inventory_deletes'], 'Failed listing prevents every thumbnail flag update/delete'); }
	else
	{
		$updates = array_values(preg_grep('/^UPDATE /', $db->queries));
		inventory_check(count($updates) === 1 && strpos($updates[0], 'attach_id = 2') !== false, 'Reset only confirmed missing thumbnail flags');
		inventory_check($GLOBALS['inventory_deletes'] === array(array('unused.jpg', MODE_THUMBNAIL)), 'Delete only confirmed existing unflagged thumbnails');
		inventory_check($GLOBALS['inventory_calls'] === array('open:1', 'list', 'close'), 'Use one complete snapshot, not a full FTP listing for every thumbnail');
	}
}
reset_inventory(array()); $GLOBALS['inventory_init_fail'] = true;
inventory_check(attach_storage_file_entries(false, true) === false && $GLOBALS['inventory_calls'] === array('open:0'), 'Quiet failed setup returns unavailable without listing or closing a nonexistent session');
reset_inventory(array()); $GLOBALS['inventory_list_throw'] = true;
inventory_check(attach_storage_file_entries(false, true) === false && end($GLOBALS['inventory_calls']) === 'close', 'Quiet listing exceptions remain unavailable and close the owned session');
reset_inventory(array()); $GLOBALS['inventory_list_throw'] = true; $caught = false;
try { attach_storage_file_entries(); } catch (RuntimeException $error) { $caught = true; }
inventory_check($caught && end($GLOBALS['inventory_calls']) === 'close', 'Default listing still propagates errors after closing');
restore_error_handler();
echo "Attachment file inventory and synchronization checks passed.\n";
