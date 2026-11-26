<?php
namespace phpbb2plus\community\migrations;

class v166_member_directory extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v121_manage_delegations'); }

	public function update_data()
	{
		return array(
			array('permission.add', array('a_plus_mail_list')),
			array('permission.permission_set', array('ROLE_ADMIN_FULL', 'a_plus_mail_list')),
			array('permission.add', array('a_plus_user_list')),
			array('permission.permission_set', array('ROLE_ADMIN_FULL', 'a_plus_user_list')),
			array('module.add', array('acp', 'ACP_CAT_USERS', array('module_basename' => '\phpbb2plus\community\acp\member_directory_module',
				'modes' => array('emails', 'members')))),
		);
	}
}
