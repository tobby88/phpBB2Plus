<?php
namespace phpbb2plus\community\migrations;

class v137_account_logins extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v109_legacy_links'); }

	public function update_schema()
	{
		return array(
			'add_columns' => array($this->table_prefix . 'users' => array(
				'plus_login_warn' => array('BOOL', 1),
				'plus_login_previous' => array('VCHAR:45', ''),
				'plus_login_current' => array('VCHAR:45', ''),
			)),
			'add_tables' => array($this->table_prefix . 'plus_account_logins' => array(
				'COLUMNS' => array('entry_id' => array('BINT', null, 'auto_increment'), 'user_id' => array('UINT', 0),
					'login_time' => array('INT:11', 0), 'login_ip' => array('VCHAR:45', '')),
				'PRIMARY_KEY' => 'entry_id',
				'KEYS' => array('account_time' => array('INDEX', array('user_id', 'login_time', 'entry_id'))),
			)),
		);
	}

	public function revert_schema()
	{
		return array('drop_tables' => array($this->table_prefix . 'plus_account_logins'),
			'drop_columns' => array($this->table_prefix . 'users' => array('plus_login_warn', 'plus_login_previous', 'plus_login_current')));
	}

	public function update_data()
	{
		// Only a fresh conversion can recover an existing board's collection policy.
		$result = $this->db->sql_query_limit('SELECT user_id FROM ' . $this->table_prefix . 'users WHERE plus_legacy_id>0', 1);
		$fresh = $this->db->sql_fetchfield('user_id') === false ? 1 : 0;
		$this->db->sql_freeresult($result);
		return array(
			array('config.add', array('plus_login_history', $fresh)),
			array('config.add', array('plus_login_limit', 10)),
			array('config.add', array('plus_login_network_check', $fresh)),
			array('module.add', array('ucp', 'UCP_MAIN', array(
				'module_basename' => '\phpbb2plus\community\ucp\logins_module', 'modes' => array('history')))),
		);
	}
}
