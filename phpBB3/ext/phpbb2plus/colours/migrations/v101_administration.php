<?php
namespace phpbb2plus\colours\migrations;

class v101_administration extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\colours\migrations\v100_palette'); }

	public function update_data()
	{
		return array(array('config.add',array('plus_colours_edit_lock',0)),
			array('module.add',array('acp','ACP_GROUPS',array(
				'module_basename'=>'\phpbb2plus\colours\acp\palette_module','modes'=>array('manage')))));
	}
}
