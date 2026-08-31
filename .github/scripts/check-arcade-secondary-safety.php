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
$classes = file_get_contents($root . '/phpBB2/includes/classes_arcade.php');

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

echo "Arcade secondary safety tests passed.\n";
