<?php
namespace phpbb2plus\gallery\migrations;

class v106_upload_policy extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\gallery\migrations\v105_thumbnails'); }
	public function update_data()
	{
		return array(array('module.add', array('acp', 'ACP_BOARD_CONFIGURATION', array(
			'module_basename' => '\phpbb2plus\gallery\acp\personal_module', 'modes' => array('media')))));
	}
}
