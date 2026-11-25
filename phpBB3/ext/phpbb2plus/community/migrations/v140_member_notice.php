<?php
namespace phpbb2plus\community\migrations;

use phpbb2plus\community\service\member_notice;

class v140_member_notice extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v139_password_reminders'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'users' => array('plus_notice_pending' => array('BOOL', 0))));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'users' => array('plus_notice_pending')));
	}

	public function update_data()
	{
		return array(array('config_text.add', array(member_notice::KEY, member_notice::EMPTY_STATE)),
			array('permission.add', array('a_plus_ct_notice')),
			array('module.add', array('acp', 'ACP_SERVER_CONFIGURATION', array(
				'module_basename' => '\phpbb2plus\community\acp\tracker_settings_module', 'modes' => array('notice')))));
	}
}
