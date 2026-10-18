<?php
// Real local file helpers, no database/network and only an owned temporary tree.
define('MODE_THUMBNAIL', 1);
define('THUMB_DIR', 'thumbs');
require dirname(dirname(__DIR__)) . '/phpBB2/attach_mod/includes/functions_attach.php';
require dirname(dirname(__DIR__)) . '/phpBB2/attach_mod/includes/functions_delete.php';
function local_path_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
$temporary = sys_get_temp_dir() . '/phpbb-local-attachment-' . uniqid('', true);
$saved_cwd = getcwd();
$directories = array($temporary, $temporary . '/files', $temporary . '/files/thumbs', $temporary . '/admin', $temporary . '/admin/files', $temporary . '/admin/files/thumbs');
$owned_files = array();
foreach ($directories as $directory) { local_path_check(mkdir($directory, 0700), 'Own local directory'); }
try
{
    foreach (array('admin-raw', 'admin-prefixed', 'frontend-raw', 'absolute') as $case)
    {
        foreach (array('/files/owned.txt', '/files/thumbs/t_owned.txt', '/admin/files/owned.txt', '/admin/files/thumbs/t_owned.txt') as $suffix)
        {
            $file = $temporary . $suffix;
            local_path_check(file_put_contents($file, strpos($suffix, '/admin/') === 0 ? 'do not touch' : 'owned') !== false, 'Own file fixture');
            $owned_files[$file] = $file;
        }
        $attach_config = array('allow_ftp_upload' => '0', 'upload_dir' => 'files');
        $upload_dir = $case === 'absolute' ? $temporary . '/files' : ($case === 'admin-prefixed' ? '../files' : 'files');
        $phpbb_root_path = $case === 'frontend-raw' ? './' : '../';
        local_path_check(chdir($case === 'frontend-raw' ? $temporary : $temporary . '/admin'), 'Own working directory');
        local_path_check(attach_storage_file_entries(false, true) === array(array('name' => 'owned.txt', 'size' => 5)), 'Inventory resolves real forum storage, not an ACP decoy: ' . $case);
        local_path_check(attach_storage_file_entries(MODE_THUMBNAIL, true) === array(array('name' => 't_owned.txt', 'size' => 5)), 'Thumbnail inventory uses the same storage root: ' . $case);
        $unchanged = $upload_dir;
        local_path_check(attach_delete_file('owned.txt') && attach_delete_file('owned.txt', MODE_THUMBNAIL), 'Real local deletion succeeds: ' . $case);
        clearstatcache();
        local_path_check(!is_file($temporary . '/files/owned.txt') && !is_file($temporary . '/files/thumbs/t_owned.txt'), 'Selected bytes removed: ' . $case);
        local_path_check(file_get_contents($temporary . '/admin/files/owned.txt') === 'do not touch' && file_get_contents($temporary . '/admin/files/thumbs/t_owned.txt') === 'do not touch', 'Unrelated ACP decoy preserved: ' . $case);
        local_path_check(attach_delete_file('owned.txt') && attach_delete_file('owned.txt', MODE_THUMBNAIL), 'Confirmed missing files remain idempotent: ' . $case);
        local_path_check($upload_dir === $unchanged && $attach_config['upload_dir'] === 'files', 'Do not rewrite URL/config globals: ' . $case);
    }
    $upload_dir = 'files'; $phpbb_root_path = '../';
    local_path_check(chdir($temporary . '/admin') && mkdir($temporary . '/files/conflict.txt', 0700), 'Own conflicting directory');
    try { local_path_check(!attach_delete_file('conflict.txt'), 'Resolved-path directory conflict is not confirmed missing'); }
    finally { rmdir($temporary . '/files/conflict.txt'); }
    foreach (array('', array('files'), "files\0invalid", 'php://memory', 'file:///tmp') as $invalid)
    {
        $upload_dir = $invalid;
        local_path_check(attach_storage_file_entries(false, true) === false && !attach_delete_file('owned.txt') && !unlink_attach('owned.txt', false, true), 'Invalid local directory fails closed');
    }
    foreach (array('/srv/forum/files', 'C:\\forum\\files', '\\\\server\\share\\files') as $absolute)
    {
        $upload_dir = $absolute; $attach_config['upload_dir'] = $absolute;
        local_path_check(attach_local_storage_directory() === $absolute, 'Preserve supported absolute configured paths');
    }
    $upload_dir = 'files'; $attach_config['upload_dir'] = 'files'; $phpbb_root_path = '';
    local_path_check(attach_local_storage_directory() === './files', 'Missing root must never turn a relative directory into /files');
    echo "Attachment local paths: root/raw-ACP/prefixed-ACP/absolute inventory, deletion, conflicts, isolation and validation passed.\n";
}
finally
{
    chdir($saved_cwd);
    foreach ($owned_files as $file) { if (is_file($file)) { unlink($file); } }
    foreach (array_reverse($directories) as $directory) { rmdir($directory); }
}
