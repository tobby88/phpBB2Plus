<?php
namespace phpbb2plus\gallery\migrations;

class v115_unicode_text extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\gallery\migrations\v114_rating_settings'); }

	public function update_data() { return array(array('custom', array(array($this, 'preserve_text_bytes')))); }

	public function preserve_text_bytes()
	{
		if ($this->db->get_sql_layer() !== 'mysqli') { return; }
		// The native driver uses a three-byte connection. These editor fields and filenames
		// are plain UTF-8, so store the original bytes, including literal character references.
		$fields = array('pics' => array('title', 'description', 'author_name', 'source_filename'), 'comments' => array('body', 'author_name'));
		$blobs = array('tinytext' => 'TINYBLOB', 'text' => 'BLOB', 'mediumtext' => 'MEDIUMBLOB', 'longtext' => 'LONGBLOB');
		foreach ($fields as $suffix => $columns)
		{
			$table = $this->table_prefix . 'plus_album_' . $suffix;
			$result = $this->db->sql_query("SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,IS_NULLABLE
				FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $this->db->sql_escape($table) .
				"' AND " . $this->db->sql_in_set('COLUMN_NAME', $columns));
			$rows = $this->db->sql_fetchrowset($result);
			$this->db->sql_freeresult($result);
			$changes = array();
			foreach ($rows as $row)
			{
				$type = $row['DATA_TYPE'];
				$nullable = $row['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL';
				if ($type === 'varchar')
				{
					$bytes = 4 * (int) $row['CHARACTER_MAXIMUM_LENGTH'];
					$definition = $bytes <= 4096 ? "VARBINARY($bytes)$nullable" . ($nullable === ' NULL' ? '' : " DEFAULT ''") : "MEDIUMBLOB$nullable";
				}
				elseif (isset($blobs[$type])) { $definition = $blobs[$type] . $nullable; }
				else { continue; } // A previous run may already have widened this table.
				$changes[] = 'MODIFY ' . $row['COLUMN_NAME'] . ' ' . $definition;
			}
			if ($changes) { $this->db->sql_query("ALTER TABLE $table " . implode(', ', $changes)); }
		}
	}

	// Older gallery readers can read the same bytes. Retain the wider storage on rollback
	// so titles, descriptions, bylines and comments added since the upgrade survive.
}
