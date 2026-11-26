<?php
namespace phpbb2plus\community\migrations;

class v149_structure_review extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\\phpbb2plus\\community\\migrations\\v148_recovered_topics'); }

	public function update_schema()
	{
		return array('add_index'=>array(
			$this->table_prefix . 'posts'=>array('plus_rehomed'=>array('plus_recovery_forum','post_id')),
			$this->table_prefix . 'topics'=>array('plus_rehomed'=>array('plus_recovery_reason','topic_id'))));
	}

	public function revert_schema()
	{
		return array('drop_keys'=>array($this->table_prefix . 'posts'=>array('plus_rehomed'),
			$this->table_prefix . 'topics'=>array('plus_rehomed')));
	}

	public function update_data()
	{
		return array(array('module.add', array('acp','ACP_CAT_DATABASE',array(
			'module_basename'=>'\\phpbb2plus\\community\\acp\\structure_module','modes'=>array('review')))));
	}
}
