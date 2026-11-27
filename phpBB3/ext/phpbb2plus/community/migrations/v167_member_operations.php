<?php
namespace phpbb2plus\community\migrations;

class v167_member_operations extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return array('\phpbb2plus\community\migrations\v166_member_directory', '\phpbb2plus\community\migrations\v123_warning_cards',
			'\phpbb2plus\community\migrations\v128_legacy_groups');
	}

	public function update_schema()
	{
		return array('add_columns' => array(SESSIONS_TABLE => array('plus_dir_review' => array('VCHAR:64', '')),
			BANLIST_TABLE => array('plus_dir_ban' => array('BOOL', 0))));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array(SESSIONS_TABLE => array('plus_dir_review'), BANLIST_TABLE => array('plus_dir_ban')));
	}

	public function update_data()
	{
		return array(array('permission.add', array('a_plus_list_status')), array('permission.add', array('a_plus_list_bans')),
			array('permission.add', array('a_plus_list_groups')), array('module.add', array('acp', 'ACP_CAT_USERS', array(
				'module_basename' => '\phpbb2plus\community\acp\member_directory_module', 'modes' => array('batch')))));
	}
}
