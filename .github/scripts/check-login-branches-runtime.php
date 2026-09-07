<?php
// Execute login.php's real credential branch with in-memory boundary doubles.
// No common.php, real database, account mutation, mail or log writes are used.
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
define('GENERAL_ERROR', 1);
define('GENERAL_MESSAGE', 2);
define('CRITICAL_ERROR', 3);
define('ADMIN', 1);
define('ANONYMOUS', -1);
define('PAGE_INDEX', 0);
define('USERS_TABLE', 'test_users');
class LoginBranchExit extends RuntimeException {}
class LoginBranchDatabase
{
	public $row;
	public $queries = array();
	public function sql_escape($value) { return addslashes($value); }
	public function sql_query($sql) { $this->queries[] = $sql; return true; }
	public function sql_fetchrow($result) { return $this->row; }
}
class LoginBranchTemplate
{
	public function assign_vars($vars) {}
}
class log_manager
{
	public function prepare_log($username) {}
	public function write_general_logfile($limit, $kind) {}
}
function message_die($code, $message) { $GLOBALS['branch_error_message'] = $message; throw new LoginBranchExit('message'); }
function append_sid($value, $html = false) { return $value . (strpos($value, '?') === false ? '?' : '&') . 'sid=test-session'; }
function redirect($url) { throw new LoginBranchExit('redirect'); }
function session_begin($id, $ip, $page, $update, $autologin, $admin) { throw new LoginBranchExit('session-created'); }
function phpbb_clean_username($name) { return trim($name); }
function phpbb_password_verify($password, $hash)
{
	$GLOBALS['branch_verifications'][] = $hash;
	return $GLOBALS['branch_password_valid'];
}
function ctracker_enforce_login_identity_limit($name)
{
	$GLOBALS['branch_limited_names'][] = $name;
	if (!empty($GLOBALS['branch_limit_reject'])) { throw new LoginBranchExit('limited'); }
}
function branch_assert($ok, $message)
{
	if (!$ok) { fwrite(STDERR, "Login branch test failed: $message\n"); exit(1); }
}
$source = file_get_contents($forum_root . 'login.php');
$first = strpos($source, '$submitted_username =');
$last = strpos($source, "\n\telse if( ( isset(\$_GET['logout'])", $first);
branch_assert($first !== false && $last !== false, 'credential branch extraction must match current endpoint');
$branch_code = substr($source, $first, $last - $first) . "\n}";
$log_include = 'include_once($phpbb_root_path . \'ctracker/classes/class_log_manager.\' . $phpEx);';
branch_assert(substr_count($branch_code, $log_include) === 1, 'only the explicit log boundary is substituted');
$branch_code = str_replace($log_include, '/* In-memory log-manager double. */', $branch_code);
function run_login_branch($record, $name, $password_valid = false, $valid_sid = true, $limit_reject = false)
{
	global $branch_code, $branch_password_valid, $branch_limited_names, $branch_verifications;
	$GLOBALS['branch_limit_reject'] = $limit_reject;
	$GLOBALS['branch_error_message'] = '';
	$branch_password_valid = $password_valid; $branch_limited_names = array(); $branch_verifications = array();
	$_POST = array('login'=>'Login', 'username'=>$name, 'password'=>'FakePassword123!', 'sid'=>'test-session');
	$_GET = array(); $HTTP_POST_VARS = $_POST;
	$userdata = array('session_logged_in'=>false, 'session_id'=>'test-session');
	$sid = $valid_sid ? 'test-session' : 'invalid';
	$board_config = array('board_disable'=>false, 'password_hashing'=>false);
	$ctracker_config = new stdClass(); $ctracker_config->settings = array('logsize_logins'=>10);
	$db = new LoginBranchDatabase(); $db->row = $record;
	$template = new LoginBranchTemplate(); $phpEx = 'php'; $user_ip = '192.0.2.10';
	$lang = array('Session_invalid'=>'invalid', 'Error_login'=>'failed', 'Click_return_login'=>'%slogin%s', 'Click_return_index'=>'%sindex%s');
	$outcome = 'fell-through';
	try { eval($branch_code); } catch (LoginBranchExit $exit) { $outcome = $exit->getMessage(); }
	return array('outcome'=>$outcome, 'names'=>$branch_limited_names, 'verifications'=>$branch_verifications, 'message'=>$GLOBALS['branch_error_message']);
}
$known = array('user_id'=>42, 'username'=>'Müller', 'user_level'=>0, 'user_active'=>1, 'user_blocktime'=>0, 'user_password'=>'fake-hash');
$failed_known = run_login_branch($known, 'Müller');
branch_assert(count($failed_known['names']) === 1 && $failed_known['outcome'] === 'message', 'known failed account must use identity limiter exactly once');
$failed_unknown = run_login_branch(false, 'Unknown');
branch_assert(count($failed_unknown['names']) === 1 && $failed_unknown['outcome'] === 'message', 'unknown failed account must use the same identity limiter exactly once');
branch_assert($failed_unknown['message'] === $failed_known['message'], 'failed-login response links must not reveal account existence when session URLs are required');
branch_assert($failed_unknown['verifications'] === array(''), 'unknown user must still execute dummy password verification');
foreach (array('Müller', 'MÜLLER', 'muller', ' Müller ') as $alias)
{
	$result = run_login_branch($known, $alias);
	branch_assert($result['names'] === array('Müller'), 'DB-resolved aliases must share the stored canonical name, not split limit buckets');
}
$inactive = $known; $inactive['user_active'] = 0;
$blocked = $known; $blocked['user_blocktime'] = time() + 3600;
foreach (array($inactive, $blocked) as $record)
{
	$result = run_login_branch($record, 'Müller');
	branch_assert(count($result['names']) === 1 && $result['outcome'] === 'message', 'unavailable known accounts must still use the failed-attempt limit');
}
$success = run_login_branch($known, 'Müller', true);
branch_assert($success['outcome'] === 'session-created' && !$success['names'], 'successful authentication must not count as a failed attempt');
$invalid = run_login_branch($known, 'Müller', false, false);
branch_assert($invalid['outcome'] === 'message' && !$invalid['names'] && !$invalid['verifications'], 'invalid session token must stop before credential checking');
foreach (array($known, false) as $record)
{
	$limited = run_login_branch($record, 'Example', false, true, true);
	branch_assert($limited['outcome'] === 'limited', 'both known and unknown branches must honor a limiter rejection');
}
$success = run_login_branch($known, 'Müller', true, true, true);
branch_assert($success['outcome'] === 'session-created' && !$success['names'], 'correct credentials must not be blocked by the failed-attempt bucket');
echo "Login branch runtime tests passed.\n";
