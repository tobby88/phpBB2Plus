<?php
namespace phpbb2plus\community\migrations;

class v148_recovered_topics extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\\phpbb2plus\\community\\migrations\\v147_recovered_forums'); }

	public function update_schema()
	{
		return array('add_columns'=>array(
			$this->table_prefix . 'posts'=>array('plus_recovery_topic'=>array('INT:11', -1), 'plus_recovery_forum'=>array('INT:11', -1)),
			$this->table_prefix . 'topics'=>array('plus_recovery_reason'=>array('VCHAR:16', ''), 'plus_recovery_from'=>array('UINT', 0),
				'plus_recovery_type'=>array('USINT', 0))),
			'add_index'=>array($this->table_prefix . 'posts'=>array('plus_lost_topic'=>array('plus_recovery_topic','topic_id'))));
	}

	public function revert_schema()
	{
		return array('drop_keys'=>array($this->table_prefix . 'posts'=>array('plus_lost_topic')),
			'drop_columns'=>array($this->table_prefix . 'posts'=>array('plus_recovery_topic','plus_recovery_forum'),
				$this->table_prefix . 'topics'=>array('plus_recovery_reason','plus_recovery_from','plus_recovery_type')));
	}
}
