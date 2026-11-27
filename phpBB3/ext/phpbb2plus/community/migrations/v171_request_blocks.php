<?php
namespace phpbb2plus\community\migrations;

class v171_request_blocks extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v138_login_administration'); }

	public function update_schema()
	{
		return array('add_tables' => array(
			$this->table_prefix . 'plus_ct_blocks' => array('COLUMNS' => array(
				'rule_id' => array('BINT', null, 'auto_increment'), 'rule_value' => array('TEXT_UNI', ''),
				'rule_active' => array('BOOL', 1)), 'PRIMARY_KEY' => 'rule_id'),
			$this->table_prefix . 'plus_ct_block_events' => array('COLUMNS' => array(
				'event_id' => array('BINT', null, 'auto_increment'), 'event_time' => array('TIMESTAMP', 0),
				'address' => array('VCHAR:45', ''), 'hostname' => array('VCHAR_UNI:255', ''),
				'agent' => array('TEXT_UNI', ''), 'script' => array('TEXT_UNI', ''), 'referrer_origin' => array('TEXT_UNI', '')),
				'PRIMARY_KEY' => 'event_id'),
			$this->table_prefix . 'plus_ct_block_state' => array('COLUMNS' => array(
				'state_id' => array('USINT', 1), 'rules_version' => array('BINT', 0), 'rules_imported' => array('BOOL', 0),
				'settings_imported' => array('BOOL', 0), 'log_imported' => array('BOOL', 0),
				'archived' => array('BINT', 0), 'reset_time' => array('TIMESTAMP', 0),
				'reset_kind' => array('VCHAR:8', '')), 'PRIMARY_KEY' => 'state_id')));
	}

	public function update_data()
	{
		return array(array('custom', array(array($this, 'initialize'))),
			array('config.add', array('plus_ct_block_enabled', 1)), array('config.add', array('plus_ct_block_logging', 1)),
			array('config.add', array('plus_ct_block_limit', 100)), array('permission.add', array('a_plus_ct_blocks')),
			array('permission.permission_set', array('ROLE_ADMIN_FULL', 'a_plus_ct_blocks')),
			array('module.add', array('acp', 'ACP_SERVER_CONFIGURATION', array(
				'module_basename' => '\phpbb2plus\community\acp\request_blocks_module', 'modes' => array('rules')))),
			array('module.add', array('acp', 'ACP_FORUM_LOGS', array(
				'module_basename' => '\phpbb2plus\community\acp\request_blocks_module', 'modes' => array('history')))));
	}

	public function initialize()
	{
		$table = $this->table_prefix . 'plus_ct_block_state';
		$this->db->sql_query("INSERT INTO $table (state_id) SELECT 1 WHERE NOT EXISTS (SELECT 1 FROM $table WHERE state_id=1)");
	}

	public function revert_schema()
	{
		return array('drop_tables' => array($this->table_prefix . 'plus_ct_blocks', $this->table_prefix . 'plus_ct_block_events',
			$this->table_prefix . 'plus_ct_block_state'));
	}
}
