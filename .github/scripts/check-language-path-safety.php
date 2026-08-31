<?php

function language_path_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Language path safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$functions = file_get_contents($root . '/phpBB2/includes/functions.php');
$common = file_get_contents($root . '/phpBB2/common.php');

language_path_assert(strpos($functions, 'function phpbb_normalize_language(') !== false, 'language paths need one shared validator');
language_path_assert(strpos($functions, "preg_match('/^[a-z0-9_-]{1,30}$/D', \$candidate)") !== false, 'language directory names need a strict allowlist');
language_path_assert(strpos($functions, "is_file(\$phpbb_root_path . 'language/lang_' . \$candidate . '/lang_main.' . \$phpEx)") !== false, 'language selections must resolve to an installed pack');
language_path_assert(strpos($functions, 'WHERE user_id = " . intval($userdata[\'user_id\'])') !== false, 'repairs must affect only the current account');
language_path_assert(strpos($functions, "WHERE user_lang = '") === false, 'repairs must not interpolate an old language value into SQL');
language_path_assert(strpos($common, '$board_config[\'default_lang\'] = phpbb_normalize_language(') !== false, 'board language must be normalized before extension bootstrap');
language_path_assert(strpos($common, "include(\$phpbb_root_path . 'attach_mod/attachment_mod.'") > strpos($common, '$board_config[\'default_lang\'] = phpbb_normalize_language('), 'attachment bootstrap must see only the normalized board language');

echo "Language path safety tests passed.\n";
