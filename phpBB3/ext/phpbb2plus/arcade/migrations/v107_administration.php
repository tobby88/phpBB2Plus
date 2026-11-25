<?php
namespace phpbb2plus\arcade\migrations;

class v107_administration extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v106_tournament_turns'); }

	public function update_schema()
	{
		return array('add_tables'=>array($this->table_prefix . 'plus_arc_proposals'=>array('COLUMNS'=>array(
			'owner_id'=>array('UINT',0), 'submit_key'=>array('CHAR:32',''), 'tour_id'=>array('UINT:10',0), 'form_hash'=>array('CHAR:64','')),
			'PRIMARY_KEY'=>array('owner_id','submit_key'))));
	}

	public function revert_schema() { return array('drop_tables'=>array($this->table_prefix . 'plus_arc_proposals')); }

	public function update_data()
	{
		return array(array('module.add', array('acp', 'ACP_BOARD_CONFIGURATION', array(
			'module_basename'=>'\phpbb2plus\arcade\acp\settings_module', 'modes'=>array('settings')))));
	}
}
