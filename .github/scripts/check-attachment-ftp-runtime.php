<?php
namespace AttachmentFtpRuntime;
use Exception;
use Error;
use RuntimeException;
const MODE_THUMBNAIL = 1;
const THUMB_DIR = 'thumbs';
const GENERAL_ERROR = 202;
const ATTACH_DEBUG = 0;
const FTP_BINARY = 2;
class FtpFailure extends RuntimeException {}
function ftp_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function message_die($type, $message) { throw new FtpFailure($message); }
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
$source = file_get_contents($forum_root . 'attach_mod/includes/functions_attach.php');
$start = strpos($source, 'function attach_init_ftp('); $end = strpos($source, '// Return complete regular-file metadata', $start);
ftp_check($start !== false && $end > $start, 'Locate actual runtime FTP functions');
eval('namespace AttachmentFtpRuntime; use Exception; use Error; ' . substr($source, $start, $end - $start));
function reset_ftp($failure = '')
{
	$GLOBALS['ftp_failure'] = $failure; $GLOBALS['ftp_calls'] = array(); $GLOBALS['ftp_bytes'] = null;
	$GLOBALS['attach_config'] = array('ftp_server' => 'fixture.invalid', 'ftp_path' => 'uploads', 'ftp_user' => 'fixture', 'ftp_pass' => 'private-password', 'ftp_pasv_mode' => '1', 'allow_ftp_upload' => '1');
	$GLOBALS['error'] = false; $GLOBALS['error_msg'] = 'Earlier warning';
}
function function_exists($name)
{
	return !($GLOBALS['ftp_failure'] === 'missing' && $name === 'ftp_connect') && !($GLOBALS['ftp_failure'] === 'missing-put' && $name === 'ftp_put') && !($GLOBALS['ftp_failure'] === 'missing-delete' && $name === 'ftp_delete');
}
function ftp_call($name, $connection)
{
	ftp_check(is_object($connection) && !$connection->closed, 'Only use an owned open FTP session: ' . $name);
	$GLOBALS['ftp_calls'][] = $name;
}
function ftp_connect($host, $port, $timeout)
{
	$GLOBALS['ftp_calls'][] = 'connect'; ftp_check($port === 21 && $timeout === 30, 'Bounded runtime FTP timeout');
	if ($GLOBALS['ftp_failure'] === 'connect') { return false; }
	$connection = new \stdClass(); $connection->closed = false; return $connection;
}
function ftp_login($connection, $username, $password)
{
	ftp_call('login', $connection);
	if ($GLOBALS['ftp_failure'] === 'init-exception') { throw new RuntimeException('private-password'); }
	return $GLOBALS['ftp_failure'] !== 'login';
}
function ftp_pasv($connection, $mode) { ftp_call('pasv', $connection); return $GLOBALS['ftp_failure'] !== 'pasv'; }
function ftp_chdir($connection, $path) { ftp_call('cwd:' . $path, $connection); return $GLOBALS['ftp_failure'] !== 'path'; }
function ftp_put($connection, $target, $source, $mode)
{
	ftp_call('put:' . $target, $connection); ftp_check($mode === FTP_BINARY, 'All MIME types must use byte-preserving binary transfer');
	$GLOBALS['ftp_bytes'] = file_get_contents($source);
	if ($GLOBALS['ftp_failure'] === 'put-exception') { throw new RuntimeException('private-password'); }
	if ($GLOBALS['ftp_failure'] === 'put-error') { throw new Error('private-password'); }
	return $GLOBALS['ftp_failure'] !== 'put';
}
function ftp_site($connection, $command)
{
	ftp_call('chmod', $connection); ftp_check(strpos($command, "\r") === false && strpos($command, "\n") === false, 'No FTP command injection');
	if ($GLOBALS['ftp_failure'] === 'chmod-exception') { throw new RuntimeException('Optional chmod unsupported'); }
	return $GLOBALS['ftp_failure'] !== 'chmod';
}
function ftp_delete($connection, $name)
{
	ftp_call('delete:' . $name, $connection);
	if ($GLOBALS['ftp_failure'] === 'delete-exception') { throw new RuntimeException('private-password'); }
	return $GLOBALS['ftp_failure'] !== 'delete';
}
function ftp_close($connection) { ftp_call('close', $connection); $connection->closed = true; return true; }
$lang = array(); require $forum_root . 'language/lang_english/lang_main_attach.php';
foreach (array('missing', 'connect', 'login', 'pasv', 'path', 'init-exception') as $failure)
{
	reset_ftp($failure); $message = ''; ftp_check(attach_init_ftp(false, true, $message) === false && $message !== '', 'Quiet initialization failure: ' . $failure);
	if ($failure === 'missing') { ftp_check(!$GLOBALS['ftp_calls'], 'Missing extension makes no FTP calls'); }
	elseif ($failure === 'connect') { ftp_check($GLOBALS['ftp_calls'] === array('connect'), 'Failed connection is never reused'); }
	else { ftp_check(end($GLOBALS['ftp_calls']) === 'close', 'Failed setup closes its session'); }
	reset_ftp($failure); $caught = false;
	try { attach_init_ftp(); } catch (FtpFailure $exception) { $caught = true; ftp_check(strpos($exception->getMessage(), 'private-password') === false, 'Do not expose native exception or password'); }
	ftp_check($caught, 'Default initialization still stops dependent maintenance on failure');
}
reset_ftp(); $connection = attach_init_ftp(MODE_THUMBNAIL); ftp_check(end($GLOBALS['ftp_calls']) === 'cwd:uploads/thumbs', 'Thumbnail setup uses the configured subdirectory'); ftp_close($connection);
foreach (array("bad\0value", "bad\r\nvalue", array('nested')) as $bad)
{
	reset_ftp(); $attach_config['ftp_user'] = $bad; $message = '';
	ftp_check(attach_init_ftp(false, true, $message) === false && !$GLOBALS['ftp_calls'], 'Invalid FTP settings rejected before connection');
}
reset_ftp('login'); $attach_config['ftp_user'] = '<user>'; $message = '';
attach_init_ftp(false, true, $message); ftp_check(strpos($message, '<user>') === false && strpos($message, '&lt;user&gt;') !== false, 'Escape configured error details');
$temporary = sys_get_temp_dir() . '/phpbb-ftp-runtime-' . uniqid('', true);
ftp_check(mkdir($temporary . '/thumbs', 0700, true), 'Owned local fixture');
$payload = "Grüße 😀\r\nline two\n\0binary\rfinal";
file_put_contents($temporary . '/source.bin', $payload);
try
{
	foreach (array('text/plain', 'text/html', 'application/octet-stream', 'image/png') as $mime)
	{
		reset_ftp(); ftp_check(ftp_file($temporary . '/source.bin', 'file.dat', $mime) && $GLOBALS['ftp_bytes'] === $payload && end($GLOBALS['ftp_calls']) === 'close', 'Successful exact-byte transfer: ' . $mime);
	}
	$failures = array('missing', 'missing-put', 'connect', 'login', 'pasv', 'path', 'init-exception', 'put', 'put-exception');
	if (PHP_VERSION_ID >= 70000) { $failures[] = 'put-error'; }
	foreach ($failures as $failure)
	{
		foreach (array(false, true) as $quiet)
		{
			reset_ftp($failure); ftp_check(!ftp_file($temporary . '/source.bin', 'thumbs/t_file.dat', 'image/png', $quiet), 'Transfer failure result: ' . $failure);
			ftp_check($error === !$quiet, 'Main upload error flag versus optional thumbnail failure');
			ftp_check(strpos($error_msg, 'Earlier warning') === 0 && ($quiet ? $error_msg === 'Earlier warning' : strlen($error_msg) > strlen('Earlier warning')), 'Preserve existing messages and respect quiet mode');
			ftp_check(strpos($error_msg, 'private-password') === false, 'Error text remains private');
			if (in_array('login', $GLOBALS['ftp_calls'])) { ftp_check(end($GLOBALS['ftp_calls']) === 'close', 'All owned upload sessions close'); }
			ftp_check(!in_array('delete:thumbs/t_file.dat', $GLOBALS['ftp_calls']), 'Do not delete a destination of unproven ownership after failed overwrite');
		}
	}
	foreach (array('chmod', 'chmod-exception') as $failure) { reset_ftp($failure); ftp_check(ftp_file($temporary . '/source.bin', 'file.dat', 'text/plain') && !$error && end($GLOBALS['ftp_calls']) === 'close', 'Optional chmod failure does not undo successful upload'); }
	reset_ftp('missing-put'); unset($GLOBALS['error_msg']);
	ftp_check(!ftp_file($temporary . '/source.bin', 'file.dat', 'text/plain') && is_string($error_msg) && $error_msg !== '', 'First error initializes the message without undefined-variable warnings');
	reset_ftp('put'); $error = true;
	ftp_check(!ftp_file($temporary . '/source.bin', 'file.dat', 'image/png', true) && $error === true && $error_msg === 'Earlier warning', 'Quiet thumbnail failure also preserves an existing error flag');
	foreach (array('../escape', 'thumbs/../escape', 'other/file', "bad\r\nDELE file", '.htaccess', '.htpasswd', 'INDEX.PHP', 'index.php', array('nested')) as $destination)
	{
		reset_ftp(); ftp_check(!ftp_file($temporary . '/source.bin', $destination, 'text/plain') && !$GLOBALS['ftp_calls'], 'Reject unsafe destination before FTP');
	}
	foreach (array($temporary . '/missing', "bad\0file", 'php://memory') as $path) { reset_ftp(); ftp_check(!ftp_file($path, 'file.dat', 'text/plain') && !$GLOBALS['ftp_calls'], 'Reject unavailable/nonlocal source'); }
	foreach (array('', 'delete', 'delete-exception') as $failure)
	{
		reset_ftp($failure); ftp_check(unlink_attach('file.dat', MODE_THUMBNAIL) === ($failure === '') && end($GLOBALS['ftp_calls']) === 'close', 'Deletion result is boolean and closes connection: ' . $failure);
		ftp_check(in_array('delete:t_file.dat', $GLOBALS['ftp_calls']) && in_array('cwd:uploads/thumbs', $GLOBALS['ftp_calls']), 'Delete only expected thumbnail target');
	}
	reset_ftp('missing-delete'); $caught = false;
	try { unlink_attach('file.dat'); } catch (FtpFailure $exception) { $caught = true; }
	ftp_check($caught && !$GLOBALS['ftp_calls'], 'Missing delete function stops maintenance without opening FTP');
	reset_ftp(); $attach_config['allow_ftp_upload'] = '0'; $upload_dir = $temporary;
	file_put_contents($temporary . '/index.php', 'protect'); file_put_contents($temporary . '/thumbs/t_file.dat', 'thumb');
	ftp_check(!unlink_attach('index.php') && file_get_contents($temporary . '/index.php') === 'protect', 'Protection files cannot be selected for deletion');
	ftp_check(unlink_attach('file.dat', MODE_THUMBNAIL) && !unlink_attach('file.dat', MODE_THUMBNAIL), 'Local successful and missing deletes return correct booleans');
}
finally
{
	foreach (array('/source.bin', '/index.php', '/thumbs/t_file.dat') as $path) { if (file_exists($temporary . $path)) { unlink($temporary . $path); } }
	rmdir($temporary . '/thumbs'); rmdir($temporary);
}
restore_error_handler();
echo "Attachment FTP runtime and error propagation checks passed.\n";
