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
$admin_games = file_get_contents($root . '/phpBB2/admin/admin_arcade_games.php');
$admin_cache = file_get_contents($root . '/phpBB2/admin/admin_arcade_cache.php');
$admin_log = file_get_contents($root . '/phpBB2/admin/admin_arcade_log.php');
$admin_scores = file_get_contents($root . '/phpBB2/admin/admin_arcade_scores.php');
$admin_categories = file_get_contents($root . '/phpBB2/admin/admin_arcade_cats.php');
$category_template = file_get_contents($root . '/phpBB2/templates/subSilver/admin/arcade_cats_body.tpl');
$admin_tournaments = file_get_contents($root . '/phpBB2/admin/admin_arcade_tournaments.php');
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
arcade_position_assert(strpos($admin_games, '$write_requested') !== false && strpos($admin_games, 'phpbb_admin_require_post_session();') !== false, 'all Arcade game writes must verify the AdminCP token');
arcade_position_assert(substr_count($admin_games, 'phpbb_admin_session_field()') >= 4, 'edit, import, list and confirmation forms must carry the AdminCP token');
arcade_position_assert(strpos($admin_games, 'arcade_admin_rename_game_references') !== false, 'renaming a game must preserve dependent records');
arcade_position_assert(strpos($admin_games, 'arcade_admin_delete_game_references') !== false, 'deleting a game must remove or clear dependent records');
arcade_position_assert(strpos($admin_games, 'WHERE game_id = $old_id') !== false, 'game reordering must verify its source record');
arcade_position_assert(strpos($admin_games, "AND cat_type <> 'l'") !== false, 'imports must target a real playable category');
arcade_position_assert(strpos($admin_games, '$admin_page_size = max(0, (int)') !== false, 'Arcade pagination limits must be numeric');
arcade_position_assert(!is_file($root . '/phpBB2/admin/admin_arcade_ban.php'), 'the empty, unregistered Arcade ban stub must remain removed');
arcade_position_assert(strpos($admin_cache, "'S_CONFIG_ACTION' =>") !== false, 'the Arcade cache form must use the template action it actually renders');
arcade_position_assert(strpos($admin_log, 'phpbb_admin_require_post_session();') !== false && strpos($admin_log, 'phpbb_admin_session_field()') !== false, 'Arcade log deletion must use the central AdminCP token');
arcade_position_assert(strpos($admin_scores, '$allowed_modes') !== false, 'Arcade score actions must use an allowlist');
arcade_position_assert(strpos($admin_scores, 'phpbb_admin_require_post_session();') !== false, 'Arcade score writes must verify the central AdminCP token');
arcade_position_assert(strpos($admin_scores, "(int) \$score_info[\$i]['player_id']") !== false, 'score editing must bind the submitted player to the displayed row');
arcade_position_assert(substr_count($admin_scores, '$arcade->update_high($game_id);') >= 2, 'score edits and deletions must refresh highscore state');
arcade_position_assert(strpos($admin_categories, '$target_scope') !== false && strpos($admin_categories, 'WHERE cat_id = " . (int) $target_cat') !== false, 'category ordering must swap one adjacent category in the same hierarchy level');
arcade_position_assert(substr_count($admin_categories, "SET cat_type = 'p', cat_parent = 0") >= 2, 'category removal or demotion must promote child categories');
arcade_position_assert(strpos($admin_categories, '`group_required`') !== false, 'new categories must persist their group restriction');
arcade_position_assert(strpos($admin_categories, '@parse_url($desc)') !== false, 'link categories must validate their destination URL');
arcade_position_assert(strpos($admin_categories, 'phpbb_arcade_local_asset($icon_input)') !== false, 'category icons must stay on a relative local asset path');
arcade_position_assert(strpos($category_template, 'colspan="3"') !== false, 'category action header markup must be valid');
arcade_position_assert(strpos($admin_tournaments, "array('', 'add_tour', 'edit', 'add_games')") !== false, 'tournament views must use an allowlisted mode');
arcade_position_assert(strpos($admin_tournaments, 'phpbb_admin_require_post_session();') !== false && strpos($admin_tournaments, 'phpbb_admin_session_field()') !== false, 'tournament writes must use the central AdminCP token');
arcade_position_assert(strpos($admin_tournaments, 'arcade_tournament_require_token') === false, 'the duplicate tournament token implementation must not return');
arcade_position_assert(strpos($admin_tournaments, 'count($existing_games) >= $maximum_games') !== false, 'the configured per-tournament game limit must be enforced');
arcade_position_assert(strpos($admin_tournaments, "strtoupper((string) \$arcade->arcade_config['default_sort_order']) === 'ASC'") !== false, 'tournament game sorting must use a strict SQL direction');
arcade_position_assert(strpos($admin_tournaments, 'is_scalar($games_list[$i - 1])') !== false, 'tournament game IDs must reject nested input');

echo "Arcade position safety tests passed.\n";
