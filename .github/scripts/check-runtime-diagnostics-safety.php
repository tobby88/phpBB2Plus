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

runtime_diagnostics_assert(strpos($nuffload, 'phpinfo(') === false, 'Nuffload must not capture full PHP diagnostics');
runtime_diagnostics_assert(strpos($captcha, 'phpinfo(') === false, 'visual confirmation must not capture full PHP diagnostics');
runtime_diagnostics_assert(strpos($admin, 'phpinfo(') === false, 'AdminCP diagnostics must not expose full PHP diagnostics');
runtime_diagnostics_assert(strpos($admin, 'INFO_VARIABLES') === false, 'AdminCP diagnostics must not expose request variables');
runtime_diagnostics_assert(strpos($admin, "get_loaded_extensions()") !== false, 'AdminCP must retain a useful extension summary');
runtime_diagnostics_assert(strpos($admin, "ini_get('upload_max_filesize')") !== false, 'AdminCP must retain upload diagnostics');

echo "Runtime diagnostics safety checks passed.\n";
