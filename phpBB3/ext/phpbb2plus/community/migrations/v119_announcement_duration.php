<?php
namespace phpbb2plus\community\migrations;

class v119_announcement_duration extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v118_conditional_read'); }
	public function update_schema()
	{
		$table = $this->table_prefix . 'topics';
		return array('add_columns'=>array($table=>array('plus_announce_days'=>array('INT:11',0))),
			'add_index'=>array($table=>array('plus_announce_type'=>array('topic_type','plus_announce_days'))));
	}
	public function revert_schema()
	{
		$table = $this->table_prefix . 'topics';
		return array('drop_keys'=>array($table=>array('plus_announce_type')),'drop_columns'=>array($table=>array('plus_announce_days')));
	}
	public function update_data()
	{
		return array(array('config.add',array('plus_announce_default',7)),array('config.add',array('plus_announce_sticky',0)),
			array('config.add',array('plus_announce_sweep',0,true)));
	}
}
