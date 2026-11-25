<?php
namespace phpbb2plus\arcade\migrations;

use phpbb2plus\arcade\service\tournament_turns;

class v106_tournament_turns extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v105_comments'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_arc_';
		return array('add_columns'=>array($p . 'tour_plays'=>array('turns_state'=>array('USINT', 0)),
			$p . 'launches'=>array('tour_participant'=>array('UINT:10', 0), 'tour_entry'=>array('UINT:10', 0))),
			'add_index'=>array($p . 'tour_plays'=>array('turn_import'=>array('turns_state','row_id'))),
			'add_tables'=>array($p . 'turns'=>array('COLUMNS'=>array('participant_id'=>array('UINT:10', 0),
				'name_key'=>array('CHAR:64',''), 'game_name'=>array('VCHAR:50',''), 'played'=>array('UINT:10',0), 'score_units'=>array('BINT', null)),
				'PRIMARY_KEY'=>array('participant_id','name_key'))));
	}

	public function revert_schema()
	{
		$p = $this->table_prefix . 'plus_arc_';
		return array('drop_keys'=>array($p . 'tour_plays'=>array('turn_import')), 'drop_columns'=>array($p . 'tour_plays'=>array('turns_state'),
			$p . 'launches'=>array('tour_participant','tour_entry')),
			'drop_tables'=>array($p . 'turns'));
	}

	public function update_data() { return array(array('custom', array(array($this, 'convert_turns')))); }

	public function convert_turns($processed = 0)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$done = tournament_turns::import_batch($this->db, $this->table_prefix . 'plus_arc_');
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
		return $done ? true : (int) $processed + 32;
	}
}
