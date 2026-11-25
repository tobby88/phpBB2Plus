<?php
namespace phpbb2plus\downloads\migrations;

class v107_mirrors extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\downloads\migrations\v106_fields'); }

	public function update_data()
	{
		return array(array('permission.add',array('a_plus_dl_mirrors')),
			array('module.add',array('acp','ACP_PLUS_DOWNLOADS',array('module_basename'=>'\phpbb2plus\downloads\acp\mirrors_module','modes'=>array('manage')))));
	}
}
