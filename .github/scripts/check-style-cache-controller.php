<?php
// Real cache controller, disposable filesystem, rendering/database-list doubles.
// This does not substitute for native database authorization tests.
$source = dirname(dirname(__DIR__)) . '/phpBB2/';
if (!isset($argv[1]))
{
	$cases = array('all', 'all-missing', 'selected', 'zero', 'array', 'null', 'newline', 'bad-sid', 'get', 'compile-array', 'compile-zero', 'compile-escaped', 'compile-failed');
	foreach ($cases as $case)
	{
		$options = DIRECTORY_SEPARATOR === '\\' ? ' -n ' : ' ';
		$process = proc_open(escapeshellarg(PHP_BINARY) . $options . escapeshellarg(__FILE__) . ' ' . escapeshellarg($case),
			array(0=>array('pipe','r'), 1=>array('pipe','w'), 2=>array('pipe','w')), $pipes, null, null, array('bypass_shell'=>true));
		if (!is_resource($process)) { throw new RuntimeException('Cannot start cache fixture'); }
		fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
		if ($status !== 0 || $errors !== '') { throw new RuntimeException($case . ': ' . $output . $errors); }
	}
	echo "Style cache controller: " . count($cases) . " isolated request cases passed.\n";
	return;
}
define('IN_PHPBB', true); define('GENERAL_ERROR', 2); define('GENERAL_MESSAGE', 1); define('THEMES_TABLE', 'fixture_themes');
require $source . 'includes/php_compat.php';
foreach (array('includes/template.php'=>array('XS_TPL_PREFIX', 'XS_SEPARATOR'),
	'admin/xs_include.php'=>array('STYLE_EXTENSION', 'XS_BACKUP_EXT', 'XS_MAX_TIMEOUT', 'XS_TPL_PATH')) as $file=>$names)
{
	$text = file_get_contents($source . $file);
	foreach ($names as $name)
	{
		if (!preg_match('/define\(\x27' . $name . '\x27, [^;]+;/', $text, $match)) { throw new RuntimeException('Missing production constant'); }
		eval($match[0]);
	}
}
$bootstrap = file_get_contents($source . 'admin/pagestart.php');
$start = strpos($bootstrap, "if (!function_exists('phpbb_admin_post_session_valid'))");
$end = strpos($bootstrap, "if (!function_exists('phpbb_admin_post_string'))", $start);
eval(substr($bootstrap, $start, $end - $start));
class CacheFixtureExit extends RuntimeException {}
function message_die($level, $message) { throw new CacheFixtureExit($message); }
function append_sid($url) { return $url; }
function xs_in_array($value, $list) { return in_array($value, $list, true); }
function xs_exit() { throw new CacheFixtureExit('rendered'); }
class CacheFixtureTemplate
{
	var $xs_version = 8; var $cachedir; var $tpldir; var $vars = array(); var $compiled = array();
	function assign_block_vars($name, $vars) {}
	function set_filenames($files) {}
	function assign_vars($vars) { $this->vars = $vars; }
	function pparse($name) {}
	function precompile($tpl, $file) { $this->compiled[] = $tpl . '/' . $file; return $GLOBALS['case'] !== 'compile-failed'; }
}
class CacheFixtureDatabase
{
	function sql_query($sql) { return true; }
	function sql_fetchrowset($result) { return array(); }
}
function cache_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function cache_clean($path, $root)
{
	if ($path !== $root && strpos($path, $root . '/') !== 0) { throw new RuntimeException('Cleanup outside fixture'); }
	if (is_link($path) || is_file($path)) { unlink($path); return; }
	foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { cache_clean($path . '/' . $name, $root); } }
	rmdir($path);
}
$case = $argv[1]; $root = str_replace('\\', '/', sys_get_temp_dir()) . '/codex_cache_' . bin2hex(phpbb_random_bytes(8));
$previous = getcwd(); mkdir($root, 0700);
try
{
	set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
	foreach (array('admin', 'cache', 'templates', 'templates/fixture', 'templates/0') as $dir) { mkdir($root . '/' . $dir, 0700); }
	foreach (array('extension.inc'=>"<?php \$phpEx='php';", 'admin/pagestart.php'=>'<?php /* Request identity supplied by fixture. */',
		'admin/xs_include.php'=>'<?php /* Rendering bootstrap only. */') as $file=>$text) { file_put_contents($root . '/' . $file, $text); }
	$journal = 'xs-import-' . str_repeat('a', 32) . '.backup'; mkdir($root . '/cache/' . $journal, 0700);
	$protected = array('.htaccess', 'index.htm', 'index.html', 'index.php', 'attach_config_data.cache', 'saved.style', 'saved.backup', $journal . '/manifest.backup.php');
	$selected = XS_TPL_PREFIX . 'fixture' . XS_SEPARATOR . 'page.cache';
	$zero = XS_TPL_PREFIX . '0' . XS_SEPARATOR . 'page.cache';
	$unusual = "a&'name.cache";
	foreach (array_merge($protected, array($selected, $zero, $unusual)) as $file) { file_put_contents($root . '/cache/' . $file, 'unchanged'); }
	foreach (array('fixture', '0') as $name) { file_put_contents($root . '/templates/' . $name . '/overall_header.tpl', 'template'); }
	if ($case === 'compile-escaped' || $case === 'compile-failed') { file_put_contents($root . "/templates/fixture/a&'name.tpl", 'template'); }
	$template = new CacheFixtureTemplate(); $template->cachedir = $root . '/cache/'; $template->tpldir = $root . '/templates/';
	$db = new CacheFixtureDatabase(); $lang = array('Not_Authorised'=>'denied'); require $source . 'language/lang_english/lang_xs.php';
	$userdata = array('session_id'=>'fixture-session'); $_SERVER['REQUEST_METHOD'] = $case === 'get' ? 'GET' : 'POST';
	$_POST = array('sid'=>$case === 'bad-sid' ? 'wrong' : 'fixture-session', 'template'=>'');
	$_POST[strpos($case, 'compile-') === 0 ? 'compile_cache' : 'clear_cache'] = '1';
	if ($case === 'all-missing') { unset($_POST['template']); }
	if ($case === 'selected' || $case === 'compile-escaped' || $case === 'compile-failed') { $_POST['template'] = 'fixture'; }
	if ($case === 'zero' || $case === 'compile-zero') { $_POST['template'] = '0'; }
	if ($case === 'array' || $case === 'compile-array') { $_POST['template'] = array('fixture'); }
	if ($case === 'null') { $_POST['template'] = null; }
	if ($case === 'newline') { $_POST['template'] = "fixture\n"; }
	$HTTP_POST_VARS = $_POST; chdir($root . '/admin');
	try { include $source . 'admin/xs_cache.php'; throw new RuntimeException('Controller did not terminate'); }
	catch (CacheFixtureExit $exit) { $result = $exit->getMessage(); }
	$denied = in_array($case, array('array', 'null', 'newline', 'bad-sid', 'compile-array'), true);
	cache_check($denied ? $result !== 'rendered' : $result === 'rendered', 'Request rejection/rendering');
	foreach ($protected as $file) { cache_check(file_get_contents($root . '/cache/' . $file) === 'unchanged', 'Protected bytes: ' . $file); }
	foreach (array($selected, $zero, $unusual) as $file)
	{
		$deleted = $case === 'all' || $case === 'all-missing' || ($case === 'selected' && $file === $selected) || ($case === 'zero' && $file === $zero);
		cache_check(file_exists($root . '/cache/' . $file) !== $deleted, 'Deletion scope: ' . $file);
	}
	$compiled = $case === 'compile-zero' ? array('0/overall_header.tpl') : array();
	if ($case === 'compile-escaped' || $case === 'compile-failed')
	{
		$compiled = array("fixture/a&'name.tpl", 'fixture/overall_header.tpl');
		cache_check(strpos($template->vars['RESULT'], htmlspecialchars($root . "/templates/fixture/a&'name.tpl", ENT_QUOTES, 'UTF-8')) !== false, 'Escaped compiler path');
	}
	sort($compiled); sort($template->compiled);
	cache_check($template->compiled === $compiled, 'Compile scope');
	if ($case === 'all') { cache_check(strpos($template->vars['RESULT'], htmlspecialchars($unusual, ENT_QUOTES, 'UTF-8')) !== false, 'Escaped filename in result'); }
}
finally { chdir($previous); restore_error_handler(); cache_clean($root, $root); }
