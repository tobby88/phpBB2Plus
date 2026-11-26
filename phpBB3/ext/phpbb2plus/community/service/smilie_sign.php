<?php
namespace phpbb2plus\community\service;

/** Native counterpart to the Smilie Creator Mod 1.0.3 by esperitox (2003). */
class smilie_sign
{
	public static function reset_formatter($container)
	{
		foreach (array('parser', 'renderer') as $part)
		{
			$container->get('cache.driver')->destroy($container->getParameter('text_formatter.cache.' . $part . '.key'));
		}
	}

	public static function faces()
	{
		static $faces = null;
		if ($faces === null)
		{
			$faces = array();
			$directory = dirname(__DIR__, 4) . '/smilie_creator/images/smilies/schild/';
			foreach (glob($directory . 'smilie*.png') ?: array() as $file)
			{
				if (preg_match('/^smilie([1-9][0-9]{0,8})\.png$/D', basename($file), $match) && is_file($file))
				{
					$faces[] = (int) $match[1];
				}
			}
			sort($faces, SORT_NUMERIC);
		}
		return $faces;
	}

	public static function face($value)
	{
		$value = strtolower((string) $value);
		if ($value === 'random') { return $value; }
		return preg_match('/^[1-9][0-9]{0,8}$/D', $value) ? $value : '1';
	}

	public static function colour($value)
	{
		$value = ltrim((string) $value, '#');
		return preg_match('/^[a-f0-9]{6}$/Di', $value) ? strtolower($value) : false;
	}

	public static function shade($value) { return (string) $value === '0' ? '0' : '1'; }

	public static function caption($text)
	{
		$text = (string) $text;
		if (strlen($text) > 8192)
		{
			// The finite input window can end in the middle of a UTF-8 character.
			$text = html_entity_decode(htmlspecialchars(substr($text, 0, 8192), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_NOQUOTES, 'UTF-8');
		}
		$text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text);
		$text = preg_replace('/[\s\p{Z}]+/u', ' ', $text);
		return is_string($text) && preg_match('/\A.{1,512}/us', trim($text), $part) ? $part[0] : '';
	}

	public static function configure($configurator)
	{
		// Let an existing administrator-defined BBCode keep its native meaning.
		if (!isset($configurator->BBCodes['SCHILD']))
		{
			$bbcode = $configurator->BBCodes->add('SCHILD');
			$bbcode->tagName = 'PLUSSMILIESIGN';
			$bbcode->defaultAttribute = 'face';
			$bbcode->contentAttributes[] = 'caption';
		}
		$tag = $configurator->tags->add('PLUSSMILIESIGN');
		$tag->attributes->add('face')->filterChain->append(__CLASS__ . '::face');
		$tag->attributes->add('caption')->filterChain->append(__CLASS__ . '::caption');
		foreach (array('fontcolor', 'shadowcolor', 'shieldshadow') as $option)
		{
			$field = $tag->attributes->add($option);
			$field->required = false;
			$field->filterChain->append(__CLASS__ . ($option === 'shieldshadow' ? '::shade' : '::colour'));
		}
		$tag->tagLimit = 64;
		$tag->nestingLimit = 1;
		$tag->rules->ignoreTags();
		$tag->rules->disableAutoLineBreaks();
		$tag->template = '<xsl:choose><xsl:when test="$S_VIEWIMG and $S_VIEWSMILIES and $PLUS_SIGN_ASSETS != \'\'">
			<span class="plus-smilie-sign" role="img" aria-label="{@caption}">
				<span aria-hidden="true"><xsl:attribute name="class">plus-sign-board<xsl:if test="@shieldshadow != 0 or not(@shieldshadow)"> plus-sign-shaded</xsl:if></xsl:attribute>
				<xsl:attribute name="style"><xsl:if test="@fontcolor">color:#<xsl:value-of select="@fontcolor"/>;</xsl:if><xsl:if test="@shadowcolor">text-shadow:1px 1px #<xsl:value-of select="@shadowcolor"/>;</xsl:if></xsl:attribute>
				<xsl:value-of select="@caption"/></span>
				<img class="plus-sign-face" src="{$PLUS_SIGN_ASSETS}smilie{@face}.png" width="23" height="23" alt="" loading="lazy"/>
			</span></xsl:when><xsl:otherwise><xsl:value-of select="@caption"/></xsl:otherwise></xsl:choose>';
	}
}
