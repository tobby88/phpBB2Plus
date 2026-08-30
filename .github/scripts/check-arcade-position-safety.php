<?php

function arcade_position_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Arcade position safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$functions = file_get_contents($root . '/phpBB2/includes/functions_arcade.php');
$english = file_get_contents($root . '/phpBB2/language/lang_english/lang_extend_arcade.php');
$german = file_get_contents($root . '/phpBB2/language/lang_german/lang_extend_arcade.php');

arcade_position_assert(strpos($functions, "isset(\$lang['games_position_text'])") !== false, 'positions must use the regular language array');
arcade_position_assert(strpos($functions, 'array_replace($default_position_text, $position_text)') !== false, 'incomplete translations need defaults');
arcade_position_assert(strpos($functions, '$games_place = max(0, (int) $games_place);') !== false, 'the submitted rank must be normalized');
arcade_position_assert(strpos($english, "\$lang['games_position_text'] = array") !== false, 'English position labels must survive scoped language loading');
arcade_position_assert(strpos($german, "\$lang['games_position_text'] = array") !== false, 'German position labels must survive scoped language loading');

echo "Arcade position safety tests passed.\n";
