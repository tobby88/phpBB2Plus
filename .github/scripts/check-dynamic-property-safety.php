<?php

function dynamic_property_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Dynamic property safety test failed: $message\n");
		exit(1);
	}
}

function dynamic_property_next_significant($tokens, $index)
{
	for ($i = $index; $i < count($tokens); $i++)
	{
		if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true))
		{
			return $i;
		}
	}
	return false;
}

$root = dirname(dirname(__DIR__)) . '/phpBB2';
$classes = array();
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

foreach ($files as $file)
{
	if (!$file->isFile() || strtolower($file->getExtension()) !== 'php')
	{
		continue;
	}

	$tokens = token_get_all(file_get_contents($file->getPathname()));
	$brace_depth = 0;
	$current_class = '';
	$class_depth = 0;
	$pending_class = null;

	for ($i = 0; $i < count($tokens); $i++)
	{
		$token = $tokens[$i];
		if (is_array($token) && $token[0] === T_CLASS)
		{
			$name_index = dynamic_property_next_significant($tokens, $i + 1);
			if ($name_index === false || !is_array($tokens[$name_index]) || $tokens[$name_index][0] !== T_STRING)
			{
				continue;
			}
			$name = strtolower($tokens[$name_index][1]);
			$parent = '';
			for ($j = $name_index + 1; $j < count($tokens); $j++)
			{
				if ($tokens[$j] === '{')
				{
					break;
				}
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_EXTENDS)
				{
					$parent_index = dynamic_property_next_significant($tokens, $j + 1);
					if ($parent_index !== false && is_array($tokens[$parent_index]))
					{
						$parent = strtolower($tokens[$parent_index][1]);
					}
				}
			}
			$classes[$name] = array(
				'name' => $tokens[$name_index][1],
				'parent' => $parent,
				'properties' => array(),
				'uses' => array(),
			);
			$pending_class = $name;
			continue;
		}

		if ($token === '{')
		{
			$brace_depth++;
			if ($pending_class !== null)
			{
				$current_class = $pending_class;
				$class_depth = $brace_depth;
				$pending_class = null;
			}
			continue;
		}
		if ($token === '}')
		{
			if ($current_class !== '' && $brace_depth === $class_depth)
			{
				$current_class = '';
				$class_depth = 0;
			}
			$brace_depth--;
			continue;
		}
		if ($current_class === '' || !is_array($token))
		{
			continue;
		}

		if ($brace_depth === $class_depth && in_array($token[0], array(T_VAR, T_PUBLIC, T_PROTECTED, T_PRIVATE), true))
		{
			for ($j = $i + 1; $j < count($tokens) && $tokens[$j] !== ';'; $j++)
			{
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_FUNCTION)
				{
					break;
				}
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE)
				{
					$classes[$current_class]['properties'][substr($tokens[$j][1], 1)] = true;
				}
			}
		}

		if ($token[0] === T_VARIABLE && $token[1] === '$this')
		{
			$operator_index = dynamic_property_next_significant($tokens, $i + 1);
			$name_index = $operator_index === false ? false : dynamic_property_next_significant($tokens, $operator_index + 1);
			if ($operator_index === false || $name_index === false || !is_array($tokens[$operator_index]) || $tokens[$operator_index][0] !== T_OBJECT_OPERATOR || !is_array($tokens[$name_index]) || $tokens[$name_index][0] !== T_STRING)
			{
				continue;
			}
			$after_index = dynamic_property_next_significant($tokens, $name_index + 1);
			if ($after_index !== false && $tokens[$after_index] === '(')
			{
				continue;
			}
			$property = $tokens[$name_index][1];
			$classes[$current_class]['uses'][$property][] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)) . ':' . $token[2];
		}
	}
}

$errors = array();
foreach ($classes as $class_name => $class)
{
	$properties = $class['properties'];
	$parent = $class['parent'];
	$seen = array();
	while ($parent !== '' && isset($classes[$parent]) && !isset($seen[$parent]))
	{
		$seen[$parent] = true;
		$properties += $classes[$parent]['properties'];
		$parent = $classes[$parent]['parent'];
	}
	foreach ($class['uses'] as $property => $locations)
	{
		if (!isset($properties[$property]))
		{
			$errors[] = $class['name'] . '::$' . $property . ' at ' . implode(', ', $locations);
		}
	}
}

dynamic_property_assert(!$errors, implode("\n", $errors));
echo "Dynamic property safety checks passed.\n";
