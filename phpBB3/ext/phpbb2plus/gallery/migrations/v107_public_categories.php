<?php
namespace phpbb2plus\gallery\migrations;

class v107_public_categories extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\gallery\migrations\v106_upload_policy'); }

	public function update_data()
	{
		return array(array('permission.add', array('a_plus_album_categories')),
			array('module.add', array('acp', 'ACP_BOARD_CONFIGURATION', array(
				'module_basename' => '\phpbb2plus\gallery\acp\categories_module', 'modes' => array('manage')))));
	}
}
