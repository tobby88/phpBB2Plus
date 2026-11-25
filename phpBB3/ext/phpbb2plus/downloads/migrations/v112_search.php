<?php
namespace phpbb2plus\downloads\migrations;

use phpbb2plus\downloads\service\search_text;

class v112_search extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\downloads\migrations\v111_reports'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_dl_';
		$text = array('MTEXT_UNI','');
		return array('add_columns'=>array($p.'files'=>array_fill_keys(array('search_title','search_summary','search_creator','search_detail'),$text),
			$p.'comments'=>array_fill_keys(array('search_title','search_body','search_links'),$text)),
			'add_tables'=>array($p.'searches'=>array('COLUMNS'=>array('search_id'=>array('UINT:10',null,'auto_increment'),'session_key'=>array('CHAR:64',''),
				'created_at'=>array('TIMESTAMP',0),'payload'=>$text),'PRIMARY_KEY'=>'search_id','KEYS'=>array('dl_search_age'=>array('INDEX','created_at')))));
	}

	public function revert_schema()
	{
		$p = $this->table_prefix . 'plus_dl_';
		return array('drop_columns'=>array($p.'files'=>array('search_title','search_summary','search_creator','search_detail'),
			$p.'comments'=>array('search_title','search_body','search_links')),'drop_tables'=>array($p.'searches'));
	}

	public function update_data()
	{
		return array(array('custom',array(array($this,'files'))),array('custom',array(array($this,'comments'))));
	}

	public function files($after) { return $this->backfill('files',$after); }
	public function comments($after) { return $this->backfill('comments',$after); }

	private function backfill($kind,$after)
	{
		$table = $this->table_prefix . 'plus_dl_' . $kind;
		$file = $kind === 'files';
		$key = $file ? 'file_id' : 'comment_id';
		$columns = $file ? 'title,summary,source_json' : 'title,body';
		$result = $this->db->sql_query_limit("SELECT $key,$columns FROM $table WHERE $key>" . (int) $after . " ORDER BY $key",100);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		foreach ($rows as $row)
		{
			$after = (int) $row[$key];
			$fields = $file ? search_text::file($row,json_decode($row['source_json'],true) ?: array()) : search_text::comment($row);
			$this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE',$fields) . " WHERE $key=$after");
		}
		return count($rows) === 100 ? $after : true;
	}
}
