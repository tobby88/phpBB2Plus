<?php
namespace phpbb2plus\community\service;

/** The Plus search shortcut is an ordinary native link with an encoded query. */
class google_search
{
	public const TAGS = array('google' => 'URL');

	public static function configure($configurator)
	{
		if (!isset($configurator->tags['URL']) || isset($configurator->BBCodes['GOOGLE'])) { return; }
		$bbcode = $configurator->BBCodes->add('GOOGLE');
		$bbcode->tagName = 'URL';
		$bbcode->contentAttributes->add('url');
		$bbcode->forceLookahead = true;
		// Build the destination before the ordinary phpBB URL filter validates it.
		$configurator->tags['URL']->filterChain->prepend(__CLASS__ . '::query')->resetParameters()->addParameterByName('tag')->addParameterByName('text');
	}

	public static function query($tag, $text)
	{
		$opening = substr($text, $tag->getPos(), $tag->getLen());
		if (!preg_match('~^\[google(?=[\]\s=])~i', $opening)) { return; }
		if (strcasecmp($opening, '[google]') !== 0 || !$tag->hasAttribute('url'))
		{
			$tag->invalidate();
			return;
		}
		$tag->setAttribute('url', 'https://www.google.com/search?q=' . rawurlencode($tag->getAttribute('url')));
	}
}
