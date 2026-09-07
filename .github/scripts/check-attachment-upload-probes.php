<?php
namespace AttachmentProbeRuntime;
use Exception;
use Error;
use RuntimeException;

const THUMB_DIR = 'thumbs';
const FTP_BINARY = 2;
const GENERAL_MESSAGE = 1;
class ProbeComplete extends RuntimeException {}
function get_config() { return $GLOBALS['probe_config']; }
function append_sid($url) { return $url; }
function message_die($type, $message) { throw new ProbeComplete($message); }
function probe_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
$source = file_get_contents($forum_root . 'attach_mod/includes/functions_admin.php');
$start = strpos($source, 'function attach_admin_probe_error(');
$end = strpos($source, '/**' . "\n" . '* Set/Change Quotas', $start);
probe_check($start !== false && $end > $start, 'Locate actual upload diagnostic helpers');
eval('namespace AttachmentProbeRuntime; use Exception; use Error; ' . substr($source, $start, $end - $start));

function reset_probe($failure = '')
{
	$GLOBALS['probe_failure'] = $failure; $GLOBALS['probe_calls'] = array();
	$GLOBALS['probe_files'] = array('uploads/t0000' => 'keep-ftp', 'uploads/0_000000.000' => 'keep-local');
	$GLOBALS['probe_directories'] = array('uploads' => true);
	$GLOBALS['probe_cwd'] = ''; $GLOBALS['probe_random'] = 0;
	$GLOBALS['probe_stream'] = false;
}
function function_exists($name) { return $GLOBALS['probe_failure'] !== 'missing'; }
function phpbb_random_bytes($length)
{
	if ($GLOBALS['probe_failure'] === 'random') { throw new RuntimeException('entropy failure'); }
	$byte = $GLOBALS['probe_failure'] === 'collision' ? 42 : ++$GLOBALS['probe_random'];
	return str_repeat(chr($byte), $length);
}
function fopen($path, $mode)
{
	if ($GLOBALS['probe_failure'] === 'open') { return false; }
	$stream = @\fopen($path, $mode); $GLOBALS['probe_stream'] = $stream; return $stream;
}
function fwrite($stream, $data) { return $GLOBALS['probe_failure'] === 'write' ? 1 : \fwrite($stream, $data); }
function fflush($stream) { return $GLOBALS['probe_failure'] === 'flush' ? false : \fflush($stream); }
function rewind($stream) { return $GLOBALS['probe_failure'] === 'rewind' ? false : \rewind($stream); }
function unlink($path) { return $GLOBALS['probe_failure'] === 'unlink' ? false : \unlink($path); }
function ftp_call($name, $connection)
{
	probe_check(is_object($connection), 'Never pass a failed FTP connection to ' . $name);
	$GLOBALS['probe_calls'][] = $name;
}
function ftp_connect($server, $port, $timeout)
{
	$GLOBALS['probe_calls'][] = 'connect'; probe_check($timeout === 10, 'Finite FTP timeout');
	return $GLOBALS['probe_failure'] === 'connect' ? false : new \stdClass();
}
function ftp_login($connection, $user, $password) { ftp_call('login', $connection); return $GLOBALS['probe_failure'] !== 'login'; }
function ftp_pasv($connection, $mode) { ftp_call('pasv', $connection); return $GLOBALS['probe_failure'] !== 'pasv'; }
function ftp_chdir($connection, $path)
{
	ftp_call('chdir', $connection);
	if ($GLOBALS['probe_failure'] === 'path') { return false; }
	$next = $path === THUMB_DIR ? $GLOBALS['probe_cwd'] . '/' . THUMB_DIR : $path;
	if (!isset($GLOBALS['probe_directories'][$next])) { return false; }
	$GLOBALS['probe_cwd'] = $next; return true;
}
function ftp_mkdir($connection, $path)
{
	ftp_call('mkdir', $connection);
	if ($GLOBALS['probe_failure'] === 'mkdir') { return false; }
	$next = $GLOBALS['probe_cwd'] . '/' . $path; $GLOBALS['probe_directories'][$next] = true; return $next;
}
function ftp_nlist($connection, $path)
{
	ftp_call('list', $connection);
	if ($GLOBALS['probe_failure'] === 'list') { return false; }
	return array_keys($GLOBALS['probe_files']);
}
function ftp_fput($connection, $name, $stream, $mode)
{
	ftp_call('put', $connection);
	probe_check($mode === FTP_BINARY && preg_match('/^\.phpbb-test-[a-f0-9]{32}\.tmp$/D', $name), 'Random diagnostic name and explicit transfer mode');
	$path = $GLOBALS['probe_cwd'] . '/' . $name;
	probe_check(!isset($GLOBALS['probe_files'][$path]), 'Never select an existing FTP file');
	$data = stream_get_contents($stream); probe_check($data === 'test', 'Upload starts at the beginning of the owned stream');
	$GLOBALS['probe_files'][$path] = $GLOBALS['probe_failure'] === 'put' ? 'te' : $data;
	if ($GLOBALS['probe_failure'] === 'exception') { throw new RuntimeException('Remote failure, private-password'); }
	if ($GLOBALS['probe_failure'] === 'typeerror') { throw new Error('Remote failure, private-password'); }
	return $GLOBALS['probe_failure'] !== 'put';
}
function ftp_delete($connection, $name)
{
	ftp_call('delete', $connection);
	if ($GLOBALS['probe_failure'] === 'delete-exception') { throw new RuntimeException('Lost connection during deletion'); }
	if ($GLOBALS['probe_failure'] === 'delete') { return false; }
	$path = $GLOBALS['probe_cwd'] . '/' . $name;
	probe_check(isset($GLOBALS['probe_files'][$path]), 'Only the attempted test file is deleted');
	unset($GLOBALS['probe_files'][$path]); return true;
}
function ftp_close($connection) { ftp_call('close', $connection); return true; }

