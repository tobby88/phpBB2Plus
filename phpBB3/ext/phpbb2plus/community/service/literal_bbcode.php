<?php
namespace phpbb2plus\community\service;

/** Locate unparsed source delimiters while leaving native markup and code samples intact. */
class literal_bbcode
{
	public static function parser($container)
	{
		// s9e's cached representation contains only configuration. A shallow clone
		// of a warmed parser keeps plugin objects bound to the original request.
		$parser = $container->get('cache.driver')->get($container->getParameter('text_formatter.cache.parser.key'));
		if (!$parser) { $parser = $container->get('text_formatter.s9e.factory')->regenerate()['parser']; }
		$copy = unserialize(serialize($parser));
		$copy->enablePlugin('BBCodes');
		return $copy;
	}

	public static function tokens($doc, $wanted, $pattern)
	{
		$found = array();
		$offset = 0;
		$xpath = new \DOMXPath($doc);
		foreach ($xpath->query('//text()') as $node)
		{
			$text = $node->data;
			$base = $offset;
			$offset += strlen($text);
			if (strpos($text, '[') === false) { continue; }
			for ($ancestor = $node->parentNode; $ancestor instanceof \DOMElement; $ancestor = $ancestor->parentNode)
			{
				if (in_array($ancestor->nodeName, array('s','e','i','CODE','IMG','URL','EMAIL','FLASH','HR'), true)) { continue 2; }
			}
			preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
			$cursor = 0;
			foreach ($matches[0] as list($token, $pos))
			{
				if (!isset($wanted[$base + $pos])) { continue; }
				if ($pos > $cursor) { $node->parentNode->insertBefore($doc->createTextNode(substr($text, $cursor, $pos - $cursor)), $node); }
				$found[$base + $pos] = $node->parentNode->insertBefore($doc->createTextNode($token), $node);
				$cursor = $pos + strlen($token);
			}
			if ($cursor) { $node->data = substr($text, $cursor); }
		}
		return $found;
	}

	public static function wrap($tag, $open, $close, $skip_breaks = false)
	{
		$doc = $open->ownerDocument;
		$open->parentNode->insertBefore($tag, $open);
		$tag->appendChild($doc->createElement('s'))->appendChild($open);
		while (($child = $tag->nextSibling) && !$child->isSameNode($close))
		{
			if ($skip_breaks && $child->nodeName === 'br') { $child->parentNode->removeChild($child); }
			else { $tag->appendChild($child); }
		}
		$tag->appendChild($doc->createElement('e'))->appendChild($close);
	}
}
