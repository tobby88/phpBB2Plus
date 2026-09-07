<?php
define('IN_PHPBB', true);
define('CTRACKER_ACP', true);
define('CTRACKER_CONFIG', 'test_ct_config');
define('GENERAL_ERROR', 1);
define('GENERAL_MESSAGE', 2);
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
class BroadcastExit extends RuntimeException {}
function message_die($code, $message) { throw new BroadcastExit($message); }
function broadcast_assert($ok, $message)
{
	if (!$ok) { throw new RuntimeException('Broadcast test failed: ' . $message); }
}
class BroadcastDatabase
{
	public $queries = array();
	public $escaped = array();
	public $fail = false;
	public function sql_query($sql) { $this->queries[] = $sql; return !$this->fail; }
	public function sql_fetchrow($result) { return false; }
	public function sql_escape($value) { $this->escaped[] = $value; return str_replace("'", "''", $value); }
}
class BroadcastTemplate { public function set_filenames($files) {} }
class ct_adminfunctions
{
	public function set_global_message() { $GLOBALS['broadcast_activated']++; }
}
function phpbb_admin_require_post_session() { $GLOBALS['broadcast_session_checks']++; }
function phpbb_admin_post_string($key, $default = '')
{
	return isset($_POST[$key]) && is_scalar($_POST[$key]) ? stripslashes((string) $_POST[$key]) : $default;
}
function append_sid($url) { return $url; }
// Load only the real pure URL validator; functions.php's real message_die()
// would pull in the forum's rendering/error side effects instead of our exit.
$helpers = file_get_contents($forum_root . 'includes/functions.php');
$helper_start = strpos($helpers, 'function phpbb_profile_http_url(');
$helper_end = strpos($helpers, 'function phpbb_avatar_remote_url(', $helper_start);
broadcast_assert($helper_start !== false && $helper_end > $helper_start, 'URL validator extraction must match the real source');
eval(substr($helpers, $helper_start, $helper_end - $helper_start));
require $forum_root . 'ctracker/classes/class_ct_database.php';
function run_broadcast($text, $type = '1', $fail = false)
{
	global $forum_root, $db, $lang, $HTTP_SERVER_VARS, $HTTP_ENV_VARS;
	$lang = array('ctracker_error_loading_config'=>'config', 'ctracker_error_updating_config'=>'database',
		'ctracker_error_global_message'=>'invalid message', 'ctracker_glob_msg_invalid_type'=>'invalid type',
		'ctracker_glob_msg_invalid_url'=>'invalid URL', 'ctracker_glob_msg_saved'=>'saved %s');
	$HTTP_SERVER_VARS = array('REMOTE_ADDR'=>'192.0.2.10'); $HTTP_ENV_VARS = array();
	$db = new BroadcastDatabase();
	$ctracker_config = new ct_database();
	$before = $ctracker_config->settings;
	$db->queries = array(); $db->fail = $fail;
	$_POST = array('submit'=>'Save', 'global_message_type'=>$type, 'global_message'=>is_string($text) ? addslashes($text) : $text);
	$HTTP_POST_VARS = $_POST;
	$GLOBALS['broadcast_activated'] = 0; $GLOBALS['broadcast_session_checks'] = 0;
	$template = new BroadcastTemplate(); $phpEx = 'php';
	$outcome = '';
	try { include $forum_root . 'ctracker/admin/acp_module_globalmessage.php'; }
	catch (BroadcastExit $exit) { $outcome = $exit->getMessage(); }
	broadcast_assert($GLOBALS['broadcast_session_checks'] === 1, 'submissions must check the admin session');
	return array('outcome'=>$outcome, 'queries'=>$db->queries, 'values'=>$db->escaped,
		'settings'=>$ctracker_config->settings, 'before'=>$before, 'activated'=>$GLOBALS['broadcast_activated']);
}
foreach (array(str_repeat('ä', 128), str_repeat('😀', 255), "  Grüße\nWelt's Nachricht  ", '') as $text)
{
	$result = run_broadcast($text);
	broadcast_assert($result['settings']['global_message'] === $text, 'valid Unicode text must remain complete, not be truncated by bytes');
	broadcast_assert(count($result['queries']) === 1 && $result['activated'] === 1, 'message and type must be stored together before activation');
	broadcast_assert($result['values'] === array($text) &&
		strpos($result['queries'][0], "('global_message_type', '1')") !== false &&
		strpos($result['queries'][0], "('global_message', '" . str_replace("'", "''", $text) . "')") !== false,
		'one SQL statement must carry the exact escaped text and its type');
}
foreach (array(str_repeat('a', 256), str_repeat('ä', 256), str_repeat('😀', 256),
	"\xff", "broken\xc3", "\xed\xa0\x80", "text\0tail", array('text'), null) as $text)
{
	$result = run_broadcast($text);
	broadcast_assert(!$result['queries'] && !$result['activated'] && $result['settings'] === $result['before'], 'invalid text must not partially change the message type or activate a broadcast');
}
foreach (array('garbage', '2', array('1')) as $type)
{
	$result = run_broadcast('https://example.org/', $type);
	broadcast_assert(!$result['queries'] && !$result['activated'], 'invalid message type must not be coerced to link mode');
}
$link = run_broadcast('https://example.org/path', '0');
broadcast_assert($link['settings']['global_message_type'] === '0' && $link['activated'] === 1, 'valid link broadcasts must remain supported');
$bad_link = run_broadcast('javascript:alert(1)', '0');
broadcast_assert(!$bad_link['queries'] && !$bad_link['activated'], 'unsafe links must be rejected before storage');
$failure = run_broadcast('Grüße', '1', true);
broadcast_assert(count($failure['queries']) === 1 && !$failure['activated'] && $failure['settings'] === $failure['before'], 'failed storage must preserve runtime settings and skip activation');
echo "CrackerTracker global-message runtime tests passed.\n";
