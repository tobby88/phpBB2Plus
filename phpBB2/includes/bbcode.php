<?php
/***************************************************************************
 *                              bbcode.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: bbcode.php,v 1.36.2.31 2004/03/25 15:57:20 acydburn Exp $
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

if ( !defined('IN_PHPBB') )
{
	die("Hacking attempt");
}

define("BBCODE_UID_LEN", 10);

// global that holds loaded-and-prepared bbcode templates, so we only have to do
// that stuff once.

$bbcode_tpl = null;

class PhpbbBbcodeParseException extends RuntimeException {}

// PCRE failures must abort a rendering/encoding pass, not replace its input
// with null and allow later substitutions to conceal the lost content.
function phpbb_bbcode_replace($pattern, $replacement, $subject)
{
	$result = @preg_replace($pattern, $replacement, $subject);
	if ($result === null || preg_last_error() !== PREG_NO_ERROR)
	{
		throw new PhpbbBbcodeParseException('BBCode replacement failed');
	}
	return $result;
}

function phpbb_bbcode_replace_callback($pattern, $callback, $subject)
{
	$result = @preg_replace_callback($pattern, $callback, $subject);
	if ($result === null || preg_last_error() !== PREG_NO_ERROR)
	{
		throw new PhpbbBbcodeParseException('BBCode callback replacement failed');
	}
	return $result;
}

function phpbb_bbcode_match($pattern, $subject, &$matches = null)
{
	$result = @preg_match($pattern, $subject, $matches);
	if ($result === false || preg_last_error() !== PREG_NO_ERROR)
	{
		throw new PhpbbBbcodeParseException('BBCode token matching failed');
	}
	return $result;
}

function phpbb_schild($smilie, $parameter, $text)
{
	$smilie = preg_match('/^[a-z0-9]+$/i', (string) $smilie) ? strtolower((string) $smilie) : '1';
	$text = rawurlencode(trim(html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8')));
	$fontcolor = '000000';
	$shadowcolor = '';
	$shieldshadow = '1';

	$values = array();
	if (preg_match_all('/(?:^|\s)(fontcolor|shadowcolor|shieldshadow)=([^\s]+)/i', trim((string) $parameter), $matches, PREG_SET_ORDER))
	{
		foreach ($matches as $match)
		{
			$values[strtolower($match[1])] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
		}
	}

	foreach (array('fontcolor', 'shadowcolor') as $color_name)
	{
		if (isset($values[$color_name]))
		{
			$color = ltrim($values[$color_name], '#');
			if (preg_match('/^[0-9a-f]{6}$/i', $color))
			{
				if ($color_name === 'fontcolor')
				{
					$fontcolor = strtolower($color);
				}
				else
				{
					$shadowcolor = strtolower($color);
				}
			}
		}
	}
	if (isset($values['shieldshadow']) && $values['shieldshadow'] === '0')
	{
		$shieldshadow = '0';
	}

	return 'text2schild.php?smilie=' . rawurlencode($smilie)
		. '&amp;fontcolor=' . rawurlencode($fontcolor)
		. '&amp;shadowcolor=' . rawurlencode($shadowcolor)
		. '&amp;shieldshadow=' . $shieldshadow
		. '&amp;text=' . $text;
}

/**
 * Loads bbcode templates from the bbcode.tpl file of the current template set.
 * Creates an array, keys are bbcode names like "b_open" or "url", values
 * are the associated template.
 * Incomplete template sets are rejected before preparation, so the public
 * rendering entry point can preserve the message as safe source text.
 *
 * Nathan Codding, Sept 26 2001.
 */
function load_bbcode_template()
{
	global $template, $phpbb_root_path;

	$bbcode_tpls = load_bbcode_template_blocks($phpbb_root_path . 'templates/fisubsilversh/bbcode.tpl');
	$style_tpls = load_bbcode_template_blocks($template->make_filename('bbcode.tpl'));
	foreach ($style_tpls as $name => $markup)
	{
		$bbcode_tpls[$name] = $markup;
	}

	return $bbcode_tpls;
}

/**
 * Reads one BBCode template into named blocks. Most imported styles only
 * override phpBB's original blocks, so load_bbcode_template() overlays them
 * on the complete Plus template instead of leaving newer tags undefined.
 */
function load_bbcode_template_blocks($tpl_filename)
{
	if (!is_string($tpl_filename) || !is_file($tpl_filename) || !is_readable($tpl_filename))
	{
		return array();
	}

	$tpl = @file_get_contents($tpl_filename);
	if ($tpl === false)
	{
		return array();
	}

	// Strip newlines while preserving the historic block parser semantics.
	$tpl = str_replace("\n", '', $tpl);
	$bbcode_tpls = array();
	$matches = array();
	$count = @preg_match_all('#<!-- BEGIN (.*?) -->(.*?)<!-- END \\1 -->#', $tpl, $matches, PREG_SET_ORDER);
	if ($count === false || preg_last_error() !== PREG_NO_ERROR)
	{
		throw new PhpbbBbcodeParseException('BBCode template block matching failed');
	}
	if ($count > 0)
	{
		foreach ($matches as $match)
		{
			$bbcode_tpls[$match[1]] = $match[2];
		}
	}

	return $bbcode_tpls;
}


/**
 * Prepares the loaded bbcode templates for insertion into preg_replace()
 * or str_replace() calls in the bbencode_second_pass functions. This
 * means replacing template placeholders with the appropriate preg backrefs
 * or with language vars. NOTE: If you change how the regexps work in
 * bbencode_second_pass(), you MUST change this function.
 *
 * Nathan Codding, Sept 26 2001
 *
 */
