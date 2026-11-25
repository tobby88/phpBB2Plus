<?php
namespace phpbb2plus\community\service;

/** Render the small HTML fragment used by the classic portal's welcome block. */
class welcome
{
	private static function url($value, $root, $link)
	{
		$value = trim($value);
		if ($value === '' || preg_match('~[\\\\\x00-\x20\x7f]~',$value) || substr($value,0,2) === '//') { return ''; }
		$scheme = parse_url($value,PHP_URL_SCHEME);
		if ($scheme !== null)
		{
			if ($scheme === false) { return ''; }
			if ($link && strtolower($scheme) === 'mailto' && filter_var(substr($value,7),FILTER_VALIDATE_EMAIL)) { return $value; }
			return in_array(strtolower($scheme),array('http','https'),true) && parse_url($value,PHP_URL_HOST) ? $value : '';
		}
		if ($value[0] === '/' || $value[0] === '#') { return $value; }
		return $root . $value;
	}

	private static function node($node, $root)
	{
		if ($node instanceof \DOMText) { return utf8_htmlspecialchars($node->nodeValue); }
		if (!($node instanceof \DOMElement)) { return ''; }
		$tag = strtolower($node->tagName);
		if (in_array($tag,array('style','script','head','object','iframe','template','svg','math','embed'),true)) { return ''; }
		if ($tag === 'br' || $tag === 'hr') { return '<' . $tag . '>'; }
		if ($tag === 'img')
		{
			$url = self::url($node->getAttribute('src'),$root,false);
			return $url === '' ? utf8_htmlspecialchars($node->getAttribute('alt')) : '<img src="' . utf8_htmlspecialchars($url) . '" alt="' .
				utf8_htmlspecialchars($node->getAttribute('alt')) . '">';
		}
		$inside = '';
		foreach ($node->childNodes as $child) { $inside .= self::node($child,$root); }
		if ($tag === 'a')
		{
			$url = self::url($node->getAttribute('href'),$root,true);
			return $url === '' ? $inside : '<a href="' . utf8_htmlspecialchars($url) . '">' . $inside . '</a>';
		}
		if ($tag === 'center') { return '<div class="plus-welcome-center">' . $inside . '</div>'; }
		$aliases = array('b'=>'strong','i'=>'em','strike'=>'s');
		$tag = $aliases[$tag] ?? $tag;
		if (in_array($tag,array('p','div','strong','em','u','s','ul','ol','li','blockquote','code','pre','h2','h3','h4','table','thead','tbody','tr','td','th'),true))
		{
			return '<' . $tag . '>' . $inside . '</' . $tag . '>';
		}
		return $inside;
	}

	private static function document($fragment)
	{
		if (trim((string) $fragment) === '') { return null; }
		$doc = new \DOMDocument();
		if (!$doc->loadHTML('<!doctype html><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body>' .
			$fragment . '</body></html>',LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) { return null; }
		return $doc;
	}

	public static function migrate_images($fragment, $resolve)
	{
		$doc = self::document($fragment);
		if (!$doc) { return (string) $fragment; }
		$changed = false;
		foreach ($doc->getElementsByTagName('img') as $index=>$img)
		{
			$old = $img->getAttribute('src');
			$new = $resolve($old,$index+1);
			if ($old !== $new) { $img->setAttribute('src',$new); $changed = true; }
		}
		if (!$changed) { return $fragment; }
		$html = '';
		foreach ($doc->getElementsByTagName('body')->item(0)->childNodes as $node) { $html .= $doc->saveHTML($node); }
		return $html;
	}

	public static function render($fragment, $web_root)
	{
		$doc = self::document($fragment);
		$body = $doc ? $doc->getElementsByTagName('body')->item(0) : null;
		return $body ? self::node($body,$web_root) : '';
	}
}
