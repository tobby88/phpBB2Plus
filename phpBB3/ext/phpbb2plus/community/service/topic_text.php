<?php
namespace phpbb2plus\community\service;

/** Produce plain text from the post HTML already rendered for this viewer. */
class topic_text
{
	public static function line($text)
	{
		return str_replace(array("\r", "\n", "\t"), ' ', html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
	}

	public static function body($html)
	{
		if ($html === '') { return ''; }
		if (strpos($html, '<') === false) { return self::crlf(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')); }
		$doc = new \DOMDocument('1.0', 'UTF-8');
		// A native MEDIUMTEXT post may exceed libxml's default single-text-node limit.
		$options = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;
		if (strlen($html) > 8*1024*1024) { $options |= LIBXML_PARSEHUGE; }
		if (!$doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
			$options)) { throw new \RuntimeException('Cannot read formatted post'); }
		$body = $doc->getElementsByTagName('body')->item(0);
		if (!$body) { throw new \RuntimeException('Missing formatted post body'); }
		return self::crlf(self::children($body, 0, false));
	}

	private static function crlf($text)
	{
		return str_replace("\n", "\r\n", str_replace(array("\r\n", "\r"), "\n", $text));
	}

	private static function children($node, $depth, $citation, $level = 0)
	{
		if ($level >= 250) { throw new \RuntimeException('Text formatting exceeds the supported nesting depth'); }
		$text = '';
		foreach ($node->childNodes as $child)
		{
			if ($child instanceof \DOMText) { $text .= $child->nodeValue; }
			elseif ($child instanceof \DOMElement) { $text .= self::element($child, $depth, $citation, $level + 1); }
		}
		return $text;
	}

	private static function element($node, $depth, $citation, $level)
	{
		$name = strtolower($node->tagName);
		$classes = explode(' ', $node->getAttribute('class'));
		if (in_array($name, array('script','style','button','input'), true) || in_array('inline-attachment', $classes, true) ||
			$node->getAttribute('data-sxbb-controls') === '1' || $node->getAttribute('aria-hidden') === 'true') { return ''; }
		if ($name === 'br') { return "\n"; }
		if ($name === 'img')
		{
			return in_array('smilies', $classes, true) || !$node->hasAttribute('src') ? $node->getAttribute('alt') : $node->getAttribute('src');
		}
		if ($name === 'ol' || $name === 'ul') { return self::listing($node, $depth, $citation, $level); }
		if (in_array('codebox', $classes, true))
		{
			$code = $node->getElementsByTagName('code')->item(0);
			if ($code) { return "\n" . str_replace("\xC2\xA0", ' ', self::children($code, $depth, false, $level)) . "\n"; }
		}
		$text = self::children($node, $depth, $citation || $name === 'cite', $level);
		if ($name === 'a' && !$citation)
		{
			$href = $node->getAttribute('href');
			if ($href !== '' && $href !== $text && $href !== 'mailto:' . $text) { return $text === '' ? $href : $text . ' (' . $href . ')'; }
		}
		if (in_array($name, array('p','div','tr','blockquote','cite','pre'), true) && substr($text, -1) !== "\n") { return $text . "\n"; }
		if ($name === 'td' || $name === 'th') { return $text . "\t"; }
		return $text;
	}

	private static function listing($node, $depth, $citation, $level)
	{
		$text = "\n";
		$number = 0;
		$type = $node->getAttribute('type');
		$style = strtolower($node->getAttribute('style'));
		if (strpos($style, 'lower-alpha') !== false) { $type = 'a'; }
		if (strpos($style, 'upper-alpha') !== false) { $type = 'A'; }
		foreach ($node->childNodes as $item)
		{
			if (!($item instanceof \DOMElement) || $item->tagName !== 'li') { continue; }
			++$number;
			$label = '-';
			if ($node->tagName === 'ol')
			{
				$label = (string) $number;
				if ($type === 'a' || $type === 'A')
				{
					$label = '';
					for ($i = $number; $i > 0; $i = intdiv($i - 1, 26)) { $label = chr(ord($type) + ($i - 1) % 26) . $label; }
				}
				$label .= '.';
			}
			$text .= str_repeat('  ', $depth) . $label . ' ' . self::children($item, $depth + 1, $citation, $level);
			if (substr($text, -1) !== "\n") { $text .= "\n"; }
		}
		return $text;
	}

	public static function disposition($title, $id)
	{
		$base = trim(preg_replace('~[\x00-\x1f\x7f<>:"/\\\\|?*]+~', '-', self::line($title)), " .-\t");
		// A multibyte character at the filename cutoff must remain intact.
		$limit = min(strlen($base), 180);
		while ($limit && $limit < strlen($base) && (ord($base[$limit]) & 0xC0) === 0x80) { --$limit; }
		$base = rtrim(substr($base, 0, $limit), " .-\t");
		if ($base === '') { $base = 'topic-' . (int) $id; }
		if (preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $base)) { $base = 'topic-' . $base; }
		$ascii = strtr($base, array('Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss'));
		$ascii = trim(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $ascii), '.-_');
		if ($ascii === '') { $ascii = 'topic-' . (int) $id; }
		return 'attachment; filename="' . $ascii . '.txt"; filename*=UTF-8\'\'' . rawurlencode($base . '.txt');
	}
}
