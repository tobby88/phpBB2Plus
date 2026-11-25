<?php
namespace phpbb2plus\downloads\migrations;

class v106_fields extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\downloads\migrations\v105_licenses'); }

	public function update_schema()
	{
		return array('add_index'=>array($this->table_prefix . 'plus_dl_field_values'=>array('dl_field_use'=>array('field_id','row_id'))));
	}

	public function revert_schema()
	{
		return array('drop_keys'=>array($this->table_prefix . 'plus_dl_field_values'=>array('dl_field_use')));
	}

	public function update_data()
	{
		return array(array('permission.add',array('a_plus_dl_fields')),
			array('module.add',array('acp','ACP_PLUS_DOWNLOADS',array('module_basename'=>'\phpbb2plus\downloads\acp\fields_module','modes'=>array('manage')))));
	}
}
