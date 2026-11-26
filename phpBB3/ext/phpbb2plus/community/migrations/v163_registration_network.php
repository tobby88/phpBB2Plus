<?php
namespace phpbb2plus\community\migrations;

class v163_registration_network extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v121_manage_delegations'); }

	public function update_schema()
	{
		return array('add_columns' => array(USERS_TABLE => array('plus_reg_host' => array('VCHAR_UNI:255', ''))),
			'add_index' => array(USERS_TABLE => array('plus_reg_ip' => array('user_ip'))));
	}

	public function update_data()
	{
		return array(array('permission.add', array('a_plus_reg_network')),
			array('permission.permission_set', array('ROLE_ADMIN_FULL', 'a_plus_reg_network')),
			array('module.add', array('acp', 'ACP_CAT_USERS', array('module_basename' => '\phpbb2plus\community\acp\registration_network_module', 'modes' => array('view')))));
	}

	public function revert_schema()
	{
		return array('drop_keys' => array(USERS_TABLE => array('plus_reg_ip')), 'drop_columns' => array(USERS_TABLE => array('plus_reg_host')));
	}
}
