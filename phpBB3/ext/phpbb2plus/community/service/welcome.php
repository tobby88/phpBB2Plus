<?php
namespace phpbb2plus\community\service;

/** Render the small HTML fragment used by the classic portal's welcome block. */
class welcome
{
	private const SOURCE = 'plus_portal_welcome_source';

	/** Older readers still receive renderable HTML on phpBB's utf8mb3 connection. */
	public static function storage($html)
	{
		$html = (string) $html;
		$view = utf8_encode_ucr($html);
		// A character and a numeric reference may render identically but are different editor text.
		$source = $view === $html ? '' : json_encode($html,JSON_THROW_ON_ERROR);
		return array('plus_portal_welcome'=>$view, self::SOURCE=>$source);
	}

	public static function editor_settings($text, array $keys)
	{
		$values = $text->get_array(array_merge($keys,array('plus_portal_welcome',self::SOURCE)));
		$view = (string) ($values['plus_portal_welcome'] ?? '');
		$source = json_decode($values[self::SOURCE] ?? '');
		unset($values[self::SOURCE]);
		// An edit by an older version takes precedence over an obsolete stored source.
		$values['plus_portal_welcome'] = is_string($source) && utf8_encode_ucr($source) === $view ? $source : $view;
		return $values;
	}

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
		$fragment = (string) $fragment;
		if (stripos($fragment,'<img') === false) { return $fragment; }
		$marker = 'data-plus-image-offset';
		while (stripos($fragment,$marker) !== false) { $marker .= '-' . bin2hex(random_bytes(8)); }
		// Let DOM identify actual images, including around comments and quoted markup.
		// The temporary byte offsets refer to the unmodified HTML entered by the operator.
		$indexed = preg_replace_callback('~<img(?=[\s/>])~i',static function ($match) use ($marker) {
			return $match[0][0] . ' ' . $marker . '=' . $match[0][1];
		},$fragment,-1,$count,PREG_OFFSET_CAPTURE);
		$doc = $count ? self::document($indexed) : null;
		if (!$doc) { return $fragment; }
		$changes = array();
		foreach ($doc->getElementsByTagName('img') as $index=>$img)
		{
			if (!$img->hasAttribute($marker)) { continue; }
			$old = preg_replace('~ ' . $marker . '=[0-9]+~','',$img->getAttribute('src'));
			$new = $resolve($old,$index + 1);
			if ($new === $old) { continue; }
			$at = (int) $img->getAttribute($marker) + 4;
			// Rewrite only the source attribute. Serializing the entire DOM would normalize
			// the original numeric references, case, quotes and whitespace in unrelated text.
			while (preg_match('~\G[\s/]+([^\s"\'=/>]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?~',$fragment,$attr,PREG_OFFSET_CAPTURE,$at))
			{
				$at += strlen($attr[0][0]);
				if (strcasecmp($attr[1][0],'src') !== 0) { continue; }
				if (isset($attr[2])) { $changes[$attr[2][1]] = array(strlen($attr[2][0]),'"' . utf8_htmlspecialchars($new) . '"'); }
				break;
			}
		}
		if (!$changes) { return $fragment; }
		ksort($changes,SORT_NUMERIC);
		$html = ''; $at = 0;
		foreach ($changes as $offset=>$change)
		{
			$html .= substr($fragment,$at,$offset-$at) . $change[1];
			$at = $offset + $change[0];
		}
		return $html . substr($fragment,$at);
	}

	public static function render($fragment, $web_root)
	{
		$doc = self::document($fragment);
		$body = $doc ? $doc->getElementsByTagName('body')->item(0) : null;
		return $body ? self::node($body,$web_root) : '';
	}
}
