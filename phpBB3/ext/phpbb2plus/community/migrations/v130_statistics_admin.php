<?php
namespace phpbb2plus\community\migrations;

class v130_statistics_admin extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\\phpbb2plus\\community\\migrations\\v127_statistics'); }

	public function update_data()
	{
		return array(
			array('config.add', array('plus_statistics_storage_ttl', 600)),
			array('config_text.add', array('plus_statistics_source_modules', '[]')),
			array('config_text.add', array('plus_statistics_source_dir', '')),
			array('custom', array(array($this, 'preserve_registry'))),
			array('permission.add', array('a_plus_stats_modules')),
			array('permission.add', array('a_plus_stats_settings')),
			array('permission.permission_set', array('ROLE_ADMIN_FULL', 'a_plus_stats_modules')),
			array('permission.permission_set', array('ROLE_ADMIN_FULL', 'a_plus_stats_settings')),
			array('module.add', array('acp', 'ACP_BOARD_CONFIGURATION', array('module_basename' => '\\phpbb2plus\\community\\acp\\statistics_module',
				'modes' => array('manage', 'settings')))),
		);
	}

	public function preserve_registry()
	{
		$table = $this->table_prefix . 'config_text';
		$result = $this->db->sql_query("SELECT config_value FROM $table WHERE config_name='plus_statistics_modules'");
		$value = $this->db->sql_fetchfield('config_value');
		$this->db->sql_freeresult($result);
		if (is_string($value) && $value !== '[]')
		{
			$this->db->sql_query("UPDATE $table SET config_value='" . $this->db->sql_escape($value) .
				"' WHERE config_name='plus_statistics_source_modules' AND config_value='[]'");
		}
	}
}
