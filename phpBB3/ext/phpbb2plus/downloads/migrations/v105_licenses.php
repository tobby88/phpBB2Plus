<?php
namespace phpbb2plus\downloads\migrations;

class v105_licenses extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\downloads\migrations\v104_permissions'); }

	public function update_schema()
	{
		return array('add_index'=>array($this->table_prefix . 'plus_dl_files'=>array('dl_license'=>array('license_id'))));
	}

	public function revert_schema()
	{
		return array('drop_keys'=>array($this->table_prefix . 'plus_dl_files'=>array('dl_license')));
	}

	public function update_data()
	{
		return array(array('permission.add',array('a_plus_dl_licenses')),
			array('module.add',array('acp','ACP_PLUS_DOWNLOADS',array(
				'module_basename'=>'\phpbb2plus\downloads\acp\licenses_module','modes'=>array('manage')))));
	}
}
