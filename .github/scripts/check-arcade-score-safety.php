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
$classes = file_get_contents($root . '/phpBB2/includes/classes_arcade.php');
$monthly = file_get_contents($root . '/phpBB2/arcade_highscore.php');
$points = file_get_contents($root . '/phpBB2/arcade_point_scores.php');
$schema = file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql');

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
arcade_score_assert(strpos($newscore, 'highscore_user_id, highscore_player') !== false && strpos($newscore, 'SET highscore_user_id = ') !== false, 'legacy score submissions must persist the monthly-highscore owner');
arcade_score_assert(strpos($classes, 'highscore_user_id, highscore_player') !== false && strpos($classes, 'SET highscore_user_id = ') !== false, 'class-based score submissions must persist the monthly-highscore owner');
arcade_score_assert(strpos($monthly, 'LEFT JOIN " . USERS_TABLE . " u ON u.user_id = h.highscore_user_id') !== false, 'monthly displays must prefer the authoritative username');
arcade_score_assert(strpos($points, "\$player_key = 'u:' . (int) \$row['player_id']") !== false, 'Arcade point totals must aggregate renamed users by stable ID');
arcade_score_assert(strpos($schema, 'highscore_user_id mediumint(8) NOT NULL DEFAULT 0') !== false, 'fresh installs need stable monthly-highscore owners');

echo "Arcade score safety tests passed.\n";
