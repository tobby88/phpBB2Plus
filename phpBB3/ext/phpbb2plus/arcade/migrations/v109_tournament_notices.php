<?php
namespace phpbb2plus\arcade\migrations;

class v109_tournament_notices extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v108_tournament_control'); }

	public function update_schema()
	{
		return array('add_tables'=>array($this->table_prefix . 'plus_arc_tour_notices'=>array(
			'COLUMNS'=>array('tour_id'=>array('UINT:10',0), 'recipient_id'=>array('UINT',0), 'sender_id'=>array('UINT',0)),
			'PRIMARY_KEY'=>array('tour_id','recipient_id'), 'KEYS'=>array('recipient'=>array('INDEX','recipient_id'), 'sender'=>array('INDEX','sender_id')))));
	}

	public function revert_schema() { return array('drop_tables'=>array($this->table_prefix . 'plus_arc_tour_notices')); }
	public function update_data() { return array(array('config.add', array('plus_arc_notice_time', 0, true))); }
}
