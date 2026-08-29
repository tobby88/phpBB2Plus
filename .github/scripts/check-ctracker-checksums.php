<?php

define('IN_PHPBB', true);
define('CTRACKER_ACP', true);

$repository_root = dirname(dirname(__DIR__));
require $repository_root . '/phpBB2/ctracker/classes/class_ct_adminfunctions.php';

function checksum_test_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Checksum test failed: $message\n");
		exit(1);
	}
}

$first_path = tempnam(sys_get_temp_dir(), 'ctsum-a-');
$second_path = tempnam(sys_get_temp_dir(), 'ctsum-b-');
checksum_test_assert($first_path !== false && $second_path !== false, 'temporary files could not be created');

file_put_contents($first_path, "AAAA\nBBBB\n");
file_put_contents($second_path, "CCCC\nDDDD\n");

$admin = new ct_adminfunctions();
$first_hash = $admin->file_checksum($first_path);
$second_hash = $admin->file_checksum($second_path);

checksum_test_assert(filesize($first_path) === filesize($second_path), 'fixtures must have equal sizes');
checksum_test_assert(strlen($first_hash) === 64 && strlen($second_hash) === 64, 'SHA-256 hashes must be 64 characters');
checksum_test_assert($first_hash !== $second_hash, 'same-size and same-line-count content changes must be detected');
checksum_test_assert($admin->file_checksum($first_path . '.missing') === false, 'missing files must not produce a checksum');
checksum_test_assert($admin->file_checksum($first_path, dirname($first_path)) === $first_hash, 'a file inside the required root must be accepted');
checksum_test_assert($admin->file_checksum(__FILE__, dirname($first_path)) === false, 'a file outside the required root must be rejected');

@unlink($first_path);
@unlink($second_path);

$class_source = file_get_contents($repository_root . '/phpBB2/ctracker/classes/class_ct_adminfunctions.php');
checksum_test_assert(strpos($class_source, "hash_file('sha256'") !== false, 'scanner must use SHA-256 content hashing');
checksum_test_assert(strpos($class_source, '@is_link($path)') !== false, 'scanner must skip symbolic links');
checksum_test_assert(strpos($class_source, 'RENAME TABLE') !== false, 'baseline replacement must use an atomic table rename');
checksum_test_assert(strpos($class_source, '$db->sql_escape($stored_path)') !== false, 'stored paths must be escaped');

echo "CrackerTracker checksum tests passed.\n";
