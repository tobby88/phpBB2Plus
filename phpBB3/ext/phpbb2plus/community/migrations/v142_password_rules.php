<?php
namespace phpbb2plus\community\migrations;

class v142_password_rules extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v141_password_renewal'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'users' => array('plus_pw_weak_hash' => array('VCHAR:64', ''))));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'users' => array('plus_pw_weak_hash')));
	}

	public function update_data()
	{
		return array(
			array('config.add', array('plus_pw_rules', 0)),
			array('config.add', array('plus_pw_min_bytes', 6)),
			array('config.add', array('plus_pw_no_name', 1)),
			array('config.add', array('plus_pw_letters_digits', 0)),
		);
	}
}
