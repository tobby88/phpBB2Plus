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
$tournament_join = file_get_contents($root . '/phpBB2/includes/functions_arcade_tournament_storage.php');
$activity = file_get_contents($root . '/phpBB2/activity.php');
$adminCategories = file_get_contents($root . '/phpBB2/admin/admin_arcade_cats.php');
$categoryWriter = file_get_contents($root . '/phpBB2/includes/functions_arcade_category_storage.php');
$modcp = file_get_contents($root . '/phpBB2/arcade_modcp.php');
$moderation_store = file_get_contents($root . '/phpBB2/includes/functions_arcade_moderation_storage.php');
$loader = file_get_contents($root . '/phpBB2/loader.php');
$classes = file_get_contents($root . '/phpBB2/includes/classes_arcade.php');
$functions = file_get_contents($root . '/phpBB2/includes/functions_arcade.php');
$constants = file_get_contents($root . '/phpBB2/includes/constants_arcade.php');
$schema = file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql');
$arcade_template = file_get_contents($root . '/phpBB2/templates/fisubsilversh/arcade_body.tpl');
$modcp_template = file_get_contents($root . '/phpBB2/templates/fisubsilversh/arcade_mod_body.tpl');

arcade_secondary_assert(strpos($rate, "!is_string(\$HTTP_POST_VARS['sid'])") !== false && strpos($rate, 'phpbb_arcade_rate_game($db, $userdata, $game_id,') !== false, 'ratings must reject nested/non-string tokens and use the owned current-actor writer');
arcade_secondary_assert(substr_count($comment, "include(\$phpbb_root_path . 'includes/page_tail.'.\$phpEx);\n\texit;") >= 2 && strpos($comment, "phpbb_arcade_comment_change(\$db, \$userdata,") !== false, 'comment confirmation/edit pages must terminate and writes must use current owned authority');
arcade_secondary_assert(strpos($comment, "'start'=>phpbb_request_scalar(") !== false && strpos($comment, "\$comment_context['page']['start']") !== false, 'comment offsets must use the scalar request and coherent bounded page reader');
arcade_secondary_assert(strpos($tournament, 'phpbb_arcade_tournament_join($db, $join_tour)') !== false &&
    strpos($tournament_join, 'count($ids)>100') !== false, 'tournament joins must use the bounded owned writer');
arcade_secondary_assert(strpos($tournament, "is_scalar(\$HTTP_POST_VARS['tour_name'])") !== false, 'tournament text fields must be scalar');
arcade_secondary_assert(strpos($tournament, "is_scalar(\$HTTP_GET_VARS['tour_token'])") !== false, 'tournament play tokens must be scalar');
arcade_secondary_assert(strpos($classes, 'if (!is_finite($passed_var))') !== false, 'numeric Arcade inputs must reject non-finite values');
arcade_secondary_assert(strpos($classes, 'substr(str_replace(array("\\r", "\\n", "\\0"), \'\', (string) $passed_var), 0, 512)') !== false &&
	strpos($classes, 'substr((string) $var, 0, 64)') !== false, 'Arcade request logging must be bounded and single-line');
arcade_secondary_assert(strpos($classes, "'at_first_list' => '', 'at_second_list' => '', 'at_third_list' => ''") !== false, 'missing Arcade user summaries need a complete fallback');
arcade_secondary_assert(strpos($activity, '$best_score = $best_at_score = \'\';') !== false, 'per-game display state must be initialized');
arcade_secondary_assert(strpos($activity, 'if (is_array($game_size) && isset($game_size[0], $game_size[1]))') !== false, 'failed legacy media probing needs configured dimension fallbacks');
$asset_gate = strpos($loader, '$asset_path = phpbb_arcade_local_asset(');
$accounting = strpos($loader, 'phpbb_arcade_record_launch($db, $game_info, $session)');
arcade_secondary_assert($asset_gate !== false && $accounting !== false && $asset_gate < $accounting, 'missing Arcade assets must be rejected before owned play accounting');
arcade_secondary_assert(strpos($functions, "ctracker_enforce_request_limit_profile(array('arcade-launch',300,'request_limit_content',60))") !== false && strpos($functions, 'PAGE_ARCADE_LAUNCH_PENDING') !== false, 'GET game launches need a dedicated request bucket before preparing their pending capability');
arcade_secondary_assert(strpos($activity, "WHERE game_desc LIKE '%\$search_sql%'") !== false, 'Arcade search totals must use the SQL-escaped term');
arcade_secondary_assert(strpos($activity, "WHERE rate_game_name = '\$game_name_sql'") !== false, 'Arcade rating lookups must escape stored game names');
arcade_secondary_assert(substr_count($activity, "AND game_name = '\" . \$game_info_name_sql . \"'") === 2, 'Arcade summary score lookups must escape stored game names');
arcade_secondary_assert(strpos($activity, "isset(\$game_rows[0]['total_games'])") !== false, 'empty Arcade categories need stable totals');
arcade_secondary_assert(strpos($activity, "strlen(\$cat_rows[0]['game_desc'])") === false, 'the broken all-games description variable must not return');
arcade_secondary_assert(strpos($activity, "strtoupper((string) \$arcade->sort_order) === 'ASC'") !== false, 'Arcade SQL ordering must be allowlisted');
arcade_secondary_assert(strpos($activity, 'function arcade_external_url($value)') !== false, 'link categories need runtime URL validation');
arcade_secondary_assert(strpos($activity, 'return phpbb_normalize_external_url($value);') !== false, 'runtime link categories must share the external URL boundary');
arcade_secondary_assert(strpos($adminCategories, 'phpbb_arcade_category_change($db,$HTTP_POST_VARS)') !== false && strpos($categoryWriter, 'phpbb_normalize_external_url($request[\'desc\'])') !== false, 'owned admin link categories must share the external URL boundary');
arcade_secondary_assert(substr_count($activity, 'arcade_output_html(') >= 20, 'stored Arcade metadata must be escaped at output boundaries');
arcade_secondary_assert(substr_count($activity, "'ARCADE_USERNAME' => phpbb_stored_text(\$userdata['username'])") === 1, 'the default Arcade page needs an escaped server-side username');
arcade_secondary_assert(substr_count($activity, '"ARCADE_USERNAME" => phpbb_stored_text($userdata[\'username\'])') === 1, 'Arcade category pages need an escaped server-side username');
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
arcade_secondary_assert(substr_count($modcp, 'phpbb_arcade_moderated_game($db, $game_id, $cat_id)') === 2 &&
    strpos($moderation_store, "\$game['cat_id'] === null") !== false &&
    strpos($moderation_store, '(string)$this->category_id') !== false, 'read views and owned deletions must remain confined to the moderated category');
arcade_secondary_assert(strpos($modcp, 'action=edit_score') === false && strpos($modcp, 'action=edit_at_score') === false, 'unimplemented score editing links must not return');
arcade_secondary_assert(strpos($modcp_template, 'game_edit_menu') === false && strpos($modcp_template, 'ALPHA RELEASE') === false, 'the moderator template must not expose the abandoned alpha game editor');

echo "Arcade secondary safety tests passed.\n";
