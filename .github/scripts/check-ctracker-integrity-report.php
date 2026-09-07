<?php
define('IN_PHPBB', true);
define('CTRACKER_ACP', true);
define('CTRACKER_FILECHK', 'fixture_hash');
define('CRITICAL_ERROR', 1);
function message_die($level, $message) { throw new RuntimeException($message); }
function phpbb_admin_post_string($key) { return ''; }
function phpbb_admin_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function phpbb_admin_session_field() { return ''; }
function append_sid($value) { return $value; }
function integrity_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
require $forum_root . 'ctracker/classes/class_ct_adminfunctions.php';
class IntegrityDatabase
{
	var $rows = array();
	function sql_query($sql) { integrity_assert($sql === 'SELECT * FROM fixture_hash', 'Report must remain read-only'); return true; }
	function sql_fetchrow($result) { return $this->rows ? array_shift($this->rows) : false; }
}
class IntegrityTemplate
{
	var $rows = array();
	function assign_block_vars($name, $data) { if ($name === 'file_output') { $this->rows[] = $data; } }
	function assign_vars($values) {}
	function set_filenames($values) {}
	function pparse($name) {}
}
class IntegrityProbeStream
{
	public $context;
	function url_stat($path, $flags) { $GLOBALS['integrity_stream_probes']++; return false; }
}
function integrity_report($rows, $language)
{
	global $forum_root, $phpbb_root_path;
	$phpEx = 'php';
	$lang = array(); require $forum_root . 'language/lang_' . $language . '/lang_cback_ctracker.php';
	$db = new IntegrityDatabase(); $db->rows = $rows; $GLOBALS['db'] = $db;
	$template = new IntegrityTemplate();
	$ctracker_config = new stdClass(); $ctracker_config->settings = array('last_checksum_scan' => 0);
	$board_config = array('default_dateformat' => 'Y-m-d'); $phpEx = 'php';
	$images = array('ctracker_fc_icon_1' => 'one.png', 'ctracker_fc_icon_2' => 'two.png');
	$_GET = array('action' => 'chk'); $_POST = array();
	include $forum_root . 'ctracker/admin/acp_module_changedfiles.php';
	return array($template->rows, $lang);
}
$root = sys_get_temp_dir() . '/ct-integrity-' . md5(uniqid('', true));
mkdir($root, 0700); mkdir($root . '/blocked', 0700);
file_put_contents($root . '/good.php', "<?php echo 'fixture';");
file_put_contents($root . '/blocked/inside.php', "<?php echo 'inside';");
$phpbb_root_path = $root;
$integrity_stream_probes = 0;
stream_wrapper_register('ctfixture', 'IntegrityProbeStream');
$link = $root . '/link.php';
$has_link = function_exists('symlink') && @symlink($root . '/good.php', $link);
try
{
	$admin = new ct_adminfunctions();
	$backtrack_limit = ini_get('pcre.backtrack_limit');
	ini_set('pcre.backtrack_limit', '0');
	try
	{
		integrity_assert(!$admin->is_local_file_path('ctfixture://payload.php') &&
			!$admin->is_local_file_path('0ctfixture://payload.php') &&
			$admin->is_local_file_path($root . '/good.php'), 'Local-path boundary must work independently of PCRE limits');
	}
	finally { ini_set('pcre.backtrack_limit', $backtrack_limit); }
	integrity_assert($admin->file_checksum('ctfixture://payload.php') === false &&
		$admin->file_checksum('ctfixture://payload.php', $root) === false &&
		$admin->resolve_file_within_root('ctfixture://payload.php', $root) === false &&
		$integrity_stream_probes === 0, 'Direct checksum helpers must not invoke stream wrappers');
	integrity_assert(!method_exists($admin, 'DropData'), 'Unused destructive report truncation must remain removed');
	foreach (array('build_filechk', 'build_file_scan') as $method)
	{
		$reflection = new ReflectionMethod($admin, $method);
		integrity_assert($reflection->isPrivate(), 'Rebuild internals must not be callable without their lock wrapper');
	}
	foreach (array('english', 'german') as $language)
	{
		$hash = hash_file('sha256', $root . '/good.php');
		$cases = array(
			array($root . '/good.php', $hash, 'unchanged'),
			array($root . '/good.php', strtoupper($hash), 'unchanged'),
			array($root . '/good.php', str_repeat('0', 64), 'changed'),
			array($root . '/good.php', 'old-checksum', 'legacy_checksum'),
			array($root . '/good.php', str_repeat('z', 64), 'legacy_checksum'),
			array($root . '/missing.php', $hash, 'deleted'),
			array(__FILE__, $hash, 'unreadable'),
			array('ctfixture://payload.php', $hash, 'unreadable'),
			array('0ctfixture://payload.php', $hash, 'unreadable'),
			array('ctfixture://<script>payload</script>', $hash, 'unreadable'),
			array($root . '/not-a-directory/missing.php', $hash, 'unreadable'),
			array($root . '/blocked', $hash, 'unreadable'),
			array(null, $hash, 'unreadable'),
			array($root . "/bad\0path.php", $hash, 'unreadable')
		);
		if ($has_link) { $cases[] = array($link, $hash, 'unreadable'); }
		$rows = array();
		foreach ($cases as $case) { $rows[] = array('filepath' => $case[0], 'hash' => $case[1]); }
		list($rendered, $lang) = integrity_report($rows, $language);
		integrity_assert(count($rendered) === count($cases), 'Every baseline entry should have a visible result');
		foreach ($cases as $index => $case)
		{
			integrity_assert($rendered[$index]['STATUS'] === $lang['ctracker_file_' . $case[2]], 'Wrong ' . $language . ' integrity status for case ' . $index . ': ' . $case[2]);
			integrity_assert(strpos($rendered[$index]['PATH'], '<script>') === false, 'Displayed baseline path must be HTML escaped');
		}
		integrity_assert($integrity_stream_probes === 0, 'Persisted paths must not invoke stream wrappers, even for metadata');
	}
	if (DIRECTORY_SEPARATOR === '/')
	{
		chmod($root . '/good.php', 0000); clearstatcache();
		if (!is_readable($root . '/good.php'))
		{
			list($rows, $lang) = integrity_report(array(array('filepath' => $root . '/good.php', 'hash' => $hash)), 'english');
			integrity_assert($rows[0]['STATUS'] === $lang['ctracker_file_unreadable'], 'Unreadable existing file is not deleted');
		}
		chmod($root . '/good.php', 0600);
		chmod($root . '/blocked', 0000); clearstatcache();
		if (!is_readable($root . '/blocked'))
		{
			list($rows, $lang) = integrity_report(array(array('filepath' => $root . '/blocked/inside.php', 'hash' => $hash)), 'english');
			integrity_assert($rows[0]['STATUS'] === $lang['ctracker_file_unreadable'], 'Inaccessible directory is not evidence of deletion');
		}
		chmod($root . '/blocked', 0400); clearstatcache();
		if (!is_executable($root . '/blocked'))
		{
			integrity_assert(!$admin->missing_file_within_root($root . '/blocked/inside.php', $root), 'A readable but unsearchable parent cannot prove absence');
		}
	}
	echo "CrackerTracker integrity report tests passed.\n";
}
finally
{
	stream_wrapper_unregister('ctfixture');
	chmod($root . '/blocked', 0700); chmod($root . '/good.php', 0600);
	if ($has_link) { unlink($link); }
	unlink($root . '/good.php'); unlink($root . '/blocked/inside.php');
	rmdir($root . '/blocked'); rmdir($root);
}
