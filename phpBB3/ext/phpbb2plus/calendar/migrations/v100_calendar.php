<?php
namespace phpbb2plus\calendar\migrations;

use phpbb2plus\calendar\service\settings;

class v100_calendar extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb\db\migration\data\v33x\v3319'); }

	public function update_schema()
	{
		$users = array();
		foreach (settings::MEMBER as $name => $default) { $users['plus_cal_' . $name] = array('USINT',$default); }
		return array('add_columns'=>array(
			$this->table_prefix . 'topics'=>array('plus_cal_start'=>array('BINT',0), 'plus_cal_end'=>array('BINT',0)),
			$this->table_prefix . 'forums'=>array('plus_cal_src_cat'=>array('UINT',0)), $this->table_prefix . 'users'=>$users),
			'add_index'=>array($this->table_prefix . 'topics'=>array('plus_cal_interval'=>array('plus_cal_start','plus_cal_end'))));
	}

	public function revert_schema()
	{
		$users = array();
		foreach (settings::MEMBER as $name => $default) { $users[] = 'plus_cal_' . $name; }
		return array('drop_keys'=>array($this->table_prefix . 'topics'=>array('plus_cal_interval')),
			'drop_columns'=>array($this->table_prefix . 'topics'=>array('plus_cal_start','plus_cal_end'),
			$this->table_prefix . 'forums'=>array('plus_cal_src_cat'),$this->table_prefix . 'users'=>$users));
	}

	public function update_data()
	{
		$changes = array();
		foreach (settings::MEMBER as $name => $default)
		{
			$changes[] = array('config.add',array('plus_cal_' . $name, $default));
			$changes[] = array('config.add',array('plus_cal_' . $name . '_over', 0));
		}
		foreach (settings::BOARD as $name => $default) { $changes[] = array('config.add',array('plus_cal_' . $name,$default)); }
		return $changes;
	}
}
