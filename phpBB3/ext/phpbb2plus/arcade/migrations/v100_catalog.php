<?php
namespace phpbb2plus\arcade\migrations;

class v100_catalog extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb\db\migration\data\v33x\v3319'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_arc_';
		// Keep source IDs alongside native ownership. Removed accounts and reused IDs must stay distinct.
		return array('add_tables' => array(
			$p . 'settings' => array('COLUMNS' => array(
				'config_name' => array('VCHAR:255', '0'), 'config_value' => array('VCHAR:255', '0'),
			), 'PRIMARY_KEY' => 'config_name'),
			$p . 'categories' => array('COLUMNS' => array(
				'cat_id' => array('INT:8', null, 'auto_increment'), 'mod_id' => array('INT:8', null),
				'cat_name' => array('VCHAR:100', null), 'cat_desc' => array('MTEXT_UNI', null),
				'cat_icon' => array('VCHAR:255', null), 'special_play' => array('INT:6', 0),
				'last_game' => array('VCHAR:50', null), 'last_player' => array('INT:8', null),
				'last_time' => array('INT:11', null), 'group_required' => array('INT:8', 0),
				'cat_type' => array('CHAR:1', 'p'), 'cat_parent' => array('INT:8', null),
				'cat_order' => array('UINT', 1), 'total_games' => array('UINT:10', 0),
				'total_played' => array('VCHAR:20', '0'), 'moderator_id' => array('UINT', 0),
				'last_user_id' => array('UINT', 0),
			), 'PRIMARY_KEY' => 'cat_id',
				'KEYS' => array('categor_1' => array('INDEX', array('cat_parent', 'cat_order')))),
			$p . 'games' => array('COLUMNS' => array(
				'game_id' => array('INT:8', null, 'auto_increment'), 'game_name' => array('VCHAR:50', null),
				'game_path' => array('VCHAR:255', null), 'game_desc' => array('VCHAR:255', null),
				'game_charge' => array('UINT:10', 0), 'game_reward' => array('UINT:10', 0),
				'game_bonus' => array('USINT', 0), 'game_use_gl' => array('INT:4', 0),
				'game_flash' => array('INT:4', 0), 'game_show_score' => array('INT:4', 1),
				'win_width' => array('INT:6', 0), 'win_height' => array('INT:6', 0),
				'highscore_limit' => array('VCHAR:255', null), 'reverse_list' => array('INT:4', 0),
				'played' => array('UINT:10', 0), 'instructions' => array('MTEXT_UNI', null),
				'game_avail' => array('INT:4', 1), 'allow_guest' => array('INT:4', 0),
				'image_path' => array('VCHAR:255', '.gif'), 'cat_id' => array('INT:8', -1),
				'at_highscore_limit' => array('INT:6', 0), 'at_game_bonus' => array('INT:6', 0),
				'score_type' => array('INT:6', null), 'game_autosize' => array('INT:6', null),
				'date_added' => array('INT:11', 0), 'rank_required' => array('INT:11', 0),
				'level_required' => array('INT:4', 0), 'group_required' => array('INT:8', 0),
				'game_control' => array('INT:11', 0), 'highscore_id' => array('INT:8', 0),
				'at_highscore_id' => array('INT:8', 0), 'winner_id' => array('UINT', 0),
				'all_winner_id' => array('UINT', 0),
			), 'PRIMARY_KEY' => 'game_id',
				'KEYS' => array('games_1' => array('INDEX', array('cat_id', 'game_avail')), 'games_2' => array('INDEX', array('game_name')))),
			$p . 'scores' => array('COLUMNS' => array(
				'row_id' => array('UINT:10', null, 'auto_increment'), 'game_name' => array('VCHAR:50', null),
				'game_data' => array('MTEXT_UNI', null), 'player' => array('VCHAR_UNI', null),
				'player_id' => array('INT:8', null), 'score_units' => array('BINT', 0),
				'recorded_at' => array('INT:11', null), 'player_ip' => array('VCHAR:16', '0'),
				'time_taken' => array('INT:11', null), 'owner_id' => array('UINT', 0),
				'author_removed' => array('BOOL', 0),
			), 'PRIMARY_KEY' => 'row_id',
				'KEYS' => array('scores_1' => array('INDEX', array('game_name', 'score_units')), 'scores_2' => array('INDEX', array('owner_id', 'game_name')))),
			$p . 'all_scores' => array('COLUMNS' => array(
				'row_id' => array('UINT:10', null, 'auto_increment'), 'game_name' => array('VCHAR:50', null),
				'player' => array('VCHAR_UNI', null), 'player_id' => array('INT:8', null),
				'player_name' => array('VCHAR_UNI', ''), 'score_units' => array('BINT', 0),
				'recorded_at' => array('INT:11', null), 'player_ip' => array('VCHAR:16', '0'),
				'time_taken' => array('INT:11', null), 'owner_id' => array('UINT', 0),
				'author_removed' => array('BOOL', 0),
			), 'PRIMARY_KEY' => 'row_id',
				'KEYS' => array('all_sco_1' => array('INDEX', array('game_name', 'score_units')), 'all_sco_2' => array('INDEX', array('owner_id', 'game_name')))),
			$p . 'monthly' => array('COLUMNS' => array(
				'highscore_id' => array('UINT', null, 'auto_increment'), 'highscore_year' => array('USINT', 0),
				'highscore_mon' => array('INT:4', 0), 'highscore_game' => array('VCHAR:50', ''),
				'highscore_user_id' => array('INT:8', 0), 'highscore_player' => array('VCHAR_UNI', ''),
				'score_units' => array('BINT', 0), 'highscore_date' => array('UINT:10', 0),
				'owner_id' => array('UINT', 0), 'author_removed' => array('BOOL', 0),
			), 'PRIMARY_KEY' => 'highscore_id',
				'KEYS' => array('monthly_1' => array('INDEX', array('highscore_game', 'highscore_year', 'highscore_mon')), 'monthly_2' => array('INDEX', array('owner_id')))),
			$p . 'comments' => array('COLUMNS' => array(
				'comment_id' => array('UINT:10', null, 'auto_increment'), 'comment_game_name' => array('VCHAR:50', null),
				'comment_user_id' => array('INT:8', 0), 'comment_username' => array('VCHAR_UNI', null),
				'comment_user_ip' => array('VCHAR:8', ''), 'comment_time' => array('UINT:10', 0),
				'comment_text' => array('MTEXT_UNI', null), 'comment_edit_time' => array('UINT:10', null),
				'comment_edit_count' => array('USINT', 0), 'comment_edit_user_id' => array('INT:8', null),
				'game_name' => array('VCHAR:50', null), 'owner_id' => array('UINT', 0),
				'editor_id' => array('UINT', 0), 'author_removed' => array('BOOL', 0),
			), 'PRIMARY_KEY' => 'comment_id',
				'KEYS' => array('comment_1' => array('INDEX', array('comment_game_name', 'comment_time')), 'comment_2' => array('INDEX', array('owner_id')))),
			$p . 'ratings' => array('COLUMNS' => array(
				'row_id' => array('UINT:10', null, 'auto_increment'), 'rate_game_name' => array('VCHAR:50', null),
				'rate_user_id' => array('INT:8', 0), 'rate_user_ip' => array('VCHAR:8', ''),
				'rate_point' => array('INT:4', 0), 'owner_id' => array('UINT', 0),
			), 'PRIMARY_KEY' => 'row_id',
				'KEYS' => array('ratings_1' => array('INDEX', array('rate_game_name', 'owner_id')))),
			$p . 'favorites' => array('COLUMNS' => array(
				'row_id' => array('UINT:10', null, 'auto_increment'), 'user_id' => array('INT:8', 0),
				'fav_game_id' => array('INT:8', null), 'fav_game_name' => array('VCHAR:50', null),
				'owner_id' => array('UINT', 0),
			), 'PRIMARY_KEY' => 'row_id',
				'KEYS' => array('favorit_1' => array('INDEX', array('owner_id', 'fav_game_id')))),
			$p . 'saves' => array('COLUMNS' => array(
				'row_id' => array('UINT:10', null, 'auto_increment'), 'user_id' => array('UINT', 0),
				'game_id' => array('INT:8', 0), 'game_data' => array('MTEXT_UNI', ''),
				'saved_at' => array('VCHAR:20', '0'), 'owner_id' => array('UINT', 0),
			), 'PRIMARY_KEY' => 'row_id',
				'KEYS' => array('saves_1' => array('INDEX', array('owner_id', 'game_id')))),
			$p . 'bans' => array('COLUMNS' => array(
				'row_id' => array('UINT:10', null, 'auto_increment'), 'username' => array('VCHAR:25', null),
				'user_id' => array('INT:8', null), 'player_ip' => array('VCHAR:16', null),
				'game' => array('VCHAR:50', null), 'score_units' => array('INT:8', null),
				'recorded_at' => array('VCHAR:16', null), 'owner_id' => array('UINT', 0),
			), 'PRIMARY_KEY' => 'row_id',
				'KEYS' => array('bans_1' => array('INDEX', array('owner_id')))),
			$p . 'log' => array('COLUMNS' => array(
				'record_no' => array('UINT', null, 'auto_increment'), 'user_id' => array('INT:8', -1),
				'name' => array('MTEXT_UNI', null), 'log_value' => array('MTEXT_UNI', null),
				'recorded_at' => array('INT:11', 0), 'owner_id' => array('UINT', 0),
			), 'PRIMARY_KEY' => 'record_no',
				'KEYS' => array('log_1' => array('INDEX', array('owner_id')))),
			$p . 'tours' => array('COLUMNS' => array(
				'tour_id' => array('INT:8', null, 'auto_increment'), 'tour_name' => array('VCHAR:25', null),
				'tour_desc' => array('MTEXT_UNI', null), 'tour_max_players' => array('INT:11', 0),
				'tour_player_turns' => array('INT:11', 0), 'block_plays' => array('INT:11', null),
				'tour_active' => array('INT:11', 0), 'start_id' => array('INT:8', 0),
				'start_date' => array('INT:11', 0), 'end_date' => array('INT:11', null),
				'tour_length' => array('INT:11', null), 'end_type' => array('INT:11', null),
				'champion' => array('INT:8', null), 'founder_id' => array('UINT', 0),
				'winner_id' => array('UINT', 0),
			), 'PRIMARY_KEY' => 'tour_id',
				'KEYS' => array('tours_1' => array('INDEX', array('tour_active', 'start_date')))),
			$p . 'tour_games' => array('COLUMNS' => array(
				'row_id' => array('UINT:10', null, 'auto_increment'), 'tour_id' => array('INT:8', 0),
				'game_name' => array('VCHAR:50', ''), 'score_units' => array('BINT', null),
				'top_player' => array('INT:8', null), 'winner_id' => array('UINT', 0),
			), 'PRIMARY_KEY' => 'row_id',
				'KEYS' => array('tour_ga_1' => array('INDEX', array('tour_id', 'game_name')))),
			$p . 'invites' => array('COLUMNS' => array(
				'row_id' => array('UINT:10', null, 'auto_increment'), 'tour_id' => array('INT:8', 0),
				'user_id' => array('INT:8', 0), 'owner_id' => array('UINT', 0),
			), 'PRIMARY_KEY' => 'row_id',
				'KEYS' => array('invites_1' => array('INDEX', array('tour_id', 'owner_id')))),
			$p . 'tour_plays' => array('COLUMNS' => array(
				'row_id' => array('UINT:10', null, 'auto_increment'), 'tour_id' => array('INT:8', 0),
				'user_id' => array('INT:8', 0), 'last_played_time' => array('INT:11', 0),
				'last_played_game' => array('VCHAR:50', null), 'gamedata' => array('MTEXT_UNI', null),
				'owner_id' => array('UINT', 0),
			), 'PRIMARY_KEY' => 'row_id',
				'KEYS' => array('tour_pl_1' => array('INDEX', array('tour_id', 'owner_id')))),
			$p . 'players' => array('COLUMNS' => array(
				'row_id' => array('UINT:10', null, 'auto_increment'), 'user_id' => array('INT:8', 0),
				'last_played' => array('VCHAR:50', null), 'last_played_date' => array('INT:11', null),
				'first_places' => array('INT:8', 0), 'first_list' => array('MTEXT_UNI', null),
				'second_places' => array('INT:8', null), 'second_list' => array('MTEXT_UNI', null),
				'third_places' => array('INT:8', null), 'third_list' => array('MTEXT_UNI', null),
				'last_won_date' => array('INT:11', null), 'at_first_places' => array('INT:8', 0),
				'at_first_list' => array('MTEXT_UNI', null), 'at_second_places' => array('INT:8', null),
				'at_second_list' => array('MTEXT_UNI', null), 'at_third_places' => array('INT:8', null),
				'at_third_list' => array('MTEXT_UNI', null), 'user_game_time' => array('INT:11', 0),
				'owner_id' => array('UINT', 0),
			), 'PRIMARY_KEY' => 'row_id',
				'KEYS' => array('players_1' => array('INDEX', array('owner_id')))),
			$p . 'grants' => array('COLUMNS' => array('legacy_group' => array('UINT', 0), 'subject_type' => array('CHAR:1', ''),
				'subject_id' => array('UINT', 0)), 'PRIMARY_KEY' => array('legacy_group','subject_type','subject_id')),
			$p . 'members' => array('COLUMNS' => array('user_id' => array('UINT', 0), 'blocked' => array('INT:4', 0),
				'block_pm' => array('BOOL', 1)), 'PRIMARY_KEY' => 'user_id'),
		));
	}

	public function update_data()
	{
		return array(array('permission.add', array('a_plus_arcade')), array('permission.add', array('m_plus_arcade', true, false)),
			array('permission.permission_set', array('ROLE_ADMIN_FULL','a_plus_arcade')),
			array('permission.permission_set', array('ROLE_ADMIN_STANDARD','a_plus_arcade')));
	}

	public function revert_schema() { return array('drop_tables' => array_keys($this->update_schema()['add_tables'])); }
}
