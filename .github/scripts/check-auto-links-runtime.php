<?php
define('IN_PHPBB', true);
$phpbb_root_path = dirname(dirname(__DIR__)) . '/phpBB2/';
require $phpbb_root_path . 'includes/bbcode.php';
function auto_link_assert($condition, $message)
{
	if (!$condition) { throw new RuntimeException('Auto-link test failed: ' . $message); }
}
$plain = 'Grüße https://example.org/path?a=1&amp;b=2 www.example.net user@example.org tail';
$linked = make_clickable($plain);
auto_link_assert(substr_count($linked, '<a ') === 3, 'normal URL, bare host and email must become links');
auto_link_assert(strip_tags($linked) === $plain, 'normal linking must retain every visible source byte');
$existing = '<a href="https://example.org/">see https://example.net/ <b>www.example.net</b></a>';
auto_link_assert(make_clickable($existing) === $existing, 'existing anchors and their nested labels must not acquire nested links');
foreach (array(
	'<acronym title="see https://example.org/ > details">Label</acronym>',
	"<span title='see www.example.org > details'>Label</span>",
	'<!-- see https://example.org/ -->',
	'<img src="image.png" alt="see https://example.org/" />',
	'<code>https://example.org/ <span>user@example.org</span></code>',
	'<pre>https://example.org/</pre>',
	'<textarea>https://example.org/</textarea>',
	'<script>var example = "https://example.org/";</script>',
	'<style>/* https://example.org/ */</style>',
	'<A HREF="https://example.org/">www.example.org</A>',
	'<code><code>www.example.org</code> https://example.org/</code>',
	'<a href="/example">unclosed https://example.org/',
) as $protected)
{
	auto_link_assert(make_clickable($protected) === $protected, 'HTML attributes, comments and literal/linked text must remain byte-identical');
}
$mixed = $existing . '<b>https://example.com/</b><code>www.example.org</code> user@example.net';
$mixed_result = make_clickable($mixed);
auto_link_assert(substr_count($mixed_result, '<a ') === 3, 'prose outside protected regions must still link');
auto_link_assert(strpos($mixed_result, $existing) === 0 && strpos($mixed_result, '<code>www.example.org</code>') !== false, 'protected regions must remain exact within mixed input');
auto_link_assert(make_clickable($mixed_result) === $mixed_result, 'repeated linkification must not nest links or change markup');
foreach (array('script', 'style', 'textarea') as $raw_tag)
{
	$raw = '<' . $raw_tag . '>literal <a> www.example.org</' . $raw_tag . '>';
	$after = make_clickable($raw . ' https://example.net/');
	auto_link_assert(strpos($after, $raw) === 0 && strpos(substr($after, strlen($raw)), '<a href="https://example.net/"') !== false, 'raw-text markup-like content must not suppress links after its closing tag');
}
$large = str_repeat('<table><tr><td><code>www.example.org</code></td></tr></table>', 1000);
$large_output = make_clickable($large . ' https://example.net/');
auto_link_assert(strpos($large_output, $large) === 0 && strpos(substr($large_output, strlen($large)), '<a href="https://example.net/"') !== false, 'long valid rendered topics must still link normally');
$attribute = '<span title="' . str_repeat('Grüße > www.example.org ', 1000) . '">label</span>';
auto_link_assert(make_clickable($attribute) === $attribute, 'long quoted attributes must remain intact');

$warnings = array();
set_error_handler(function ($number, $message) use (&$warnings) { $warnings[] = $message; return true; });
$limit = ini_get('pcre.backtrack_limit');
$input = 'prefix Grüße https://example.org/path user@example.org tail';
$complete = make_clickable($input);
$fallback_count = 0;
foreach (array(1, 2, 5, 10) as $small_limit)
{
	ini_set('pcre.backtrack_limit', (string) $small_limit);
	$output = make_clickable($input);
	// PCRE/JIT versions use different amounts of backtracking: successful
	// complete rendering is valid, partial output or lost source is not.
	auto_link_assert($output === $input || $output === $complete, 'linking must be all-or-nothing when regex limits are reached');
	if ($output === $input) { $fallback_count++; }
}
ini_set('pcre.backtrack_limit', $limit);
restore_error_handler();
auto_link_assert(count($warnings) === 0, 'regex failure must not emit null-subject warnings');
auto_link_assert($fallback_count > 0, 'fixture must actually exercise regex-failure recovery');
echo "Automatic link runtime tests passed.\n";
