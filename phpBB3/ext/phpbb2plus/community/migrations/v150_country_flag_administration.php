<?php
namespace phpbb2plus\community\migrations;

class v150_country_flag_administration extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return array('\phpbb2plus\community\migrations\v107_flags', '\phpbb2plus\community\migrations\v121_manage_delegations');
	}

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'plus_flags' => array('catalogue_state' => array('USINT', 0))));
	}

	public function update_data()
	{
		return array(array('config.add', array('plus_flag_revision', 0, true)), array('permission.add', array('a_plus_flags')),
			array('permission.permission_set', array('ROLE_ADMIN_FULL', 'a_plus_flags')),
			array('module.add', array('acp', 'ACP_CAT_USERS', array('module_basename' => '\phpbb2plus\community\acp\flags_module', 'modes' => array('manage')))));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'plus_flags' => array('catalogue_state')));
	}
}
