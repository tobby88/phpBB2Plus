<?php
namespace phpbb2plus\knowledgebase\migrations;

class v106_settings extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\knowledgebase\migrations\v105_categories'); }
	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'plus_kb_settings' => array('value_encoded' => array('BOOL', 0))));
	}
	public function update_data()
	{
		return array(array('permission.add', array('a_plus_kb_settings')),
			array('module.add', array('acp', 'ACP_PLUS_KB', array('module_basename' => '\phpbb2plus\knowledgebase\acp\settings_module', 'modes' => array('manage')))));
	}
	// Earlier plain rows and native encoded submission instructions remain readable after reinstallation.
}
