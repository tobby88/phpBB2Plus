<?php
namespace phpbb2plus\downloads\migrations;

class v114_checker extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\downloads\migrations\v113_recommendations'); }
	public function update_schema() { return array('add_columns'=>array($this->table_prefix . 'plus_dl_files'=>array('preview_unavailable'=>array('BOOL', 0)))); }
	public function revert_schema() { return array('drop_columns'=>array($this->table_prefix . 'plus_dl_files'=>array('preview_unavailable'))); }
	public function update_data()
	{
		return array(array('permission.add', array('a_plus_dl_checker')),
			array('module.add', array('acp', 'ACP_PLUS_DOWNLOADS', array('module_basename'=>'\phpbb2plus\downloads\acp\checker_module','modes'=>array('report')))),
			array('custom', array(array($this, 'older_previews'))));
	}

	public function older_previews($after = 0)
	{
		$table = $this->table_prefix . 'plus_dl_files';
		$r = $this->db->sql_query_limit("SELECT file_id,source_json FROM $table WHERE preview_name='' AND file_id>" . (int) $after . ' ORDER BY file_id', 500);
		$rows = $this->db->sql_fetchrowset($r);
		$this->db->sql_freeresult($r);
		$missing = array();
		foreach ($rows as $row)
		{
			$after = (int) $row['file_id'];
			$source = json_decode($row['source_json'], true);
			$url = $source['file_ssurl'] ?? '';
			if (!is_string($url) || $url === '') { continue; }
			$parts = parse_url($url);
			// Earlier converters retained the original URL but no outcome for a missing screenshot.
			// Relative references are local. Absolute addresses may still serve the original remote preview.
			if ($parts && !isset($parts['scheme']) && !isset($parts['host']) && !empty($parts['path'])) { $missing[] = $after; }
		}
		if ($missing) { $this->db->sql_query("UPDATE $table SET preview_unavailable=1 WHERE preview_name='' AND " . $this->db->sql_in_set('file_id', $missing)); }
		return count($rows) === 500 ? $after : true;
	}
}
