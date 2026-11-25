<?php
namespace phpbb2plus\community\migrations;

class v139_password_reminders extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v138_login_administration'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'users' => array(
			'plus_pw_notice_change' => array('TIMESTAMP', 0), 'plus_pw_native_origin' => array('TIMESTAMP', 0),
		)));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'users' => array('plus_pw_notice_change', 'plus_pw_native_origin')));
	}

	public function update_data()
	{
		return array(array('config.add', array('plus_pw_reminder', 0)), array('config.add', array('plus_pw_reminder_days', 30)));
	}
}
