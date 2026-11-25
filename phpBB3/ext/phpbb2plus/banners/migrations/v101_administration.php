<?php
namespace phpbb2plus\banners\migrations;

class v101_administration extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\banners\migrations\v100_banners'); }

	public function update_schema()
	{
		return array('add_tables'=>array($this->table_prefix . 'plus_banner_forms'=>array(
			'COLUMNS'=>array('form_key'=>array('CHAR:64','')), 'PRIMARY_KEY'=>'form_key')));
	}
	public function revert_schema() { return array('drop_tables'=>array($this->table_prefix . 'plus_banner_forms')); }
	public function update_data()
	{
		return array(array('custom',array(array($this,'reserve_ids'))), array('module.add',array('acp','ACP_BOARD_CONFIGURATION',array(
			'module_basename'=>'\phpbb2plus\banners\acp\manage_module','modes'=>array('manage')))));
	}
	public function reserve_ids() { \phpbb2plus\banners\service\management::reserve_legacy_ids($this->db,$this->table_prefix . 'plus_banner'); }
}
