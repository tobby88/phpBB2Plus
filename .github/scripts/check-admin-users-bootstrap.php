<?php
// Load the actual user-manager dependencies in a fresh process. A helper being
// available in some other module/test must not hide a missing runtime include.
define('IN_PHPBB', true);
$phpbb_root_path = dirname(dirname(__DIR__)) . '/phpBB2/'; $phpEx = 'php';
$source = file_get_contents($phpbb_root_path . 'admin/admin_users.php');
$end = strpos($source, '$html_entities_match =');
if ($end === false) { throw new RuntimeException('User-manager bootstrap boundary missing'); }
preg_match_all('/^require_once\(\$phpbb_root_path \. \'includes\/[^\']+\'\.\$phpEx\);/m', substr($source, 0, $end), $includes);
foreach ($includes[0] as $include) { eval($include); }
foreach (array('phpbb_removal_pending_html', 'phpbb_admin_user_remove', 'phpbb_cleanup_removed_user_references') as $function) {
    if (!function_exists($function)) { throw new RuntimeException('User-manager dependency missing: ' . $function); }
}
echo "Actual user-manager bootstrap loads pending jobs, removal and cleanup helpers.\n";
