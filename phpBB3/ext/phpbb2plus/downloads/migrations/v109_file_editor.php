<?php
namespace phpbb2plus\downloads\migrations;

use phpbb2plus\downloads\service\file_display;

class v109_file_editor extends \phpbb\db\migration\migration
{
	const OLD_AUTH = 'ext_phpbb2plus/downloads && (acl_a_plus_downloads || acl_a_plus_dl_approval || acl_a_plus_dl_delete)';
	const AUTH = 'ext_phpbb2plus/downloads && (acl_a_plus_downloads || acl_a_plus_dl_approval || acl_a_plus_dl_delete || acl_a_plus_dl_edit)';

	public static function depends_on() { return array('\phpbb2plus\downloads\migrations\v108_file_actions'); }

	public function update_schema()
	{
		return array('add_columns'=>array($this->table_prefix . 'plus_dl_files'=>array('icon_name'=>array('VCHAR:100',''),'preview_link'=>array('BOOL',0))));
	}

	public function revert_schema()
	{
		return array('drop_columns'=>array($this->table_prefix . 'plus_dl_files'=>array('icon_name','preview_link')));
	}

	public function update_data()
	{
		return array(array('permission.add',array('a_plus_dl_edit')),array('custom',array(array($this,'enable_editor'))),
			array('custom',array(array($this,'backfill'))));
	}

	public function revert_data() { return array(array('custom',array(array($this,'disable_editor')))); }
	public function enable_editor() { $this->module_auth(self::OLD_AUTH,self::AUTH); }
	public function disable_editor() { $this->module_auth(self::AUTH,self::OLD_AUTH); }

	private function module_auth($from,$to)
	{
		// Keep locally customized module rules, placement and visibility.
		$this->db->sql_query('UPDATE ' . $this->table_prefix . "modules SET module_auth='" . $this->db->sql_escape($to) .
			"' WHERE module_class='acp' AND module_mode='manage' AND module_basename='" .
			$this->db->sql_escape('\phpbb2plus\downloads\acp\file_actions_module') . "' AND module_auth='" . $this->db->sql_escape($from) . "'");
	}

	public function backfill($after)
	{
		$table = $this->table_prefix . 'plus_dl_files';
		$result = $this->db->sql_query_limit("SELECT file_id,source_json FROM $table WHERE file_id>" . (int) $after . ' ORDER BY file_id',250);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		foreach ($rows as $row)
		{
			$after = (int) $row['file_id'];
			$values = file_display::imported(json_decode($row['source_json'],true) ?: array());
			if ($values['icon_name'] !== '' || $values['preview_link'])
			{ $this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE',$values) . " WHERE file_id=$after"); }
		}
		return count($rows) === 250 ? $after : true;
	}
}
