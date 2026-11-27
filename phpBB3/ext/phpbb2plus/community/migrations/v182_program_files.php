<?php
namespace phpbb2plus\community\migrations;

class v182_program_files extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v138_login_administration'); }
	public function update_schema()
	{
		$columns = array('state_id' => array('USINT', 1), 'revision' => array('ULINT', 0),
			'reference_time' => array('TIMESTAMP', 0), 'reference_data' => array('MTEXT', ''),
			'package_id' => array('VCHAR:64', ''), 'scan_start' => array('TIMESTAMP', 0),
			'scan_end' => array('TIMESTAMP', 0), 'file_cursor' => array('UINT', 0), 'scan_data' => array('MTEXT', ''));
		return array('add_tables' => array($this->table_prefix . 'plus_ct_programs' => array('COLUMNS' => $columns, 'PRIMARY_KEY' => 'state_id')));
	}
	public function update_data()
	{
		return array(array('custom', array(array($this, 'initialize'))), array('permission.add', array('a_plus_ct_files')),
			array('permission.permission_set', array('ROLE_ADMIN_FULL', 'a_plus_ct_files')),
			array('module.add', array('acp', 'ACP_SERVER_CONFIGURATION', array('module_basename' => '\phpbb2plus\community\acp\file_integrity_module', 'modes' => array('main')))));
	}
	public function initialize()
	{
		$table = $this->table_prefix . 'plus_ct_programs';
		$this->db->sql_query("INSERT INTO $table (state_id,reference_data,scan_data) SELECT 1,'','' WHERE NOT EXISTS (SELECT 1 FROM $table WHERE state_id=1)");
	}
	public function revert_schema() { return array('drop_tables' => array($this->table_prefix . 'plus_ct_programs')); }
}
