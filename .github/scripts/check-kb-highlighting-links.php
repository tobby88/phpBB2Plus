<?php
// Actual KB controller branches and URL helpers; synthetic text, no DB/session.
if (PHP_SAPI !== 'cli') { exit(2); }
if (!isset($argv[1]))
{
	foreach (array('raw', 'legacy') as $mode)
	{
		$process = proc_open(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg(__FILE__) . ' ' . $mode, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, null, array('bypass_shell' => true));
		if (!is_resource($process)) { throw new RuntimeException('Own KB fixture process unavailable'); }
		fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
		if (proc_close($process) !== 0 || $err !== '') { throw new RuntimeException('KB ' . $mode . ' fixture failed: ' . $out . $err); }
		echo $out;
	}
	echo "KB highlighting/links: both actual request-boundary modes PASS.\n"; return;
}
define('IN_PHPBB', true); define('POST_USERS_URL', 'u'); define('PHPBB_LEGACY_REQUEST_ESCAPED', $argv[1] === 'legacy');
$app = dirname(dirname(__DIR__)) . '/phpBB2/';
require $app . 'includes/functions_topic_highlight.php';
$checks = $cases = 0;
error_reporting(E_ALL); set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
function kh_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function kh_fragment($source, $from, $to)
{
	$a = strpos($source, $from); $b = $a === false ? false : strpos($source, $to, $a);
	kh_check($a !== false && $b !== false, 'Actual KB branch boundaries'); return substr($source, $a, $b - $a);
}
function kh_function($source, $name)
{
	kh_check(preg_match('/^function ' . preg_quote($name, '/') . '\(.*?^\}/ms', $source, $match) === 1, 'Actual URL helper ' . $name); return $match[0];
}
function kh_query($html)
{
	$decoded = html_entity_decode($html, ENT_QUOTES, 'UTF-8'); $query = parse_url($decoded, PHP_URL_QUERY); $values = array(); parse_str($query === null ? '' : $query, $values);
	foreach (array_keys($values) as $key) { kh_check(strpos($key, 'amp;') !== 0, 'One browser entity decode must yield real URL parameters'); }
	if ($GLOBALS['SID'] !== '') { kh_check(isset($values['sid']) && $values['sid'] === str_repeat('a', 32), 'Actual appended session parameter survives URL serialization'); }
	else { kh_check(!isset($values['sid']), 'Cookie navigation does not invent a session parameter'); }
	return $values;
}
$source = file_get_contents($app . 'includes/kb_article.php'); $functions = file_get_contents($app . 'includes/functions_kb.php'); $search = file_get_contents($app . 'kb_search.php');
eval(kh_function($functions, 'this_kb_mxurl')); eval(kh_function(file_get_contents($app . 'includes/sessions.php'), 'append_sid'));
eval(kh_function(file_get_contents($app . 'includes/functions.php'), 'generate_pagination'));
$lang = array('Previous' => 'Previous', 'Next' => 'Next', 'Goto_page' => 'Goto page');
$input = kh_fragment($source, '$requested_highlight =', "if ( !\$board_config['allow_html']");
$render = kh_fragment($source, 'if ($highlight_match)', '$article_title = phpbb_stored_text');
kh_check(substr_count($source, 'if ($highlight_match)') === 1, 'Article has one final highlight pass');
kh_check(preg_match_all('/^\s*\$(pagination|print_url|temp_url) = [^\r\n]*\$highlight_param[^\r\n]*;/m', $source, $navigation, PREG_SET_ORDER) === 6, 'Print/comments/TOC/page navigation boundaries');
$phpbb_root_path = './'; $phpEx = 'php'; $theme = array('fontcolor3' => 'ff0000');
$samples = array(
	array('hello', 'hello', 1), array('foo*', 'foobar', 1), array('*bar', 'foobar', 1), array('**foo**', 'foobar', 1), array('***', 'hello', 0),
	array('a+b', 'a+b', 1), array('C++', 'C++', 1), array('%2B', '%2B', 1), array('cat cat', 'cat', 1), array('cat', 'concatenate cat', 1),
	array('ÄPFEL', 'äpfel', 1), array('ü', 'Grüße ü', 1), array("cafe\xCC\x81*", "cafe\xCC\x81s", 1), array("hello\xC2\xA0world", 'hello world', 2),
	array("O'Reilly", 'O&#039;Reilly', 1), array('"quoted"', '&quot;quoted&quot;', 1), array('C:\\temp', 'C:\\temp', 1), array('&', 'A &amp; B', 1), array('amp', 'A &amp; B', 0),
	array('<b>', '&lt;b&gt;', 1), array('hello', '<a title="hello > world" href="/hello">hello</a>', 1), array('hello', '<!-- hello -->hello', 1),
	array('hello', '<script>hello</script>hello', 1), array('hello', '<style>.hello{color:red}</style>hello', 1), array('hello', '<textarea>hello</textarea>hello', 1),
	array('hello', '<title>hello</title>hello', 1), array('hello', '<a title="hello', 0), array('hello', '<plaintext>hello</plaintext>hello', 0),
	array(array('hello'), 'hello', 0), array(null, 'hello', 0), array(false, 'hello', 0), array(1, 'hello', 0), array("bad\xff", 'hello', 0),
	array(str_repeat('x', 1025), 'hello', 0), array("hello\0", 'hello', 0), array('', 'hello', 0), array('hello', "hello\xff", 0)
);
foreach ($samples as $sample)
{
	$_GET = array('highlight' => $sample[0]); if (PHPBB_LEGACY_REQUEST_ESCAPED) { $_GET = phpbb_addslashes_recursive($_GET); }
	$article = $sample[1]; eval($input); eval($render);
	kh_check(is_string($article) && substr_count($article, '<b style="color:#ff0000">') === $sample[2], 'Expected single visible KB highlight count, case ' . $cases);
	if ($sample[2] === 0) { kh_check($article === $sample[1], 'Unmatched/invalid request never changes article text'); }
	if (strpos($sample[1], '<a title="hello >') === 0) { kh_check(strpos($article, '<a title="hello > world" href="/hello">') === 0, 'Quoted attributes and href remain byte-for-byte intact'); }
	$canonical = is_string($sample[0]) && strlen($sample[0]) <= 1024 && preg_match('//u', $sample[0]) === 1 && strpos($sample[0], "\0") === false ? $sample[0] : '';
	kh_check($requested_highlight === $canonical && urldecode($highlight) === $canonical, 'One raw UTF-8 query after request normalization');
	foreach (array('', 'sid=' . str_repeat('a', 32)) as $SID)
	{
		$article_id = 17; $page_numm = '&page_num=2'; $topic = array('topic_replies' => 23); $show_num_comments = 10; $start = 10; $page_number = 3; $start_pag = '&start=10';
		foreach ($navigation as $statement)
		{
			eval($statement[0]);
			if ($statement[1] === 'pagination') { kh_check(preg_match('/href="([^"]+)"/', $pagination, $link) === 1, 'Actual pagination emits a reachable page link'); $url = $link[1]; }
			else { $url = ${$statement[1]}; }
			$values = kh_query($url);
			kh_check(isset($values['mode'], $values['k']) && $values['mode'] === 'article' && $values['k'] === '17', 'Navigation keeps the article target');
			kh_check($canonical === '' ? !isset($values['highlight']) : (isset($values['highlight']) && $values['highlight'] === $canonical), 'Every print/comment/TOC/page link preserves the exact bounded query');
			if ($statement[1] === 'temp_url') { kh_check($values['page_num'] === '3' && $values['start'] === '10', 'Article page and comment offset survive navigation'); }
			if (strpos($statement[0], '&print=true') !== false) { kh_check(isset($values['print']) && $values['print'] === 'true', 'Print navigation remains print navigation'); }
		}
	}
	$cases++;
}
// Real search/category/author URL statements must survive one HTML decode.
$url_statements = array(); foreach (array($functions, $search) as $text) { preg_match_all('/^\s*\$(temp_url|article_url|author_url|category_temp) = htmlspecialchars\(append_sid[^\r\n]+;/m', $text, $found, PREG_SET_ORDER); $url_statements = array_merge($url_statements, $found); }
kh_check(count($url_statements) === 11, 'All eleven KB list/search URL serialization sites');
preg_match_all('/htmlspecialchars\(\$temp_url, ENT_QUOTES, \'UTF-8\'[^)]*\)/', $source, $inline);
kh_check(count($inline[0]) === 2, 'Article category and author link serialization');
foreach (array('', 'sid=' . str_repeat('a', 32)) as $SID)
{
	$article_id = 17; $category_id = 19; $author_id = 23; $highlight_active = urlencode('Grüße + & O\'Reilly');
	foreach ($url_statements as $statement)
	{
		eval($statement[0]); $values = kh_query(${$statement[1]});
		$mode = $values['mode']; $key = $mode === 'article' ? 'k' : ($mode === 'cat' ? 'cat' : 'u'); $id = $key === 'k' ? '17' : ($key === 'cat' ? '19' : '23');
		kh_check(isset($values[$key]) && $values[$key] === $id, 'Actual list/search article/category/profile target survives HTML serialization');
		if (strpos($statement[0], '$highlight_active') !== false) { kh_check($values['highlight'] === 'Grüße + & O\'Reilly', 'Search result query reaches article unchanged'); } $cases++;
	}
	foreach ($inline[0] as $expression)
	{
		$temp_url = append_sid(this_kb_mxurl('mode=cat&amp;cat=19')); eval('$html = ' . $expression . ';'); $values = kh_query($html); kh_check(isset($values['cat']) && $values['cat'] === '19', 'Article inline URL is escaped exactly once'); $cases++;
	}
}
$SID = ''; $theme = array('fontcolor3' => '" broken-color'); $_GET = array('highlight' => 'hello'); $article = 'hello'; eval($input); eval($render); kh_check($article === '<b style="color:#000000">hello</b>', 'Invalid theme color cannot change markup');
$theme = array(); $article = 'hello'; eval($render); kh_check($article === '<b style="color:#000000">hello</b>', 'Missing theme color has a defined fallback');
$limit = ini_get('pcre.backtrack_limit'); $jit = ini_get('pcre.jit'); ini_set('pcre.jit', '0'); ini_set('pcre.backtrack_limit', '1');
$highlight_match = phpbb_topic_highlight_pattern('a*a*a*a*b'); $article = str_repeat('a', 10000) . 'c'; $original = $article; eval($render); kh_check($article === $original && preg_last_error() !== PREG_NO_ERROR, 'Actual KB render retains whole article on PCRE resource failure');
ini_set('pcre.backtrack_limit', $limit); if ($jit !== false) { ini_set('pcre.jit', $jit); }
restore_error_handler(); echo 'KB highlighting/links ', $argv[1], ': ', $cases, ' cases, ', $checks, " assertions PASS; UTF-8, wildcards, HTML boundaries, single-pass rendering, URL/session round trips and PCRE failure.\n";
