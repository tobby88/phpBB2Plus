<?php
namespace phpbb2plus\arcade\migrations;

use phpbb2plus\arcade\service\activity;

class v105_comments extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v104_community'); }

	public function update_schema()
	{
		$t = $this->table_prefix . 'plus_arc_comments';
		return array('add_columns'=>array($t=>array('target_game'=>array('UINT',0),'author_ip'=>array('VCHAR:45',''))),
			'add_index'=>array($t=>array('game_comment'=>array('target_game','comment_id'))),
			'add_tables'=>array($this->table_prefix . 'plus_arc_comment_receipts'=>array('COLUMNS'=>array(
				'owner_id'=>array('UINT',0),'target_game'=>array('UINT',0),'submit_key'=>array('CHAR:32',''),'comment_id'=>array('UINT',0)),
				'PRIMARY_KEY'=>array('owner_id','target_game','submit_key'))));
	}

	public function revert_schema()
	{
		$t = $this->table_prefix . 'plus_arc_comments';
		return array('drop_keys'=>array($t=>array('game_comment')),
			'drop_columns'=>array($t=>array('target_game','author_ip')),'drop_tables'=>array($this->table_prefix . 'plus_arc_comment_receipts'));
	}

	public function update_data() { return array(array('custom',array(array($this,'link_comments')))); }
	public function link_comments() { activity::link_comments($this->db,$this->table_prefix . 'plus_arc_'); }
}
