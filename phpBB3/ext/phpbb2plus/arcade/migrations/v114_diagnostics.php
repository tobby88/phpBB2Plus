<?php
namespace phpbb2plus\arcade\migrations;

class v114_diagnostics extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v113_category_administration'); }

	public function update_schema()
	{
		$table = $this->table_prefix . 'plus_arc_log';
		return array('add_columns'=>array($table=>array('text_encoded'=>array('BOOL',0))),
			'add_index'=>array($table=>array('log_type_id'=>array('name(12)','record_no'))));
	}

	public function revert_schema()
	{
		// Keep the encoding marker for imported texts still present on a later re-enable.
		$table = $this->table_prefix . 'plus_arc_log';
		$result = $this->db->sql_query_limit("SELECT record_no FROM $table WHERE text_encoded=1",1);
		$used = (bool) $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		$changes = array('drop_keys'=>array($table=>array('log_type_id')));
		if (!$used) { $changes['drop_columns'] = array($table=>array('text_encoded')); }
		return $changes;
	}

	public function update_data()
	{
		return array(array('permission.add',array('a_plus_arc_log',true,'a_plus_arcade')),
			array('module.add',array('acp','ACP_BOARD_CONFIGURATION',array('module_basename'=>'\phpbb2plus\arcade\acp\diagnostics_module',
				'modes'=>array('manage')))));
	}
}