$lang = array();
foreach (array('Directory_does_not_exist', 'Directory_not_writeable', 'Attachment_test_temporary_failed', 'Attachment_test_cleanup_failed', 'Attachment_test_ftp_unavailable', 'Attachment_test_ftp_invalid', 'Attachment_test_ftp_listing', 'Ftp_error_connect', 'Ftp_error_login', 'Ftp_error_path', 'Ftp_error_upload', 'Ftp_error_pasv_mode') as $key) { $lang[$key] = $key . ': %s'; }
$config = array('allow_ftp_upload' => '1', 'img_create_thumbnail' => '1', 'ftp_server' => 'fixture.invalid', 'ftp_user' => 'fixture', 'ftp_pass' => 'private-password', 'ftp_path' => 'uploads', 'ftp_pasv_mode' => '1', 'upload_dir' => 'files');
foreach (array('', 'missing', 'connect', 'login', 'pasv', 'path', 'list', 'random', 'open', 'write', 'rewind', 'put', 'exception', 'delete', 'delete-exception') as $failure)
{
	reset_probe($failure); $before = $GLOBALS['probe_files'];
	$error = attach_admin_test_settings($config, './');
	probe_check(($error === '') === ($failure === ''), 'Expected FTP diagnostic outcome: ' . $failure);
	probe_check(strpos($error, 'private-password') === false, 'Never expose exception text or password');
	if ($failure !== 'delete' && $failure !== 'delete-exception') { probe_check($GLOBALS['probe_files'] === $before, 'Existing files preserved and partial uploads cleaned: ' . $failure); }
	else { probe_check(strpos($error, 'Attachment_test_cleanup_failed') !== false, 'Report unconfirmed cleanup with filename'); }
	$calls = $GLOBALS['probe_calls'];
	if ($failure === 'missing') { probe_check(!$calls, 'Missing extension does not call FTP'); }
	elseif ($failure === 'connect') { probe_check($calls === array('connect'), 'Failed connect is not used or closed'); }
	else { probe_check(end($calls) === 'close', 'Every owned FTP session is closed: ' . $failure); }
	probe_check(!is_resource($GLOBALS['probe_stream']), 'All opened diagnostic streams closed');
}
if (PHP_VERSION_ID >= 70000)
{
	reset_probe('typeerror'); $before = $GLOBALS['probe_files'];
	probe_check(attach_admin_test_settings($config, './') !== '' && $GLOBALS['probe_files'] === $before, 'PHP8 errors clean partial uploads without becoming HTTP500');
}
reset_probe('collision');
$GLOBALS['probe_files']['uploads/.PHPBB-TEST-' . str_repeat('2A', 16) . '.TMP'] = 'keep-collision';
$before = $GLOBALS['probe_files'];
probe_check(attach_admin_test_settings($config, './') !== '' && $GLOBALS['probe_files'] === $before && !in_array('put', $GLOBALS['probe_calls']), 'FTP name collision does not overwrite or delete a file');
reset_probe(); $before = $GLOBALS['probe_files'];
probe_check(attach_admin_test_settings($config, './', true) === '' && isset($GLOBALS['probe_directories']['uploads/thumbs']) && $GLOBALS['probe_files'] === $before, 'Thumbnail diagnostic creates only the configured thumbnail directory');
reset_probe('mkdir'); probe_check(attach_admin_test_settings($config, './', true) !== '' && !in_array('put', $GLOBALS['probe_calls']), 'Failed thumbnail directory creation stops upload');
reset_probe(); $disabled = $config; $disabled['img_create_thumbnail'] = '0';
probe_check(attach_admin_test_settings($disabled, './', true) === '' && !$GLOBALS['probe_calls'], 'Disabled thumbnails do not run a probe');
reset_probe(); $invalid = $config; $invalid['ftp_user'] = "fixture\r\nINJECT";
probe_check(attach_admin_test_settings($invalid, './') !== '' && !$GLOBALS['probe_calls'], 'Control characters stop before connection');
probe_check(strpos(attach_admin_probe_error('Ftp_error_path', '<script>'), '<script>') === false, 'Configuration details are escaped');

