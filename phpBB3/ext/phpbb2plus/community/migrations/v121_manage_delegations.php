<?php
namespace phpbb2plus\community\migrations;

use phpbb2plus\community\auth\delegations;

class v121_manage_delegations extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v120_junior_admin'); }

	public function update_schema()
	{
		return array('add_tables' => array($this->table_prefix . 'plus_jr_delegations' => array(
			'COLUMNS' => array('user_id' => array('UINT', 0), 'rights_json' => array('TEXT', ''),
				'notes_b64' => array('MTEXT', ''), 'notes_view' => array('BOOL', 0), 'start_date' => array('BINT', 0),
				'update_date' => array('BINT', 0), 'revision' => array('UINT', 0)), 'PRIMARY_KEY' => 'user_id')));
	}

	public function update_data()
	{
		return array(array('custom', array(array($this, 'populate'))),
			array('module.add', array('acp', 'ACP_GLOBAL_PERMISSIONS', array('module_basename' => '\phpbb2plus\community\acp\delegations_module', 'modes' => array('manage')))));
	}

	public function populate() { delegations::import($this->db, $this->table_prefix); }

	public function revert_schema() { return array('drop_tables' => array($this->table_prefix . 'plus_jr_delegations')); }
}
