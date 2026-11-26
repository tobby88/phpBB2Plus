<?php
namespace phpbb2plus\arcade\migrations;

class v111_score_moderation extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v110_artwork'); }

	public function update_data()
	{
		return array(
			array('permission.add', array('a_plus_arc_scores', true, 'a_plus_arcade')),
			array('module.add', array('acp', 'ACP_BOARD_CONFIGURATION', array(
				'module_basename' => '\phpbb2plus\arcade\acp\scores_module', 'modes' => array('manage')))),
		);
	}
}
