<?php
define('IN_PHPBB', true);
$phpbb_root_path = dirname(dirname(__DIR__)) . '/phpBB2/';
require $phpbb_root_path . 'includes/functions_post.php';
require $phpbb_root_path . 'includes/bbcode.php';
class TableBbcodeTemplate
{
	public function make_filename($file) { return $GLOBALS['phpbb_root_path'] . 'templates/fisubsilversh/' . $file; }
}
$template = new TableBbcodeTemplate();
$lang = array('Quote'=>'Quote', 'wrote'=>'wrote', 'Code'=>'Code', 'PHPCode'=>'PHP', 'Select'=>'Select', 'Expand'=>'Expand', 'Contract'=>'Contract', 'Media_open'=>'Open');
function table_bbcode_assert($condition, $message)
{
	if (!$condition) { fwrite(STDERR, "Table BBCode test failed: $message\n"); exit(1); }
}
function table_bbcode_render($source)
{
	$stored = stripslashes(prepare_message(addslashes($source), false, true, false, 'abc'));
	return str_replace("\n", '<br />', bbencode_second_pass($stored, 'abc'));
}
function table_bbcode_check_structure($html)
{
	preg_match_all('#<[^>]*>|[^<]+#s', $html, $pieces);
	$stack = array();
	foreach ($pieces[0] as $piece)
	{
		$parent = count($stack) ? $stack[count($stack) - 1] : '';
		if (preg_match('#^<(/?)(table|tr|td)\b#i', $piece, $tag))
		{
			$name = strtolower($tag[2]);
			if ($tag[1] === '/')
			{
				table_bbcode_assert(array_pop($stack) === $name, 'tables, rows and cells must close in nesting order');
			}
			else
			{
				table_bbcode_assert(($name === 'table' && ($parent === '' || $parent === 'td')) || ($name === 'tr' && $parent === 'table') || ($name === 'td' && $parent === 'tr'), 'cells and rows must have their own structural parent');
				$stack[] = $name;
			}
		}
		else if ($parent === 'tr' || $parent === 'table')
		{
			table_bbcode_assert(trim($piece) === '', 'row contents must not escape cells through text or converted newline tags');
		}
	}
	table_bbcode_assert(empty($stack), 'no table may remain open at the end of a post');
}

$valid = array(
	array('[table=width:80%][cell=color:red]left[/cell][cell=]right[/cell][/table]', 1, 2),
	array("[table=]\n [cell=]first\nline[/cell]\n [cell=]second[/cell]\n[/table]", 1, 2),
	array('[table=][cell=]outer[table=][cell=]inner[/cell][/table][/cell][/table]', 2, 2),
	array('[quote][table=][cell=]quoted[/cell][/table][/quote]', 2, 3),
	array('[table=][cell=][quote]inside[/quote][/cell][/table]', 2, 3),
	array('[align=center][table=][cell=][b]inside[/b][/cell][/table][/align]', 1, 1),
);
foreach ($valid as $case)
{
	$html = table_bbcode_render($case[0]);
	table_bbcode_check_structure($html);
	$source_words = preg_split('/\[[^\]]*\]/', $case[0], -1, PREG_SPLIT_NO_EMPTY);
	$visible = html_entity_decode(strip_tags(str_replace('<br />', "\n", $html)), ENT_QUOTES, 'UTF-8');
	foreach ($source_words as $words)
	{
		if (trim($words) !== '')
		{
			table_bbcode_assert(strpos($visible, $words) !== false, 'nested table encoding must retain all original text');
		}
	}
	table_bbcode_assert(substr_count($html, '<table') === $case[1] && substr_count($html, '<td') === $case[2], 'valid nested tables and cells must remain supported: ' . $case[0] . ' => ' . $html);
	table_bbcode_assert(strpos($html, '&#91;table') === false && strpos($html, ':abc]') === false, 'valid table markup must render, not become literal compiler syntax');
}
$invalid = array(
	'[cell=]standalone[/cell]',
	'[table=][cell=]crossed[/table]tail[/cell]',
	'[table=]text without cells[/table]',
	'[table=][b][cell=]misplaced[/cell][/b][/table]',
	'[quote][table=][cell=]crossed quote[/quote][/cell][/table]',
	'[align=center][table=][cell=]crossed div[/align][/cell][/table]',
	'[table=][cell=][quote]crossed cell[/cell][/table][/quote]',
);
foreach ($invalid as $case)
{
	$html = table_bbcode_render($case);
	table_bbcode_check_structure($html);
	table_bbcode_assert(strpos($html, '&#91;cell') !== false || strpos($html, '&#91;table') !== false, 'invalid structure must retain readable source tags');
}
$html = table_bbcode_render('[cell=]orphan[/cell] [table=][cell=]valid neighbor[/cell][/table]');
table_bbcode_check_structure($html);
table_bbcode_assert(substr_count($html, '<table') === 1, 'an invalid standalone cell must not disable a separate valid table');
$html = bbencode_second_pass('[TABLE=:ABC][CELL=:ABC]legacy[/CELL:ABC][/TABLE:ABC]', 'abc');
table_bbcode_check_structure($html);
table_bbcode_assert(substr_count($html, '<table') === 1, 'mixed-case legacy tags must render consistently');
$html = bbencode_second_pass('[acronym:abc="[table=width:100%:abc][cell=:abc]"]label[/acronym:abc]', 'abc');
table_bbcode_assert(strpos($html, '<table') === false && strpos($html, '<td') === false, 'BBCode in an acronym description must stay inside the escaped attribute');

// Deterministic malformed legacy sequences: check structure, not a duplicated
// copy of the validator's decision algorithm.
$tokens = array('[table=:abc]', '[cell=:abc]', '[/table:abc]', '[/cell:abc]', 'text');
for ($number = 0; $number < 625; $number++)
{
	$value = $number;
	$source = '';
	for ($position = 0; $position < 4; $position++)
	{
		$source .= $tokens[$value % 5];
		$value = (int) floor($value / 5);
	}
	table_bbcode_check_structure(bbencode_second_pass($source, 'abc'));
}
echo "Table BBCode runtime tests passed.\n";
