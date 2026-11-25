<?php
namespace phpbb2plus\downloads\service;

class license_text
{
	// Converted rows retain the exact phpBB2 input in their source document.
	// In particular, a licence name can contain literal entity text as well as emoji.
	public static function raw($row, $column)
	{
		$source = json_decode($row['source_json'],true);
		$value = $row[$column];
		if (!empty($source['plus_license_escaped'])) { return access::plain($value); }
		$key = $column === 'title' ? 'license_name' : 'license_text';
		if (isset($source[$key]) && is_string($source[$key]) && utf8_encode_ucr($source[$key]) === $value) { return $source[$key]; }
		// Older layouts can lack the source document. Undo only the supplementary
		// character references produced by the original converter.
		return preg_replace_callback('/&#([0-9]{5,7});/',static function ($match) {
			$point = (int) $match[1];
			return $point >= 65536 && $point <= 1114111 ? utf8_chr($point) : $match[0];
		},$value);
	}

	private static function url($value, $root, $link)
	{
		$value = trim($value);
		if ($value === '' || preg_match('/[\\\\\x00-\x20\x7f]/',$value) || substr($value,0,2) === '//') { return ''; }
		$scheme = parse_url($value,PHP_URL_SCHEME);
		if ($scheme === false) { return ''; }
		if ($scheme !== null)
		{
			if ($link && strtolower($scheme) === 'mailto' && filter_var(substr($value,7),FILTER_VALIDATE_EMAIL)) { return $value; }
			return access::external($value);
		}
		return $value[0] === '/' || $value[0] === '#' ? $value : $root . $value;
	}

	private static function contents($node, $root)
	{
		if ($node instanceof \DOMText) { return utf8_htmlspecialchars($node->nodeValue); }
		if (!($node instanceof \DOMElement)) { return ''; }
		$tag = strtolower($node->tagName);
		if (in_array($tag,array('head','script','style','iframe','object','embed','applet','template','svg','math','form'),true)) { return ''; }
		if ($tag === 'br' || $tag === 'hr') { return '<' . $tag . '>'; }
		if ($tag === 'img')
		{
			$url = self::url($node->getAttribute('src'),$root,false);
			$alt = utf8_htmlspecialchars($node->getAttribute('alt'));
			return $url === '' ? $alt : '<img src="' . utf8_htmlspecialchars($url) . '" alt="' . $alt . '">';
		}
		$inside = '';
		foreach ($node->childNodes as $child) { $inside .= self::contents($child,$root); }
		if ($tag === 'a')
		{
			$url = self::url($node->getAttribute('href'),$root,true);
			return $url === '' ? $inside : '<a href="' . utf8_htmlspecialchars($url) . '">' . $inside . '</a>';
		}
		if (!in_array($tag,array('p','div','span','b','strong','i','em','u','s','strike','pre','code','blockquote','ul','ol','li','dl','dt','dd',
			'h1','h2','h3','h4','h5','h6','table','thead','tbody','tfoot','tr','th','td'),true)) { return $inside; }
		$attributes = '';
		if ($tag === 'th' || $tag === 'td')
		{
			foreach (array('rowspan','colspan') as $attribute)
			{
				$count = $node->getAttribute($attribute);
				if (preg_match('/^[1-9][0-9]{0,2}$/D',$count)) { $attributes .= ' ' . $attribute . '="' . $count . '"'; }
			}
		}
		return '<' . $tag . $attributes . '>' . $inside . '</' . $tag . '>';
	}

	public static function render($raw, $root)
	{
		$document = new \DOMDocument('1.0','UTF-8');
		$loaded = $document->loadHTML('<!doctype html><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body>' .
			$raw . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		$body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;
		return $body ? self::contents($body,$root) : utf8_htmlspecialchars($raw);
	}
}
