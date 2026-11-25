<?php
namespace phpbb2plus\downloads\service;

class search_text
{
	// Double literal ampersands before phpBB maps supplementary characters to numeric references.
	public static function encode($plain)
	{
		return utf8_encode_ucr(str_replace('&','&&',utf8_strtolower($plain)));
	}

	public static function file($row,$source)
	{
		$fields = array('search_title'=>$row['title'],'search_summary'=>$row['summary'],
			'search_creator'=>$source['file_creator'] ?? '','search_detail'=>$source['file_longdesc'] ?? '');
		foreach ($fields as $key=>$value) { $fields[$key] = self::encode(is_string($value) ? access::plain($value) : ''); }
		return $fields;
	}

	public static function comment($row)
	{
		$text = $links = '';
		$body = (string) $row['body'];
		// Read displayed text, never a formatter attribute, hidden URL target, image source or BBCode delimiter.
		if (substr($body,0,1) !== '<') { $text = access::plain($body); }
		else
		{
			$doc = new \DOMDocument();
			if (@$doc->loadXML($body,LIBXML_NONET) && !$doc->doctype)
			{
				$xpath = new \DOMXPath($doc);
				foreach ($xpath->query('//s|//e|//i') as $node) { $node->parentNode->removeChild($node); }
				foreach ($xpath->query('//br|//BR|//IMG') as $node) { $node->parentNode->replaceChild($doc->createTextNode(' '),$node); }
				foreach ($xpath->query('//URL|//EMAIL') as $node)
				{
					$links .= "\n" . $node->textContent;
					$node->parentNode->replaceChild($doc->createTextNode(' '),$node);
				}
				$text = $doc->documentElement->textContent;
			}
		}
		return array('search_title'=>self::encode(access::plain($row['title'])),'search_body'=>self::encode($text),'search_links'=>self::encode($links));
	}
}
