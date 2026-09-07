<?php
define('IN_PHPBB', true);
$phpbb_root_path = dirname(dirname(__DIR__)) . '/phpBB2/';
require $phpbb_root_path . 'includes/bbcode.php';
require $phpbb_root_path . 'includes/functions_post.php';
class NestedFormattingTemplate
{
	public function make_filename($file) { return $GLOBALS['phpbb_root_path'] . 'templates/fisubsilversh/' . $file; }
}
$template = new NestedFormattingTemplate();
$lang = array('Quote'=>'Quote', 'wrote'=>'wrote', 'Code'=>'Code', 'PHPCode'=>'PHP', 'Select'=>'Select', 'Expand'=>'Expand', 'Contract'=>'Contract');
function nested_assert($ok, $message)
{
	if (!$ok) { fwrite(STDERR, "Nested formatting test failed: $message\n"); exit(1); }
}
function nested_store($source)
{
	return stripslashes(prepare_message(addslashes($source), false, true, false, 'abc'));
}
$tags = array('b', 'i', 'u', 's', 'center', 'fade', 'flipv', 'fliph', 'scrollleft', 'scrollright', 'scrollup', 'scrolldown', 'color=red', 'size=12', 'font=serif', 'align=center', 'marq=left', 'glow=red', 'shadow=red', 'highlight=red', 'poet');
foreach ($tags as $tag)
{
	$pieces = explode('=', $tag); $name = $pieces[0];
	$source = '[' . $tag . ']Grüße [' . strtoupper($tag) . ']innen 😀[/' . strtoupper($name) . '] außen[/' . $name . '] Ende';
	$stored = nested_store($source);
	nested_assert(substr_count($stored, ':' . 'abc]') === 4, 'both nested pairs must be compiled: ' . $tag . ' => ' . $stored);
	$html = bbencode_second_pass($stored, 'abc');
	nested_assert(strpos($html, '&#91;') === false && strpos($html, ':abc]') === false, 'valid nested formatting must not become literal source: ' . $tag);
	$visible = trim(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", '', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'))));
	nested_assert($visible === 'Grüße innen 😀 außen Ende', 'every nested text segment must survive: ' . $tag);
}
$stored = nested_store('[color=red]red[color=blue]blue[/color]red again[/color]');
$html = bbencode_second_pass($stored, 'abc');
nested_assert(strpos($html, 'color:red') !== false && strpos($html, 'color:blue') !== false, 'nested attributes must keep their own values');
$stored = nested_store('[b]outer [i]crossed[/b] tail[/i]');
nested_assert(strpos(bbencode_second_pass($stored, 'abc'), '<span') === false, 'crossed source remains subject to second-pass containment');
$source = '[quote="Name [b]example[/b]"][font=serif]body[/font][/quote]';
$html = bbencode_second_pass(nested_store($source), 'abc');
nested_assert(strpos($html, 'Name &#91;b&#93;example&#91;/b&#93;') !== false, 'formatting examples in quote names must not be compiled as body tags');
$stored = nested_store('[b]unclosed [b]paired[/b] tail');
nested_assert(substr_count($stored, ':abc]') === 2 && strpos($stored, '[b]unclosed') === 0, 'valid inner pair survives an unclosed outer tag');
$source = str_repeat('[font=serif]', 128) . 'tiefe Grüße 😀' . str_repeat('[/font]', 128);
$stored = nested_store($source);
nested_assert(substr_count($stored, ':abc]') === 256, 'deep valid nesting must compile every pair');
$html = bbencode_second_pass($stored, 'abc');
nested_assert(substr_count($html, '<span') === 128 && substr_count($html, '</span>') === 128 && strip_tags($html) === 'tiefe Grüße 😀', 'deep nesting must retain structure and exact text');
$source = str_repeat('[b]', 257) . 'complete source' . str_repeat('[/b]', 257);
nested_assert(nested_store($source) === $source, 'excessive nesting must preserve the complete original rather than partial compilation');
$source = '[b:abc]old[/b:abc] [b]new[/b]';
nested_assert(phpbb_bbcode_encode_formatting($source, 'abc') === '[b:abc]old[/b:abc] [b:abc]new[/b:abc]', 'precompiled tags must not be encoded twice or consume new closers');
echo "Nested formatting runtime tests passed.\n";
