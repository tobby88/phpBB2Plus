<?php
namespace phpbb2plus\knowledgebase\migrations;

class v107_unicode_text extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\knowledgebase\migrations\v106_settings'); }

	public function update_data() { return array(array('custom', array(array($this, 'preserve_text_bytes')))); }

	public function preserve_text_bytes()
	{
		if ($this->db->get_sql_layer() !== 'mysqli') { return; }
		// MySQLi negotiates utf8mb3 and DBAL creates utf8mb3 text columns even on
		// an utf8mb4 database. These fields hold actual UTF-8 supplied to the editor,
		// search and formatter. Binary columns preserve it on both reads and writes
		// through the native connection, including literal HTML character references.
		$fields = array('articles'=>array('title','description','author_name','editor_text','search_text'), 'pages'=>array('heading','content'));
		$blobs = array('tinytext'=>'TINYBLOB', 'text'=>'BLOB', 'mediumtext'=>'MEDIUMBLOB', 'longtext'=>'LONGBLOB');
		foreach ($fields as $suffix => $columns)
		{
			$table = $this->table_prefix . 'plus_kb_' . $suffix;
			$result = $this->db->sql_query('SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,IS_NULLABLE FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'' . $this->db->sql_escape($table) . "' AND " . $this->db->sql_in_set('COLUMN_NAME', $columns));
			$rows = $this->db->sql_fetchrowset($result);
			$this->db->sql_freeresult($result);
			$changes = array();
			foreach ($rows as $row)
			{
				$type = $row['DATA_TYPE'];
				$null = $row['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL';
				if ($type === 'varchar')
				{
					$bytes = 4 * (int) $row['CHARACTER_MAXIMUM_LENGTH'];
					$definition = $bytes <= 4096 ? "VARBINARY($bytes)$null" . ($null === ' NULL' ? '' : " DEFAULT ''") : "MEDIUMBLOB$null";
				}
				elseif (isset($blobs[$type])) { $definition = $blobs[$type] . $null; }
				else { continue; } // A previous run may already have converted this table.
				$changes[] = 'MODIFY ' . $row['COLUMN_NAME'] . ' ' . $definition;
			}
			if ($changes) { $this->db->sql_query("ALTER TABLE $table " . implode(', ', $changes)); }
		}
	}

	// A pre-upgrade reader can use exactly the same byte strings. Keep this widening
	// on rollback: converting back to utf8mb3 would destroy newly stored characters.
}
