<?php

function arcade_score_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Arcade score safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$compat = file_get_contents($root . '/phpBB2/includes/php_compat.php');
$newscore = file_get_contents($root . '/phpBB2/newscore.php');
$ibpro = file_get_contents($root . '/phpBB2/IBProArcade.php');
$pnflash = file_get_contents($root . '/phpBB2/pnFlashGames.php');
$functions = file_get_contents($root . '/phpBB2/includes/functions_arcade.php');

arcade_score_assert(strpos($compat, 'function phpbb_request_source_is_same_origin') !== false, 'legacy GET-capable protocols need a strict request-source check');
foreach (array('newscore' => $newscore, 'IBProArcade' => $ibpro, 'pnFlashGames' => $pnflash) as $name => $source)
{
	arcade_score_assert(strpos($source, 'phpbb_request_source_is_same_origin()') !== false, $name . ' must reject browser-declared cross-site requests');
}
arcade_score_assert(strpos($ibpro, 'mysqli_real_escape_string') === false, 'IBProArcade must not bypass the active database driver');
arcade_score_assert(strpos($ibpro, '$log = $db->sql_escape(') !== false, 'IBProArcade logs must use database-driver escaping');
arcade_score_assert(strpos($ibpro, 'phpbb_random_bytes(2)') !== false, 'IBPro score challenges must use the compatibility-safe random source');
arcade_score_assert(strpos($functions, '$privmsg_subject_sql = $db->sql_escape($privmsg_subject)') !== false, 'Arcade PM notifications must use database-driver escaping');
arcade_score_assert(strpos($functions, "'USERNAME' => \$to_userdata['username']") !== false, 'Arcade PM emails must use the loaded recipient name');
arcade_score_assert(strpos($functions, "is_dir(\$phpbb_root_path . 'language/lang_' . \$to_userdata['user_lang'])") !== false, 'Arcade PM language templates must stay inside an installed language directory');

echo "Arcade score safety tests passed.\n";
