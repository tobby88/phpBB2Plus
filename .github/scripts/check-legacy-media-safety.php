<?php

function legacy_media_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Legacy media safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$bbcode = file_get_contents($root . '/phpBB2/includes/bbcode.php');
$full_template = file_get_contents($root . '/phpBB2/templates/fisubsilversh/bbcode.tpl');
$config = file_get_contents($root . '/phpBB2/assets/ruffle/phpbb-config.js');

legacy_media_assert(strpos($bbcode, 'load_bbcode_template_blocks') !== false, 'partial styles need the complete Plus BBCode fallback');
legacy_media_assert(strpos($full_template, 'type="application/x-shockwave-flash"') !== false, 'Flash BBCode must use a Ruffle-compatible object');
legacy_media_assert(strpos($full_template, '<audio controls="controls"') !== false, 'audio BBCode must use native HTML5 controls');
legacy_media_assert(strpos($full_template, '<video controls="controls"') !== false, 'video BBCode must use native HTML5 controls');
legacy_media_assert(strpos($config, 'allowScriptAccess: false') !== false, 'generic Flash embeds must not call page JavaScript');
legacy_media_assert(strpos($config, "openUrlMode: 'confirm'") !== false, 'generic Flash navigation must require confirmation');

$header_count = 0;
foreach (glob($root . '/phpBB2/templates/*/overall_header.tpl') as $header_file)
{
	$header = file_get_contents($header_file);
	legacy_media_assert(strpos($header, 'assets/ruffle/phpbb-config.js') !== false, basename(dirname($header_file)) . ' must load the safe Ruffle configuration');
	legacy_media_assert(strpos($header, 'assets/ruffle/ruffle.js') !== false, basename(dirname($header_file)) . ' must load Ruffle');
	$header_count++;
}
legacy_media_assert($header_count >= 7, 'all bundled styles must be covered');

$legacy_patterns = array(
	'clsid:D27CDB6E',
	'download.macromedia.com',
	'PLUGINSPAGE="http://www.macromedia.com',
	'activex.microsoft.com',
	'application/x-mplayer2',
	'audio/x-pn-realaudio-plugin'
);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/phpBB2', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file)
{
	if (!$file->isFile() || !in_array(strtolower($file->getExtension()), array('php', 'tpl'), true))
	{
		continue;
	}
	$contents = file_get_contents($file->getPathname());
	foreach ($legacy_patterns as $pattern)
	{
		legacy_media_assert(stripos($contents, $pattern) === false, $file->getPathname() . ' still contains obsolete browser-plugin markup');
	}
}

echo "Legacy media safety tests passed.\n";
