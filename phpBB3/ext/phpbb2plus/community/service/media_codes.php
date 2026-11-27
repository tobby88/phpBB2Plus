<?php
namespace phpbb2plus\community\service;

/** Audio and video brackets from the Plus post editor. */
class media_codes
{
	public const TAGS = array('ram' => 'PLUSMEDIAAUDIO', 'stream' => 'PLUSMEDIAAUDIO', 'video' => 'PLUSMEDIAVIDEO');

	public static function configure($configurator)
	{
		foreach (self::TAGS as $name => $tag_name)
		{
			// The board's own BBCode wins for new posts. A stored Plus tag keeps its renderer.
			if (!isset($configurator->BBCodes[strtoupper($name)]))
			{
				$bbcode = $configurator->BBCodes->add(strtoupper($name));
				$bbcode->tagName = $tag_name;
				$bbcode->contentAttributes->add('src');
				$bbcode->forceLookahead = true;
			}
			if (isset($configurator->tags[$tag_name])) { continue; }
			$tag = $configurator->tags->add($tag_name);
			$src = $tag->attributes->add('src');
			$src->filterChain->append($configurator->attributeFilters->get('#regexp'))->setRegexp('~^https?://[^\s<>"\'\[\]\\\\]+$~iD');
			$src->filterChain->append($configurator->attributeFilters->get('#url'));
			$element = $name === 'video' ? 'video' : 'audio';
			$dimensions = '';
			if ($name === 'video')
			{
				foreach (array('width', 'height') as $attribute)
				{
					$tag->attributes->add($attribute)->filterChain->append($configurator->attributeFilters->get('#uint'));
					$dimensions .= ' ' . $attribute . '="{@' . $attribute . '}"';
				}
			}
			$tag->filterChain->add(__CLASS__ . '::opening')->resetParameters()->addParameterByName('tag')->addParameterByName('text');
			$tag->rules->ignoreTags();
			$tag->template = '<div style="text-align:center"><' . $element . ' controls="controls" preload="none" src="{@src}"' .
				$dimensions . ' style="max-width:100%"><a href="{@src}" rel="noopener noreferrer"><xsl:value-of select="@src"/></a></' . $element . '></div>';
		}
	}

	public static function opening($tag, $text)
	{
		$opening = substr($text, $tag->getPos(), $tag->getLen());
		if ($tag->getName() === self::TAGS['video'])
		{
			if (preg_match('~^\[video width=([0-6]?[0-9]?[0-9]) height=([0-4]?[0-9]?[0-9])\]$~iD', $opening)) { return; }
		}
		elseif (preg_match('~^\[(?:ram|stream)\]$~iD', $opening)) { return; }
		$tag->invalidate();
	}
}
