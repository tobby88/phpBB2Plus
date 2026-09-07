<?php
define('IN_PHPBB', true);
define('GENERAL_ERROR', 202);
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
$phpbb_root_path = $forum_root; $phpEx = 'php'; $table_prefix = 'fixture_';
function attachment_config_check($ok, $message)
{
	if (!$ok) { throw new RuntimeException($message); }
}
$functions = file_get_contents($forum_root . 'includes/functions.php');
$helper_start = strpos($functions, 'function phpbb_load_config_table(');
$helper_end = strpos($functions, 'function phpbb_data_cache_read(', $helper_start);
attachment_config_check($helper_start !== false && $helper_end > $helper_start, 'Locate real configuration loader');
eval(substr($functions, $helper_start, $helper_end - $helper_start));
function phpbb_data_cache_read($filename) { throw new RuntimeException('Configuration must not read a persistent cache'); }
function phpbb_data_cache_write($filename, $data) { throw new RuntimeException('Configuration must not publish a persistent cache'); }
set_error_handler(function ($severity, $message) { throw new RuntimeException($message); });
class AttachmentConfigFailure extends RuntimeException {}
function message_die($type, $message, $title = '', $line = 0, $file = '', $sql = '')
{
	throw new AttachmentConfigFailure($message);
}
class AttachmentConfigReadFixture
{
	var $values = array('allow_ftp_upload' => '0', 'upload_dir' => ' files ', 'disable_mod' => '0', 'max_filesize' => '262144', 'board_lang' => 'obsolete');
	var $queries = array(); var $freed = 0; var $fail = false; var $after_snapshot = null;
	function sql_query($sql)
	{
		$this->queries[] = $sql;
		attachment_config_check(preg_match('/^SELECT (?:\*|config_name, config_value)\s+FROM fixture_attachments_config$/D', $sql) === 1, 'Only the attachment configuration may be queried');
		if ($this->fail) { return false; }
		$result = new stdClass(); $result->rows = array();
		foreach ($this->values as $key => $value) { $result->rows[] = array('config_name' => $key, 'config_value' => $value); }
		if ($this->after_snapshot !== null) { $this->values = $this->after_snapshot; $this->after_snapshot = null; }
		return $result;
	}
	function sql_fetchrow($result) { return $result->rows ? array_shift($result->rows) : false; }
	function sql_freeresult($result) { $this->freed++; }
}
$db = new AttachmentConfigReadFixture(); $board_config = array('default_lang' => ' german ');
require $forum_root . 'attach_mod/attachment_mod.php';
attachment_config_check($attach_config['board_lang'] === 'german' && $upload_dir === 'files', 'Actual bootstrap preserves trimmed settings and current default language');
attachment_config_check(count($db->queries) === 1 && $db->freed === 1, 'Bootstrap reads one buffered result and releases it');

// Reuse the real bootstrap with a stale historical file in an isolated cache.
// A request that snapshots settings before an ACP update may finish afterwards;
// it must not make that older snapshot authoritative for subsequent requests.
$source = file_get_contents($forum_root . 'attach_mod/attachment_mod.php');
$start = strpos($source, '// Get Attachment Config');
$end = strpos($source, '// Please do not change the include-order', $start);
attachment_config_check($start !== false && $end > $start, 'Locate actual attachment configuration bootstrap');
$bootstrap = substr($source, $start, $end - $start);
$temporary = sys_get_temp_dir() . '/phpbb-attach-config-' . uniqid('', true);
attachment_config_check(mkdir($temporary . '/cache', 0700, true), 'Create isolated cache directory');
$cache_file = $temporary . '/cache/attach_config_data.cache';
$stale = serialize(array('disable_mod' => '0', 'board_lang' => 'english', 'max_filesize' => '999999'));
file_put_contents($cache_file, $stale);
$phpbb_root_path = $temporary . '/';
try
{
	$db->after_snapshot = $db->values;
	$db->after_snapshot['disable_mod'] = '1'; $db->after_snapshot['max_filesize'] = '1024';
	eval($bootstrap);
	attachment_config_check($attach_config['disable_mod'] === '0', 'Older request keeps only its own database snapshot');
	$board_config['default_lang'] = 'english';
	eval($bootstrap);
	attachment_config_check($attach_config['disable_mod'] === '1' && $attach_config['max_filesize'] === '1024', 'Next request sees new upload restrictions despite older request completing later');
	attachment_config_check($attach_config['board_lang'] === 'english', 'Language fallback follows current board configuration, not historical cache');
	attachment_config_check(file_get_contents($cache_file) === $stale, 'Loading never rewrites the obsolete cache');
	unlink($cache_file);
	$board_config['default_lang'] = 'german'; $db->values['upload_dir'] = ' Grüße 😀 ';
	eval($bootstrap);
	attachment_config_check(!file_exists($cache_file), 'Cold requests never republish configuration files');
	attachment_config_check($attach_config['upload_dir'] === 'Grüße 😀' && $attach_config['board_lang'] === 'german', 'UTF-8 and language fallback survive fresh reads');
	foreach (array('query', 'empty') as $failure)
	{
		$db->fail = $failure === 'query'; if ($failure === 'empty') { $db->values = array(); }
		$caught = false;
		try { eval($bootstrap); } catch (AttachmentConfigFailure $error) { $caught = true; }
		attachment_config_check($caught, 'Unavailable configuration must stop the request: ' . $failure);
	}
}
finally
{
	$phpbb_root_path = $forum_root;
	if (file_exists($cache_file)) { unlink($cache_file); }
	rmdir($temporary . '/cache'); rmdir($temporary);
}
restore_error_handler();
echo "Attachment configuration consistency checks passed.\n";
