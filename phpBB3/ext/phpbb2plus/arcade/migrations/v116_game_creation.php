<?php
namespace phpbb2plus\arcade\migrations;

class v116_game_creation extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v115_game_editor'); }

	public function update_schema()
	{
		return array('add_tables'=>array($this->table_prefix . 'plus_arc_staged'=>array('COLUMNS'=>array(
			'stage_key'=>array('CHAR:64',''),'game_name'=>array('VCHAR:50',''),'main_file'=>array('VCHAR_UNI',''),
			'icon_file'=>array('VCHAR_UNI',''),'manifest_json'=>array('MTEXT',''),'ready'=>array('BOOL',0),
			'game_id'=>array('INT:8',0),'prepared_at'=>array('TIMESTAMP',0)),
			'PRIMARY_KEY'=>'stage_key','KEYS'=>array('arc_available'=>array('INDEX',array('ready','game_id','game_name'))))));
	}

	public function revert_schema()
	{
		$table = $this->table_prefix . 'plus_arc_staged';
		$result = $this->db->sql_query_limit("SELECT stage_key FROM $table",1);
		$used = (bool) $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $used ? array() : array('drop_tables'=>array($table));
	}

	public function update_data()
	{
		return array(array('permission.add',array('a_plus_arc_add',true,'a_plus_arcade')),
			array('module.add',array('acp','ACP_BOARD_CONFIGURATION',array('module_basename'=>'\phpbb2plus\arcade\acp\add_game_module',
				'modes'=>array('manage')))));
	}
}
