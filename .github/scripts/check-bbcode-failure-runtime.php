<?php
define('IN_PHPBB', true);
$phpbb_root_path = dirname(dirname(__DIR__)) . '/phpBB2/';
require $phpbb_root_path . 'includes/bbcode.php';
require $phpbb_root_path . 'includes/functions_post.php';
class BbcodeFailureTemplate
{
	public function make_filename($file) { return $GLOBALS['phpbb_root_path'] . 'templates/fisubsilversh/' . $file; }
}
$template = new BbcodeFailureTemplate();
$lang = array('Quote'=>'Quote', 'wrote'=>'wrote', 'Code'=>'Code', 'PHPCode'=>'PHP', 'Select'=>'Select', 'Expand'=>'Expand', 'Contract'=>'Contract', 'Media_open'=>'Open');
function bbcode_failure_assert($condition, $message)
{
	if (!$condition) { fwrite(STDERR, "BBCode failure test failed: $message\n"); exit(1); }
}
// Normal parsing still works, including a quoted name with a ] bracket, which
// follows the PDA's optional-match-output branch.
$source = '[quote="Name ] Test"]content[/quote]';
$stored = stripslashes(prepare_message(addslashes($source), false, true, false, 'abc'));
$rendered = bbencode_second_pass($stored, 'abc');
bbcode_failure_assert(strpos($rendered, 'Name &#93; Test') !== false && strpos($rendered, 'content') !== false, 'normal quote encoding must retain bracketed names');
$poem = "[poet]erste Zeile\n  zweite Zeile[poet]innen[/poet]Ende[/poet]";
$stored_poem = stripslashes(prepare_message(addslashes($poem), false, true, false, 'abc'));
$rendered_poem = bbencode_second_pass($stored_poem, 'abc');
bbcode_failure_assert(strpos($rendered_poem, 'display:none') === false && strpos($rendered_poem, 'doPoetry') === false, 'legacy poetry must be visible without a missing JavaScript function');
bbcode_failure_assert(substr_count($rendered_poem, '<div') === 2 && substr_count($rendered_poem, '</div>') === 2, 'nested poetry must remain balanced');
bbcode_failure_assert(strip_tags($rendered_poem) === "erste Zeile\n  zweite ZeileinnenEnde", 'poetry must retain all text, indentation and line breaks');
$board_config = array('allow_html_tags' => 'b,i');
bbcode_failure_assert(stripslashes(prepare_message(addslashes('<b>bold</b> & plain'), true, false, false)) === '<b>bold</b> &amp; plain', 'normal permitted HTML must retain its existing behavior');
bbcode_failure_assert(stripslashes(prepare_message(addslashes('<b>bold</b> & plain'), false, false, false)) === '&lt;b&gt;bold&lt;/b&gt; &amp; plain', 'disabled HTML must remain escaped');

// Force PCRE failures inside public encoding/rendering entry points. Compare
// with the complete original, not just a nonempty prefix or a missing warning.
$limit = ini_get('pcre.backtrack_limit');
ini_set('pcre.backtrack_limit', '1');
$input = '[b]prefix[/b] [quote="User"]' . str_repeat('Grüße ', 100) . '[/quote] tail';
$encoded = bbencode_first_pass($input, 'abc');
$legacy = '[b:abc]prefix[/b:abc] [quote:abc="User"]' . str_repeat('Grüße ', 100) . '[/quote:abc] tail &lt;script&gt;';
$fallback = bbencode_second_pass($legacy, 'abc');
$board_config = array('allow_html_tags' => 'b,i');
$submission = 'prefix &' . str_repeat('7', 100) . ' tail <script>alert("example")</script>';
$prepared_plain = prepare_message(addslashes($submission), false, true, false, 'abc');
$prepared_html = prepare_message(addslashes($submission), true, true, false, 'abc');
ini_set('pcre.backtrack_limit', $limit);
bbcode_failure_assert($encoded === $input, 'failed encoding must return the entire original, not partially compiled data');
bbcode_failure_assert(strpos($fallback, '<script>') === false && strpos($fallback, '<table') === false, 'rendering fallback must contain escaped text, not partially rendered HTML');
bbcode_failure_assert(str_replace(':abc', '', html_entity_decode($fallback, ENT_QUOTES, 'UTF-8')) === str_replace(':abc', '', html_entity_decode($legacy, ENT_QUOTES, 'UTF-8')), 'regex-limited rendering must preserve every byte of visible source apart from internal tag UIDs');
foreach (array($prepared_plain, $prepared_html) as $prepared)
{
	bbcode_failure_assert(strpos($prepared, '<script>') === false, 'HTML preparation fallback must remain safe');
	bbcode_failure_assert(html_entity_decode(stripslashes($prepared), ENT_QUOTES, 'UTF-8') === $submission, 'preparing a message must not lose its source when HTML matching exceeds regex limits');
}

$caught = false;
try { phpbb_bbcode_replace('#[invalid#', '', 'source'); }
catch (PhpbbBbcodeParseException $error) { $caught = true; }
bbcode_failure_assert($caught, 'a malformed regex must be handled as an explicit parser failure');
echo "BBCode failure runtime tests passed.\n";
