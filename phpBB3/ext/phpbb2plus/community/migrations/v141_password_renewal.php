<?php
namespace phpbb2plus\community\migrations;

class v141_password_renewal extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v140_member_notice'); }

	public function update_schema()
	{
		return array('add_columns' => array(
			$this->table_prefix . 'users' => array('plus_pw_renewal_seen' => array('BOOL', 0)),
			$this->table_prefix . 'sessions' => array('plus_pw_login_notice' => array('BOOL', 0)),
		));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array(
			$this->table_prefix . 'users' => array('plus_pw_renewal_seen'),
			$this->table_prefix . 'sessions' => array('plus_pw_login_notice'),
		));
	}

	public function update_data()
	{
		// Earlier previews do not tell us whether the Plus administrator enabled this policy.
		return array(array('config.add', array('plus_pw_age_policy', 0)));
	}
}
