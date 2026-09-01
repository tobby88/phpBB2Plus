<?php

function runtime_diagnostics_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Runtime diagnostics safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$nuffload = file_get_contents($root . '/phpBB2/album_nuffload.php');
$captcha = file_get_contents($root . '/phpBB2/includes/usercp_confirm_adv.php');
$admin = file_get_contents($root . '/phpBB2/admin/admin_phpinfo.php');
$constants = file_get_contents($root . '/phpBB2/includes/constants.php');
$functions = file_get_contents($root . '/phpBB2/includes/functions.php');
$database = file_get_contents($root . '/phpBB2/includes/class_db.php');
$sessions = file_get_contents($root . '/phpBB2/includes/sessions.php');
$common = file_get_contents($root . '/phpBB2/common.php');

runtime_diagnostics_assert(strpos($nuffload, 'phpinfo(') === false, 'Nuffload must not capture full PHP diagnostics');
runtime_diagnostics_assert(strpos($captcha, 'phpinfo(') === false, 'visual confirmation must not capture full PHP diagnostics');
runtime_diagnostics_assert(strpos($admin, 'phpinfo(') === false, 'AdminCP diagnostics must not expose full PHP diagnostics');
runtime_diagnostics_assert(strpos($admin, 'INFO_VARIABLES') === false, 'AdminCP diagnostics must not expose request variables');
runtime_diagnostics_assert(strpos($admin, "get_loaded_extensions()") !== false, 'AdminCP must retain a useful extension summary');
runtime_diagnostics_assert(strpos($admin, "ini_get('upload_max_filesize')") !== false, 'AdminCP must retain upload diagnostics');
runtime_diagnostics_assert(strpos($constants, "define('DEBUG', false)") !== false, 'production builds must default to disabled debug output');
runtime_diagnostics_assert(strpos($database, "defined('DEBUG') && DEBUG && !defined('DEBUG_RUN_STATS')") !== false, 'SQL timing must run only when debug is deliberately enabled');
runtime_diagnostics_assert(substr_count($functions, 'phpbb_debug_details_allowed()') >= 4, 'error details must be confined to authenticated administrators');
runtime_diagnostics_assert(strpos($functions, "if ( DEBUG && ( \$msg_code") === false, 'message_die must not expose SQL details merely because DEBUG is enabled');
runtime_diagnostics_assert(strpos($sessions, 'glob($phpbb_root_path') === false, 'session creation must not scan and delete obsolete cache files');
runtime_diagnostics_assert(strpos($common, 'phpbb_rotate_local_log($phpbb_error_log, 8388608, 2);') !== false, 'the local PHP error log must have a bounded rotation policy');

require_once $root . '/phpBB2/includes/php_compat.php';
$rotation_root = sys_get_temp_dir() . '/phpbb-log-rotation-' . getmypid();
mkdir($rotation_root, 0700, true);
$rotation_log = $rotation_root . '/php_errors.log';
file_put_contents($rotation_log, str_repeat('current', 200));
file_put_contents($rotation_log . '.1', 'previous');
file_put_contents($rotation_log . '.2', 'oldest');
runtime_diagnostics_assert(phpbb_rotate_local_log($rotation_log, 1024, 2), 'an oversized local error log was not rotated');
runtime_diagnostics_assert(!file_exists($rotation_log), 'rotation left the oversized active logfile in place');
runtime_diagnostics_assert(file_get_contents($rotation_log . '.1') === str_repeat('current', 200), 'the current logfile was not preserved as the newest backup');
runtime_diagnostics_assert(file_get_contents($rotation_log . '.2') === 'previous', 'the preceding logfile backup was not shifted');
runtime_diagnostics_assert(!phpbb_rotate_local_log($rotation_log . '.1', 4096, 2), 'a below-limit logfile was rotated');

@unlink($rotation_log . '.rotate.lock');
@unlink($rotation_log . '.1');
@unlink($rotation_log . '.2');
@rmdir($rotation_root);

echo "Runtime diagnostics safety checks passed.\n";
