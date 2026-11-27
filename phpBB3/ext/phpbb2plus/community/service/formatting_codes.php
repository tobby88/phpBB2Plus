<?php
namespace phpbb2plus\community\service;

/** Original Plus text styles expressed as native, administrator-editable BBCodes. */
class formatting_codes
{
	public static function definitions()
	{
		return self::text_styles() + self::scrolling();
	}

	public static function text_styles()
	{
		return array(
			's' => array('[s]{TEXT}[/s]', '<s>{TEXT}</s>', '[s]ABC[/s]'),
			'center' => array('[center]{TEXT}[/center]', '<div style="text-align:center">{TEXT}</div>', '[center]ABC[/center]'),
			'align' => array('[align={CHOICE=left,right,center,justify}]{TEXT}[/align]', '<div style="text-align:{CHOICE}">{TEXT}</div>', '[align=right]ABC[/align]'),
			'font' => array('[font={FONTFAMILY}]{TEXT}[/font]', '<span style="font-family:{FONTFAMILY}">{TEXT}</span>', '[font=Georgia]ABC[/font]'),
			'highlight' => array('[highlight={COLOR}]{TEXT}[/highlight]', '<span style="background-color:{COLOR}">{TEXT}</span>', '[highlight=yellow]ABC[/highlight]'),
			'hr' => array('[hr]', '<hr/>', '[hr]'),
			'poet' => array('[poet={TEXT1;optional}]{TEXT2}[/poet]', '<div class="bbcode-poem" style="white-space:pre-wrap">{TEXT2}</div>', '[poet]ABC[/poet]'),
			'glow' => array('[glow={COLOR}]{TEXT}[/glow]', '<span style="text-shadow:0 0 4px {COLOR}">{TEXT}</span>', '[glow=blue]ABC[/glow]'),
			'shadow' => array('[shadow={COLOR}]{TEXT}[/shadow]', '<span style="text-shadow:1px 1px 2px {COLOR}">{TEXT}</span>', '[shadow=gray]ABC[/shadow]'),
			'fliph' => array('[fliph]{TEXT}[/fliph]', '<span style="display:inline-block;transform:scaleX(-1)">{TEXT}</span>', '[fliph]ABC[/fliph]'),
			'flipv' => array('[flipv]{TEXT}[/flipv]', '<span style="display:inline-block;transform:scaleY(-1)">{TEXT}</span>', '[flipv]ABC[/flipv]'),
			'fade' => array('[fade]{TEXT}[/fade]', '<span style="mask-image:linear-gradient(to right,black,transparent)">{TEXT}</span>', '[fade]ABC[/fade]'),
		);
	}

	public static function scrolling()
	{
		$codes = array();
		$frame = '<span class="plus-scroll plus-scroll-%s"><span class="plus-scroll-track"><span class="plus-scroll-text">{TEXT}</span></span></span>';
		foreach (array('left', 'right', 'up', 'down') as $direction)
		{
			$tag = 'scroll' . $direction;
			$codes[$tag] = array("[$tag]{TEXT}[/$tag]", sprintf($frame, $direction), "[$tag]ABC[/$tag]");
		}
		$codes['marq'] = array('[marq={CHOICE=left,right,up,down}]{TEXT}[/marq]', sprintf($frame, '{CHOICE}'), '[marq=left]ABC[/marq]');
		return $codes;
	}

	/** Boards that have exhausted phpBB's 1511 IDs can still render and edit these tags. */
	public static function overflow($configurator, $names)
	{
		if ($names === '') { return; }
		$definitions = self::definitions();
		foreach (explode(',', $names) as $name)
		{
			if (isset($definitions[$name]) && !isset($configurator->BBCodes[strtoupper($name)]))
			{
				list($usage, $template) = $definitions[$name];
				$configurator->BBCodes->addCustom($usage, $template);
			}
		}
	}
}
