<?php
// Actual KB query/cache branches, production driver and canonical schema only.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_KB_SEARCH_NATIVE') !== '1') { echo "KB search requires an explicitly enabled disposable database.\n"; return; }
define('IN_PHPBB', true); define('GENERAL_ERROR', 202); define('GENERAL_MESSAGE', 200);
define('PHPBB_LEGACY_REQUEST_ESCAPED', !isset($argv[1]) || $argv[1] === 'legacy');
foreach (array('ARTICLES'=>'articles','WORD'=>'wordlist','MATCH'=>'wordmatch','SEARCH'=>'results') as $key=>$suffix) { define('KB_'.$key.'_TABLE', 'fixture_kb_'.$suffix); }
$app = dirname(dirname(__DIR__)) . '/phpBB2/';
require $app.'includes/php_compat.php'; require $app.'includes/functions_search.php'; require $app.'db/mysqli.php';
$checks = $cases = 0;
function kn_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function kn_fragment($source, $start, $end)
{
	$a = strpos($source, $start); $b = $a === false ? false : strpos($source, $end, $a);
	kn_check($a !== false && $b !== false, 'Actual KB controller branch'); return substr($source, $a, $b-$a);
}
class KnMessage extends RuntimeException {}
function message_die($level, $message) { throw new KnMessage($message); }
function kn_sql($sql) { $result = $GLOBALS['db']->sql_query($sql); kn_check($result !== false, 'Owned fixture SQL'); return $result; }
function kn_rows($sql)
{
	$result = kn_sql($sql); $rows = array(); while ($row = $GLOBALS['db']->sql_fetchrow($result)) { $rows[] = $row; } $GLOBALS['db']->sql_freeresult($result); return $rows;
}
function kn_content()
{
	return serialize(array(kn_rows('SELECT * FROM fixture_kb_articles ORDER BY article_id'), kn_rows('SELECT * FROM fixture_kb_wordlist ORDER BY word_id'), kn_rows('SELECT * FROM fixture_kb_wordmatch ORDER BY article_id,word_id')));
}
function kn_valid_terms($terms)
{
	kn_check(is_array($terms) && count($terms) <= 50, 'At most fifty cache/search terms');
	foreach ($terms as $term) { kn_check(is_string($term) && preg_match('//u', $term) === 1 && preg_match_all('/./us', $term, $unused) <= 100, 'Whole Unicode search/cache terms within character bound'); }
}
$source = file_get_contents($app.'kb_search.php'); $functions = file_get_contents($app.'includes/functions_kb.php');
foreach (array('kb_limit_text', 'kb_search_limit_text') as $name) { kn_check(preg_match('/^function '.$name.'\(.*?^\}/ms', $functions, $part) === 1, 'Actual Unicode KB helper'); eval($part[0]); }
$input = kn_fragment($source, '$is_block = false;', 'switch($mode)');
$fresh = kn_fragment($source, "if (\$search_keywords !== '')", 'else if ($search_id)');
$cached = 'if (false) {} ' . kn_fragment($source, 'else if ($search_id)', '$orig_word = array();');
$port = getenv('PHPBB_KB_SEARCH_PORT') ?: '3306'; $password = getenv('PHPBB_KB_SEARCH_PASSWORD') ?: '';
kn_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port <= 65535, 'Explicit loopback fixture port');
$host = '127.0.0.1:'.$port; $control = new sql_db($host, 'root', $password, '', false);
kn_check($control->db_connect_id, 'Owned loopback fixture server');
$fixture = 'codex_kb_search_'.bin2hex(phpbb_random_bytes(8)); $created = false; $db = null;
error_reporting(E_ALL); set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	kn_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Exclusive own schema'); $created = true;
	$db = new sql_db($host, 'root', $password, $fixture, false); kn_check($db->db_connect_id, 'Own schema connection');
	kn_sql("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'"); $schema = file_get_contents($app.'install/schemas/mysql_schema.sql');
	foreach (array('articles','wordlist','wordmatch','results') as $table)
	{
		kn_check(preg_match('/CREATE TABLE phpbb_kb_'.$table.' \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $ddl) === 1, 'Canonical InnoDB/utf8mb4 schema');
		kn_sql(str_replace('phpbb_kb_', 'fixture_kb_', $ddl[0]));
	}
	$unicode_word = str_repeat('ü', 49).'😀';
	kn_sql("INSERT INTO fixture_kb_articles(article_id,article_body,approved) VALUES(1,'Synthetic public unchanged',1),(2,'Synthetic pending unchanged',0)");
	foreach (array(1=>'grüße',2=>$unicode_word,3=>'titletoken',4=>'oreilly') as $id=>$word)
	{
		kn_sql("INSERT INTO fixture_kb_wordlist(word_id,word_text) VALUES(".$id.",'".$db->sql_escape($word)."')");
		kn_sql('INSERT INTO fixture_kb_wordmatch(article_id,word_id,title_match) VALUES(1,'.$id.','.($id===3?1:0).'),(2,'.$id.','.($id===3?1:0).')');
	}
	$original = kn_content(); $phpbb_root_path = $app; $board_config = array('topics_per_page'=>10,'default_lang'=>'english'); $lang = array('ENCODING'=>'UTF-8','No_search_match'=>'No match');
	$samples = array(array('Grüße','1'),array('Gr*','1'),array($unicode_word,'1'),array("O'Reilly",'1'),array(str_repeat('x',99).'ü',''),array(str_repeat('x',499).'ü',''),array('xx'.str_repeat('😀',125),''),array(str_repeat('😀',501),''),array('0',''),array('titletoken',''),array('titletoken','1','all'),array("bad\xff",''),array(array('Grüße'),''));
	foreach ($samples as $index=>$sample)
	{
		$_GET = array(); $_POST = array('mode'=>'results','search_keywords'=>$sample[0],'search_fields'=>isset($sample[2])?$sample[2]:''); if (PHPBB_LEGACY_REQUEST_ESCAPED) { $_POST = phpbb_addslashes_recursive($_POST); }
		$userdata = array('session_id'=>str_pad((string)$index,32,'a')); eval($input); $has_query = $search_keywords !== ''; eval($fresh);
		kn_check($search_results === $sample[1], 'Actual indexed match set excludes unpublished articles, case '.$index); kn_valid_terms($split_search);
		$rows = kn_rows("SELECT * FROM fixture_kb_results WHERE session_id='".$db->sql_escape($userdata['session_id'])."'");
		kn_check(count($rows) === ($has_query?1:0), 'Only valid actual searches create a cache row');
		if ($has_query)
		{
			kn_check(preg_match('//u',$rows[0]['search_array'])===1, 'Actual strict utf8mb4 cache INSERT/UPDATE is valid');
			$stored = phpbb_safe_unserialize_array($rows[0]['search_array']); kn_check(is_array($stored) && $stored['split_search'] === $split_search, 'Exact Unicode bytes survive serialization and actual DB storage');
			$expected_terms = $split_search; $saved_id = $search_id; $before_cache = serialize(kn_rows('SELECT * FROM fixture_kb_results ORDER BY search_id'));
			$_POST = array(); $_GET = array('mode'=>'results','search_id'=>(string)$saved_id); eval($input); eval($cached);
			kn_check($search_results === $sample[1] && $split_search === $expected_terms, 'Actual cached search round trip retains match set and Unicode terms'); kn_valid_terms($split_search);
			kn_check(serialize(kn_rows('SELECT * FROM fixture_kb_results ORDER BY search_id')) === $before_cache, 'Cache reads never rewrite stored results');
			$userdata['session_id'] = str_repeat('z',32); $denied = false; try { eval($cached); } catch (KnMessage $error) { $denied = true; }
			kn_check($denied, 'A different session cannot reuse the cached search');
		}
		kn_check(kn_content() === $original, 'Searching preserves all article content, approval flags and index records'); $cases++;
	}
	$userdata = array('session_id'=>str_repeat('b',32)); $oversized = serialize(array('search_results'=>'1','split_search'=>array(str_repeat('😀',300),'0',array('ignored'),' ü '),'sort_dir'=>'ASC'));
	kn_sql("INSERT INTO fixture_kb_results(search_id,session_id,search_array) VALUES(2147483000,'".$userdata['session_id']."','".$db->sql_escape($oversized)."')");
	$_POST = array(); $_GET = array('mode'=>'results','search_id'=>'2147483000'); eval($input); eval($cached);
	kn_check($split_search === array(str_repeat('😀',100),'0','ü'), 'Legacy/oversized cached terms are bounded by whole characters and arrays are ignored'); kn_valid_terms($split_search);
	$rows = kn_rows('SELECT search_array FROM fixture_kb_results WHERE search_id=2147483000'); kn_check($rows[0]['search_array'] === $oversized && kn_content() === $original, 'Normalization is display-only and preserves original cache/content'); $cases++;
	echo 'KB search native ', PHPBB_LEGACY_REQUEST_ESCAPED?'legacy':'raw', ': ', $cases, ' cases, ', $checks, " assertions PASS; canonical utf8mb4/InnoDB, actual Unicode queries/cache writes/reads, session isolation and unchanged content.\n";
}
finally
{
	if ($db) { $db->sql_close(); }
	if ($created) { kn_check(preg_match('/^codex_kb_search_[a-f0-9]{16}$/D',$fixture) && $control->sql_query('DROP DATABASE '.$fixture), 'Exact own schema cleanup'); }
	$control->sql_close(); restore_error_handler();
}
