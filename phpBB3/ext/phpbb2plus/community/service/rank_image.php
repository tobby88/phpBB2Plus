<?php
namespace phpbb2plus\community\service;

class rank_image
{
	public static function localize($src, $language)
	{
		if (!$src) { return $src; }
		$locale = strpos($language, 'de') === 0 ? 'de' : 'en';
		return preg_replace('~(/plus-rank-[0-9]+-[a-f0-9]{16}-)(?:de|en)(\.(?:gif|png|jpe?g))$~D', '$1' . $locale . '$2', $src);
	}
}
