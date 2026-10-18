<?php
// Exact ACP action body after controlled bootstrap; no live config, mail or files.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_KB_COUNTER_NATIVE') !== '1') { echo "KB article actions require a disposable native database.\n"; return; }
define('IN_PHPBB', true); define('GENERAL_ERROR', 1); define('GENERAL_MESSAGE', 2);
$ka_source = dirname(dirname(__DIR__)) . '/phpBB2/';
require $ka_source . 'includes/php_compat.php';
require $ka_source . 'db/mysqli.php';
foreach (array('ARTICLES'=>'articles','CATEGORIES'=>'categories','CONFIG'=>'config','WORD'=>'wordlist','MATCH'=>'wordmatch','SEARCH'=>'results','VOTES'=>'votes') as $constant=>$suffix) { define('KB_' . $constant . '_TABLE', 'fixture_kb_' . $suffix); }
require $ka_source . 'includes/functions_kb.php';
require $ka_source . 'includes/functions_search.php';
function ka_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class KbActionExit extends RuntimeException { public $level; function __construct($level, $message) { parent::__construct($message); $this->level = $level; } }
function message_die($level, $message) { throw new KbActionExit($level, $message); }
function append_sid($url) { return $url; }
$bootstrap = file_get_contents($ka_source . 'admin/pagestart.php');
$a = strpos($bootstrap, "if (!function_exists('phpbb_admin_post_session_valid'))"); $b = strpos($bootstrap, 'if (empty($no_page_header))', $a);
ka_check($a !== false && $b > $a, 'Production ACP POST helpers'); eval(substr($bootstrap, $a, $b - $a));
$controller = file_get_contents($ka_source . 'admin/admin_kb_art.php'); $a = strpos($controller, '$approve ='); $b = strpos($controller, "$" . "template->pparse('body');", $a);
ka_check($a !== false && $b > $a, 'Complete production action parsing/switch'); $ka_controller = substr($controller, $a, $b - $a);
$phpbb_root_path = $ka_source; $phpEx = 'php'; $board_config = array('default_lang'=>'english');
$kb_config = array('comments'=>0, 'del_topic'=>0); $userdata = array('session_id'=>str_repeat('a', 32));
$lang = array('No_Articles'=>'missing','Article_approved'=>'approved','Article_unapproved'=>'unapproved','Article_deleted'=>'deleted','Click_return_article_manager'=>'%smanager%s','Click_return_admin_index'=>'%sadmin%s');
function ka_run($mode, $sid = true, $method = 'POST') {
 global $db, $ka_controller, $kb_config, $userdata, $lang, $phpbb_root_path, $phpEx;
 $_GET = array(); $_POST = array('mode'=>$mode, 'a'=>'1', 'c'=>'yes'); $_SERVER['REQUEST_METHOD'] = $method;
 if ($sid) { $_POST['sid'] = $userdata['session_id']; }
 try { eval($ka_controller); } catch (KbActionExit $exit) { return $exit; }
 throw new RuntimeException('Controller did not report completion/refusal');
}
$port = getenv('PHPBB_KB_COUNTER_PORT') ?: '3306'; $password = getenv('PHPBB_KB_COUNTER_PASSWORD') ?: '';
ka_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port <= 65535, 'Loopback port');
$host = '127.0.0.1:' . $port; $control = new sql_db($host, 'root', $password, '', false);
ka_check($control->db_connect_id, 'Own database server'); $fixture = 'codex_kb_actions_' . bin2hex(phpbb_random_bytes(8));
ka_check($control->sql_query('CREATE DATABASE ' . $fixture . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Own disposable schema'); $db = null;
function ka_sql($sql) { $r = $GLOBALS['db']->sql_query($sql); ka_check($r, 'Fixture SQL'); return $r; }
function ka_rows($sql) { $r = ka_sql($sql); $out = array(); while ($row = $GLOBALS['db']->sql_fetchrow($r)) { $named = array(); foreach ($row as $key=>$value) { if (is_string($key)) { $named[$key] = $value; } } $out[] = $named; } $GLOBALS['db']->sql_freeresult($r); return $out; }
function ka_counts() { return ka_rows('SELECT category_id,number_articles FROM fixture_kb_categories ORDER BY category_id'); }
function ka_snapshot() { $out = array(); foreach (array('articles','categories','wordlist','wordmatch','results','votes') as $suffix) { $out[$suffix] = ka_rows('SELECT * FROM fixture_kb_' . $suffix); } return $out; }
try {
 $db = new sql_db($host, 'root', $password, $fixture, false); ka_check($db->db_connect_id, 'Production database driver');
 $schema = file_get_contents($ka_source . 'install/schemas/mysql_schema.sql');
 foreach (array('articles','categories','wordlist','wordmatch','results','votes') as $suffix) { ka_check(preg_match('/CREATE TABLE phpbb_kb_' . $suffix . '\s*\([\s\S]*?;/', $schema, $m) === 1, 'Canonical table'); ka_sql(str_replace('phpbb_kb_' . $suffix, 'fixture_kb_' . $suffix, $m[0])); }
 ka_sql("INSERT INTO fixture_kb_categories(category_id,category_name,category_details,number_articles,parent,cat_order) VALUES(1,'Root','Keep',0,0,0),(2,'Leaf','Keep',0,1,1)");
 ka_sql("INSERT INTO fixture_kb_articles(article_id,article_category_id,article_title,article_description,article_date,article_body,approved) VALUES(1,2,'Article title','Keep','123','Synthetic searchable article',0)");
 $cases = 0;
 foreach (array('approve','unapprove','delete') as $mode) {
  foreach (array(array(false,'POST'), array(true,'GET')) as $request) { $before = ka_snapshot(); ka_check(ka_run($mode, $request[0], $request[1])->level === GENERAL_ERROR && ka_snapshot() === $before, 'Invalid method/token refuses action without writes'); $cases++; }
 }
 $zero = array(array('category_id'=>'1','number_articles'=>'0'), array('category_id'=>'2','number_articles'=>'0'));
 $one = array(array('category_id'=>'1','number_articles'=>'1'), array('category_id'=>'2','number_articles'=>'1'));
 foreach (array('approve','approve','unapprove','unapprove','approve') as $mode) {
  $result = ka_run($mode); ka_check($result->level === GENERAL_MESSAGE, 'Actual action succeeds: ' . $mode . ': ' . $result->getMessage());
  ka_check(ka_counts() === ($mode === 'approve' ? $one : $zero), 'Actual controller updates leaf and ancestor only on state transition');
  $matches = ka_rows('SELECT * FROM fixture_kb_wordmatch WHERE article_id=1'); ka_check(($mode === 'approve') === (count($matches) > 0), 'Search matches follow current approval'); $cases++;
 }
 ka_sql("INSERT INTO fixture_kb_votes VALUES('127.0.0.1','1',1),('127.0.0.1','1',2)");
 ka_check(ka_run('delete')->level === GENERAL_MESSAGE && ka_counts() === $zero, 'Approved deletion decrements complete path');
 ka_check(!ka_rows('SELECT * FROM fixture_kb_articles') && !ka_rows('SELECT * FROM fixture_kb_wordmatch') && count(ka_rows('SELECT * FROM fixture_kb_votes')) === 1, 'Deletion removes only owned article/matches/votes'); $cases++;
 $before = ka_snapshot(); ka_check(ka_run('delete')->getMessage() === 'missing' && ka_snapshot() === $before, 'Missing/repeated delete cannot decrement again'); $cases++;
 echo 'Knowledge Base actual ACP action body: ' . $cases . " transition/token/search cases passed (comments disabled; bootstrap authority not under test).\n";
} finally {
 if ($db) { $db->sql_close(); } ka_check($control->sql_query('DROP DATABASE ' . $fixture), 'Remove own schema'); $control->sql_close();
}
