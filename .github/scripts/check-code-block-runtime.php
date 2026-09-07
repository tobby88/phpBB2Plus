<?php
define('IN_PHPBB', true);
$phpbb_root_path = dirname(dirname(__DIR__)) . '/phpBB2/';
require $phpbb_root_path . 'includes/functions.php';
require $phpbb_root_path . 'includes/functions_post.php';
require $phpbb_root_path . 'includes/bbcode.php';
class CodeBlockTemplate
{
	public function make_filename($file) { return $GLOBALS['phpbb_root_path'] . 'templates/fisubsilversh/' . $file; }
}
$template = new CodeBlockTemplate();
$lang = array('Quote'=>'Quote', 'wrote'=>'wrote', 'Code'=>'Code', 'PHPCode'=>'PHP', 'Select'=>'Select', 'Expand'=>'Expand', 'Contract'=>'Contract', 'Media_open'=>'Open');
function code_block_assert($condition, $message)
{
	if (!$condition) { fwrite(STDERR, "Code block test failed: $message\n"); exit(1); }
}
function code_block_text($html)
{
	code_block_assert(preg_match('#<code\b[^>]*>(.*?)</code>#s', $html, $match) === 1, 'a complete source container must be rendered');
	$text = preg_replace('#<br\s*/?>#i', "\n", $match[1]);
	$text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
	return str_replace(array("\xc2\xa0", "\r\n"), array(' ', "\n"), $text);
}
foreach (array('code', 'php') as $kind)
{
	foreach (array(
		'echo "[quote]Grüße 😀[/quote] [b]text[/b]";',
		"  first line\n\tsecond line\n  third line",
		'<script>alert("example");</script>',
		'echo "&quot; &amp; &lt;";',
		'echo "[code]example[/code]";',
		'<?php echo "original tags"; ?>',
	) as $source)
	{
		$stored = stripslashes(prepare_message(addslashes('[' . $kind . ']' . $source . '[/' . $kind . ']'), false, true, false, 'abc'));
		$html = bbencode_second_pass($stored, 'abc');
		$actual = code_block_text($html);
		// Older PHP highlighters expand each tab to four spaces.
		code_block_assert(str_replace("\t", '    ', $actual) === str_replace("\t", '    ', $source), "$kind source must survive storage and rendering: " . json_encode(array('expected'=>$source, 'actual'=>$actual)));
		code_block_assert(substr_count($html, '<table') === 1, "$kind examples must not generate nested quote or code boxes");
		code_block_assert(strpos($html, '<script>') === false, 'source must remain escaped HTML');
		code_block_assert(strpos($html, ':abc]') === false, 'internal post UIDs must not appear in source examples');
	}
}
$legacy = '[php:1:abc]echo &quot;[quote:abc]sample[/quote:abc] [b:abc]bold[/b:abc]&quot;;[/php:1:abc]';
code_block_assert(code_block_text(bbencode_second_pass($legacy, 'abc')) === 'echo "[quote]sample[/quote] [b]bold[/b]";', 'legacy PHP examples must lose compiler-added UIDs, not their source syntax');
foreach (array('[code:abc]unclosed', '[/php:abc]', '[php:1:abc]unclosed', '[/code:1:abc]') as $broken)
{
	$html = bbencode_second_pass($broken, 'abc');
	code_block_assert(strpos($html, '<table') === false && strpos($html, '</table>') === false, 'unmatched legacy source tags must not alter surrounding tables');
}

define('SMILIES_TABLE', 'smilies');
define('ACRONYMS_TABLE', 'acronyms');
class CodeBlockDictionary
{
	public function sql_query($sql) { return strpos($sql, SMILIES_TABLE) !== false ? 'smilies' : 'acronyms'; }
	public function sql_fetchrowset($result)
	{
		return $result === 'smilies' ? array(array('code'=>':)', 'smile_url'=>'smile.gif', 'emoticon'=>'Smile')) : array(array('acronym'=>'PHP', 'description'=>'Language'));
	}
}
$db = new CodeBlockDictionary();
$board_config = array('smilies_path'=>'images/smiles');
$text = 'https://example.org :) PHP';
$source = stripslashes(prepare_message(addslashes('[code]' . $text . '[/code]'), false, true, false, 'abc'));
$html = bbencode_second_pass($source, 'abc');
$transformed = acronym_pass(smilies_pass(make_clickable($html . ' ' . $text)));
code_block_assert(code_block_text($transformed) === $text, 'links, smilies and acronyms must not rewrite code examples');
code_block_assert(substr_count($transformed, '<a href=') === 1 && substr_count($transformed, '<img ') === 1 && substr_count($transformed, '<acronym ') === 1, 'normal prose must retain links, smilies and acronym descriptions');
$old_limit = ini_get('pcre.backtrack_limit');
ini_set('pcre.backtrack_limit', '1');
$long_code = '<code>' . str_repeat('sample ', 100) . '</code>';
$safe_prose = phpbb_bbcode_transform_prose($long_code, 'strtoupper');
$safe_block = phpbb_bbcode_render_code_blocks('[code:1:abc]' . str_repeat('sample ', 100) . '[/code:1:abc]', 'abc', $bbcode_tpl);
ini_set('pcre.backtrack_limit', $old_limit);
code_block_assert($safe_prose === $long_code, 'regex exhaustion must preserve the original code instead of blanking it');
code_block_assert(strpos($safe_block, 'sample') !== false && strpos($safe_block, '[code:1:abc]') === false, 'a failed code parse must retain inert visible source');
echo "Code block runtime tests passed.\n";
