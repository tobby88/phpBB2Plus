<?php

/*
 * Locate legacy barewords which PHP 7 treated as strings
 * with a notice, but PHP 8 rejects as undefined constants. This script is kept
 * deliberately conservative and reports candidates rather than changing code.
 */

$root = dirname(dirname(__DIR__)) . '/phpBB2';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$ignored = array('true', 'false', 'null', 'self', 'parent', 'static');
$candidates = array();
$defined_constants = array();

foreach ($iterator as $file)
{
	if (!$file->isFile() || strtolower($file->getExtension()) !== 'php')
	{
		continue;
	}
	$source = file_get_contents($file->getPathname());
	if (preg_match_all('/\bdefine\s*\(\s*([\'\"])([A-Za-z_][A-Za-z0-9_]*)\1/i', $source, $matches))
	{
		foreach ($matches[2] as $constant_name)
		{
			$defined_constants[$constant_name] = true;
		}
	}
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

foreach ($iterator as $file)
{
	if (!$file->isFile() || strtolower($file->getExtension()) !== 'php')
	{
		continue;
	}

	$tokens = token_get_all(file_get_contents($file->getPathname()));
	$count = count($tokens);
	for ($i = 0; $i < $count; $i++)
	{
		if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING)
		{
			continue;
		}

		$word = $tokens[$i][1];
		if ($word === strtoupper($word) || isset($defined_constants[$word]) || in_array(strtolower($word), $ignored, true))
		{
			continue;
		}

		$previous = null;
		for ($p = $i - 1; $p >= 0; $p--)
		{
			if (!is_array($tokens[$p]) || !in_array($tokens[$p][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true))
			{
				$previous = $tokens[$p];
				break;
			}
		}

		$next = null;
		for ($n = $i + 1; $n < $count; $n++)
		{
			if (!is_array($tokens[$n]) || !in_array($tokens[$n][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true))
			{
				$next = $tokens[$n];
				break;
			}
		}

		$previous_id = is_array($previous) ? $previous[0] : null;
		$next_text = is_array($next) ? $next[1] : $next;
		if ($next_text === '(' || $next_text === ':' || $next_text === '{')
		{
			continue;
		}
		if (in_array($previous_id, array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CLASS, T_INTERFACE, T_EXTENDS, T_IMPLEMENTS, T_NEW, T_INSTANCEOF, T_NAMESPACE, T_USE, T_AS), true))
		{
			continue;
		}

		if (!in_array($next_text, array(',', ')', ';', ']', '?', '+', '-', '*', '/', '.', '==', '===', '!=', '!==', '&&', '||'), true))
		{
			continue;
		}

		$relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
		$candidates[] = $relative . ':' . $tokens[$i][2] . ': ' . $word;
	}
}

sort($candidates);
if (count($candidates))
{
	fwrite(STDERR, "PHP 8 bareword safety test failed:\n" . implode("\n", $candidates) . "\n");
	exit(1);
}

echo "PHP 8 bareword safety checks passed.\n";
