<?php
namespace phpbb2plus\gallery\migrations;

class v109_personal_permissions extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\gallery\migrations\v108_public_permissions'); }

	public function update_data()
	{
		// Stored category grants and native creation rights already represent the source.
		// A phpBB2 root or subcategory INSERT never copied grants from another owner.
		return array(array('permission.add', array('a_plus_album_personal_auth')),
			array('module.add', array('acp', 'ACP_BOARD_CONFIGURATION', array(
				'module_basename' => '\phpbb2plus\gallery\acp\personal_permissions_module', 'modes' => array('manage')))));
	}
}
