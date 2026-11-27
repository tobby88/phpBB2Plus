<?php
namespace phpbb2plus\community\migrations;

class v174_attachment_rule_spelling extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v170_attachment_blocks'); }

	public function update_data() { return array(array('custom', array(array($this, 'widen')))); }

	public function widen()
	{
		if ($this->db->get_sql_layer() !== 'mysqli') { return; }
		$table = $this->table_prefix . 'plus_attach_forbidden';
		// A historical label may contain any of 100 Unicode characters. The effective
		// extension key remains strictly ASCII and keeps its existing indexed type.
		$result = $this->db->sql_query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" .
			$this->db->sql_escape($table) . "' AND COLUMN_NAME='extension_name'");
		$type = $this->db->sql_fetchfield('DATA_TYPE');
		$this->db->sql_freeresult($result);
		if ($type === 'varchar') { $this->db->sql_query("ALTER TABLE $table MODIFY extension_name VARBINARY(400) NOT NULL DEFAULT ''"); }
	}

	// Older versions already read these bytes verbatim. A rollback must not discard original spelling.
}
