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

$text = phpbb_bbcode_safe_text('&lt;img src=x onerror=alert(1)&gt;');
bbcode_safety_assert(strpos($text, '<img') === false, 'stored entities must not become active markup');
bbcode_safety_assert(strpos($text, '&lt;img') !== false, 'safe visible text must be preserved');

$balanced_quote = phpbb_bbcode_balance_quotes('before[quote:abc]inside', 'abc');
bbcode_safety_assert($balanced_quote === 'before[quote:abc]inside[/quote:abc]', 'an incomplete legacy quote must receive its missing closing tag');
$balanced_nested_quotes = phpbb_bbcode_balance_quotes('[quote:abc="User"][quote:abc]inside[/quote:abc]', 'abc');
bbcode_safety_assert(substr_count($balanced_nested_quotes, '[/quote:abc]') === 2, 'nested legacy quotes must be balanced independently');
$complete_quote = '[quote:abc]inside[/quote:abc]';
bbcode_safety_assert(phpbb_bbcode_balance_quotes($complete_quote, 'abc') === $complete_quote, 'complete quotes must remain unchanged');

$shield = phpbb_schild('1', 'fontcolor=ff0000 shadowcolor=00ff00 shieldshadow=0', 'Gr&uuml;&szlig;e & mehr');
bbcode_safety_assert(strpos($shield, 'fontcolor=ff0000') !== false, 'valid shield colors must remain supported');
bbcode_safety_assert(strpos($shield, 'shadowcolor=00ff00') !== false, 'valid shield shadow colors must remain supported');
bbcode_safety_assert(strpos($shield, 'shieldshadow=0') !== false, 'disabled shield shadows must remain supported');
bbcode_safety_assert(strpos($shield, '&amp;') !== false && strpos($shield, 'Gr%C3%BC%C3%9Fe%20%26%20mehr') !== false, 'shield query strings must be HTML-safe UTF-8');

$malicious_shield = phpbb_schild('1', 'fontcolor=red&onerror=x shadowcolor=javascript:alert(1)', 'x');
bbcode_safety_assert(strpos($malicious_shield, 'onerror') === false && strpos($malicious_shield, 'javascript') === false, 'invalid shield parameters must be discarded');

bbcode_safety_assert(phpbb_bbcode_safe_font('Arial, sans-serif') === 'Arial, sans-serif', 'normal font families must remain supported');
bbcode_safety_assert(phpbb_bbcode_safe_font('Arial; background:url(javascript:alert(1))') === 'sans-serif', 'font declarations must not inject CSS');

$style = phpbb_bbcode_safe_style('width: 80%; text-align: center; background-image: url(javascript:alert(1)); color: #abc');
bbcode_safety_assert(strpos($style, 'width: 80%') !== false, 'safe table width must remain supported');
bbcode_safety_assert(strpos($style, 'text-align: center') !== false, 'safe text alignment must remain supported');
bbcode_safety_assert(strpos($style, 'color: #abc') !== false, 'safe colors must remain supported');
bbcode_safety_assert(stripos($style, 'url') === false && stripos($style, 'background-image') === false, 'active CSS must be removed');

echo "BBCode safety tests passed.\n";
