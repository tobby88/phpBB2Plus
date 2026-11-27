<?php
namespace phpbb2plus\arcade\migrations;

class v119_tournament_unicode extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v108_tournament_control'); }

	public function update_data() { return array(array('custom', array(array($this, 'widen_text')))); }

	public function widen_text()
	{
		if ($this->db->get_sql_layer() !== 'mysqli') { return; }
		$table = $this->table_prefix . 'plus_arc_tours';
		$result = $this->db->sql_query("SELECT COLUMN_NAME,DATA_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" .
			$this->db->sql_escape($table) . "' AND COLUMN_NAME IN ('tour_name','tour_desc')");
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		$changes = array();
		foreach ($rows as $column)
		{
			// The original limits are 25 Unicode characters and a text description.
			// Binary storage carries the exact UTF-8 bytes through phpBB's utf8mb3 connection.
			$null = $column['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL';
			if ($column['COLUMN_NAME'] === 'tour_name' && $column['DATA_TYPE'] === 'varchar') { $changes[] = 'MODIFY tour_name VARBINARY(100)' . $null; }
			if ($column['COLUMN_NAME'] === 'tour_desc' && $column['DATA_TYPE'] === 'mediumtext') { $changes[] = 'MODIFY tour_desc MEDIUMBLOB' . $null; }
		}
		if ($changes) { $this->db->sql_query("ALTER TABLE $table " . implode(', ', $changes)); }
	}

	// Earlier tournament readers accept these raw bytes, including after a rollback.
}
