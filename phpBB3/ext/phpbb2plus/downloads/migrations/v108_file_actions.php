<?php
namespace phpbb2plus\downloads\migrations;

class v108_file_actions extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\downloads\migrations\v107_mirrors'); }

	public function update_data()
	{
		return array(array('permission.add',array('a_plus_dl_approval')),array('permission.add',array('a_plus_dl_delete')),
			array('module.add',array('acp','ACP_PLUS_DOWNLOADS',array('module_basename'=>'\phpbb2plus\downloads\acp\file_actions_module','modes'=>array('manage')))));
	}
}
