<?php
namespace phpbb2plus\community\service;

/** Add previously unrecognised signs without reparsing or losing other native markup. */
class legacy_smilie_signs
{
	private const PROTECTED = array('s', 'e', 'i', 'CODE', 'IMG', 'FLASH', 'URL', 'EMAIL', 'SCHILD', 'PLUSSMILIESIGN');

	public static function upgrade($xml)
	{
		if (stripos($xml, '[schild=') === false || stripos($xml, '[/schild]') === false || !preg_match('/^<[rt][ >]/D', $xml) ||
			strpos($xml, '<SCHILD') !== false || !preg_match('/(?<!<s>)\[schild=/i', $xml))
		{
			return $xml;
		}
		$dom = new \DOMDocument();
		if (!@$dom->loadXML($xml, LIBXML_NONET)) { return $xml; }
		$budget = 64;
		$changed = false;
		self::walk($dom->documentElement, $budget, $changed);
		if (!$changed) { return $xml; }
		$out = \s9e\TextFormatter\Utils::encodeUnicodeSupplementaryCharacters($dom->saveXML($dom->documentElement));
		return $dom->documentElement->nodeName === 't' ? '<r>' . substr($out, 3, -4) . '</r>' : $out;
	}

	private static function walk($parent, &$budget, &$changed)
	{
		for ($node = $parent->firstChild; $node && $budget > 0; $node = $next)
		{
			$next = $node->nextSibling;
			if ($node instanceof \DOMElement)
			{
				if (!in_array($node->nodeName, self::PROTECTED, true)) { self::walk($node, $budget, $changed); }
				continue;
			}
			if (!($node instanceof \DOMText) || !preg_match('/\[schild=([a-z0-9]+)([^\]\r\n]{0,256})\]/i', $node->data, $match, PREG_OFFSET_CAPTURE)) { continue; }
			--$budget;
			$before = substr($node->data, 0, $match[0][1]);
			$caption = '';
			$source = '';
			$part = substr($node->data, $match[0][1] + strlen($match[0][0]));
			// An old conversion may already have decorated a smiley or hyperlink in the caption.
			for ($end = $node, $n = 0; $end && $n < 128 && strlen($source) <= 8192; $end = $end->nextSibling, ++$n)
			{
				if ($end instanceof \DOMText)
				{
					if ($end !== $node) { $part = $end->data; }
					$close = stripos($part, '[/schild]');
					if ($close !== false)
					{
						if (strlen($source) + $close > 8192) { break; }
						$caption .= substr($part, 0, $close);
						$source .= substr($part, 0, $close);
						$after = substr($part, $close + strlen('[/schild]'));
						$dom = $parent->ownerDocument;
						$sign = self::sign($dom, $match, substr($part, $close, strlen('[/schild]')), $caption, $source);
						if ($before !== '') { $parent->insertBefore($dom->createTextNode($before), $node); }
						$parent->insertBefore($sign, $node);
						$next = $end->nextSibling;
						do { $old = $node; $node = $old->nextSibling; $parent->removeChild($old); } while ($old !== $end);
						if ($after !== '') { $next = $parent->insertBefore($dom->createTextNode($after), $next); }
						$changed = true;
						break;
					}
					$caption .= $part;
					$source .= $part;
				}
				elseif (in_array($end->nodeName, array('s', 'e', 'i', 'CODE', 'PLUSSMILIESIGN'), true)) { break; }
				else { $source .= $end->textContent; $caption .= self::visible($end); }
			}
		}
	}

	private static function sign($dom, $opening, $closing, $caption, $source)
	{
		$sign = $dom->createElement('PLUSSMILIESIGN');
		$sign->setAttribute('caption', smilie_sign::caption($caption));
		$sign->setAttribute('face', smilie_sign::face($opening[1][0]));
		preg_match_all('/(?:^|\s)(fontcolor|shadowcolor|shieldshadow)=([^\s]+)/i', $opening[2][0], $options, PREG_SET_ORDER);
		foreach ($options as $option)
		{
			$name = strtolower($option[1]);
			$value = $name === 'shieldshadow' ? smilie_sign::shade($option[2]) : smilie_sign::colour($option[2]);
			if ($value !== false) { $sign->setAttribute($name, $value); }
		}
		// The native unparser restores these exact characters when the post is edited.
		$sign->appendChild($dom->createElement('s'))->appendChild($dom->createTextNode($opening[0][0]));
		$sign->appendChild($dom->createTextNode($source));
		$sign->appendChild($dom->createElement('e'))->appendChild($dom->createTextNode($closing));
		return $sign;
	}

	private static function visible($node)
	{
		if ($node instanceof \DOMText) { return $node->data; }
		if ($node->nodeName === 'br') { return ' '; }
		if (in_array($node->nodeName, array('s', 'e', 'i'), true)) { return ''; }
		$text = '';
		foreach ($node->childNodes as $child) { $text .= self::visible($child); }
		return $text;
	}
}
