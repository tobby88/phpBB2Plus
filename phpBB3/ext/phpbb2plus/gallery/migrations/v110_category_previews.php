<?php
namespace phpbb2plus\gallery\migrations;

class v110_category_previews extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\gallery\migrations\v109_personal_permissions'); }
	public function update_data()
	{
		return array(array('module.add', array('acp', 'ACP_BOARD_CONFIGURATION', array(
			'module_basename' => '\phpbb2plus\gallery\acp\personal_module', 'modes' => array('display')))));
	}
}
