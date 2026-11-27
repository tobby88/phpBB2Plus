<?php
namespace phpbb2plus\community\service;

/** The Plus BBCode models a one-row table with one or more explicitly delimited cells. */
class table_formatting
{
	private const CONTAINERS = array('quote','acronym','table','cell','list','b','i','u','s','color','size','font','align','center','poet',
		'fade','glow','shadow','highlight','flipv','fliph','scrollleft','scrollright','scrollup','scrolldown','marq','img','left','right',
		'ram','stream','flash','video','url','email','spoiler');

	public static function configure($configurator)
	{
		if (isset($configurator->tags['PLUSTABLE']) || isset($configurator->tags['PLUSCELL'])) { return; }
		// Treat the table and its cells as a unit. An existing native definition retains control of its markup.
		$use_plus = !isset($configurator->BBCodes['TABLE']) && !isset($configurator->BBCodes['CELL']);
		foreach (array('TABLE' => 'table', 'CELL' => 'td') as $name => $element)
		{
			$tag_name = 'PLUS' . $name;
			if ($use_plus)
			{
				$bbcode = $configurator->BBCodes->add($name);
				$bbcode->tagName = $tag_name;
				$bbcode->defaultAttribute = 'style';
			}
			// Keep tags already stored by the Plus formatter readable after a native BBCode is added.
			$tag = $configurator->tags->add($tag_name);
			$style = $tag->attributes->add('style');
			$style->filterChain->append(__CLASS__ . '::style');
			$style->markAsSafeInCSS();
			$tag->filterChain->add(__CLASS__ . '::check_tag')->resetParameters()->addParameterByName('tag')->addParameterByName('text');
			$content = '<xsl:apply-templates/>';
			if ($name === 'TABLE')
			{
				$content = '<tr>' . $content . '</tr>';
				$tag->rules->ignoreText();
				$tag->rules->suspendAutoLineBreaks();
			}
			else { $tag->rules->requireParent('PLUSTABLE'); }
			$tag->template = '<' . $element . '><xsl:if test="@style != \'\'"><xsl:attribute name="style"><xsl:value-of select="@style"/></xsl:attribute></xsl:if>' .
				$content . '</' . $element . '>';
		}
	}

	public static function style($value)
	{
		static $rules = array(
			'width|height|min-width|max-width' => '(?:auto|[0-9]{1,4}(?:\.[0-9]{1,2})?(?:px|%|em|rem)?)',
			'text-align|vertical-align' => '(?:left|right|center|justify|top|middle|bottom)',
			'color|background-color' => '(?:\#[a-f0-9]{3}(?:[a-f0-9]{3})?|[a-z]{1,20})',
			'padding|margin|border-spacing' => '[0-9]{1,3}(?:px|em|rem|%)(?:\s+[0-9]{1,3}(?:px|em|rem|%)){0,3}',
			'border-collapse' => '(?:collapse|separate)',
			'border' => '[0-9]{1,2}px\s+(?:none|solid|dashed|dotted|double)\s+(?:\#[a-f0-9]{3}(?:[a-f0-9]{3})?|[a-z]{1,20})');
		if (strlen($value) > 8192) { return ''; }
		$value = str_replace(array("\r", "\n", "\0"), '', html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
		$out = array();
		foreach (explode(';', $value) as $declaration)
		{
			$pair = explode(':', $declaration, 2);
			if (count($pair) !== 2) { continue; }
			$property = strtolower(trim($pair[0]));
			$argument = strtolower(trim($pair[1]));
			foreach ($rules as $names => $pattern)
			{
				if (in_array($property, explode('|', $names), true) && preg_match('~^' . $pattern . '$~D', $argument))
				{
					$out[] = $property . ': ' . $argument;
					break;
				}
			}
		}
		return implode('; ', $out);
	}

	public static function check_tag($tag, $text)
	{
		// s9e calls this filter once per opening tag in the same parse.
		static $source = null, $accepted;
		if ($source !== $text)
		{
			$source = $text;
			$accepted = array();
			foreach (self::tables($text) as $start => $group) { $accepted[$start] = true; $accepted += $group['cells']; }
		}
		if (!isset($accepted[$tag->getPos()])) { $tag->invalidate(); return; }
		// Use the Plus declaration syntax even if the native tokenizer recognised additional named attributes.
		$tag->setAttribute('style', self::source_style(substr($text, $tag->getPos(), $tag->getLen())));
	}

	public static function source_style($opening) { return self::style(substr($opening, strpos($opening, '=') + 1, -1)); }

	/** Source ranges of complete, well-nested tables. Malformed brackets stay visible and editable. */
	public static function tables($text)
	{
		$stack = $groups = array();
		$cursor = $tokens = 0;
		// Code is opaque to the native parser. Brackets written as examples inside it do not delimit this table.
		$pattern = '~\[code(?:=[^\]\r\n]*)?\].*?(?:\[/code\]|\z)|\[(/?)([a-z][a-z0-9_-]*)([=\s][^\[\]]*)?\]~is';
		while (($match = preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $cursor)) === 1)
		{
			if (++$tokens > 10000 || count($stack) > 256) { return array(); }
			list($token, $offset) = $m[0];
			$name = isset($m[2]) ? strtolower($m[2][0]) : 'code';
			$close = isset($m[1]) && $m[1][0] === '/';
			$option = $m[3][0] ?? '';
			$top = end($stack);
			if ($top && $top[0] === 'table' && (strspn($text, " \t\n\r", $cursor, $offset - $cursor) !== $offset - $cursor ||
				!((!$close && $name === 'cell' && substr($option, 0, 1) === '=') || ($close && $name === 'table' && $option === ''))))
			{ $groups[$top[1]]['valid'] = false; }
			$cursor = $offset + strlen($token);
			if (!in_array($name, self::CONTAINERS, true)) { continue; }
			$structural = $name === 'table' || $name === 'cell';
			if ($structural && ($close ? $option !== '' : substr($option, 0, 1) !== '=')) { continue; }
			if (!$close)
			{
				$group = null;
				if ($name === 'table')
				{
					$group = $offset;
					$groups[$group] = array('valid' => true, 'end' => null, 'cells' => array());
				}
				elseif ($name === 'cell' && $top && $top[0] === 'table')
				{
					$group = $top[1];
					$groups[$group]['cells'][$offset] = null;
				}
				$stack[] = array($name, $group, $offset);
				continue;
			}
			for ($i = count($stack) - 1; $i >= 0 && $stack[$i][0] !== $name; --$i) {}
			if ($i < 0 && !$structural) { continue; }
			if ($i !== count($stack) - 1 || $i < 0)
			{
				foreach ($stack as $frame) { if ($frame[1] !== null) { $groups[$frame[1]]['valid'] = false; } }
			}
			if ($i < 0) { continue; }
			$frame = $stack[$i];
			if ($name === 'table') { $groups[$frame[1]]['end'] = $offset; }
			elseif ($name === 'cell' && $frame[1] !== null) { $groups[$frame[1]]['cells'][$frame[2]] = $offset; }
			array_splice($stack, $i);
		}
		if ($match === false) { return array(); }
		return array_filter($groups, static function ($group) {
			return $group['valid'] && $group['end'] !== null && $group['cells'] && !in_array(null, $group['cells'], true);
		});
	}
}
