<?php
namespace phpbb2plus\gallery\migrations;

class v108_public_permissions extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\gallery\migrations\v107_public_categories'); }

	public function update_data()
	{
		return array(array('permission.add', array('a_plus_album_auth')),
			array('module.add', array('acp', 'ACP_BOARD_CONFIGURATION', array(
				'module_basename' => '\phpbb2plus\gallery\acp\permissions_module', 'modes' => array('manage')))));
	}
}
