<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// Preserve the legacy internal '+ 1'/'- 1' contract without treating a partial
// number, array or SQL expression as an instruction to change stored counts.
function kb_counter_delta($value)
{
	if (is_int($value)) { return $value >= -16777215 && $value <= 16777215 ? $value : null; }
	if (!is_string($value) || strlen($value) > 32 ||
		preg_match('/^[ \t]*([+-]?)[ \t]*([0-9]{1,8})[ \t]*$/D', $value, $match) !== 1)
	{ return null; }
	$number = (int) $match[2];
	if ($number > 16777215) { return null; }
	return $match[1] === '-' ? -$number : $number;
}

function kb_counter_category_id($value, $zero = false)
{
	if (!(is_int($value) || is_string($value)) || preg_match('/^[0-9]{1,8}$/D', (string) $value) !== 1)
	{ throw new RuntimeException('Invalid Knowledge Base category reference'); }
	$id = (int) $value;
	if ($id > 16777215 || (!$zero && $id === 0)) { throw new RuntimeException('Knowledge Base category reference out of range'); }
	return $id;
}

// Pure derived-data plan. Validate the complete hierarchy and all sources before
// returning any updates; never remove/reparent articles to make a plan succeed.
function kb_counter_rebuild_plan($categories, $article_counts)
{
	if (!is_array($categories) || !is_array($article_counts)) { throw new RuntimeException('Invalid Knowledge Base counter inventory'); }
	$nodes = array(); $expected = array();
	foreach ($categories as $row)
	{
		if (!is_array($row) || !array_key_exists('category_id', $row) || !array_key_exists('parent', $row) || !array_key_exists('number_articles', $row))
		{ throw new RuntimeException('Incomplete Knowledge Base category inventory'); }
		$id = kb_counter_category_id($row['category_id']);
		if (isset($nodes[$id])) { throw new RuntimeException('Duplicate Knowledge Base category reference'); }
		$parent = $row['parent'] === null ? 0 : kb_counter_category_id($row['parent'], true);
		$stored = $row['number_articles'];
		if ($stored !== null && (!(is_int($stored) || is_string($stored)) || preg_match('/^-?(?:0|[1-9][0-9]{0,19})$/D', (string) $stored) !== 1))
		{ throw new RuntimeException('Unsupported Knowledge Base counter value'); }
		$nodes[$id] = array('parent'=>$parent, 'stored'=>$stored === null ? null : (string) $stored); $expected[$id] = 0;
	}
	foreach ($nodes as $id=>$node)
	{
		$seen = array(); $current = $id;
		while ($current > 0)
		{
			if (!isset($nodes[$current]) || isset($seen[$current]) || count($seen) >= 100)
			{ throw new RuntimeException('Invalid or unsupported Knowledge Base category hierarchy'); }
			$seen[$current] = true; $current = $nodes[$current]['parent'];
		}
	}
	$direct = array();
	foreach ($article_counts as $category=>$value)
	{
		$id = kb_counter_category_id($category);
		if (!isset($nodes[$id]) || isset($direct[$id])) { throw new RuntimeException('Ambiguous Knowledge Base article category'); }
		$count = kb_counter_category_id($value, true); $direct[$id] = $count;
		$current = $id;
		while ($current > 0)
		{
			if ($expected[$current] > 16777215 - $count) { throw new RuntimeException('Knowledge Base category count exceeds bundled capacity'); }
			$expected[$current] += $count; $current = $nodes[$current]['parent'];
		}
	}
	ksort($expected, SORT_NUMERIC); $changes = array();
	foreach ($expected as $id=>$value)
	{
		if ($nodes[$id]['stored'] === null || $nodes[$id]['stored'] !== (string) $value)
		{ $changes[$id] = array('old'=>$nodes[$id]['stored'], 'new'=>$value); }
	}
	return array('expected'=>$expected, 'changes'=>$changes);
}
