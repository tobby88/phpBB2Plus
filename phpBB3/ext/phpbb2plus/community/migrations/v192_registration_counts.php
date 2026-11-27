<?php
namespace phpbb2plus\community\migrations;

class v192_registration_counts extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v178_daily_visitors'); }
	public function update_schema()
	{
		return array('add_index' => array($this->table_prefix . 'users' => array('plus_regdate' => array('user_regdate', 'user_type'))));
	}
	public function revert_schema()
	{
		return array('drop_keys' => array($this->table_prefix . 'users' => array('plus_regdate')));
	}
	public function update_data() { return array(array('config.add', array('plus_registration_counts', 0))); }
}
