<?php
namespace phpbb2plus\arcade\migrations;

class v101_play extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\arcade\migrations\v100_catalog'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_arc_';
		return array('add_columns' => array($p . 'games' => array('asset_status' => array('VCHAR:16', 'missing'), 'main_asset' => array('VCHAR_UNI', ''))),
			'add_tables' => array($p . 'assets' => array('COLUMNS' => array('game_id' => array('INT:8', 0), 'path_key' => array('CHAR:64', ''),
				'relative_name' => array('TEXT_UNI', ''), 'storage_name' => array('VCHAR:80', ''), 'mime_type' => array('VCHAR:80', ''),
				'bytes' => array('BINT', 0), 'legacy_checksum' => array('CHAR:32', '')), 'PRIMARY_KEY' => array('game_id','path_key')),
			$p . 'launches' => array('COLUMNS' => array('token_key' => array('CHAR:64', ''), 'session_key' => array('CHAR:64', ''),
				'user_id' => array('UINT', 0), 'game_id' => array('INT:8', 0), 'started_at' => array('TIMESTAMP', 0), 'expires_at' => array('TIMESTAMP', 0),
				'finished_at' => array('TIMESTAMP', 0), 'score_units' => array('BINT', 0), 'tour_id' => array('UINT', 0),
				'rand_one' => array('USINT', 0), 'rand_two' => array('USINT', 0)), 'PRIMARY_KEY' => 'token_key',
				'KEYS' => array('arc_expiry' => array('INDEX', 'expires_at'), 'arc_session' => array('INDEX', array('session_key','started_at')))),
			$p . 'state' => array('COLUMNS' => array('state_id' => array('USINT', 0)), 'PRIMARY_KEY' => 'state_id')));
	}

	public function update_data()
	{
		return array(array('config.add', array('plus_arc_media_key', bin2hex(random_bytes(32)))),
			array('custom', array(array($this, 'coordinator'))));
	}

	public function coordinator()
	{
		$table = $this->table_prefix . 'plus_arc_state';
		$result = $this->db->sql_query("SELECT state_id FROM $table WHERE state_id=1");
		$found = $this->db->sql_fetchfield('state_id');
		$this->db->sql_freeresult($result);
		if (!$found) { $this->db->sql_query("INSERT INTO $table (state_id) VALUES (1)"); }
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'plus_arc_games' => array('asset_status','main_asset')),
			'drop_tables' => array_keys($this->update_schema()['add_tables']));
	}
}
