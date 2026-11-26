<?php
namespace phpbb2plus\community\migrations;

class v161_acronym_dictionary extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v121_manage_delegations'); }

	public function update_schema()
	{
		return array('add_tables' => array($this->table_prefix . 'plus_acronyms' => array(
			'COLUMNS' => array('acronym_id' => array('UINT', null, 'auto_increment'),
				'term_text' => array('TEXT_UNI', ''), 'definition_text' => array('TEXT_UNI', ''), 'revision' => array('UINT', 0)),
			'PRIMARY_KEY' => 'acronym_id')));
	}

	public function update_data()
	{
		return array(array('config.add', array('plus_acronym_sequence', 0, true)), array('permission.add', array('a_plus_acronyms')),
			array('permission.permission_set', array('ROLE_ADMIN_FULL', 'a_plus_acronyms')),
			array('module.add', array('acp', 'ACP_MESSAGES', array(
				'module_basename' => '\phpbb2plus\community\acp\acronyms_module', 'modes' => array('manage')))));
	}

	public function revert_schema()
	{
		$table = $this->table_prefix . 'plus_acronyms';
		if (!$this->db_tools->sql_table_exists($table)) { return array(); }
		$result = $this->db->sql_query_limit('SELECT acronym_id FROM ' . $table, 1);
		$used = (bool) $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $used ? array() : array('drop_tables' => array($table));
	}
}
