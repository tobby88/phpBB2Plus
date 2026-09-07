<?php
define('IN_PHPBB', true);
$phpbb_root_path = dirname(dirname(__DIR__)) . '/phpBB2/';
require $phpbb_root_path . 'includes/bbcode.php';
require $phpbb_root_path . 'includes/functions_post.php';
class ContainerBbcodeTemplate
{
	public function make_filename($file) { return $GLOBALS['phpbb_root_path'] . 'templates/fisubsilversh/' . $file; }
}
$template = new ContainerBbcodeTemplate();
$lang = array('Quote'=>'Quote', 'wrote'=>'wrote', 'Code'=>'Code', 'PHPCode'=>'PHP', 'Select'=>'Select', 'Expand'=>'Expand', 'Contract'=>'Contract');
function container_assert($ok, $message)
{
	if (!$ok) { fwrite(STDERR, "Container BBCode test failed: $message\n"); exit(1); }
}
function container_structure($html)
{
	preg_match_all('#</?(?:div|span|table|tr|td|ul|ol|li|s|acronym|marquee)\b[^>]*>#i', $html, $tokens);
	$stack = array();
	foreach ($tokens[0] as $token)
	{
		preg_match('#^<(/?)([a-z]+)#i', $token, $match);
		$name = strtolower($match[2]);
		if ($name === 'li' && $match[1] === '' && end($stack) === 'li') { array_pop($stack); }
		if ($match[1] === '/' && ($name === 'ul' || $name === 'ol') && end($stack) === 'li') { array_pop($stack); }
		if ($match[1] === '/') { container_assert(array_pop($stack) === $name, 'closing tag escapes its container: ' . $html); }
		else { $stack[] = $name; }
	}
	container_assert(!$stack, 'post leaves layout containers open: ' . $html);
}

$cases = array(
	'[/align:abc]tail', '[center:abc]open', '[poet:abc]poem',
	'[align=invalid:abc]text[/align:abc]',
	'[align=center:abc][quote:abc]crossed[/align:abc][/quote:abc]',
	'[quote:abc][poet:abc]crossed[/quote:abc][/poet:abc]',
	'[b:abc][i:abc]crossed[/b:abc][/i:abc]',
	'[COLOR=red:ABC]mixed[/COLOR:ABC]',
	'[list:abc][*:abc][b:abc]first[*:abc]second[/b:abc][/list:u:abc]',
	'[*:abc]orphan', '[list:abc][*:abc]wrong kind[/list:o:abc]',
	'[poetfoo:abc]unknown poetry[/poet:abc]',
	'[font=x [b:abc]text[/b:abc]:abc]',
	'[font=<bad>:abc]bad value[/font:abc]',
	'[poet=<bad>:abc]bad value[/poet:abc]',
	'[table=<bad>:abc][cell=:abc]safe style fallback[/cell:abc][/table:abc]',
	'[table=:abc][cell=<bad>:abc]safe cell fallback[/cell:abc][/table:abc]',
	'[table=x [b:abc]text[/b:abc]:abc]',
);
foreach ($cases as $source) { container_structure(bbencode_second_pass($source, 'abc')); }
foreach (array('b', 'i', 'u', 's', 'fade', 'flipv', 'fliph', 'scrollleft', 'scrollright', 'scrollup', 'scrolldown', 'center', 'poet', 'align=center', 'color=red', 'size=12', 'font=serif', 'marq=left', 'glow=red', 'shadow=red', 'highlight=red') as $tag)
{
	$name = explode('=', $tag);
	$html = bbencode_second_pass('[' . strtoupper($tag) . ':ABC]body[/' . strtoupper($name[0]) . ':ABC]', 'abc');
	container_structure($html);
	container_assert(strpos($html, ':ABC') === false && strpos($html, ':abc') === false && strpos($html, '&#91;') === false, 'valid mixed-case container must render: ' . $tag);
}
$valid = '[align=center:abc][poet:abc][b:abc]Grüße[/b:abc][/poet:abc][/align:abc]';
$html = bbencode_second_pass($valid, 'abc');
container_structure($html);
container_assert(strpos($html, 'text-align:center') !== false && strpos($html, 'font-weight:bold') !== false && strpos($html, 'bbcode-poem') !== false, 'valid nested formatting must be retained');
$html = bbencode_second_pass('[/center:abc][b:abc]valid neighbor[/b:abc]', 'abc');
container_structure($html);
container_assert(strpos($html, 'font-weight:bold') !== false && strpos(html_entity_decode($html, ENT_QUOTES, 'UTF-8'), '[/center]') !== false, 'malformed tags must not disable independent valid formatting');
foreach (array('[list:abc][*:abc]one[*:abc]two[/list:u:abc]', '[list=1:abc][*:abc]one[list:abc][*:abc]inner[/list:u:abc][/list:o:abc]') as $source)
{
	$html = bbencode_second_pass($source, 'abc');
	container_structure($html);
	container_assert(strpos($html, '<li>') !== false, 'valid lists must still render');
}
$tokens = array('[align=center:abc]', '[/align:abc]', '[poet:abc]', '[/poet:abc]', '[quote:abc]', '[/quote:abc]', '[b:abc]', '[/b:abc]', '[table=:abc]', '[/table:abc]', '[cell=:abc]', '[/cell:abc]', '[list:abc]', '[/list:u:abc]');
$base = count($tokens);
for ($number = 0; $number < $base * $base * $base * $base; $number++)
{
	$value = $number; $source = '';
	for ($position = 0; $position < 4; $position++) { $source .= $tokens[$value % $base] . 'x'; $value = (int) floor($value / $base); }
	$html = bbencode_second_pass($source, 'abc');
	container_structure($html);
	container_assert(substr_count(strip_tags($html), 'x') === 4, 'malformed structures must retain all body text');
}
echo "Container BBCode runtime tests passed.\n";
