<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_arcade_owner($id)
{
	$native = plus_existing_author($id);
	return $native === ANONYMOUS ? 0 : $native;
}

function plus_arcade_grants()
{
	global $src_db, $db, $table_prefix, $convert;
	$p = $convert->src_table_prefix;
	\phpbb2plus\arcade\service\category_history::ensure_global($db, $table_prefix . 'plus_arc_');
	\phpbb2plus\arcade\service\activity::link_games($db,$table_prefix . 'plus_arc_');
	\phpbb2plus\arcade\service\activity::link_comments($db,$table_prefix . 'plus_arc_');
	$result = $src_db->sql_query("SELECT group_required FROM ".$p."ina_games WHERE group_required>0 UNION
		SELECT group_required FROM ".$p."ina_cat WHERE group_required>0 UNION
		SELECT config_value AS group_required FROM ".$p."ina_data WHERE config_name='games_group_required'");
	$groups = array();
	while ($row = $src_db->sql_fetchrow($result)) { if ((int) $row['group_required'] > 0) { $groups[] = (int) $row['group_required']; } }
	$src_db->sql_freeresult($result);
	$table = $table_prefix . 'plus_arc_grants';
	$db->sql_transaction('begin');
	$db->sql_query("DELETE FROM $table");
	foreach (array_chunk(array_unique($groups), 200) as $batch)
	{
		$result = $src_db->sql_query("SELECT g.group_id,g.group_single_user,ug.user_id FROM {$p}groups g
			LEFT JOIN {$p}user_group ug ON ug.group_id=g.group_id AND g.group_single_user=1 AND ug.user_pending=0
			WHERE " . $src_db->sql_in_set('g.group_id', $batch));
		$grants = array();
		while ($row = $src_db->sql_fetchrow($result))
		{
			$single = (int) $row['group_single_user'];
			$id = $single ? plus_arcade_owner($row['user_id']) : (int) $row['group_id'];
			if ($id) { $grants[$row['group_id'] . ':' . $id] = array('legacy_group' => (int) $row['group_id'], 'subject_type' => $single ? 'u' : 'g', 'subject_id' => $id); }
		}
		$src_db->sql_freeresult($result);
		if ($grants) { $db->sql_multi_insert($table, array_values($grants)); }
	}
	$db->sql_transaction('commit');
}

function plus_arcade_tables()
{
	return array(
		'data' => array('settings', 'config_name',
			'config_name config_value',
			array()),
		'cat' => array('categories', 'cat_id',
			'cat_id mod_id cat_name cat_desc cat_icon special_play last_game last_player last_time group_required cat_type cat_parent cat_order total_games total_played',
			array('mod_id' => 'moderator_id', 'last_player' => 'last_user_id')),
		'games' => array('games', 'game_id',
			'game_id game_name game_path game_desc game_charge game_reward game_bonus game_use_gl game_flash game_show_score win_width win_height highscore_limit reverse_list played instructions game_avail allow_guest image_path cat_id at_highscore_limit at_game_bonus score_type game_autosize date_added rank_required level_required group_required game_control highscore_id at_highscore_id',
			array('highscore_id' => 'winner_id', 'at_highscore_id' => 'all_winner_id')),
		'scores' => array('scores', '',
			'game_name gameData player player_id score date player_ip time_taken',
			array('player_id' => 'owner_id')),
		'at_scores' => array('all_scores', '',
			'game_name player player_id player_name score date player_ip time_taken',
			array('player_id' => 'owner_id')),
		'highscore' => array('monthly', 'highscore_id',
			'highscore_id highscore_year highscore_mon highscore_game highscore_user_id highscore_player highscore_score highscore_date',
			array('highscore_user_id' => 'owner_id')),
		'comment' => array('comments', 'comment_id',
			'comment_id comment_game_name comment_user_id comment_username comment_user_ip comment_time comment_text comment_edit_time comment_edit_count comment_edit_user_id game_name',
			array('comment_user_id' => 'owner_id', 'comment_edit_user_id' => 'editor_id')),
		'rate' => array('ratings', '',
			'rate_game_name rate_user_id rate_user_ip rate_point',
			array('rate_user_id' => 'owner_id')),
		'fav' => array('favorites', '',
			'user_id fav_game_id fav_game_name',
			array('user_id' => 'owner_id')),
		'game_saves' => array('saves', '',
			'user_id game_id game_data saved_at',
			array('user_id' => 'owner_id')),
		'banned' => array('bans', '',
			'username user_id player_ip game score date',
			array('user_id' => 'owner_id')),
		'log' => array('log', 'record_no',
			'record_no user_id name value date',
			array('user_id' => 'owner_id')),
		'tour' => array('tours', 'tour_id',
			'tour_id tour_name tour_desc tour_max_players tour_player_turns block_plays tour_active start_id start_date end_date length end_type champion',
			array('start_id' => 'founder_id', 'champion' => 'winner_id')),
		'tour_data' => array('tour_games', '',
			'tour_id game_name top_score top_player',
			array('top_player' => 'winner_id')),
		'tour_invite' => array('invites', '',
			'tour_id user_id',
			array('user_id' => 'owner_id')),
		'tour_play' => array('tour_plays', '',
			'tour_id user_id last_played_time last_played_game gamedata',
			array('user_id' => 'owner_id')),
		'user_data' => array('players', '',
			'user_id last_played last_played_date first_places first_list second_places second_list third_places third_list last_won_date at_first_places at_first_list at_second_places at_second_list at_third_places at_third_list user_game_time',
			array('user_id' => 'owner_id')),
	);
}

function plus_arcade_copy_group($short, $column, $value)
{
	global $src_db, $db, $convert, $table_prefix;
	$source = plus_arcade_tables();
	list($target, $key, $columns, $owners) = $source[$short];
	$columns = explode(' ', $columns);
	if (!in_array($column, $columns, true)) { throw new \LogicException('Unknown Arcade import group.'); }
	$rename = array('gameData' => 'game_data', 'score' => 'score_units', 'highscore_score' => 'score_units',
		'top_score' => 'score_units', 'date' => 'recorded_at', 'length' => 'tour_length', 'value' => 'log_value');
	$names = array('scores' => array('player_id','player'), 'at_scores' => array('player_id','player'),
		'highscore' => array('highscore_user_id','highscore_player'), 'comment' => array('comment_user_id','comment_username'));
	$target = $table_prefix . 'plus_arc_' . $target;
	$table = $convert->src_table_prefix . 'ina_' . $short;
	// The standard mapping casts every SQL NULL to ''. Keep NULL distinct from zero,
	// and replace one complete group atomically so duplicate historical rows survive a retry.
	$match = static function ($driver) use ($column, $value) {
		return $column . ($value === null ? ' IS NULL' : '=' . $driver->_sql_validate_value($value));
	};
	$result = $src_db->sql_query('SELECT ' . implode(',', $columns) . " FROM $table WHERE " . $match($src_db) .
		($key ? ' ORDER BY ' . $key : ''));
	$db->sql_transaction('begin');
	try
	{
		if ($short === 'tour_play')
		{
			$db->sql_query('DELETE FROM ' . $table_prefix . "plus_arc_turns WHERE participant_id IN (SELECT row_id FROM $target WHERE " . $match($db) . ')');
		}
		$db->sql_query("DELETE FROM $target WHERE " . $match($db));
		$batch = array();
		while ($row = $src_db->sql_fetchrow($result))
		{
			$data = array();
			foreach ($columns as $field)
			{
				$dest = $rename[$field] ?? $field;
				$data[$dest] = $dest === 'score_units' ? \phpbb2plus\arcade\service\score::units($row[$field]) : $row[$field];
			}
			foreach ($owners as $field => $owner) { $data[$owner] = plus_arcade_owner($row[$field]); }
			// The native database connection uses three-byte UTF-8. Keep literal
			// entity text distinct from the NCRs needed for supplementary characters.
			if ($short === 'log' && preg_match('/[\xf0-\xf4]/',(string) $data['name'] . (string) $data['log_value']))
			{
				$data['text_encoded'] = 1;
				foreach (array('name','log_value') as $field)
				{
					if ($data[$field] !== null) { $data[$field] = utf8_encode_ucr(str_replace('&','&amp;',$data[$field])); }
				}
			}
			elseif ($short === 'log') { $data['text_encoded'] = 0; }
			if ($short === 'cat')
			{
				foreach (array('cat_name','cat_desc','cat_icon') as $field)
				{ if ($data[$field] !== null) { $data[$field] = utf8_encode_ucr($data[$field]); } }
			}
			if ($short === 'comment')
			{
				foreach (array('comment_text','comment_username') as $field)
				{ if ($data[$field] !== null) { $data[$field] = utf8_encode_ucr($data[$field]); } }
			}
			if (array_key_exists('game_data', $data) && in_array($short, array('scores','game_saves'), true) && $data['game_data'] !== null)
			{
				$data = array_replace($data, \phpbb2plus\arcade\service\saved_game::pack($data['game_data']));
			}
			elseif ($short === 'scores') { $data['save_encoded'] = 0; }
			if (isset($names[$short]))
			{
				list($id, $byline) = $names[$short];
				$data['author_removed'] = plus_removed_author($row[$id], $row[$byline]);
			}
			$batch[] = $data;
			if (count($batch) === 64) { $db->sql_multi_insert($target, $batch); $batch = array(); }
		}
		if ($batch) { $db->sql_multi_insert($target, $batch); }
		if ($short === 'tour_play')
		{
			do { $done = \phpbb2plus\arcade\service\tournament_turns::import_batch($db, $table_prefix . 'plus_arc_', $value); } while (!$done);
		}
		$db->sql_transaction('commit');
	}
	catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }
	finally { $src_db->sql_freeresult($result); }
}

function plus_arcade_schema($prefix, $truncate)
{
	// Source sessions and launch tokens expire on migration. Persistent rows retain their original identity.
	$groups = array('data' => 'config_name', 'cat' => 'cat_parent', 'games' => 'cat_id', 'scores' => 'player_id',
		'at_scores' => 'player_id', 'highscore' => 'highscore_year', 'comment' => 'comment_user_id', 'rate' => 'rate_user_id',
		'fav' => 'user_id', 'game_saves' => 'user_id', 'banned' => 'user_id', 'log' => 'user_id', 'tour' => 'start_id',
		'tour_data' => 'tour_id', 'tour_invite' => 'tour_id', 'tour_play' => 'tour_id', 'user_data' => 'user_id');
	$sections = array();
	foreach (plus_arcade_tables() as $short => $definition)
	{
		list($target, $key) = $definition;
		$target = $prefix . 'plus_arc_' . $target;
		$column = $groups[$short];
		$group = 'ina_' . $short . '.' . $column;
		$section = array('target' => $target, 'query_first' => array('target', $truncate . $target), 'group_by' => $group, 'order_by' => $group,
			array('', array($short, $column, $group), 'plus_arcade_copy_group'));
		if ($short === 'games')
		{
			$section['query_first'] = array(array('target', $truncate . $target), array('target', $truncate . $prefix . 'plus_arc_launches'));
		}
		elseif ($short === 'tour')
		{
			$section['query_first'] = array(array('target', $truncate . $target), array('target', $truncate . $prefix . 'plus_arc_proposals'),
				array('target', $truncate . $prefix . 'plus_arc_tour_notices'));
		}
		elseif ($short === 'tour_play')
		{
			$section['query_first'] = array(array('target', $truncate . $target), array('target', $truncate . $prefix . 'plus_arc_turns'));
		}
		if ($key && $short !== 'data') { $section['autoincrement'] = $key; }
		$sections[] = $section;
	}
	$table = $prefix . 'plus_arc_members';
	$sections[] = array('target' => $table, 'primary' => 'users.user_id', 'where' => 'users.user_id > 0',
		'query_first' => array('target', $truncate . $table), array('user_id','users.user_id','phpbb_user_id'),
		array('blocked','users.arcade_banned','intval'), array('block_pm','users.games_block_pm','intval'));
	$sections[] = array('target' => $prefix . 'plus_arc_assets', 'primary' => 'ina_games.game_id',
		'query_first' => array('target', $truncate . $prefix . 'plus_arc_assets'),
		array('',array('ina_games.game_id','ina_games.game_name','ina_games.game_path','ina_games.game_flash','ina_games.image_path'),'plus_arcade_media'));
	$sections[] = array('target' => $prefix . 'plus_arc_categories', 'primary' => 'ina_cat.cat_id', 'where' => "ina_cat.cat_id>0 AND ina_cat.cat_icon<>''",
		array('', array('ina_cat.cat_id', 'ina_cat.cat_icon'), 'plus_arcade_category_artwork'));
	return array_merge($sections, plus_arcade_wallet_schema($prefix, $truncate));
}
