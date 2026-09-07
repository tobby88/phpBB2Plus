<?php
// Exercise real storage methods with an in-memory query recorder. Never use
// common.php, real configuration, database connections or installed block rules.
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
define('CTRACKER_CONFIG', 'test_ct_config');
define('CTRACKER_IPBLOCKER', 'test_ct_blocklist');
define('GENERAL_ERROR', 1);
define('GENERAL_MESSAGE', 2);
class BlocklistWriteRejected extends RuntimeException {}
function message_die($code, $message) { throw new BlocklistWriteRejected($message); }
function blocklist_write_assert($condition, $message)
{
	if (!$condition) { throw new RuntimeException('Blocklist write test failed: ' . $message); }
}
class BlocklistWriteDatabase
{
	public $queries = array();
	public $escaped = array();
	public $fail = false;
	public function sql_query($sql) { $this->queries[] = $sql; return !$this->fail; }
	public function sql_fetchrow($result) { return false; }
	public function sql_escape($text) { $this->escaped[] = $text; return str_replace("'", "''", $text); }
}
$db = new BlocklistWriteDatabase();
$lang = array(
	'ctracker_error_loading_config'=>'config',
	'ctracker_error_insert_blocklist'=>'insert',
	'ctracker_error_delete_blocklist'=>'delete',
	'ctracker_error_database_op'=>'update',
	'ctracker_error_blocklist_value'=>'invalid value',
	'ctracker_error_blocklist_id'=>'invalid id'
);
$HTTP_SERVER_VARS = array('REMOTE_ADDR'=>'192.0.2.10');
$HTTP_ENV_VARS = array();
require $forum_root . 'ctracker/classes/class_ct_database.php';
$config = new ct_database();
foreach (array("192.0.\n2.*", "*\0*", "\tbad", "bad\r", 'bad' . chr(127),
	'', ' ', str_repeat('a', 201), '*********', "\xff", "Agent\xc3*", "\xc0\xaf", array('bad'), new stdClass()) as $invalid)
{
	foreach (array('save_to_blocklist', 'update_blocklist') as $method)
	{
		$db->queries = array(); $rejected = false;
		try
		{
			if ($method === 'save_to_blocklist') { $config->$method($invalid); }
			else { $config->$method(7, $invalid); }
		}
		catch (BlocklistWriteRejected $error) { $rejected = true; }
		blocklist_write_assert($rejected && !$db->queries, 'invalid values must be rejected without SQL, not silently rewritten');
	}
}
foreach (array('7junk', '7 OR 1=1', 0, -1, '16777216', str_repeat('9', 50), 7.5, true, array(7), new stdClass()) as $invalid)
{
	foreach (array('delete_from_blocklist', 'update_blocklist') as $method)
	{
		$db->queries = array(); $rejected = false;
		try
		{
			if ($method === 'delete_from_blocklist') { $config->$method($invalid); }
			else { $config->$method($invalid, 'ValidAgent*'); }
		}
		catch (BlocklistWriteRejected $error) { $rejected = true; }
		blocklist_write_assert($rejected && !$db->queries, 'invalid identifiers must never select another record by integer coercion');
	}
}
foreach (array('192.0.2.0/24', '2001:0db8::42', 'Mozilla/5.0*', "Agent\\Path's*",
	'BöserBot*', '代理*', str_repeat('ä', 100), str_repeat('a', 200), 'a*b*c*d*e*f*g*h*') as $valid)
{
	$db->queries = array(); $db->escaped = array();
	$config->save_to_blocklist(' ' . $valid . ' ');
	$config->update_blocklist('7', $valid);
	blocklist_write_assert(count($db->queries) === 2 && $db->escaped === array($valid, $valid), 'valid rules must reach SQL escaping unchanged apart from surrounding spaces');
	blocklist_write_assert(strpos($db->queries[0], 'INSERT INTO') === 0 && strpos($db->queries[1], 'WHERE id = 7') !== false, 'valid writes must use intended operation and identifier');
}
$db->queries = array();
$config->delete_from_blocklist('16777215');
blocklist_write_assert($db->queries === array('DELETE FROM test_ct_blocklist WHERE id = 16777215'), 'largest valid mediumint identifier must remain usable');
$db->fail = true;
foreach (array('save_to_blocklist', 'delete_from_blocklist', 'update_blocklist') as $method)
{
	$rejected = false;
	try
	{
		if ($method === 'save_to_blocklist') { $config->$method('Agent*'); }
		elseif ($method === 'delete_from_blocklist') { $config->$method(7); }
		else { $config->$method(7, 'Agent*'); }
	}
	catch (BlocklistWriteRejected $error) { $rejected = true; }
	blocklist_write_assert($rejected, 'database errors must never be reported as successful changes');
}

// Execute the actual ACP remove branch: it must pass the raw identifier to
// the storage boundary instead of coercing "7junk" to the valid record 7.
define('IN_PHPBB', true);
define('CTRACKER_ACP', true);
class BlocklistWriteTemplate
{
	public function set_filenames($files) {}
}
function phpbb_admin_post_string($key, $default = '') { return isset($_POST[$key]) ? $_POST[$key] : $default; }
function phpbb_admin_require_post_session() { $GLOBALS['blocklist_session_checks']++; }
$template = new BlocklistWriteTemplate();
$ctracker_config = $config;
$db->fail = false; $db->queries = array();
$_POST = array('mode'=>'remove', 'id'=>'7junk');
$blocklist_session_checks = 0; $rejected = false;
try { include $forum_root . 'ctracker/admin/acp_module_ipblocker.php'; }
catch (BlocklistWriteRejected $error) { $rejected = true; }
blocklist_write_assert($rejected && !$db->queries && $blocklist_session_checks === 1, 'ACP must require a session check and reject malformed raw identifiers');
echo "CrackerTracker blocklist write runtime tests passed.\n";
