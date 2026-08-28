<?php

define('IN_PHPBB', true);
$phpbb_root_path = './';

$lang = array(
	'Quote' => 'Quote',
	'wrote' => 'wrote',
	'Code' => 'Code',
	'PHPCode' => 'PHP',
	'Select' => 'Select',
	'Expand' => 'Expand',
	'Contract' => 'Contract',
);

class BbcodeSmokeTemplate
{
	function make_filename($filename)
	{
		return dirname(__FILE__) . '/../templates/fisubsilversh/' . $filename;
	}
}

$template = new BbcodeSmokeTemplate();

require dirname(__FILE__) . '/../includes/bbcode.php';

$uid = 'abc123def4';
$input = '[b]bold[/b] [fade]fade[/fade] [center]centered[/center] '
	. '[flipv]vertical[/flipv] [fliph]horizontal[/fliph] '
	. '[google]abi test[/google] '
	. '[left]https://example.com/image.jpg[/left] '
	. '[schild=1]hello world[/schild]';

$encoded = bbencode_first_pass($input, $uid);
$output = bbencode_second_pass($encoded, $uid);

$expected_fragments = array(
	'font-weight:bold',
	'centered',
	'vertical',
	'horizontal',
	'google.com/search?q=abi+test',
	'align="left"',
	'text2schild.php?smilie=1',
	'text=hello+world',
);

foreach ($expected_fragments as $fragment)
{
	if (strpos($output, $fragment) === false)
	{
		fwrite(STDERR, "Missing BBCode output fragment: $fragment\n");
		exit(1);
	}
}

foreach (array('[fade]', '[center]', '[flipv]', '[fliph]', '[google]', '[left]', '[schild=') as $raw_tag)
{
	if (strpos($output, $raw_tag) !== false)
	{
		fwrite(STDERR, "Unprocessed BBCode tag remains: $raw_tag\n");
		exit(1);
	}
}

fwrite(STDOUT, "BBCode smoke test passed.\n");

?>
