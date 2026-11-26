<?php
namespace phpbb2plus\arcade\migrations;

class v115_game_editor extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v114_diagnostics'); }

	public function update_schema()
	{
		$table = $this->table_prefix . 'plus_arc_games';
		// phpBB represents supplementary characters as numeric character references.
		return array('change_columns'=>array($table=>array('game_desc'=>array('VCHAR_UNI:2550',null))),
			'add_columns'=>array($table=>array('native_group_id'=>array('UINT',0))));
	}

	public function revert_schema()
	{
		// Preserve wide titles and any live restriction. Older code also refuses
		// access to the negative legacy group sentinel used for native groups.
		$table = $this->table_prefix . 'plus_arc_games';
		$result = $this->db->sql_query_limit("SELECT game_id FROM $table WHERE native_group_id>0",1);
		$used = (bool) $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $used ? array() : array('drop_columns'=>array($table=>array('native_group_id')));
	}

	public function update_data()
	{
		return array(array('permission.add',array('a_plus_arc_games',true,'a_plus_arcade')),
			array('module.add',array('acp','ACP_BOARD_CONFIGURATION',array('module_basename'=>'\phpbb2plus\arcade\acp\games_module',
				'modes'=>array('manage')))));
	}
}
