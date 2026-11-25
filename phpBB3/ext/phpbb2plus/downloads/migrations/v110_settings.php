<?php
namespace phpbb2plus\downloads\migrations;

class v110_settings extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\downloads\migrations\v109_file_editor'); }

	public function update_data()
	{
		return array(array('permission.add',array('a_plus_dl_settings')),
			array('module.add',array('acp','ACP_PLUS_DOWNLOADS',array('module_basename'=>'\phpbb2plus\downloads\acp\settings_module','modes'=>array('manage')))));
	}
}
