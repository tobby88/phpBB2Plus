<?php
namespace phpbb2plus\community\migrations;

class v138_login_administration extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return array('\phpbb2plus\community\migrations\v137_account_logins', '\phpbb2plus\community\migrations\v121_manage_delegations');
	}

	public function update_data()
	{
		return array(array('permission.add', array('a_plus_ct_settings')),
			array('module.add', array('acp', 'ACP_SERVER_CONFIGURATION', array(
				'module_basename' => '\phpbb2plus\community\acp\tracker_settings_module', 'modes' => array('manage')))));
	}
}
