<?php
define('IN_PHPBB', true);
$phpbb_root_path = dirname(dirname(__DIR__)) . '/phpBB2/';
require $phpbb_root_path . 'includes/bbcode.php';
class BbcodeColdTemplate
{
	public $filename;
	public function make_filename($file) { return $this->filename; }
}
$template = new BbcodeColdTemplate();
$template->filename = $phpbb_root_path . 'templates/fisubsilversh/bbcode.tpl';
$lang = array('Quote'=>'Quote', 'wrote'=>'wrote', 'Code'=>'Code', 'PHPCode'=>'PHP', 'Select'=>'Select', 'Expand'=>'Expand', 'Contract'=>'Contract');
function cold_template_assert($condition, $message)
{
	if (!$condition) { throw new RuntimeException('BBCode template test failed: ' . $message); }
}
$warnings = array();
set_error_handler(function ($number, $message) use (&$warnings) { $warnings[] = $message; return true; });
$limit = ini_get('pcre.backtrack_limit');
// A short message passes the first regex, but the long quote template exceeds
// this limit. Start cold: the ready constant must not mask the loading path.
ini_set('pcre.backtrack_limit', '100');
$input = '[b:abc]Grüße[/b:abc] &lt;script&gt; tail';
cold_template_assert(preg_replace('#(script|about|applet|activex|chrome):#is', '\\1&#058;', $input) === $input, 'fixture must reach template loading');
$fallback = bbencode_second_pass($input, 'abc');
ini_set('pcre.backtrack_limit', $limit);
cold_template_assert(count($warnings) === 0, 'cold loading must not produce missing-key/type warnings');
cold_template_assert(!defined('BBCODE_TPL_READY'), 'failed loading must not poison the request-wide ready flag');
cold_template_assert($fallback === htmlspecialchars(phpbb_bbcode_code_source($input, 'abc'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'cold failure must preserve the entire escaped source');

// A missing optional style still uses the complete default template.
$template->filename = $phpbb_root_path . 'templates/no-such-test-style/bbcode.tpl';
$blocks = load_bbcode_template();
cold_template_assert(isset($blocks['quote_close'], $blocks['table_close'], $blocks['b_open']), 'missing optional overrides must retain Plus defaults');
// Every installed block is used by rendering. An incomplete set must be
// rejected before any substitutions or readiness flag changes take place.
foreach (array_keys($blocks) as $name)
{
	$partial = $blocks;
	unset($partial[$name]);
	$caught = false;
	try { prepare_bbcode_template($partial); }
	catch (PhpbbBbcodeParseException $error) { $caught = true; }
	cold_template_assert($caught, 'missing ' . $name . ' must abort preparation');
	cold_template_assert(!defined('BBCODE_TPL_READY'), 'incomplete template must remain retryable');
}
foreach (array(null, array(), array('b_open' => array('invalid'))) as $invalid)
{
	$caught = false;
	try { prepare_bbcode_template($invalid); }
	catch (PhpbbBbcodeParseException $error) { $caught = true; }
	cold_template_assert($caught, 'invalid template set must abort preparation');
}
foreach (array(null, false, 42, array('invalid')) as $invalid_markup)
{
	$invalid = $blocks;
	$invalid['quote_close'] = $invalid_markup;
	$caught = false;
	try { prepare_bbcode_template($invalid); }
	catch (PhpbbBbcodeParseException $error) { $caught = true; }
	cold_template_assert($caught, 'non-string block must abort preparation');
}
$original_root = $phpbb_root_path;
$phpbb_root_path .= 'no-such-test-root/';
$missing_fallback = bbencode_second_pass($input, 'abc');
$phpbb_root_path = $original_root;
cold_template_assert($missing_fallback === $fallback, 'missing default and optional templates must retain source');
cold_template_assert(!defined('BBCODE_TPL_READY'), 'missing template files must not poison the ready flag');

// Recovery in the same request must load real templates, not retain an empty
// or partially prepared global array left behind by the failed first attempt.
$rendered = bbencode_second_pass($input, 'abc');
cold_template_assert(defined('BBCODE_TPL_READY'), 'successful retry must prepare templates');
cold_template_assert($rendered === '<span style="font-weight:bold">Grüße</span> &lt;script&gt; tail', 'retry must render complete normal markup');
cold_template_assert(count($warnings) === 0, 'all loading/preparation paths must be warning-free');
restore_error_handler();
echo "BBCode cold-template runtime tests passed.\n";
