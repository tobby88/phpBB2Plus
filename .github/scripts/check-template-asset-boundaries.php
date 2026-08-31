<?php

$root = dirname(dirname(__DIR__));
$templates_root = $root . '/phpBB2/templates';
$styles = array('BS', 'BS_subIce', 'BS_subSilver', 'fisubsilversh', 'prosilver', 'prosilver_se', 'subSilver');
$failed = false;

foreach ($styles as $style)
{
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templates_root . '/' . $style, FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file)
	{
		if (!$file->isFile() || strtolower($file->getExtension()) !== 'tpl')
		{
			continue;
		}
		$source = file_get_contents($file->getPathname());
		if (strpos($source, 'templates/fisubsilver/') !== false)
		{
			fwrite(STDERR, $file->getPathname() . ": references the misspelled fisubsilver style\n");
			$failed = true;
		}
		if (!preg_match_all('~(?:\.\./)?templates/([A-Za-z0-9_-]+)/([^\"\'\)\s<>]+)~', $source, $matches, PREG_SET_ORDER))
		{
			continue;
		}
		foreach ($matches as $match)
		{
			$target = $match[1];
			$suffix = $match[2];
			if ($target !== $style && $target !== 'assets' && file_exists($templates_root . '/' . $style . '/' . $suffix))
			{
				fwrite(STDERR, $file->getPathname() . ': needlessly couples ' . $style . ' to ' . $target . '/' . $suffix . "\n");
				$failed = true;
			}
		}
	}
}

$prosilver_se_header = file_get_contents($templates_root . '/prosilver_se/simple_header.tpl');
if (strpos($prosilver_se_header, 'templates/prosilver_se/{T_HEAD_STYLESHEET}') === false)
{
	fwrite(STDERR, "prosilver_se simple header does not load its own stylesheet\n");
	$failed = true;
}

if ($failed)
{
	exit(1);
}

echo "Template asset boundary checks passed.\n";
