<?php
namespace phpbb2plus\arcade\migrations;

class v117_local_import extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v116_game_creation'); }

	public function update_schema()
	{
		$table = $this->table_prefix . 'plus_arc_imports';
		return array('add_tables'=>array($table=>array('COLUMNS'=>array(
			'batch_key'=>array('CHAR:64',''),'label'=>array('VCHAR_UNI:1000',''),'kind'=>array('VCHAR:8',''),
			'settings_json'=>array('TEXT',''),'item_count'=>array('UINT',0),
			'scan_skipped'=>array('UINT',0),'position'=>array('UINT',0),'added'=>array('UINT',0),'prepared_at'=>array('TIMESTAMP',0)),
			'PRIMARY_KEY'=>'batch_key','KEYS'=>array('arc_batches'=>array('INDEX','prepared_at'))),
			$table . '_items'=>array('COLUMNS'=>array('batch_key'=>array('CHAR:64',''),'ordinal'=>array('UINT',0),
				'stage_key'=>array('CHAR:64',''),'source_name'=>array('TEXT',''),'width'=>array('USINT',0),'height'=>array('USINT',0)),
				'PRIMARY_KEY'=>array('batch_key','ordinal'))));
	}

	public function revert_schema()
	{
		$table = $this->table_prefix . 'plus_arc_imports';
		$result = $this->db->sql_query_limit("SELECT batch_key FROM $table",1);
		$used = (bool) $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $used ? array() : array('drop_tables'=>array($table . '_items',$table));
	}

	public function update_data()
	{
		return array(array('permission.add',array('a_plus_arc_import',true,'a_plus_arcade')),
			array('module.add',array('acp','ACP_BOARD_CONFIGURATION',array('module_basename'=>'\phpbb2plus\arcade\acp\import_game_module',
				'modes'=>array('manage')))));
	}
}
