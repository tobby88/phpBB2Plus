<?php
namespace phpbb2plus\arcade\migrations;

class v118_game_names extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v117_local_import'); }

	public function update_schema()
	{
		$p=$this->table_prefix.'plus_arc_';
		return array('add_columns'=>array($p.'games'=>array('protocol_name'=>array('VCHAR:50','')),
				$p.'launches'=>array('start_name'=>array('VCHAR:50',''))),
			'add_index'=>array($p.'games'=>array('arc_proto_name'=>array('protocol_name')),
				$p.'launches'=>array('arc_start_name'=>array('start_name','expires_at'))));
	}

	public function revert_schema()
	{
		$p=$this->table_prefix.'plus_arc_';
		$renamed=false;
		if ($this->db_tools->sql_column_exists($p.'games','protocol_name'))
		{
			$result=$this->db->sql_query_limit("SELECT game_id FROM {$p}games WHERE protocol_name<>''",1);
			$renamed=(bool)$this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
		}
		return $renamed ? array() : array('drop_keys'=>array($p.'games'=>array('arc_proto_name'),$p.'launches'=>array('arc_start_name')),
			'drop_columns'=>array($p.'games'=>array('protocol_name'),$p.'launches'=>array('start_name')));
	}
}
