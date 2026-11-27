<?php
namespace phpbb2plus\community\migrations;

class v181_profile_visits extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v110_portal'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'users' => array(
			'plus_visit_seconds' => array('BINT', 0),
			'plus_visit_logins' => array('BINT', 0),
			'plus_visit_pages' => array('BINT', 0),
			'plus_visit_until' => array('TIMESTAMP', 0),
		)));
	}

	public function update_data()
	{
		return array(
			array('permission.add', array('u_plus_profile_visits')),
			array('permission.permission_set', array('GLOBAL_MODERATORS', 'u_plus_profile_visits', 'group')),
			array('permission.permission_set', array('ADMINISTRATORS', 'u_plus_profile_visits', 'group')),
			array('config.add', array('plus_profile_visits_ready', 1)),
		);
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'users' => array(
			'plus_visit_seconds', 'plus_visit_logins', 'plus_visit_pages', 'plus_visit_until',
		)));
	}
}
