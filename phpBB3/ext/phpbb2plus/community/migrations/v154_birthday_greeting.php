<?php
namespace phpbb2plus\community\migrations;

class v154_birthday_greeting extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb\db\migration\data\v330\v330'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'users' => array('plus_next_birthday' => array('USINT', 0))));
	}

	public function update_data() { return array(array('config.add', array('plus_birthday_greeting', 0))); }

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'users' => array('plus_next_birthday')));
	}
}
