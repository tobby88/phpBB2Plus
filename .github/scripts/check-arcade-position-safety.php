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
$admin_config = file_get_contents($root . '/phpBB2/admin/admin_arcade.php');
$config_template = file_get_contents($root . '/phpBB2/templates/subSilver/admin/arcade_config_body.tpl');

arcade_position_assert(strpos($functions, "isset(\$lang['games_position_text'])") !== false, 'positions must use the regular language array');
arcade_position_assert(strpos($functions, 'array_replace($default_position_text, $position_text)') !== false, 'incomplete translations need defaults');
arcade_position_assert(strpos($functions, '$games_place = max(0, (int) $games_place);') !== false, 'the submitted rank must be normalized');
arcade_position_assert(strpos($english, "\$lang['games_position_text'] = array") !== false, 'English position labels must survive scoped language loading');
arcade_position_assert(strpos($german, "\$lang['games_position_text'] = array") !== false, 'German position labels must survive scoped language loading');
arcade_position_assert(strpos($admin_config, 'phpbb_admin_require_post_session();') !== false, 'Arcade configuration writes must verify the AdminCP token');
arcade_position_assert(strpos($admin_config, "in_array(\$mode, array('', 'switches', 'moderators', 'messages'), true)") !== false, 'Arcade configuration modes must use an allowlist');
arcade_position_assert(strpos($admin_config, '$db->sql_escape($new[$config_name])') !== false, 'Arcade configuration values must use driver escaping');
arcade_position_assert(strpos($admin_config, 'Invalid Arcade asset directory.') !== false, 'Arcade asset directories must reject unsafe paths');
arcade_position_assert(strpos($admin_config, 'phpbb_admin_html($arcade->arcade_config[\'games_default_txt\'])') !== false, 'Arcade configuration text must be escaped in forms');
arcade_position_assert(strpos($admin_config, 'phpbb_admin_session_field()') !== false, 'Arcade configuration forms must carry a token');
arcade_position_assert(strpos($config_template, '{S_HIDDEN_FIELDS}') !== false, 'the main Arcade configuration template must render its hidden fields');
arcade_position_assert(strpos($config_template, '{S_HIDDEN_postS}') === false, 'the broken legacy hidden-field placeholder must not return');

echo "Arcade position safety tests passed.\n";
