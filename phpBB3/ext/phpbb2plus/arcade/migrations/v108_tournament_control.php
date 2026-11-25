<?php
namespace phpbb2plus\arcade\migrations;

class v108_tournament_control extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v107_administration'); }

	public function update_data()
	{
		return array(array('module.add', array('acp', 'ACP_BOARD_CONFIGURATION', array(
			'module_basename'=>'\phpbb2plus\arcade\acp\tournaments_module', 'modes'=>array('manage')))));
	}
}
