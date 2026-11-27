<?php
namespace phpbb2plus\community\migrations;

class v170_attachment_blocks extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return array('\phpbb2plus\community\migrations\v115_attachment_quotas', '\phpbb2plus\community\migrations\v121_manage_delegations');
	}

	public function update_schema()
	{
		return array('add_tables' => array($this->table_prefix . 'plus_attach_forbidden' => array(
			'COLUMNS' => array('rule_id' => array('UINT', null, 'auto_increment'), 'source_id' => array('UINT', 0),
				'extension_name' => array('VCHAR_UNI:100', ''), 'extension_key' => array('VCHAR:16', '')),
			'PRIMARY_KEY' => 'rule_id', 'KEYS' => array('ext_key' => array('INDEX', 'extension_key')))));
	}

	public function revert_schema() { return array('drop_tables' => array($this->table_prefix . 'plus_attach_forbidden')); }

	public function update_data()
	{
		return array(array('config.add', array('plus_attach_block_rev', 1)),
			array('permission.add', array('a_plus_attach_block', true, 'a_attach')),
			array('module.add', array('acp', 'ACP_ATTACHMENTS', array(
				'module_basename' => '\phpbb2plus\community\acp\attachment_blocks_module', 'modes' => array('manage')))));
	}
}
