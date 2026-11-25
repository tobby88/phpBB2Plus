<?php
namespace phpbb2plus\downloads\migrations;

use phpbb2plus\downloads\service\client_family;

class v111_reports extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\downloads\migrations\v110_settings'); }

	public function update_schema()
	{
		$columns = array('client_os'=>array('VCHAR:12','other'),'client_browser'=>array('VCHAR:12','other'));
		return array('add_columns'=>array($this->table_prefix . 'plus_dl_visitors'=>$columns,$this->table_prefix . 'plus_dl_votes'=>$columns));
	}

	public function revert_schema()
	{
		$columns = array('client_os','client_browser');
		return array('drop_columns'=>array($this->table_prefix . 'plus_dl_visitors'=>$columns,$this->table_prefix . 'plus_dl_votes'=>$columns));
	}

	public function update_data()
	{
		return array(array('custom',array(array($this,'visitors'))),array('custom',array(array($this,'votes'))));
	}

	public function visitors($after) { return $this->backfill('visitors','downloader',$after); }
	public function votes($after) { return $this->backfill('votes','voter',$after); }

	private function backfill($name,$prefix,$after)
	{
		$table = $this->table_prefix . 'plus_dl_' . $name;
		$result = $this->db->sql_query_limit("SELECT row_id,source_json FROM $table WHERE row_id>" . (int) $after . ' ORDER BY row_id',500);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		$groups = array();
		foreach ($rows as $row)
		{
			$after = (int) $row['row_id'];
			$family = client_family::imported(json_decode($row['source_json'],true) ?: array(),$prefix);
			$groups[$family['client_os']][$family['client_browser']][] = $after;
		}
		foreach ($groups as $os=>$browsers)
		{
			foreach ($browsers as $browser=>$ids)
			{
				if ($os === 'other' && $browser === 'other') { continue; }
				$this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE',array('client_os'=>$os,'client_browser'=>$browser)) .
					' WHERE ' . $this->db->sql_in_set('row_id',$ids));
			}
		}
		return count($rows) === 500 ? $after : true;
	}
}
