<?php
namespace phpbb2plus\community\migrations;

class v125_manage_credits extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v124_credits'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'plus_credits' => array(
			'revision' => array('UINT', 1), 'legacy_quotes' => array('BOOL', 1))));
	}

	public function update_data()
	{
		return array(array('config.add', array('plus_credit_sequence', 0, true)), array('permission.add', array('a_plus_credits')),
			array('permission.permission_set', array('ROLE_ADMIN_FULL', 'a_plus_credits')),
			array('module.add', array('acp', 'ACP_BOARD_CONFIGURATION', array(
				'module_basename' => '\phpbb2plus\community\acp\credits_module', 'modes' => array('manage')))));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'plus_credits' => array('revision', 'legacy_quotes')));
	}
}