$temporary = sys_get_temp_dir() . '/phpbb-upload-probe-' . uniqid('', true);
probe_check(mkdir($temporary . '/files', 0700, true), 'Create isolated local fixture');
file_put_contents($temporary . '/files/0_000000.000', 'keep-local');
$local = $config; $local['allow_ftp_upload'] = '0';
try
{
	foreach (array('', 'open', 'write', 'flush', 'random', 'unlink') as $failure)
	{
		reset_probe($failure); $error = attach_admin_test_settings($local, $temporary . '/');
		probe_check(($error === '') === ($failure === ''), 'Expected local diagnostic result: ' . $failure);
		probe_check(file_get_contents($temporary . '/files/0_000000.000') === 'keep-local', 'Legacy fixed-name file is untouched');
		probe_check(!is_resource($GLOBALS['probe_stream']), 'Local test handle closed');
		$remaining = glob($temporary . '/files/.phpbb-test-*.tmp');
		probe_check(count($remaining) === ($failure === 'unlink' ? 1 : 0), 'Only explicitly reported cleanup failures leave a diagnostic file');
		foreach ($remaining as $path) { \unlink($path); }
	}
	reset_probe('collision'); $collision = $temporary . '/files/.phpbb-test-' . str_repeat('2a', 16) . '.tmp';
	file_put_contents($collision, 'keep-collision');
	probe_check(attach_admin_test_settings($local, $temporary . '/') !== '' && file_get_contents($collision) === 'keep-collision', 'Exclusive local open preserves an existing random-name collision');
	\unlink($collision);
	reset_probe(); probe_check(attach_admin_test_settings($local, $temporary . '/', true) === '' && is_dir($temporary . '/files/thumbs'), 'Local thumbnail diagnostic works and cleans up');
	foreach (array('', 'php://temp', "bad\0path", 'missing') as $path)
	{
		$bad = $local; $bad['upload_dir'] = $path;
		probe_check(attach_admin_test_settings($bad, $temporary . '/') !== '', 'Reject invalid local path');
	}
}
finally
{
	foreach (glob($temporary . '/files/.phpbb-test-*.tmp') as $path) { \unlink($path); }
	if (is_dir($temporary . '/files/thumbs')) { rmdir($temporary . '/files/thumbs'); }
	\unlink($temporary . '/files/0_000000.000'); rmdir($temporary . '/files'); rmdir($temporary);
}
$controller = file_get_contents($forum_root . 'admin/admin_attachments.php');
probe_check(substr_count($controller, 'attach_admin_test_settings($attach_config, $phpbb_root_path, ') === 2, 'Both real ACP actions use the shared probe');
probe_check(strpos($controller, '0_000000.000') === false && strpos($controller, "'t0000'") === false && strpos($controller, 'tempnam(') === false, 'Remove fixed filenames and unlink/reopen races from both actions');
foreach (array('english', 'german') as $language)
{
	$lang = array(); require $forum_root . 'language/lang_' . $language . '/lang_main_attach.php';
	foreach (array('Attachment_test_temporary_failed', 'Attachment_test_cleanup_failed', 'Attachment_test_ftp_unavailable', 'Attachment_test_ftp_invalid', 'Attachment_test_ftp_listing') as $key)
	{
		probe_check(isset($lang[$key]) && attach_admin_probe_error($key, '<unsafe>') !== '' && strpos(attach_admin_probe_error($key, '<unsafe>'), '<unsafe>') === false, 'Complete and escaped diagnostic translation: ' . $language . '/' . $key);
	}
}
// Execute both real controller branches. Their successful result must stop in
// message_die; failures must flow into the existing ACP error box instead.
$lang['Test_settings_successful'] = 'Probe succeeded'; $lang['Click_return_attach_config'] = '%sBack%s'; $lang['Click_return_admin_index'] = '%sAdmin%s';
$phpbb_root_path = './'; $phpEx = 'php'; $GLOBALS['probe_config'] = $config;
foreach (array(array('// Check Settings', '// Management', false), array('// Check Cat Settings', "if (\$mode == 'sync' && !\$sync_confirm)", true)) as $section)
{
	$start = strpos($controller, $section[0]); $end = strpos($controller, $section[1], $start);
	probe_check($start !== false && $end > $start, 'Locate actual controller branch');
	$branch = substr($controller, $start, $end - $start);
	$check_upload = !$section[2]; $check_image_cat = $section[2];
	foreach (array('', 'connect') as $failure)
	{
		reset_probe($failure); $error = false; $error_msg = ''; $completed = false;
		try { eval('namespace AttachmentProbeRuntime; ' . $branch); }
		catch (ProbeComplete $done) { $completed = true; }
		probe_check($completed === ($failure === '') && $error === ($failure !== ''), 'Actual ACP success/error routing');
	}
}
restore_error_handler();
echo "Attachment upload diagnostic checks passed.\n";
