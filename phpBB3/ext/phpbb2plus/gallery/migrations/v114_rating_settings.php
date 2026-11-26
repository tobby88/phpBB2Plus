<?php
namespace phpbb2plus\gallery\migrations;

class v114_rating_settings extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\\phpbb2plus\\gallery\\migrations\\v113_preview_cache'); }
	public function update_data()
	{
		return array(array('module.add', array('acp', 'ACP_BOARD_CONFIGURATION', array(
			'module_basename' => '\\phpbb2plus\\gallery\\acp\\personal_module', 'modes' => array('ratings')))));
	}
}
