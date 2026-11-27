<?php
namespace phpbb2plus\community\service;

/** Media brackets from the Plus post editor. */
class media_codes
{
	public const TAGS = array('ram' => 'PLUSMEDIAAUDIO', 'stream' => 'PLUSMEDIAAUDIO', 'video' => 'PLUSMEDIAVIDEO');
	public const IMAGES = array('left' => 'IMG', 'right' => 'IMG');

	public static function configure($configurator)
	{
		self::floating_images($configurator);
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

	private static function floating_images($configurator)
	{
		if (!isset($configurator->tags['IMG'])) { return; }
		// Both spellings are native IMG. Posting permissions, image limits, alt text,
		// URL filtering and the reader's image preference still belong to phpBB.
		foreach (self::IMAGES as $name => $image)
		{
			$name = strtoupper($name);
			if (isset($configurator->BBCodes[$name])) { continue; }
			$bbcode = $configurator->BBCodes->add($name);
			$bbcode->tagName = $image;
			$bbcode->contentAttributes->add('src');
			$bbcode->forceLookahead = true;
		}
		$img = $configurator->tags['IMG'];
		if (isset($img->attributes['plusfloat'])) { return; }
		$side = $img->attributes->add('plusfloat');
		$side->required = false;
		$side->filterChain->append($configurator->attributeFilters->get('#regexp'))->setRegexp('~^(?:left|right)$~D');
		$img->filterChain->add(__CLASS__ . '::image_side')->resetParameters()->addParameterByName('tag')->addParameterByName('text');
		$native = (string) $img->template;
		$img->template = '<xsl:choose><xsl:when test="$S_VIEWIMG and (@plusfloat=\'left\' or @plusfloat=\'right\')">' .
			'<span><xsl:attribute name="style"><xsl:choose><xsl:when test="@plusfloat=\'left\'">float:left</xsl:when>' .
			'<xsl:otherwise>float:right</xsl:otherwise></xsl:choose>;max-width:100%</xsl:attribute>' .
			$native . '</span></xsl:when><xsl:otherwise>' . $native . '</xsl:otherwise></xsl:choose>';
	}

	public static function image_side($tag, $text)
	{
		$tag->removeAttribute('plusfloat');
		$opening = substr($text, $tag->getPos(), $tag->getLen());
		if (!preg_match('~^\[(left|right)(?=[\]\s=])~i', $opening, $matches)) { return; }
		$name = strtolower($matches[1]);
		if (strtolower($opening) !== '[' . $name . ']' || !$tag->hasAttribute('src') ||
			!preg_match('~^(?:https?|ftps?)://[^\s?&#"\'<>\[\]\\\\]+\.(?:jpe?g|gif|png)$~iD', $tag->getAttribute('src')))
		{
			$tag->invalidate();
			return;
		}
		$tag->setAttribute('plusfloat', $name);
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
