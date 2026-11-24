<?php
if (!defined('IN_PHPBB')) { exit; }

/** Only read paths inside the operator's offline media copy. */
function plus_media_path($root, $relative, $directory = false)
{
	if (!is_string($relative) || $relative === '' || trim($relative, '/') !== $relative ||
		preg_match('~[\\\\:\x00-\x1f\x7f]|(?:^|/)\.{1,2}(?:/|$)|//~', $relative))
	{
		throw new \RuntimeException('Invalid relative media path in the source board.');
	}
	$root = realpath($root);
	$path = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $relative);
	if ($path === false || strpos($path, rtrim($root, '/\\') . DIRECTORY_SEPARATOR) !== 0 ||
		($directory ? !is_dir($path) : !is_file($path)))
	{
		throw new \RuntimeException('Missing media or a path outside its configured directory: ' . htmlspecialchars($relative, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
	}
	return $path;
}

/** Publish only a complete, byte-identical copy and reuse it on wizard retries. */
function plus_copy_media($source, $target)
{
	if ($source === $target || is_link($target)) { throw new \RuntimeException('The source and target media locations overlap or contain a link.'); }
	$digest = hash_file('sha256', $source);
	if ($digest === false) { throw new \RuntimeException('Unable to read the source media.'); }
	if (is_file($target))
	{
		if (hash_file('sha256', $target) !== $digest) { throw new \RuntimeException('A previously copied file differs from the source snapshot.'); }
		return;
	}
	$directory = realpath(dirname($target));
	$temp = tempnam($directory, 'plus-');
	if (!$temp || dirname($temp) !== $directory)
	{
		if ($temp) { unlink($temp); }
		throw new \RuntimeException('The destination media directory is not writable.');
	}
	try
	{
		if (!copy($source, $temp) || hash_file('sha256', $temp) !== $digest || !rename($temp, $target))
		{
			throw new \RuntimeException('Unable to publish a verified media copy.');
		}
	}
	finally
	{
		if (is_file($temp)) { unlink($temp); }
	}
}
