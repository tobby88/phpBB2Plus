<?php
namespace phpbb2plus\arcade\migrations;

use phpbb2plus\arcade\service\activity;

class v104_community extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v103_wallets'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_arc_';
		return array('add_columns'=>array($p . 'favorites'=>array('target_game'=>array('UINT',0)),
			$p . 'ratings'=>array('target_game'=>array('UINT',0))),
			'add_index'=>array($p . 'favorites'=>array('fav_game_owner'=>array('target_game','owner_id')),
				$p . 'ratings'=>array('vote_game_owner'=>array('target_game','owner_id'))));
	}

	public function revert_schema()
	{
		$p = $this->table_prefix . 'plus_arc_';
		return array('drop_keys'=>array($p . 'favorites'=>array('fav_game_owner'),$p . 'ratings'=>array('vote_game_owner')),
			'drop_columns'=>array($p . 'favorites'=>array('target_game'),$p . 'ratings'=>array('target_game')));
	}

	public function update_data() { return array(array('custom',array(array($this,'link_games')))); }
	public function link_games() { activity::link_games($this->db,$this->table_prefix . 'plus_arc_'); }
}
