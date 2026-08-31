<?php

function new_window_link_files($root)
{
	$files = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
	);
	foreach ($iterator as $file)
	{
		if (!$file->isFile() || !in_array(strtolower($file->getExtension()), array('php', 'tpl'), true))
		{
			continue;
		}
		$files[] = $file->getPathname();
	}
	return $files;
}

$root = dirname(dirname(__DIR__)) . '/phpBB2';
$errors = array();
foreach (new_window_link_files($root) as $filename)
{
	$contents = (string) file_get_contents($filename);
	if (!preg_match_all('/<a\b[^>]*\btarget\s*=\s*["\']_blank["\'][^>]*>/i', $contents, $matches, PREG_OFFSET_CAPTURE))
	{
		continue;
	}
	foreach ($matches[0] as $match)
	{
		$tag = $match[0];
		if (!preg_match('/\brel\s*=\s*["\'][^"\']*\bnoopener\b[^"\']*\bnoreferrer\b[^"\']*["\']/i', $tag))
		{
			$line = substr_count(substr($contents, 0, $match[1]), "\n") + 1;
			$errors[] = str_replace('\\', '/', substr($filename, strlen($root) + 1)) . ':' . $line;
		}
	}
}

// These fragments are injected into opening anchor tags by legacy Album and
// attachment templates, so they need the same opener protection as literals.
foreach (array('album_cat.php', 'album_personal.php', 'album_showpage.php', 'album.php', 'portal.php', 'attach_mod/displaying.php') as $relative)
{
	$contents = (string) file_get_contents($root . '/' . $relative);
	if (preg_match('/(?:=>|=)\s*[\'\"]target="_blank"[\'\"]/', $contents))
	{
		$errors[] = $relative . ': unsafe generated target fragment';
	}
}

if ($errors)
{
	fwrite(STDERR, "New-window links without opener isolation:\n" . implode("\n", $errors) . "\n");
	exit(1);
}

echo "New-window link safety checks passed.\n";
