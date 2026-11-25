<?php
namespace phpbb2plus\downloads\migrations;

class v103_categories extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\downloads\migrations\v102_submissions'); }

	public function update_data()
	{
		return array(array('permission.add', array('a_plus_dl_categories')),
			array('module.add', array('acp', 'ACP_CAT_DOT_MODS', 'ACP_PLUS_DOWNLOADS')),
			array('module.add', array('acp', 'ACP_PLUS_DOWNLOADS', array(
				'module_basename'=>'\phpbb2plus\downloads\acp\categories_module','modes'=>array('manage')))));
	}
}
