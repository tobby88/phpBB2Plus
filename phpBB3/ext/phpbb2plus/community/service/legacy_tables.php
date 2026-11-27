<?php
namespace phpbb2plus\community\service;

/** Earlier previews stored table brackets as literal text interleaved with already parsed XML. */
class legacy_tables
{
	private $db;
	private $container;
	private $custom;
	private $parser;

	public function __construct($db, $container) { $this->db = $db; $this->container = $container; }

	public static function candidate($xml)
	{
		return stripos($xml, '[table=') !== false && stripos($xml, '[/table]') !== false &&
			preg_match('~^<[rt][ >]~D', $xml) && preg_match('~(?<!<s>)\[table=~i', $xml);
	}

	public function for_display($xml)
	{
		if (!self::candidate($xml)) { return $xml; }
		if ($this->custom === null)
		{
			$result = $this->db->sql_query_limit('SELECT bbcode_id FROM ' . BBCODES_TABLE .
				" WHERE LOWER(bbcode_tag) IN ('table','table=','cell','cell=','plustable','plustable=','pluscell','pluscell=')", 1);
			$this->custom = (bool) $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
		}
		if ($this->custom && !$this->parser)
		{
			$this->parser = clone $this->container->get('text_formatter.parser')->get_parser();
			$this->parser->enablePlugin('BBCodes');
		}
		return self::upgrade($xml, $this->parser);
	}

	public static function upgrade($xml, $native = null)
	{
		if (!self::candidate($xml)) { return $xml; }
		$doc = new \DOMDocument();
		if (!@$doc->loadXML($xml, LIBXML_NONET)) { return $xml; }
		$text = $doc->documentElement->textContent;
		$tables = table_formatting::tables($text);
		if (!$tables) { return $xml; }
		$wanted = array();
		foreach ($tables as $start => $table)
		{
			$wanted[$start] = $wanted[$table['end']] = true;
			foreach ($table['cells'] as $open => $end) { $wanted[$open] = $wanted[$end] = true; }
		}
		$nodes = self::tokens($doc, $wanted);
		$changed = false;
		$budget = 1048576;
		// Inner tables are formed before their parents, without reparsing or flattening native child elements.
		foreach (array_reverse($tables, true) as $start => $table)
		{
			$pairs = array($start => $table['end']) + $table['cells'];
			if (!isset($nodes[$start])) { continue; }
			$parent = $nodes[$start]->parentNode;
			foreach ($pairs as $open => $end)
			{
				if (!isset($nodes[$open], $nodes[$end]) || !$nodes[$open]->parentNode->isSameNode($parent) ||
					!$nodes[$end]->parentNode->isSameNode($parent)) { continue 2; }
			}
			foreach ($table['cells'] + array($start => $table['end']) as $open => $end)
			{
				$name = $open === $start ? 'TABLE' : 'CELL';
				if ($native)
				{
					// Let the actual board definition interpret this span's attributes. Its existing child nodes stay intact.
					$length = $end + strlen($nodes[$end]->data) - $open;
					if ($length > $budget) { continue; }
					$budget -= $length;
					$parsed = new \DOMDocument();
					if (!@$parsed->loadXML($native->parse(substr($text, $open, $length)), LIBXML_NONET)) { continue; }
					$node = $parsed->documentElement->firstChild;
					if (!($node instanceof \DOMElement) || $node->nodeName !== $name || $node->nextSibling) { continue; }
					$tag = $doc->importNode($node, false);
				}
				else
				{
					$tag = $doc->createElement('PLUS' . $name);
					$tag->setAttribute('style', table_formatting::source_style($nodes[$open]->data));
				}
				self::wrap($tag, $nodes[$open], $nodes[$end]);
				$changed = true;
			}
		}
		if (!$changed) { return $xml; }
		$out = \s9e\TextFormatter\Utils::encodeUnicodeSupplementaryCharacters($doc->saveXML($doc->documentElement));
		return $doc->documentElement->nodeName === 't' ? '<r>' . substr($out, 3, -4) . '</r>' : $out;
	}

	private static function tokens($doc, $wanted)
	{
		$nodes = array();
		$offset = 0;
		$xpath = new \DOMXPath($doc);
		foreach ($xpath->query('//text()') as $node)
		{
			$text = $node->data;
			$base = $offset;
			$offset += strlen($text);
			if (strpos($text, '[') === false) { continue; }
			// Stored syntax, code samples, generated media attributes and link labels keep their own semantics.
			for ($ancestor = $node->parentNode; $ancestor instanceof \DOMElement; $ancestor = $ancestor->parentNode)
			{
				if (in_array($ancestor->nodeName, array('s','e','i','CODE','IMG','URL','EMAIL','FLASH'), true)) { continue 2; }
			}
			preg_match_all('~\[(?:(?:table|cell)=[^\[\]]*|/(?:table|cell))\]~i', $text, $matches, PREG_OFFSET_CAPTURE);
			$cursor = 0;
			foreach ($matches[0] as list($token, $pos))
			{
				if (!isset($wanted[$base + $pos])) { continue; }
				if ($pos > $cursor) { $node->parentNode->insertBefore($doc->createTextNode(substr($text, $cursor, $pos - $cursor)), $node); }
				$nodes[$base + $pos] = $node->parentNode->insertBefore($doc->createTextNode($token), $node);
				$cursor = $pos + strlen($token);
			}
			if ($cursor) { $node->data = substr($text, $cursor); }
		}
		return $nodes;
	}

	private static function wrap($tag, $start, $end)
	{
		$doc = $start->ownerDocument;
		$start->parentNode->insertBefore($tag, $start);
		$tag->appendChild($doc->createElement('s'))->appendChild($start);
		while (($child = $tag->nextSibling) && !$child->isSameNode($end))
		{
			// Earlier native BR nodes between the cells have no source characters.
			if ($tag->nodeName === 'PLUSTABLE' && $child->nodeName === 'br') { $child->parentNode->removeChild($child); }
			else { $tag->appendChild($child); }
		}
		$tag->appendChild($doc->createElement('e'))->appendChild($end);
	}
}
