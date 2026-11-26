<?php
namespace phpbb2plus\community\migrations;

class v164_acp_registration extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v121_manage_delegations'); }

	public function update_data()
	{
		return array(array('permission.add', array('a_plus_user_register')),
			array('permission.permission_set', array('ROLE_ADMIN_FULL', 'a_plus_user_register')),
			array('module.add', array('acp', 'ACP_CAT_USERS', array('module_basename' => '\phpbb2plus\community\acp\registration_module',
				'modes' => array('create')))));
	}
}
