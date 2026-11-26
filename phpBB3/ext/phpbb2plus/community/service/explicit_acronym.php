<?php
namespace phpbb2plus\community\service;

/** Explicit CMX acronyms authored in a message, independently of the board's dictionary. */
class explicit_acronym
{
	private $db;
	private $container;
	private $custom;
	private $parser;

	public function __construct($db, $container) { $this->db = $db; $this->container = $container; }

	public function for_display($xml)
	{
		if (!self::candidate($xml)) { return $xml; }
		if ($this->custom === null) { $this->custom = self::custom_definition($this->db); }
		if ($this->custom && !$this->parser)
		{
			// Keep the posting options on the request's shared parser intact.
			$this->parser = clone $this->container->get('text_formatter.parser')->get_parser();
			$this->parser->enablePlugin('BBCodes');
		}
		return self::upgrade($xml, $this->parser);
	}

	public static function configure($configurator)
	{
		if (!isset($configurator->BBCodes['ACRONYM']))
		{
			$bbcode = $configurator->BBCodes->add('ACRONYM');
			$bbcode->tagName = 'PLUSACRONYM';
			$bbcode->defaultAttribute = 'title';
		}
		// Keep existing Plus XML readable if an administrator later adds a different native BBCode.
		$tag = $configurator->tags->add('PLUSACRONYM');
		$tag->attributes->add('title')->filterChain->append(__CLASS__ . '::title');
		$tag->template = '<abbr title="{@title}"><xsl:apply-templates/></abbr>';
	}

	public static function title($value)
	{
		$value = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string) $value));
		// The original title was limited to 255 bytes. Keep the last UTF-8 character intact.
		$value = substr($value, 0, 255);
		while ($value !== '' && !preg_match('//u', $value)) { $value = substr($value, 0, -1); }
		return $value;
	}

	public static function custom_definition($db)
	{
		$result = $db->sql_query_limit('SELECT bbcode_id FROM ' . BBCODES_TABLE .
			" WHERE LOWER(bbcode_tag) IN ('acronym', 'acronym=')", 1);
		$found = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		return (bool) $found;
	}

	public static function candidate($xml)
	{
		return stripos($xml, '[acronym=') !== false && stripos($xml, '[/acronym]') !== false &&
			preg_match('/^<[rt][ >]/D', $xml) && preg_match('/(?<!<s>)\[acronym=/i', $xml);
	}

	public static function upgrade($xml, $native = null)
	{
		if (!self::candidate($xml)) { return $xml; }
		$document = new \DOMDocument();
		if (!@$document->loadXML($xml, LIBXML_NONET)) { return $xml; }
		$remaining = 256;
		$changed = false;
		self::wrap_children($document->documentElement, $remaining, $changed, $native);
		if (!$changed) { return $xml; }
		$out = \s9e\TextFormatter\Utils::encodeUnicodeSupplementaryCharacters($document->saveXML($document->documentElement));
		return $document->documentElement->nodeName === 't' ? '<r>' . substr($out, 3, -4) . '</r>' : $out;
	}

	private static function wrap_children($parent, &$remaining, &$changed, $native)
	{
		$document = $parent->ownerDocument;
		$open = array();
		for ($node = $parent->firstChild; $node && $remaining; $node = $next)
		{
			$next = $node->nextSibling;
			if ($node instanceof \DOMElement)
			{
				if (in_array($node->nodeName, array('CODE', 'QUOTE', 'LIST'), true)) { $open = array(); }
				if (in_array($node->nodeName, array('s','e','i','CODE','URL','IMG','FLASH','EMAIL','ACRONYM','PLUSACRONYM'), true)) { continue; }
				self::wrap_children($node, $remaining, $changed, $native);
				continue;
			}
			if (!($node instanceof \DOMText) || stripos($node->data, 'acronym') === false) { continue; }
			$parts = preg_split('~(\[acronym="[^"]{0,2048}"\]|\[/acronym\])~i', $node->data, $remaining + 1, PREG_SPLIT_DELIM_CAPTURE);
			if (count($parts) === 1) { continue; }
			foreach ($parts as $i => $part)
			{
				if ($part === '') { continue; }
				$part_node = $parent->insertBefore($document->createTextNode($part), $node);
				if (!($i % 2) || !$remaining) { continue; }
				--$remaining;
				if ($part[1] !== '/')
				{
					$open[] = $part_node;
					continue;
				}
				if (!$open) { continue; }
				$start = array_pop($open);
				$tag = self::wrapper($start, $part_node, $native);
				if (!$tag) { continue; }
				$parent->insertBefore($tag, $start);
				// Preserve existing formatted children and the exact source markers for native editing.
				$inside = $start->nextSibling;
				$tag->appendChild($document->createElement('s'))->appendChild($start);
				while ($inside !== $part_node)
				{
					$move = $inside;
					$inside = $inside->nextSibling;
					$tag->appendChild($move);
				}
				$tag->appendChild($document->createElement('e'))->appendChild($part_node);
				$changed = true;
			}
			$parent->removeChild($node);
		}
	}

	private static function wrapper($start, $end, $native)
	{
		$document = $start->ownerDocument;
		if (!$native)
		{
			$tag = $document->createElement('PLUSACRONYM');
			$tag->setAttribute('title', self::title(substr($start->data, 10, -2)));
			return $tag;
		}
		// Ask the administrator's actual BBCode how it interprets this single span,
		// including its attributes, while retaining the original formatted child nodes.
		$fragment = '';
		for ($child = $start; $child; $child = $child->nextSibling)
		{
			$fragment .= $document->saveXML($child);
			if (strlen($fragment) > 65536) { return null; }
			if ($child === $end) { break; }
		}
		$source = \s9e\TextFormatter\Unparser::unparse('<r>' . $fragment . '</r>');
		$parsed = new \DOMDocument();
		if (!@$parsed->loadXML($native->parse($source), LIBXML_NONET)) { return null; }
		$tag = $parsed->documentElement->firstChild;
		return $tag instanceof \DOMElement && $tag->nodeName === 'ACRONYM' && !$tag->nextSibling ? $document->importNode($tag, false) : null;
	}
}