function prepare_bbcode_template($bbcode_tpl)
{
	global $lang;

	// Validate the whole renderer contract before preparing any block or setting
	// BBCODE_TPL_READY. Missing closers must never leave half-rendered page HTML.
	$required = array('listitem', 'img', 'url', 'email', 'schild', 'ram', 'flash',
		'stream', 'video', 'hr', 'google', 'left', 'right', 'quote_username_open');
	foreach (array('ulist', 'olist', 'quote', 'code', 'php', 'b', 'u', 'i',
		'color', 'size', 'align', 'marq', 'table', 'cell', 'font', 'poet', 'fade',
		'glow', 'shadow', 'highlight', 's', 'scrollleft', 'scrollright',
		'scrollup', 'scrolldown', 'fliph', 'flipv', 'acronym') as $name)
	{
		$required[] = $name . '_open';
		$required[] = $name . '_close';
	}
	foreach ($required as $name)
	{
		if (!is_array($bbcode_tpl) || !isset($bbcode_tpl[$name]) || !is_string($bbcode_tpl[$name]))
		{
			throw new PhpbbBbcodeParseException('Incomplete BBCode template set');
		}
	}

	$bbcode_tpl['olist_open'] = str_replace('{LIST_TYPE}', '\\1', $bbcode_tpl['olist_open']);

	$bbcode_tpl['color_open'] = str_replace('{COLOR}', '\\1', $bbcode_tpl['color_open']);

	$bbcode_tpl['size_open'] = str_replace('{SIZE}', '\\1', $bbcode_tpl['size_open']);

	$bbcode_tpl['quote_open'] = str_replace('{L_QUOTE}', $lang['Quote'], $bbcode_tpl['quote_open']);

	$bbcode_tpl['quote_username_open'] = str_replace('{L_QUOTE}', $lang['Quote'], $bbcode_tpl['quote_username_open']);
	$bbcode_tpl['quote_username_open'] = str_replace('{L_WROTE}', $lang['wrote'], $bbcode_tpl['quote_username_open']);
	$bbcode_tpl['quote_username_open'] = str_replace('{USERNAME}', '\\1', $bbcode_tpl['quote_username_open']);

	$bbcode_tpl['code_open'] = str_replace('{L_CODE}', $lang['Code'], $bbcode_tpl['code_open']);
	$bbcode_tpl['php_open'] = str_replace('{L_PHP}', $lang['PHPCode'], $bbcode_tpl['php_open']); // PHP MOD
	$bbcode_tpl['img'] = str_replace('{URL}', '\\1', $bbcode_tpl['img']);

	// We do URLs in several different ways..
	$bbcode_tpl['url1'] = str_replace('{URL}', '\\1', $bbcode_tpl['url']);
	$bbcode_tpl['url1'] = str_replace('{DESCRIPTION}', '\\1', $bbcode_tpl['url1']);

	$bbcode_tpl['url2'] = str_replace('{URL}', 'http://\\1', $bbcode_tpl['url']);
	$bbcode_tpl['url2'] = str_replace('{DESCRIPTION}', '\\1', $bbcode_tpl['url2']);

	$bbcode_tpl['url3'] = str_replace('{URL}', '\\1', $bbcode_tpl['url']);
	$bbcode_tpl['url3'] = str_replace('{DESCRIPTION}', '\\2', $bbcode_tpl['url3']);

	$bbcode_tpl['url4'] = str_replace('{URL}', 'http://\\1', $bbcode_tpl['url']);
	$bbcode_tpl['url4'] = str_replace('{DESCRIPTION}', '\\3', $bbcode_tpl['url4']);

	$bbcode_tpl['email'] = str_replace('{EMAIL}', '\\1', $bbcode_tpl['email']);
	/* BEGIN CMX ACRONYM MOD */
	$bbcode_tpl['acronym_open'] = str_replace('{DESCRIPTION}', '\\1', $bbcode_tpl['acronym_open']);
	/* END CMX ACRONYM MOD */ 
	// bbcode_box Mod
	$bbcode_tpl['align_open'] = str_replace('{ALIGN}', '\\1', $bbcode_tpl['align_open']);
	$bbcode_tpl['stream'] = str_replace('{URL}', '\\1', $bbcode_tpl['stream']);
	$bbcode_tpl['ram'] = str_replace('{URL}', '\\1', $bbcode_tpl['ram']);
	$media_open = isset($lang['BBCode_media_open']) ? $lang['BBCode_media_open'] : 'Open media file';
	$flash_open = isset($lang['BBCode_flash_open']) ? $lang['BBCode_flash_open'] : 'Open Flash file';
	$bbcode_tpl['stream'] = str_replace('{L_MEDIA_OPEN}', $media_open, $bbcode_tpl['stream']);
	$bbcode_tpl['ram'] = str_replace('{L_MEDIA_OPEN}', $media_open, $bbcode_tpl['ram']);
	$bbcode_tpl['marq_open'] = str_replace('{MARQ}', '\\1', $bbcode_tpl['marq_open']);
	$bbcode_tpl['table_open'] = str_replace('{TABLE}', '\\1', $bbcode_tpl['table_open']);
	$bbcode_tpl['cell_open'] = str_replace('{CELL}', '\\1', $bbcode_tpl['cell_open']);
	$bbcode_tpl['flash'] = str_replace('{WIDTH}', '\\1', $bbcode_tpl['flash']);
	$bbcode_tpl['flash'] = str_replace('{HEIGHT}', '\\2', $bbcode_tpl['flash']);
	$bbcode_tpl['flash'] = str_replace('{URL}', '\\3', $bbcode_tpl['flash']);
	$bbcode_tpl['flash'] = str_replace('{L_FLASH_OPEN}', $flash_open, $bbcode_tpl['flash']);
	$bbcode_tpl['video'] = str_replace('{URL}', '\\3', $bbcode_tpl['video']);
	$bbcode_tpl['video'] = str_replace('{WIDTH}', '\\1', $bbcode_tpl['video']);
	$bbcode_tpl['video'] = str_replace('{HEIGHT}', '\\2', $bbcode_tpl['video']);
	$bbcode_tpl['video'] = str_replace('{L_MEDIA_OPEN}', $media_open, $bbcode_tpl['video']);
	$bbcode_tpl['font_open'] = str_replace('{FONT}', '\\1', $bbcode_tpl['font_open']);
	$bbcode_tpl['poet_open'] = str_replace('{POET}', '\\1', $bbcode_tpl['poet_open']);
	$bbcode_tpl['glow_open'] = str_replace('{GLOWCOLOR}', '\\1', $bbcode_tpl['glow_open']);
	$bbcode_tpl['shadow_open'] = str_replace('{SHADOWCOLOR}', '\\1', $bbcode_tpl['shadow_open']);
	$bbcode_tpl['highlight_open'] = str_replace('{HIGHLIGHTCOLOR}', '\\1', $bbcode_tpl['highlight_open']);
	// Google and Schild are expanded by callbacks in bbencode_second_pass().
	// Keep their templates intact instead of turning them into PHP code for
	// preg_replace()'s removed /e modifier.
	$bbcode_tpl['left'] = str_replace('{URL}', '\\1', $bbcode_tpl['left']);
	$bbcode_tpl['right'] = str_replace('{URL}', '\\1', $bbcode_tpl['right']);
	// bbcode_box Mod
 
	//Begin Smilie Creator Mod Copyright esperitox 2003

	// Select/expand controls use HTML attributes, never inline JavaScript.
	$expand_keys = array('{L_SELECT}', '{L_EXPAND}', '{L_CONTRACT}');
	$expand_labels = array();
	foreach (array('Select', 'Expand', 'Contract') as $label)
	{
		$expand_labels[] = htmlspecialchars($lang[$label], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
	foreach (array('quote_open', 'quote_username_open', 'code_open', 'php_open') as $block)
	{
		$bbcode_tpl[$block] = str_replace($expand_keys, $expand_labels, $bbcode_tpl[$block]);
	}
	
	define("BBCODE_TPL_READY", true);

	return $bbcode_tpl;
}

function phpbb_bbcode_safe_attribute($value, $max_length = 100)
{
	$value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
	$value = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value));
	if (strlen($value) > $max_length)
	{
		$value = substr($value, 0, $max_length);
	}
	return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function phpbb_bbcode_safe_text($value, $max_length = 0)
{
	$value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
	$value = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]+/', '', $value);
	if ($max_length > 0 && strlen($value) > $max_length)
	{
		$value = substr($value, 0, $max_length);
	}
	return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Balance encoded legacy quotes in document order, including orphan closers.
 *
 * Old first-pass data can contain an encoded opening quote without its closing
 * partner. Turning that opening tag into the table-based quote template would
 * otherwise leave the surrounding topic layout inside the quote table.
 */
function phpbb_bbcode_balance_quotes($text, $uid)
{
	$text = (string) $text;
	$uid = (string) $uid;
	if ($text === '' || $uid === '')
	{
		return $text;
	}

	$uid_pattern = preg_quote($uid, '#');
	$depth = 0;
	$text = phpbb_bbcode_replace_callback('#\[(?:quote:' . $uid_pattern . '(?:="(.*?)")?|(/)quote:' . $uid_pattern . ')\]#is', function ($match) use ($uid, &$depth)
	{
		if (!empty($match[2]))
		{
			if ($depth === 0)
			{
				// Keep malformed source visible without closing the page's table.
				return '[/quote]';
			}
			$depth--;
			return '[/quote:' . $uid . ']';
		}
		$depth++;
		return '[quote:' . $uid . (isset($match[1]) ? '="' . $match[1] . '"' : '') . ']';
	}, $text);
	return $text . str_repeat('[/quote:' . $uid . ']', $depth);
}

/**
 * Validate table/cell structure before substituting HTML. Invalid tables retain
 * their readable source tags; valid, independent tables are left intact.
 * Track other containers too, since a table crossing a quote/div boundary is
 * unsafe even when the numbers of table and cell tags happen to match.
 */
function phpbb_bbcode_validate_tables($text, $uid)
{
	if (stripos($text, '[table') === false && stripos($text, '[cell') === false && stripos($text, '[/table') === false && stripos($text, '[/cell') === false)
	{
		return $text;
	}
	$uid_pattern = preg_quote((string) $uid, '#');
	$parts = preg_split('#(\[(?:quote|acronym):' . $uid_pattern . '=".*?"\]|\[[^\[\]]*?:' . $uid_pattern . '\])#is', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
	if (!is_array($parts) || preg_last_error() !== PREG_NO_ERROR)
	{
		return str_replace(array('[', ']'), array('&#91;', '&#93;'), $text);
	}
	$containers = array('quote', 'acronym', 'table', 'cell', 'list', 'b', 'i', 'u', 's', 'color', 'size', 'font', 'align', 'center', 'poet', 'fade', 'glow', 'shadow', 'highlight', 'flipv', 'fliph', 'scrollleft', 'scrollright', 'scrollup', 'scrolldown', 'marq', 'img', 'left', 'right', 'ram', 'stream', 'flash', 'video');
	$stack = array();
	$groups = array();
	$literal = array();
	$all_table_tokens = array();
	$too_deep = false;
	foreach ($parts as $index => $part)
	{
		$top = count($stack) ? $stack[count($stack) - 1] : null;
		if (($index % 2) === 0)
		{
			if ($top !== null && $top['name'] === 'table')
			{
				if (trim($part) !== '') { $groups[$top['group']]['invalid'] = true; }
				else { $groups[$top['group']]['gaps'][] = $index; }
			}
			continue;
		}
		if (!preg_match('#^\[(/?)([a-z]+)(.*)\]$#is', $part, $tag)) { continue; }
		$name = strtolower($tag[2]);
		$closing = ($tag[1] === '/');
		$is_table_tag = ($name === 'table' || $name === 'cell');
		if ($is_table_tag) { $all_table_tokens[] = $index; }
		if ($too_deep) { continue; }
		if ($top !== null && $top['name'] === 'table' && !(($name === 'cell' && !$closing) || ($name === 'table' && $closing)))
		{
			$groups[$top['group']]['invalid'] = true;
		}
		if (!in_array($name, $containers, true)) { continue; }
		if ($is_table_tag)
		{
			$pattern = $closing ? '#^\[/(table|cell):' . $uid_pattern . '\]$#i' : '#^\[(table|cell)=(.*):' . $uid_pattern . '\]$#is';
			if (!preg_match($pattern, $part, $table_tag))
			{
				$literal[$index] = true;
				continue;
			}
			$parts[$index] = '[' . ($closing ? '/' : '') . $name . ($closing ? '' : '=' . $table_tag[2]) . ':' . $uid . ']';
		}
		if (!$closing)
		{
			$group = null;
			if ($name === 'table')
			{
				$group = count($groups);
				$groups[$group] = array('tokens' => array($index), 'gaps' => array(), 'cells' => 0, 'closed' => false, 'invalid' => false);
			}
			else if ($name === 'cell')
			{
				if ($top !== null && $top['name'] === 'table')
				{
					$group = $top['group'];
					$groups[$group]['tokens'][] = $index;
					$groups[$group]['cells']++;
				}
				else { $literal[$index] = true; }
			}
			$stack[] = array('name' => $name, 'group' => $group);
			if (count($stack) > 256) { $too_deep = true; }
			continue;
		}
		$match = -1;
		for ($position = count($stack) - 1; $position >= 0; $position--)
		{
			if ($stack[$position]['name'] === $name) { $match = $position; break; }
		}
		if ($match === -1 || $match !== count($stack) - 1)
		{
			// Any crossing inside an open table invalidates that table, not
			// just the closing token that happens to expose the crossing.
			foreach ($stack as $frame)
			{
				if ($frame['group'] !== null) { $groups[$frame['group']]['invalid'] = true; }
			}
		}
		if ($match === -1)
		{
			if ($is_table_tag) { $literal[$index] = true; }
			continue;
		}
		$frame = $stack[$match];
		if ($is_table_tag)
		{
			if ($frame['group'] === null) { $literal[$index] = true; }
			else
			{
				$groups[$frame['group']]['tokens'][] = $index;
				if ($name === 'table') { $groups[$frame['group']]['closed'] = true; }
			}
		}
		$stack = array_slice($stack, 0, $match);
	}
	foreach ($groups as $group)
	{
		if ($group['invalid'] || !$group['closed'] || !$group['cells'])
		{
			foreach ($group['tokens'] as $index) { $literal[$index] = true; }
		}
		else
		{
			// The caller later turns newlines into <br>; between cells those
			// would become invalid row children, so discard formatting gaps.
			foreach ($group['gaps'] as $index) { $parts[$index] = ''; }
		}
	}
	if ($too_deep) { $literal = array_fill_keys($all_table_tokens, true); }
	foreach ($literal as $index => $unused)
	{
		$parts[$index] = str_replace(array('[', ']'), array('&#91;', '&#93;'), htmlspecialchars(phpbb_bbcode_code_source($parts[$index], $uid), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
	}
	return implode('', $parts);
}

/**
 * Keep independently substituted container tags inside their own post. The
 * first pass normally pairs them, but legacy/crossed markup is not necessarily
 * balanced. Invalidate only offending pairs, preserving independent neighbors.
 * Table/cell grammar has already been checked by validate_tables().
 */
function phpbb_bbcode_validate_containers($text, $uid)
{
	$uid_pattern = preg_quote((string) $uid, '#');
	$parts = preg_split('#(\[(?:quote|acronym):' . $uid_pattern . '=".*?"\]|\[[^\[\]]*?:' . $uid_pattern . '\])#is', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
	if (!is_array($parts) || preg_last_error() !== PREG_NO_ERROR)
	{
		throw new PhpbbBbcodeParseException('BBCode container tokenization failed');
	}
	$simple = array('b', 'i', 'u', 's', 'center', 'fade', 'flipv', 'fliph', 'scrollleft', 'scrollright', 'scrollup', 'scrolldown');
	$valued = array(
		'color' => '(?:\#[0-9A-F]{6}|[a-z]+)', 'size' => '[1-2]?[0-9]',
		'glow' => '(?:\#[0-9A-F]{6}|[a-z]+)', 'shadow' => '(?:\#[0-9A-F]{6}|[a-z]+)',
		'highlight' => '(?:\#[0-9A-F]{6}|[a-z]+)', 'font' => '[^\[\]]*',
		'align' => '(?:left|right|center|justify)', 'marq' => '(?:left|right|up|down)',
		'table' => '[^\[\]]*', 'cell' => '[^\[\]]*'
	);
	$frames = array(); $stack = array(); $literal = array();
	$names = implode('|', array_merge($simple, array_keys($valued), array('poet', 'quote', 'acronym', 'list')));
	foreach ($parts as $index => $part)
	{
		if (($index % 2) === 0)
		{
			// Incomplete prefixes must not become active later when another
			// substitution removes their nested brackets or generates HTML.
			$parts[$index] = phpbb_bbcode_replace('#\[(?=/?(?:' . $names . ')(?:[=:\]\s]))#i', '&#91;', $part);
			continue;
		}
		if (phpbb_bbcode_match('#^\[\*:' . $uid_pattern . '\]$#i', $part))
		{
			$list = null;
			for ($position = count($stack) - 1; $position >= 0; $position--)
			{
				if ($frames[$stack[$position]]['name'] === 'list') { $list = $stack[$position]; break; }
			}
			if ($list === null) { $literal[$index] = true; }
			else
			{
				$frames[$list]['tokens'][] = $index;
				if ($position !== count($stack) - 1) { $frames[$list]['invalid'] = true; }
				$parts[$index] = '[*:' . $uid . ']';
			}
			continue;
		}
		if (!phpbb_bbcode_match('#^\[(/?)([a-z]+)(.*)\]$#is', $part, $tag)) { continue; }
		$name = strtolower($tag[2]); $closing = ($tag[1] === '/'); $kind = '';
		if (in_array($name, $simple, true)) { $suffix = ''; }
		else if (isset($valued[$name])) { $suffix = '=' . $valued[$name]; }
		else if ($name === 'poet') { $suffix = '(?:=[^\[\]]*)?'; }
		else if ($name === 'quote' || $name === 'acronym') { $suffix = ''; }
		else if ($name === 'list') { $suffix = '(?:=[a1])?'; }
		else { continue; }
		$pattern = '#^\[' . ($closing ? '/' : '') . $name . ($closing ? '' : $suffix) . ':' . $uid_pattern . '\]$#is';
		if (!$closing && ($name === 'quote' || $name === 'acronym'))
		{
			$pattern = '#^\[' . $name . ':' . $uid_pattern . ($name === 'quote' ? '(?:=".*?")?' : '=".*?"') . '\]$#is';
		}
		else if ($closing && $name === 'list') { $pattern = '#^\[/list:([uo]):' . $uid_pattern . '\]$#i'; }
		if (!phpbb_bbcode_match($pattern, $part, $matched)) { $literal[$index] = true; continue; }
		if ($name === 'list') { $kind = $closing ? strtolower($matched[1]) : (strpos($part, '=') === false ? 'u' : 'o'); }
		// Canonicalize tag names/UIDs together: regex openers are case-insensitive
		// while the historic closing substitutions use exact string matching.
		if ($closing) { $parts[$index] = '[/' . $name . ($name === 'list' ? ':' . $kind : '') . ':' . $uid . ']'; }
		else
		{
			$parts[$index] = phpbb_bbcode_replace('#^\[[a-z]+#i', '[' . $name, $part);
			$uid_location = ($name === 'quote' || $name === 'acronym') ? '#^(\[' . $name . '):' . $uid_pattern . '#i' : '#:' . $uid_pattern . '\]$#i';
			$parts[$index] = phpbb_bbcode_replace($uid_location, ($name === 'quote' || $name === 'acronym') ? '${1}:' . $uid : ':' . $uid . ']', $parts[$index]);
		}
		if (!$closing)
		{
			$id = count($frames);
			$frames[$id] = array('name' => $name, 'kind' => $kind, 'tokens' => array($index), 'closed' => false, 'invalid' => false);
			$stack[] = $id;
			if (count($stack) > 256) { throw new PhpbbBbcodeParseException('BBCode nesting limit exceeded'); }
			continue;
		}
		$match = -1;
		for ($position = count($stack) - 1; $position >= 0; $position--)
		{
			if ($frames[$stack[$position]]['name'] === $name) { $match = $position; break; }
		}
		if ($match === -1) { $literal[$index] = true; continue; }
		$id = $stack[$match];
		$frames[$id]['tokens'][] = $index;
		$frames[$id]['closed'] = true;
		if ($frames[$id]['kind'] !== $kind) { $frames[$id]['invalid'] = true; }
		if ($match !== count($stack) - 1)
		{
			for ($position = $match; $position < count($stack); $position++) { $frames[$stack[$position]]['invalid'] = true; }
		}
		array_splice($stack, $match, 1);
	}
	foreach ($frames as $frame)
	{
		if (!$frame['closed'] || $frame['invalid'])
		{
			foreach ($frame['tokens'] as $index) { $literal[$index] = true; }
		}
	}
	foreach ($literal as $index => $unused)
	{
		$parts[$index] = str_replace(array('[', ']'), array('&#91;', '&#93;'), htmlspecialchars(phpbb_bbcode_code_source($parts[$index], $uid), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
	}
	return implode('', $parts);
}

function phpbb_bbcode_safe_font($value)
{
	$value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
	$value = trim($value);
	if ($value === '' || strlen($value) > 80 || !preg_match('/^[\pL\pN _,-]+$/u', $value))
	{
		return 'sans-serif';
	}
	return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function phpbb_bbcode_safe_style($value)
{
	$value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
	$value = str_replace(array("\r", "\n", "\0"), '', $value);
	$allowed = array();
	foreach (explode(';', $value) as $declaration)
	{
		$parts = explode(':', $declaration, 2);
		if (count($parts) !== 2)
		{
			continue;
		}
		$property = strtolower(trim($parts[0]));
		$property_value = trim($parts[1]);
		$is_safe = false;

		if (in_array($property, array('width', 'height', 'min-width', 'max-width'), true))
		{
			$is_safe = (bool) preg_match('/^(?:auto|[0-9]{1,4}(?:\.[0-9]{1,2})?(?:px|%|em|rem)?)$/i', $property_value);
		}
		else if (in_array($property, array('text-align', 'vertical-align'), true))
		{
			$is_safe = (bool) preg_match('/^(?:left|right|center|justify|top|middle|bottom)$/i', $property_value);
		}
		else if (in_array($property, array('color', 'background-color'), true))
		{
			$is_safe = (bool) preg_match('/^(?:#[0-9a-f]{3}(?:[0-9a-f]{3})?|[a-z]{1,20})$/i', $property_value);
		}
		else if (in_array($property, array('padding', 'margin', 'border-spacing'), true))
		{
			$is_safe = (bool) preg_match('/^[0-9]{1,3}(?:px|em|rem|%)(?:\s+[0-9]{1,3}(?:px|em|rem|%)){0,3}$/i', $property_value);
		}
		else if ($property === 'border-collapse')
		{
			$is_safe = in_array(strtolower($property_value), array('collapse', 'separate'), true);
		}
		else if ($property === 'border')
		{
			$is_safe = (bool) preg_match('/^[0-9]{1,2}px\s+(?:none|solid|dashed|dotted|double)\s+(?:#[0-9a-f]{3}(?:[0-9a-f]{3})?|[a-z]{1,20})$/i', $property_value);
		}

		if ($is_safe)
		{
			$allowed[] = $property . ': ' . strtolower($property_value);
		}
	}

	return htmlspecialchars(implode('; ', $allowed), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


/**
 * Does second-pass bbencoding. This should be used before displaying the message in
 * a thread. Assumes the message is already first-pass encoded, and we are given the
 * correct UID as used in first-pass encoding.
 */
function bbencode_second_pass($text, $uid)
{
	$text = (string) $text;
	try
	{
		return phpbb_bbcode_render_second_pass($text, $uid);
	}
	catch (PhpbbBbcodeParseException $error)
	{
		// A safe plain-text fallback preserves the entire source. In
		// particular, do not return partially generated table/div markup.
		return htmlspecialchars(phpbb_bbcode_code_source($text, $uid), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}

function phpbb_bbcode_render_second_pass($text, $uid)
{
	global $lang, $bbcode_tpl;
	$text = phpbb_bbcode_replace('#(script|about|applet|activex|chrome):#is', "\\1&#058;", $text);

	// pad it with a space so we can distinguish between FALSE and matching the 1st char (index 0).
	// This is important; bbencode_quote(), bbencode_list(), and bbencode_code() all depend on it.
	$text = " " . $text;

	// First: If there isn't a "[" and a "]" in the message, don't bother.
	if (! (strpos($text, "[") && strpos($text, "]")) )
	{
		// Remove padding, return.
		$text = substr($text, 1);
		return $text;
	}

	// Only load the templates ONCE..
	if (!defined("BBCODE_TPL_READY"))
	{
		// load templates from file into array.
		$bbcode_tpl = load_bbcode_template();

		// prepare array for use in regexps.
		$bbcode_tpl = prepare_bbcode_template($bbcode_tpl);
	}

	// Handle both literal block types together so a PHP example containing
	// [code] syntax cannot turn into a nested, active HTML table (or vice versa).
	$text = phpbb_bbcode_render_code_blocks($text, $uid, $bbcode_tpl);

	// Preserve old posts with incomplete quote markup without allowing their
	// table-based quote layout to consume the rest of the topic page.
	$text = phpbb_bbcode_balance_quotes($text, $uid);
	$text = phpbb_bbcode_validate_tables($text, $uid);
	$text = phpbb_bbcode_validate_containers($text, $uid);
	
	// [QUOTE] and [/QUOTE] for posting replies with quote, or just for quoting stuff.
	// Consume each complete token once: quote-like text inside a username must
	// never be interpreted as layout markup by a separate replacement pass.
	$text = phpbb_bbcode_replace_callback('#\[(?:quote:' . preg_quote($uid, '#') . '(?:="(.*?)")?|(/)quote:' . preg_quote($uid, '#') . ')\]#s', function ($matches) use ($bbcode_tpl)
	{
		if (!empty($matches[2]))
		{
			return $bbcode_tpl['quote_close'];
		}
		if (!isset($matches[1]))
		{
			return $bbcode_tpl['quote_open'];
		}
		$username = str_replace(array('[', ']'), array('&#91;', '&#93;'), phpbb_bbcode_safe_text($matches[1], 255));
		return str_replace('\\1', $username, $bbcode_tpl['quote_username_open']);
	}, $text);
	/* BEGIN CMX ACRONYM MOD */

	// acronym
	$text = phpbb_bbcode_replace_callback("/\[acronym:$uid=\"(.*?)\"\]/si", function ($matches) use ($bbcode_tpl)
	{
		$description = str_replace(array('[', ']'), array('&#91;', '&#93;'), phpbb_bbcode_safe_attribute($matches[1], 255));
		return str_replace('\\1', $description, $bbcode_tpl['acronym_open']);
	}, $text);
	$text = str_replace("[/acronym:$uid]", $bbcode_tpl['acronym_close'], $text);
	/* END CMX ACRONYM MOD */ 
	
	// [list] and [list=x] for (un)ordered lists.
	// unordered lists
	$text = str_replace("[list:$uid]", $bbcode_tpl['ulist_open'], $text);
	// li tags
	$text = str_replace("[*:$uid]", $bbcode_tpl['listitem'], $text);
	// ending tags
	$text = str_replace("[/list:u:$uid]", $bbcode_tpl['ulist_close'], $text);
	$text = str_replace("[/list:o:$uid]", $bbcode_tpl['olist_close'], $text);
	// Ordered lists
	$text = phpbb_bbcode_replace("/\[list=([a1]):$uid\]/si", $bbcode_tpl['olist_open'], $text);

	// colours
	$text = phpbb_bbcode_replace("/\[color=(\#[0-9A-F]{6}|[a-z]+):$uid\]/si", $bbcode_tpl['color_open'], $text);
	$text = str_replace("[/color:$uid]", $bbcode_tpl['color_close'], $text);

	// size
	$text = phpbb_bbcode_replace("/\[size=([1-2]?[0-9]):$uid\]/si", $bbcode_tpl['size_open'], $text);
	$text = str_replace("[/size:$uid]", $bbcode_tpl['size_close'], $text);
	
	// [b] and [/b] for bolding text.
	$text = str_replace("[b:$uid]", $bbcode_tpl['b_open'], $text);
	$text = str_replace("[/b:$uid]", $bbcode_tpl['b_close'], $text);
	
	// [scroll_**] and [/scroll_**] for scrolling text.
	$text = str_replace("[scrollleft:$uid]", $bbcode_tpl['scrollleft_open'], $text);
	$text = str_replace("[/scrollleft:$uid]", $bbcode_tpl['scrollleft_close'], $text);
	$text = str_replace("[scrollright:$uid]", $bbcode_tpl['scrollright_open'], $text);
	$text = str_replace("[/scrollright:$uid]", $bbcode_tpl['scrollright_close'], $text);
	$text = str_replace("[scrollup:$uid]", $bbcode_tpl['scrollup_open'], $text);
	$text = str_replace("[/scrollup:$uid]", $bbcode_tpl['scrollup_close'], $text);
	$text = str_replace("[scrolldown:$uid]", $bbcode_tpl['scrolldown_open'], $text);
	$text = str_replace("[/scrolldown:$uid]", $bbcode_tpl['scrolldown_close'], $text);
	
	// [u] and [/u] for underlining text.
	$text = str_replace("[u:$uid]", $bbcode_tpl['u_open'], $text);
	$text = str_replace("[/u:$uid]", $bbcode_tpl['u_close'], $text);

	// [i] and [/i] for italicizing text.
	$text = str_replace("[i:$uid]", $bbcode_tpl['i_open'], $text);
	$text = str_replace("[/i:$uid]", $bbcode_tpl['i_close'], $text);
	
	// [flipv] and [/flipv] for vertically flipped text.
	$text = str_replace("[flipv:$uid]", $bbcode_tpl['flipv_open'], $text);
	$text = str_replace("[/flipv:$uid]", $bbcode_tpl['flipv_close'], $text);

	// [fliph] and [/fliph] for horizontally flipped text.
	$text = str_replace("[fliph:$uid]", $bbcode_tpl['fliph_open'], $text);
	$text = str_replace("[/fliph:$uid]", $bbcode_tpl['fliph_close'], $text);
	
	//[glow=red]and[/glow]for glowing text.
	$text = phpbb_bbcode_replace("/\[glow=(\#[0-9A-F]{6}|[a-z]+):$uid\]/si", $bbcode_tpl['glow_open'], $text);
	$text = str_replace("[/glow:$uid]", $bbcode_tpl['glow_close'], $text);

	//[shadow=red]and[/shadow]for glowing text.
	$text = phpbb_bbcode_replace("/\[shadow=(\#[0-9A-F]{6}|[a-z]+):$uid\]/si", $bbcode_tpl['shadow_open'], $text);
	$text = str_replace("[/shadow:$uid]", $bbcode_tpl['shadow_close'], $text);
	
	// Highlight
	$text = phpbb_bbcode_replace("/\[highlight=(\#[0-9A-F]{6}|[a-z]+):$uid\]/si", $bbcode_tpl['highlight_open'], $text);
	$text = str_replace("[/highlight:$uid]", $bbcode_tpl['highlight_close'], $text);
	
	// [s] and [/s]
	$text = str_replace("[s:$uid]", $bbcode_tpl['s_open'], $text);
	$text = str_replace("[/s:$uid]", $bbcode_tpl['s_close'], $text);
	
	// Patterns and replacements for URL and email tags..
	$patterns = array();
	$replacements = array();

	// [img]image_url_here[/img] code..
	// This one gets first-passed..
	$patterns[] = "#\[img:$uid\]([^?](?:[^\[]+|\[(?!url))*?)\[/img:$uid\]#i";
	$replacements[] = $bbcode_tpl['img'];

	// matches a [url]xxxx://www.phpbb.com[/url] code..
	$patterns[] = "#\[url\]((?:https?|ftps?)://([\w\#$%&~/.\-;:=,?@\]+]+|\[(?!url=))*?)\[/url\]#is";
	$replacements[] = $bbcode_tpl['url1'];

	// [url]www.phpbb.com[/url] code.. (no xxxx:// prefix).
	$patterns[] = "#\[url\]((www|ftp)\.([\w\#$%&~/.\-;:=,?@\]+]+|\[(?!url=))*?)\[/url\]#is";
	$replacements[] = $bbcode_tpl['url2'];

	// [url=xxxx://www.phpbb.com]phpBB[/url] code..
	$patterns[] = "#\[url=((?:https?|ftps?)://[\w\#$%&~/.\-;:=,?@\[\]+]*?)\]([^?\n\r\t].*?)\[/url\]#is";
	$replacements[] = $bbcode_tpl['url3'];

	// [url=www.phpbb.com]phpBB[/url] code.. (no xxxx:// prefix).
	$patterns[] = "#\[url=((www|ftp)\.[\w\#$%&~/.\-;:=,?@\[\]+]*?)\]([^?\n\r\t].*?)\[/url\]#is";
	$replacements[] = $bbcode_tpl['url4'];

	// [email]user@domain.tld[/email] code..
	$patterns[] = "#\[email\]([a-z0-9&\-_.]+?@[\w\-]+\.([\w\-\.]+\.)?[\w]+)\[/email\]#si";
	$replacements[] = $bbcode_tpl['email'];
	
	// bbcode_box Mod
	// [fade] and [/fade] for faded text.
	$text = str_replace("[fade:$uid]", $bbcode_tpl['fade_open'], $text);
	$text = str_replace("[/fade:$uid]", $bbcode_tpl['fade_close'], $text);
	// real
	$patterns[] = "#\[ram:$uid\](https?://[^\\s\"'<>\[\]]+)\[/ram:$uid\]#si";
	$replacements[] = $bbcode_tpl['ram'];
	// sound
	$patterns[] = "#\[stream:$uid\](https?://[^\\s\"'<>\[\]]+)\[/stream:$uid\]#si";
	$replacements[] = $bbcode_tpl['stream'];
	// [flash width= height= loop= ] and [/flash] code..
	$patterns[] = "#\[flash width=([0-6]?[0-9]?[0-9]) height=([0-4]?[0-9]?[0-9]):$uid\](https?://[^\\s\"'<>\[\]]+)\[/flash:$uid\]#si";
	$replacements[] = $bbcode_tpl['flash'];
	// [flash width= height= loop= ] and [/flash] code..
	$patterns[] = "#\[video width=([0-6]?[0-9]?[0-9]) height=([0-4]?[0-9]?[0-9]):$uid\](https?://[^\\s\"'<>\[\]]+)\[/video:$uid\]#si";
	$replacements[] = $bbcode_tpl['video'];
	$text = phpbb_bbcode_replace($patterns, $replacements, $text);
	// align
	$text = phpbb_bbcode_replace("/\[align=(left|right|center|justify):$uid\]/si", $bbcode_tpl['align_open'], $text);
	$text = str_replace("[/align:$uid]", $bbcode_tpl['align_close'], $text);
	// marquee
	$text = phpbb_bbcode_replace("/\[marq=(left|right|up|down):$uid\]/si", $bbcode_tpl['marq_open'], $text);
	$text = str_replace("[/marq:$uid]", $bbcode_tpl['marq_close'], $text);
	// table
	$text = phpbb_bbcode_replace_callback("/\[table=([^\[\]]*):$uid\]/si", function ($matches) use ($bbcode_tpl)
	{
		return str_replace('\\1', phpbb_bbcode_safe_style($matches[1]), $bbcode_tpl['table_open']);
	}, $text);
	$text = str_replace("[/table:$uid]", $bbcode_tpl['table_close'], $text);
	// cell
	$text = phpbb_bbcode_replace_callback("/\[cell=([^\[\]]*):$uid\]/si", function ($matches) use ($bbcode_tpl)
	{
		return str_replace('\\1', phpbb_bbcode_safe_style($matches[1]), $bbcode_tpl['cell_open']);
	}, $text);
	$text = str_replace("[/cell:$uid]", $bbcode_tpl['cell_close'], $text);
	// center
	$center_open = str_replace('\\1', 'center', $bbcode_tpl['align_open']);
	$text = str_replace("[center:$uid]", $center_open, $text);
	$text = str_replace("[/center:$uid]", $bbcode_tpl['align_close'], $text);
	// font
	$text = phpbb_bbcode_replace_callback("/\[font=([^\[\]]*):$uid\]/si", function ($matches) use ($bbcode_tpl)
	{
		return str_replace('\\1', phpbb_bbcode_safe_font($matches[1]), $bbcode_tpl['font_open']);
	}, $text);
	$text = str_replace("[/font:$uid]", $bbcode_tpl['font_close'], $text);
	// poet
	$text = phpbb_bbcode_replace("/\[poet(?:=[^\[\]]*)?:$uid\]/i", $bbcode_tpl['poet_open'], $text);
	$text = str_replace("[/poet:$uid]", $bbcode_tpl['poet_close'], $text);
	//[hr]
	$text = str_replace("[hr:$uid]", $bbcode_tpl['hr'], $text);
	// [google]string for search[/google] code.
	$text = phpbb_bbcode_replace_callback("#\[google\](.*?)\[/google\]#is",
		function($matches) use ($bbcode_tpl)
		{
			$string = str_replace('\\"', '"', $matches[1]);
			$query = html_entity_decode($string, ENT_QUOTES, 'UTF-8');
			return str_replace(
				array('{STRING}', '{QUERY}'),
				array(phpbb_bbcode_safe_text($string), rawurlencode($query)),
				$bbcode_tpl['google']
			);
		},
		$text);
	// [left]image_url_here[/left] code..
	$text = phpbb_bbcode_replace("#\[left:$uid\]([^?](?:[^\[]+|\[(?!url))*?)\[/left:$uid\]#si", $bbcode_tpl['left'], $text);
	// [right]image_url_here[/right] code..
	$text = phpbb_bbcode_replace("#\[right:$uid\]([^?](?:[^\[]+|\[(?!url))*?)\[/right:$uid\]#si", $bbcode_tpl['right'], $text);
	// bbcode_box Mod	
	
	//Begin Smilie Creator Mod Copyright esperitox 2003 [schild=] and [/schild] code..
	$text = phpbb_bbcode_replace_callback("#\[schild=([a-z0-9]+)([a-z0-9\-\.,\?!% \*_\#:;~\\&$@\/=\+\\\\)]*)\](.*?)\[/schild\]#si",
		function($matches) use ($bbcode_tpl)
		{
			return str_replace('{URL}', phpbb_schild($matches[1], $matches[2], $matches[3]), $bbcode_tpl['schild']);
		},
		$text);

	// Remove our padding from the string..
	$text = substr($text, 1);

	return $text;

} // bbencode_second_pass()

function make_bbcode_uid()
{
	// Unique ID for this message..
	return phpbb_random_string(BBCODE_UID_LEN, '0123456789abcdef');
}

/** Encode complete formatting pairs without consuming the first nested closer. */
function phpbb_bbcode_encode_formatting($text, $uid)
{
	$rules = array_fill_keys(array('b', 'i', 'u', 's', 'center', 'fade', 'flipv', 'fliph', 'scrollleft', 'scrollright', 'scrollup', 'scrolldown'), '');
	$rules += array(
		'color' => '=(?:\#[0-9a-f]{6}|[a-z]+)', 'size' => '=[1-2]?[0-9]',
		'font' => '=[^\[\]]*', 'align' => '=(?:left|right|center|justify)',
		'marq' => '=(?:left|right|up|down)', 'poet' => '(?:=[^\[\]]*)?',
		'glow' => '=(?:\#[0-9a-f]{6}|[a-z]+)', 'shadow' => '=(?:\#[0-9a-f]{6}|[a-z]+)',
		'highlight' => '=(?:\#[0-9a-f]{6}|[a-z]+)'
	);
	$uid_pattern = preg_quote((string) $uid, '#');
	// Quote names/acronym descriptions are atomic attributes, not body text.
	$parts = preg_split('#(\[(?:quote|acronym):' . $uid_pattern . '=(?:\\\\?"|\\\\?&quot;).*?(?:\\\\?"|\\\\?&quot;)\]|\[[^\[\]]*\])#is', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
	if (!is_array($parts) || preg_last_error() !== PREG_NO_ERROR)
	{
		throw new PhpbbBbcodeParseException('Formatting tokenization failed');
	}
	$stacks = array();
	foreach ($parts as $index => $part)
	{
		if (($index % 2) === 0 || phpbb_bbcode_match('#:' . $uid_pattern . '\]$#i', $part)) { continue; }
		if (!phpbb_bbcode_match('#^\[(/?)([a-z]+)([^\[\]]*)\]$#is', $part, $tag)) { continue; }
		$name = strtolower($tag[2]);
		if (!isset($rules[$name])) { continue; }
		if ($tag[1] === '')
		{
			if (!phpbb_bbcode_match('#^' . $rules[$name] . '$#is', $tag[3])) { continue; }
			$stacks[$name][] = array('index' => $index, 'opening' => '[' . $name . $tag[3] . ':' . $uid . ']');
			if (count($stacks[$name]) > 256) { throw new PhpbbBbcodeParseException('Formatting nesting limit exceeded'); }
		}
		else if ($tag[3] === '' && !empty($stacks[$name]))
		{
			$opening = array_pop($stacks[$name]);
			$parts[$opening['index']] = $opening['opening'];
			$parts[$index] = '[/' . $name . ':' . $uid . ']';
		}
	}
	return implode('', $parts);
}

function bbencode_first_pass($text, $uid)
{
	$text = (string) $text;
	try
	{
		return phpbb_bbcode_encode_first_pass($text, $uid);
	}
	catch (PhpbbBbcodeParseException $error)
	{
		// This input has already passed prepare_message's HTML escaping.
		// Store the complete original, never an incomplete compiled prefix.
		return $text;
	}
}

function phpbb_bbcode_encode_first_pass($text, $uid)
{
	// pad it with a space so we can distinguish between FALSE and matching the 1st char (index 0).
	// This is important; bbencode_quote(), bbencode_list(), and bbencode_code() all depend on it.
	$text = " " . $text;

	// [CODE] and [/CODE] for posting code (HTML, PHP, C etc etc) in your posts.
	$text = bbencode_first_pass_pda($text, $uid, '[code]', '[/code]', '', true, '');
	
	/* BEGIN CMX ACRONYM MOD */
	// [acronym] and [/acronym]
	$text = bbencode_first_pass_pda($text, $uid, '/\[acronym=(\\\".*?\\\")\]/is', '[/acronym]', '', false, '', "[acronym:$uid=\\1]");
	/* END CMX ACRONYM MOD */ 
	
	// PHP MOD
	// [PHP] and [/PHP] for posting PHP code in your posts.
	$text = bbencode_first_pass_pda($text, $uid, '[php]', '[/php]', '', true, '');
	
	// [QUOTE] and [/QUOTE] for posting replies with quote, or just for quoting stuff.
	$text = bbencode_first_pass_pda($text, $uid, '[quote]', '[/quote]', '', false, '');
	$text = bbencode_first_pass_pda($text, $uid, '/\[quote=\\\\&quot;(.*?)\\\\&quot;\]/is', '[/quote]', '', false, '', "[quote:$uid=\\\"\\1\\\"]");

	// [list] and [list=x] for (un)ordered lists.
	$open_tag = array();
	$open_tag[0] = "[list]";

	// unordered..
	$text = bbencode_first_pass_pda($text, $uid, $open_tag, "[/list]", "[/list:u]", false, 'replace_listitems');

	$open_tag[0] = "[list=1]";
	$open_tag[1] = "[list=a]";

	// ordered.
	$text = bbencode_first_pass_pda($text, $uid, $open_tag, "[/list]", "[/list:o]",  false, 'replace_listitems');

	// Pair nested formatting in one token pass, including repeated tag names.
	$text = phpbb_bbcode_encode_formatting($text, $uid);
	
	// [img]image_url_here[/img] code..
	$text = phpbb_bbcode_replace_callback("#\[img\]((http|ftp|https|ftps)://)([^\?&=\#\"\n\r\t<]*?(\.(jpg|jpeg|gif|png)))\[/img\]#si",
		function($matches) use ($uid)
		{
			return "[img:$uid]" . $matches[1] . str_replace(" ", "%20", $matches[3]) . "[/img:$uid]";
	        },
		$text);
	
	// bbcode_box Mod
	// [table] and [/table]
	$text = bbencode_first_pass_pda($text, $uid, '#\[table=(?![^\]]*:' . preg_quote($uid, '#') . '\])([^\]]*)\]#is', '[/table]', '', false, '', "[table=\\1:$uid]");
	// [cell] and [/cell]
	$text = bbencode_first_pass_pda($text, $uid, '#\[cell=(?![^\]]*:' . preg_quote($uid, '#') . '\])([^\]]*)\]#is', '[/cell]', '', false, '', "[cell=\\1:$uid]");
	// [real]and[/real]
	$text = phpbb_bbcode_replace("#\[ram\](https?://[^\\s\"'<>\[\]]+)\[/ram\]#si", "[ram:$uid]\\1[/ram:$uid]", $text);
	// [stream]and[/stream]
	$text = phpbb_bbcode_replace("#\[stream\](https?://[^\\s\"'<>\[\]]+)\[/stream\]#si", "[stream:$uid]\\1[/stream:$uid]", $text);
	//[flash width= heigth= loop=] and [/flash]
	$text = phpbb_bbcode_replace("#\[flash width=([0-6]?[0-9]?[0-9]) height=([0-4]?[0-9]?[0-9])\](https?://[^\\s\"'<>\[\]]+)\[\/flash\]#si", "[flash width=\\1 height=\\2:$uid]\\3[/flash:$uid]", $text);
	//[video width= heigth=] and [/video]
	$text = phpbb_bbcode_replace("#\[video width=([0-6]?[0-9]?[0-9]) height=([0-4]?[0-9]?[0-9])\](https?://[^\\s\"'<>\[\]]+)\[\/video\]#si", "[video width=\\1 height=\\2:$uid]\\3[/video:$uid]", $text);
	// [hr]
	$text = phpbb_bbcode_replace("#\[hr\]#si", "[hr:$uid]", $text);
	// [left]image_url_here[/left] code..
	$text = phpbb_bbcode_replace_callback("#\[left\]((http|ftp|https|ftps)://)([^ \?&=\#\"\n\r\t<]*?(\.(jpg|jpeg|gif|png)))\[/left\]#si",
		function($matches) use ($uid)
		{
			return "[left:$uid]" . $matches[1] . str_replace(' ', '%20', $matches[3]) . "[/left:$uid]";
		},
		$text);
	// [right]image_url_here[/right] code..
	$text = phpbb_bbcode_replace_callback("#\[right\]((http|ftp|https|ftps)://)([^ \?&=\#\"\n\r\t<]*?(\.(jpg|jpeg|gif|png)))\[/right\]#si",
		function($matches) use ($uid)
		{
			return "[right:$uid]" . $matches[1] . str_replace(' ', '%20', $matches[3]) . "[/right:$uid]";
		},
		$text);
	// bbcode_box Mod
 
	
	// Remove our padding from the string..
	return substr($text, 1);;

} // bbencode_first_pass()

/**
 * $text - The text to operate on.
 * $uid - The UID to add to matching tags.
 * $open_tag - The opening tag to match. Can be an array of opening tags.
 * $close_tag - The closing tag to match.
 * $close_tag_new - The closing tag to replace with.
 * $mark_lowest_level - boolean - should we specially mark the tags that occur
 * 					at the lowest level of nesting? (useful for [code], because
 *						we need to match these tags first and transform HTML tags
 *						in their contents..
 * $func - This variable should contain a string that is the name of a function.
 *				That function will be called when a match is found, and passed 2
 *				parameters: ($text, $uid). The function should return a string.
 *				This is used when some transformation needs to be applied to the
 *				text INSIDE a pair of matching tags. If this variable is FALSE or the
 *				empty string, it will not be executed.
 * If open_tag is an array, then the pda will try to match pairs consisting of
 * any element of open_tag followed by close_tag. This allows us to match things
 * like [list=A]...[/list] and [list=1]...[/list] in one pass of the PDA.
 *
 * NOTES:	- this function assumes the first character of $text is a space.
 *				- every opening tag and closing tag must be of the [...] format.
 */
function bbencode_first_pass_pda($text, $uid, $open_tag, $close_tag, $close_tag_new, $mark_lowest_level, $func, $open_regexp_replace = false)
{
	$open_tag_count = 0;

	if (!$close_tag_new || ($close_tag_new == ''))
	{
		$close_tag_new = $close_tag;
	}

	$close_tag_length = strlen($close_tag);
	$close_tag_new_length = strlen($close_tag_new);
	$uid_length = strlen($uid);

	$use_function_pointer = ($func && ($func != ''));

	$stack = array();

	if (is_array($open_tag))
	{
		if (0 == count($open_tag))
		{
			// No opening tags to match, so return.
			return $text;
		}
		$open_tag_count = count($open_tag);
	}
	else
	{
		// only one opening tag. make it into a 1-element array.
		$open_tag_temp = $open_tag;
		$open_tag = array();
		$open_tag[0] = $open_tag_temp;
		$open_tag_count = 1;
	}

	$open_is_regexp = false;

	if ($open_regexp_replace)
	{
		$open_is_regexp = true;
		if (!is_array($open_regexp_replace))
		{
			$open_regexp_temp = $open_regexp_replace;
			$open_regexp_replace = array();
			$open_regexp_replace[0] = $open_regexp_temp;
		}
	}

	if ($mark_lowest_level && $open_is_regexp)
	{
		message_die(GENERAL_ERROR, "Unsupported operation for bbcode_first_pass_pda().");
	}

	// Start at the 2nd char of the string, looking for opening tags.
	$curr_pos = 1;
	while ($curr_pos && ($curr_pos < strlen($text)))
	{
		$curr_pos = strpos($text, "[", $curr_pos);

		// If not found, $curr_pos will be 0, and the loop will end.
		if ($curr_pos)
		{
			// We found a [. It starts at $curr_pos.
			// check if it's a starting or ending tag.
			$found_start = false;
			$which_start_tag = "";
			$start_tag_index = -1;

			for ($i = 0; $i < $open_tag_count; $i++)
			{
				// Grab everything until the first "]"...
				$possible_start = substr($text, $curr_pos, strpos($text, ']', $curr_pos + 1) - $curr_pos + 1);

				//
				// We're going to try and catch usernames with "[' characters.
				//
				if( phpbb_bbcode_match('#\[quote=\\\&quot;#si', $possible_start, $match) && !phpbb_bbcode_match('#\[quote=\\\&quot;(.*?)\\\&quot;\]#si', $possible_start) )
				{
					// OK we are in a quote tag that probably contains a ] bracket.
					// Grab a bit more of the string to hopefully get all of it..
					if ($close_pos = strpos($text, '&quot;]', $curr_pos + 14))
					{
						if (strpos(substr($text, $curr_pos + 14, $close_pos - ($curr_pos + 14)), '[quote') === false)
						{
							$possible_start = substr($text, $curr_pos, $close_pos - $curr_pos + 7);
						}
					}
				}

				// Now compare, either using regexp or not.
				if ($open_is_regexp)
				{
					$match_result = array();
					if (phpbb_bbcode_match($open_tag[$i], $possible_start, $match_result))
					{
						$found_start = true;
						$which_start_tag = $match_result[0];
						$start_tag_index = $i;
						break;
					}
				}
				else
				{
					// straightforward string comparison.
					if (0 == strcasecmp($open_tag[$i], $possible_start))
					{
						$found_start = true;
						$which_start_tag = $open_tag[$i];
						$start_tag_index = $i;
						break;
					}
				}
			}

			if ($found_start)
			{
				// We have an opening tag.
				// Push its position, the text we matched, and its index in the open_tag array on to the stack, and then keep going to the right.
				$match = array("pos" => $curr_pos, "tag" => $which_start_tag, "index" => $start_tag_index);
				array_push($stack, $match);
				//
				// Rather than just increment $curr_pos
				// Set it to the ending of the tag we just found
				// Keeps error in nested tag from breaking out
				// of table structure..
				//
				$curr_pos += strlen($possible_start);
			}
			else
			{
				// check for a closing tag..
				$possible_end = substr($text, $curr_pos, $close_tag_length);
				if (0 == strcasecmp($close_tag, $possible_end))
				{
					// We have an ending tag.
					// Check if we've already found a matching starting tag.
					if (sizeof($stack) > 0)
					{
						// There exists a starting tag.
						$curr_nesting_depth = sizeof($stack);
						// We need to do 2 replacements now.
						$match = array_pop($stack);
						$start_index = $match['pos'];
						$start_tag = $match['tag'];
						$start_length = strlen($start_tag);
						$start_tag_index = $match['index'];

						if ($open_is_regexp)
						{
							$start_tag = phpbb_bbcode_replace($open_tag[$start_tag_index], $open_regexp_replace[$start_tag_index], $start_tag);
						}

						// everything before the opening tag.
						$before_start_tag = substr($text, 0, $start_index);

						// everything after the opening tag, but before the closing tag.
						$between_tags = substr($text, $start_index + $start_length, $curr_pos - $start_index - $start_length);

						// Run the given function on the text between the tags..
						if ($use_function_pointer)
						{
							$between_tags = $func($between_tags, $uid);
						}

						// everything after the closing tag.
						$after_end_tag = substr($text, $curr_pos + $close_tag_length);

						// Mark the lowest nesting level if needed.
						if ($mark_lowest_level && ($curr_nesting_depth == 1))
						{
							if ($open_tag[0] == '[code]' || $open_tag[0] == '[php]')
							{
								$code_entities_match = array('#<#', '#>#', '#"#', '#:#', '#\[#', '#\]#', '#\(#', '#\)#', '#\{#', '#\}#');
								$code_entities_replace = array('&lt;', '&gt;', '&quot;', '&#58;', '&#91;', '&#93;', '&#40;', '&#41;', '&#123;', '&#125;');
								$between_tags = phpbb_bbcode_replace($code_entities_match, $code_entities_replace, $between_tags);
							}
							$text = $before_start_tag . substr($start_tag, 0, $start_length - 1) . ":$curr_nesting_depth:$uid]";
							$text .= $between_tags . substr($close_tag_new, 0, $close_tag_new_length - 1) . ":$curr_nesting_depth:$uid]";
						}
						else
						{
							if ($open_tag[0] == '[code]')
							{
								$text = $before_start_tag . '&#91;code&#93;';
								$text .= $between_tags . '&#91;/code&#93;';
							}
							else if ($open_tag[0] == '[php]') // PHP MOD
							{
								$text = $before_start_tag . '/*php ';
								$text .= $between_tags . ' /php*/';
							}
							else
							{
								if ($open_is_regexp)
								{
									$text = $before_start_tag . $start_tag;
								}
								else
								{
									$text = $before_start_tag . substr($start_tag, 0, $start_length - 1) . ":$uid]";
								}
								$text .= $between_tags . substr($close_tag_new, 0, $close_tag_new_length - 1) . ":$uid]";
							}
						}

						$text .= $after_end_tag;

						// Now.. we've screwed up the indices by changing the length of the string.
						// So, if there's anything in the stack, we want to resume searching just after it.
						// otherwise, we go back to the start.
						if (sizeof($stack) > 0)
						{
							$match = array_pop($stack);
							$curr_pos = $match['pos'];
//							bbcode_array_push($stack, $match);
//							++$curr_pos;
						}
						else
						{
							$curr_pos = 1;
						}
					}
					else
					{
						// No matching start tag found. Increment pos, keep going.
						++$curr_pos;
					}
				}
				else
				{
					// No starting tag or ending tag.. Increment pos, keep looping.,
					++$curr_pos;
				}
			}
		}
	} // while

	return $text;

} // bbencode_first_pass_pda()

/**
 * Recover visible source from first-pass storage. Older PHP blocks also had
 * ordinary BBCode processed inside them, so remove only this post's tag UID.
 */
function phpbb_bbcode_code_source($source, $uid)
{
	$source = html_entity_decode((string) $source, ENT_QUOTES, 'UTF-8');
	$uid_pattern = preg_quote((string) $uid, '#');
	$cleaned = preg_replace_callback('#\\[[^\\]\\r\\n]*\\]#', function ($match) use ($uid_pattern)
	{
		return preg_replace('#:(?:[a-z0-9]:)?' . $uid_pattern . '(?=[=\\]])#i', '', $match[0]);
	}, $source);
	return ($cleaned === null || preg_last_error() !== PREG_NO_ERROR) ? $source : $cleaned;
}

function phpbb_bbcode_render_code_blocks($text, $uid, $templates)
{
	$original = (string) $text;
	$uid_pattern = preg_quote((string) $uid, '#');
	$text = preg_replace_callback('#\\[(code|php)(?::1)?:' . $uid_pattern . '\\](.*?)\\[/\\1(?::1)?:' . $uid_pattern . '\\]#is', function ($match) use ($uid, $templates)
	{
		$kind = strtolower($match[1]);
		$source = phpbb_bbcode_code_source($match[2], $uid);
		if ($kind === 'php')
		{
			$added_prefix = !preg_match('#\\A\\s*<\\?#', $source);
			$html = highlight_string(($added_prefix ? '<?php ' : '') . $source, true);
			if ($added_prefix)
			{
				// Remove exactly the synthetic opening token; never add a fake
				// closing PHP token or depend on PHP 4's obsolete font markup.
				$html = preg_replace('#&lt;\\?php(?:&nbsp;| )?#', '', $html, 1);
			}
			// New highlighters wrap source in <pre> and retain real newlines.
			// Older versions use <br /> for source lines and add formatting
			// newlines around their HTML tags. Remove only that extra markup.
			if (strpos($html, '<pre>') === 0)
			{
				$html = str_replace(array('<pre>', '</pre>'), '', $html);
			}
			else
			{
				$html = str_replace(array("\r", "\n"), '', $html);
			}
		}
		else
		{
			$html = '<code>' . htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
		}
		// Later BBCode passes must not interpret syntax shown in an example.
		$html = str_replace(array('[', ']'), array('&#91;', '&#93;'), $html);
		return $templates[$kind . '_open'] . $html . $templates[$kind . '_close'];
	}, $original);
	if ($text === null || preg_last_error() !== PREG_NO_ERROR)
	{
		// A regex resource limit must not turn a long legacy post into an
		// empty result or let its unparsed code become active BBCode later.
		return str_replace(array('[', ']'), array('&#91;', '&#93;'), htmlspecialchars(html_entity_decode($original, ENT_QUOTES, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
	}
	// Unmatched legacy source tags remain visible, not unbalanced HTML tables.
	return preg_replace('#\\[(/?)(code|php)(?::1)?:' . $uid_pattern . '\\]#i', '&#91;$1$2&#93;', $text);
}

/** Apply prose-only transformations without modifying rendered code examples. */
function phpbb_bbcode_transform_prose($text, $callback)
{
	$segments = preg_split('#(<code\\b[^>]*>.*?</code>)#is', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);
	if (!is_array($segments) || preg_last_error() !== PREG_NO_ERROR)
	{
		return (string) $text;
	}
	foreach ($segments as $index => $segment)
	{
		if (($index % 2) === 0)
		{
			$segments[$index] = $callback($segment);
		}
	}
	return implode('', $segments);
}
/**
 * Rewritten by Nathan Codding - Feb 6, 2001.
 * - Goes through the given string, and replaces xxxx://yyyy with an HTML <a> tag linking
 * 	to that URL
 * - Goes through the given string, and replaces www.xxxx.yyyy[zzzz] with an HTML <a> tag linking
 * 	to http://www.xxxx.yyyy[/zzzz]
 * - Goes through the given string, and replaces xxxx@yyyy with an HTML mailto: tag linking
 *		to that email address
 * - Only matches these 2 patterns either after a space, or at the beginning of a line
 *
 * Notes: the email one might get annoying - it's easy to make it more restrictive, though.. maybe
 * have it require something like xxxx@yyyy.zzzz or such. We'll see.
 */
function make_clickable($text)
{
	$original = (string) $text;
	try
	{
		// Attribute values may contain > signs or URL-looking text. Keep whole
		// tags/comments intact and never add anchors inside an existing link or
		// literal source container. This processes already escaped/rendered HTML,
		// not raw user HTML, and is not a substitute for input/output escaping.
		$parts = @preg_split('#(<!--.*?-->|<(?:[^<>"\']++|"[^"]*"|\'[^\']*\')*>)#s', $original, -1, PREG_SPLIT_DELIM_CAPTURE);
		if (!is_array($parts) || preg_last_error() !== PREG_NO_ERROR)
		{
			throw new PhpbbBbcodeParseException('Auto-link HTML splitting failed');
		}
		$protected = array();
		foreach ($parts as $index => $part)
		{
			if (($index % 2) === 0)
			{
				if (empty($protected))
				{
					$parts[$index] = phpbb_make_clickable_prose($part);
				}
			}
			else if (phpbb_bbcode_match('#^<(/?)(a|code|pre|script|style|textarea)(?=[\s/>])#i', $part, $tag))
			{
				$name = strtolower($tag[2]);
				// Raw-text HTML elements do not open nested elements: a string
				// containing "<a>" inside a script must not swallow later prose.
				if (!empty($protected) && in_array(end($protected), array('script', 'style', 'textarea'), true))
				{
					if ($tag[1] === '/' && end($protected) === $name) { array_pop($protected); }
					continue;
				}
				if ($tag[1] === '')
				{
					$protected[] = $name;
				}
				else if (!empty($protected) && end($protected) === $name)
				{
					array_pop($protected);
				}
			}
		}
		return implode('', $parts);
	}
	catch (PhpbbBbcodeParseException $error)
	{
		// Keep the complete original HTML, including safe code/BBCode markup,
		// instead of returning partial links or losing failed prose segments.
		return $original;
	}
}

function phpbb_make_clickable_prose($text)
{
	$text = phpbb_bbcode_replace('#(script|about|applet|activex|chrome):#is', "\\1&#058;", $text);
	
	// pad it with a space so we can match things at the start of the 1st line.
	$ret = ' ' . $text;

	// matches an "xxxx://yyyy" URL at the start of a line, or after a space.
	// xxxx can only be alpha characters.
	// yyyy is anything up to the first space, newline, comma, double quote or <
	$ret = phpbb_bbcode_replace("#(^|[\n ])((?:https?|ftps?)://[\w\#$%&~/.\-;:=,?@\[\]+]*)#is", "\\1<a href=\"\\2\" target=\"_blank\" rel=\"noopener noreferrer\">\\2</a>", $ret);

	// matches a "www|ftp.xxxx.yyyy[/zzzz]" kinda lazy URL thing
	// Must contain at least 2 dots. xxxx contains either alphanum, or "-"
	// zzzz is optional.. will contain everything up to the first space, newline, 
	// comma, double quote or <.
	$ret = phpbb_bbcode_replace("#(^|[\n ])((www|ftp)\.[\w\#$%&~/.\-;:=,?@\[\]+]*)#is", "\\1<a href=\"http://\\2\" target=\"_blank\" rel=\"noopener noreferrer\">\\2</a>", $ret);

	// matches an email@domain type address at the start of a line, or after a space.
	// Note: Only the followed chars are valid; alphanums, "-", "_" and or ".".
	$ret = phpbb_bbcode_replace("#(^|[\n ])([a-z0-9&\-_.]+?)@([\w\-]+\.([\w\-\.]+\.)*[\w]+)#i", "\\1<a href=\"mailto:\\2@\\3\">\\2@\\3</a>", $ret);

	// Remove our padding..
	$ret = substr($ret, 1);

	return($ret);
}


/**
 * This is used to change a [*] tag into a [*:$uid] tag as part
 * of the first-pass bbencoding of [list] tags. It fits the
 * standard required in order to be passed as a variable
 * function into bbencode_first_pass_pda().
 */
function replace_listitems($text, $uid)
{
	$text = str_replace("[*]", "[*:$uid]", $text);

	return $text;
}

/**
 * Escapes the "/" character with "\/". This is useful when you need
 * to stick a runtime string into a PREG regexp that is being delimited
 * with slashes.
 */
function escape_slashes($input)
{
	$output = str_replace('/', '\/', $input);
	return $output;
}

/**
 * This function does exactly what the PHP4 function array_push() does
 * however, to keep phpBB compatable with PHP 3 we had to come up with our own
 * method of doing it.
 * This function was deprecated in phpBB 2.0.18
 */
function bbcode_array_push(&$stack, $value)
{
   $stack[] = $value;
   return(sizeof($stack));
}

/**
 * This function does exactly what the PHP4 function array_pop() does
 * however, to keep phpBB compatable with PHP 3 we had to come up with our own
 * method of doing it.
 * This function was deprecated in phpBB 2.0.18
 */
function bbcode_array_pop(&$stack)
{
	return array_pop($stack);
}

//
// Smilies code ... would this be better tagged on to the end of bbcode.php?
// Probably so and I'll move it before B2
//
function smilies_pass($message)
{
	static $orig, $repl;

	if (!isset($orig))
	{
		global $db, $board_config;
		$orig = $repl = array();

		$sql = 'SELECT * FROM ' . SMILIES_TABLE;
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, "Couldn't obtain smilies data", "", __LINE__, __FILE__, $sql);
		}
		$smilies = $db->sql_fetchrowset($result);

		if (count($smilies))
		{
			usort($smilies, 'smiley_sort');
		}

		for ($i = 0; $i < count($smilies); $i++)
		{
			$orig[] = "/(?<=.\W|\W.|^\W)" . preg_quote($smilies[$i]['code'], "/") . "(?=.\W|\W.|\W$)/";
			$repl[] = '<img src="'. $board_config['smilies_path'] . '/' . $smilies[$i]['smile_url'] . '" alt="' . $smilies[$i]['emoticon'] . '" border="0" />';
		}
	}

	if (count($orig))
	{
		$message = phpbb_bbcode_transform_prose($message, function ($segment) use ($orig, $repl)
		{
			$replaced = preg_replace($orig, $repl, ' ' . $segment . ' ');
			return ($replaced === null) ? $segment : substr($replaced, 1, -1);
		});
	}
	
	return $message;
}
function acronym_pass($message)
{
	static $orig, $repl;

	if( !isset($orig) )
	{
		global $db, $board_config;
		$orig = $repl = array();

		$sql = 'SELECT * FROM ' . ACRONYMS_TABLE;
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, "Couldn't obtain acronyms data", "", __LINE__, __FILE__, $sql);
		}
		
		$acronyms = $db->sql_fetchrowset($result);

		if( count($acronyms) )
		{
			usort( $acronyms, 'acronym_sort' );
		}

		for ($i = 0; $i < count($acronyms); $i++)
		{
			$acronym_text = html_entity_decode((string) $acronyms[$i]['acronym'], ENT_QUOTES, 'UTF-8');
			$description_text = html_entity_decode((string) $acronyms[$i]['description'], ENT_QUOTES, 'UTF-8');
			if ($acronym_text === '')
			{
				continue;
			}
			$acronym_html = htmlspecialchars($acronym_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$description_html = htmlspecialchars($description_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$orig[] = '#\b(' . preg_quote($acronym_text, "/") . ')\b#';
			//$orig[] = "/(?<=.\W|\W.|^\W)" . phpbb_preg_quote($acronyms[$i]['acronym'], "/") . "(?=.\W|\W.|\W$)/";
			$repl[] = str_replace(array('\\', '$'), array('\\\\', '\\$'), '<acronym title="' . $description_html . '">' . $acronym_html . '</acronym>');
		}
	}
	
	if( count( $orig ) )
	{
		$segments = preg_split( '#(<code\b[^>]*>.*?</code>|<acronym.+?>.+?</acronym>|<.+?>)#s' , $message, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
		if (!is_array($segments) || preg_last_error() !== PREG_NO_ERROR)
		{
			return $message;
		}

		$message = '';

		foreach( $segments as $seg )
		{
			if( $seg[0] != '<' && $seg[0] != '[' )
			{
				$message .= phpbb_preg_replace_outside_tags($seg, $orig, $repl);
			}
			else
			{
				$message .= $seg;
			}
		}
	}
	
	return $message;
} 
function smiley_sort($a, $b)
{
	if ( strlen($a['code']) == strlen($b['code']) )
	{
		return 0;
	}

	return ( strlen($a['code']) > strlen($b['code']) ) ? -1 : 1;
}
function acronym_sort($a, $b)
{
	if ( strlen($a['acronym']) == strlen($b['acronym']) )
	{
		return 0;
	}

	return ( strlen($a['acronym']) > strlen($b['acronym']) ) ? -1 : 1;
} 
?>
