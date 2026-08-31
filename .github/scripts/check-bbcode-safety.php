<?php

define('IN_PHPBB', true);
require dirname(dirname(__DIR__)) . '/phpBB2/includes/bbcode.php';

function bbcode_safety_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "BBCode safety test failed: $message\n");
		exit(1);
	}
}

$attribute = phpbb_bbcode_safe_attribute('title&quot; onmouseover=&quot;alert(1)');
bbcode_safety_assert(strpos($attribute, '"') === false, 'attribute quotes must be encoded');
bbcode_safety_assert(strpos($attribute, '&quot;') !== false, 'encoded quotes must remain inert');

bbcode_safety_assert(phpbb_bbcode_safe_font('Arial, sans-serif') === 'Arial, sans-serif', 'normal font families must remain supported');
bbcode_safety_assert(phpbb_bbcode_safe_font('Arial; background:url(javascript:alert(1))') === 'sans-serif', 'font declarations must not inject CSS');

$style = phpbb_bbcode_safe_style('width: 80%; text-align: center; background-image: url(javascript:alert(1)); color: #abc');
bbcode_safety_assert(strpos($style, 'width: 80%') !== false, 'safe table width must remain supported');
bbcode_safety_assert(strpos($style, 'text-align: center') !== false, 'safe text alignment must remain supported');
bbcode_safety_assert(strpos($style, 'color: #abc') !== false, 'safe colors must remain supported');
bbcode_safety_assert(stripos($style, 'url') === false && stripos($style, 'background-image') === false, 'active CSS must be removed');

echo "BBCode safety tests passed.\n";
