<?php
namespace phpbb2plus\community\service;

/** Apply native BBCode definitions to literal spans in XML made by an earlier preview. */
class legacy_text_styles
{
	private $container;
	private $parser;

	public function __construct($container) { $this->container = $container; }

	public static function candidate($xml)
	{
		return strpos($xml, '[') !== false && preg_match('~^<[rt][ >]~D', $xml) &&
			preg_match('~(?<!<s>)(?<!<HR>)\[(?:' . self::names() . ')(?=[=\] ])~i', $xml);
	}

	private static function names()
	{
		static $names;
		if ($names === null) { $names = implode('|', array_keys(formatting_codes::definitions() + media_codes::TAGS)); }
		return $names;
	}

	public function for_display($xml)
	{
		if (!self::candidate($xml)) { return $xml; }
		$doc = new \DOMDocument();
		if (!@$doc->loadXML($xml, LIBXML_NONET)) { return $xml; }
		if (!$this->parser) { $this->parser = literal_bbcode::parser($this->container); }
		// Use the board's parser to identify only our original delimiters and their
		// interpreted attributes. All surrounding XML comes from the stored document.
		$text = $doc->documentElement->textContent;
		$parsed = new \DOMDocument();
		if (!@$parsed->loadXML($this->parser->parse($text), LIBXML_NONET) || $parsed->documentElement->textContent !== $text) { return $xml; }
		$names = formatting_codes::definitions() + media_codes::TAGS + array_change_key_case(array_flip(media_codes::TAGS));
		$xpath = new \DOMXPath($parsed);
		$offset = 0;
		$open = $wanted = $spans = array();
		foreach ($xpath->query('//text()') as $node)
		{
			$pos = $offset;
			$offset += strlen($node->data);
			$marker = $node->parentNode;
			if ($marker->nodeName === 'HR' && !$node->previousSibling && !$node->nextSibling && strcasecmp($node->data, '[hr]') === 0)
			{
				$wanted[$pos] = true;
				$spans[] = array($marker, $pos, $pos);
				continue;
			}
			$tag = $marker->parentNode;
			if (($marker->nodeName !== 's' && $marker->nodeName !== 'e') || !isset($names[strtolower($tag->nodeName)])) { continue; }
			$id = spl_object_id($tag);
			if ($marker->nodeName === 's') { $open[$id] = array($tag, $pos); }
			elseif (isset($open[$id]))
			{
				$start = $open[$id][1];
				$wanted[$start] = $wanted[$pos] = true;
				$spans[] = array($tag, $start, $pos);
				unset($open[$id]);
			}
		}
		if (!$spans) { return $xml; }
		$nodes = literal_bbcode::tokens($doc, $wanted, '~\[/?(?:' . self::names() . ')(?:[= ][^\[\]\r\n]*)?\]~i');
		$changed = false;
		foreach ($spans as list($tag, $start, $end))
		{
			if (!isset($nodes[$start], $nodes[$end]) || !$nodes[$start]->parentNode->isSameNode($nodes[$end]->parentNode)) { continue; }
			if ($start === $end) { $nodes[$start]->parentNode->replaceChild($doc->importNode($tag, true), $nodes[$start]); }
			else { literal_bbcode::wrap($doc->importNode($tag, false), $nodes[$start], $nodes[$end]); }
			$changed = true;
		}
		if (!$changed) { return $xml; }
		$out = \s9e\TextFormatter\Utils::encodeUnicodeSupplementaryCharacters($doc->saveXML($doc->documentElement));
		return $doc->documentElement->nodeName === 't' ? '<r>' . substr($out, 3, -4) . '</r>' : $out;
	}
}
