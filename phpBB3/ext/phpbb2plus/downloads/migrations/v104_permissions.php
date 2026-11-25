<?php
namespace phpbb2plus\downloads\migrations;

class v104_permissions extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\downloads\migrations\v103_categories'); }

	public function update_data()
	{
		return array(array('permission.add', array('a_plus_dl_perms')),
			array('module.add', array('acp', 'ACP_PLUS_DOWNLOADS', array(
				'module_basename'=>'\phpbb2plus\downloads\acp\permissions_module','modes'=>array('manage')))));
	}
}
