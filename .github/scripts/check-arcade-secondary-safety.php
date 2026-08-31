<?php

function arcade_secondary_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Arcade secondary safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$rate = file_get_contents($root . '/phpBB2/arcade_rate.php');
$comment = file_get_contents($root . '/phpBB2/arcade_comment.php');
$tournament = file_get_contents($root . '/phpBB2/arcade_tournament.php');
$activity = file_get_contents($root . '/phpBB2/activity.php');
$modcp = file_get_contents($root . '/phpBB2/arcade_modcp.php');
$classes = file_get_contents($root . '/phpBB2/includes/classes_arcade.php');
$functions = file_get_contents($root . '/phpBB2/includes/functions_arcade.php');
$constants = file_get_contents($root . '/phpBB2/includes/constants_arcade.php');
$schema = file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql');
$arcade_template = file_get_contents($root . '/phpBB2/templates/fisubsilversh/arcade_body.tpl');
$modcp_template = file_get_contents($root . '/phpBB2/templates/fisubsilversh/arcade_mod_body.tpl');

arcade_secondary_assert(strpos($rate, "!is_scalar(\$HTTP_POST_VARS['sid'])") !== false, 'ratings must reject nested tokens');
arcade_secondary_assert(substr_count($comment, "include(\$phpbb_root_path . 'includes/page_tail.'.\$phpEx);\n\texit;") >= 2, 'comment confirmation and edit pages must stop after their page tail');
arcade_secondary_assert(strpos($comment, 'min(1000000, intval(phpbb_request_scalar') !== false, 'comment offsets must be scalar and bounded');
arcade_secondary_assert(strpos($tournament, 'count($normalized_join_tours) >= 100') !== false, 'tournament join batches must be bounded');
arcade_secondary_assert(strpos($tournament, "is_scalar(\$HTTP_POST_VARS['tour_name'])") !== false, 'tournament text fields must be scalar');
arcade_secondary_assert(strpos($tournament, "is_scalar(\$HTTP_GET_VARS['tour_token'])") !== false, 'tournament play tokens must be scalar');
arcade_secondary_assert(strpos($classes, 'if (!is_finite($passed_var))') !== false, 'numeric Arcade inputs must reject non-finite values');
arcade_secondary_assert(strpos($classes, "substr(\$var . '=>' . (string) \$passed_var, 0, 1024)") !== false, 'Arcade request logging must be bounded');
arcade_secondary_assert(strpos($classes, "'at_first_list' => '', 'at_second_list' => '', 'at_third_list' => ''") !== false, 'missing Arcade user summaries need a complete fallback');
arcade_secondary_assert(strpos($activity, '$best_score = $best_at_score = \'\';') !== false, 'per-game display state must be initialized');
arcade_secondary_assert(strpos($activity, 'if (is_array($game_size) && isset($game_size[0], $game_size[1]))') !== false, 'failed legacy media probing needs configured dimension fallbacks');
arcade_secondary_assert(strpos($activity, "WHERE game_desc LIKE '%\$search_sql%'") !== false, 'Arcade search totals must use the SQL-escaped term');
arcade_secondary_assert(strpos($activity, "WHERE rate_game_name = '\$game_name_sql'") !== false, 'Arcade rating lookups must escape stored game names');
arcade_secondary_assert(substr_count($activity, "AND game_name = '\" . \$game_info_name_sql . \"'") === 2, 'Arcade summary score lookups must escape stored game names');
arcade_secondary_assert(strpos($activity, "isset(\$game_rows[0]['total_games'])") !== false, 'empty Arcade categories need stable totals');
arcade_secondary_assert(strpos($activity, "strlen(\$cat_rows[0]['game_desc'])") === false, 'the broken all-games description variable must not return');
arcade_secondary_assert(strpos($activity, "strtoupper((string) \$arcade->sort_order) === 'ASC'") !== false, 'Arcade SQL ordering must be allowlisted');
arcade_secondary_assert(strpos($activity, 'function arcade_external_url($value)') !== false, 'link categories need runtime URL validation');
arcade_secondary_assert(substr_count($activity, 'arcade_output_html(') >= 20, 'stored Arcade metadata must be escaped at output boundaries');
arcade_secondary_assert(substr_count($activity, "'ARCADE_USERNAME' => arcade_output_html(\$userdata['username'])") === 1, 'the default Arcade page needs an escaped server-side username');
arcade_secondary_assert(substr_count($activity, '"ARCADE_USERNAME" => arcade_output_html($userdata[\'username\'])') === 1, 'Arcade category pages need an escaped server-side username');
arcade_secondary_assert(strpos($activity, 'ina_send_user_pm(') === false, 'viewing another player statistics must remain side-effect free');
arcade_secondary_assert(strpos($activity, "\$score_names_sql[] = \"'\" . \$db->sql_escape(\$score_name) . \"'\"") !== false, 'statistics game-name lists must be SQL escaped');
arcade_secondary_assert(strpos($functions, "\$game_name_sql = \$db->sql_escape(\$rows[\$i]['game_name'])") !== false, 'highscore aggregation must escape stored game names');
arcade_secondary_assert(strpos($classes, "htmlspecialchars(\$this->categories[\$i]['cat_name'], ENT_QUOTES, 'UTF-8')") !== false, 'category jump labels must be HTML escaped');
arcade_secondary_assert(strpos($arcade_template, '{ARCADE_USERNAME}') !== false && strpos($arcade_template, 'document.write') === false, 'the Arcade welcome must not parse translated login text with JavaScript');
arcade_secondary_assert(substr_count($activity, '$cat_icon = phpbb_arcade_local_asset(') === 2, 'category icons must remain restricted to local assets in both views');
arcade_secondary_assert(substr_count($activity, '$image_path_html = arcade_output_html($image_path);') === 2, 'game image paths must be escaped in statistics and list views');
arcade_secondary_assert(strpos($functions, 'function ina_check_last_pm(') === false && strpos($constants, 'iNA_PMs_TABLE') === false && strpos($schema, 'phpbb_ina_pms') === false, 'the unused automatic-statistics-PM tracker must not return');
arcade_secondary_assert(strpos($modcp, 'network-tools.com') === false, 'score moderation must not disclose player addresses to a third-party lookup service');
arcade_secondary_assert(strpos($modcp, 'FILTER_VALIDATE_IP') !== false, 'score moderation must validate stored IP addresses before display');
arcade_secondary_assert(strpos($modcp, "array('', 'scores', 'at_scores', 'delete_score', 'delete_at_score')") !== false, 'score moderation actions must be allowlisted');
arcade_secondary_assert(strpos($modcp, 'ina_find_image(') !== false, 'moderator game images must use the local Arcade asset resolver');
arcade_secondary_assert(substr_count($modcp, 'AND g.cat_id = " . (int) $cat_id') === 2, 'both score views must remain confined to the moderated category');
arcade_secondary_assert(strpos($modcp, 'action=edit_score') === false && strpos($modcp, 'action=edit_at_score') === false, 'unimplemented score editing links must not return');
arcade_secondary_assert(strpos($modcp_template, 'game_edit_menu') === false && strpos($modcp_template, 'ALPHA RELEASE') === false, 'the moderator template must not expose the abandoned alpha game editor');

echo "Arcade secondary safety tests passed.\n";
