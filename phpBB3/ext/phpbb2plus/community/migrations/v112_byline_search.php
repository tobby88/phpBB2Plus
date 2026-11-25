<?php
namespace phpbb2plus\community\migrations;

use phpbb2plus\community\service\former_members;

class v112_byline_search extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v111_post_icons'); }

	public function update_schema()
	{
		$t = $this->table_prefix . 'plus_bylines';
		return array('add_columns'=>array($t=>array('author_clean'=>array('TEXT_UNI',''),'author_key'=>array('CHAR:64',''))),
			'add_index'=>array($t=>array('author_key'=>array('author_key'))));
	}

	public function revert_schema()
	{
		$t = $this->table_prefix . 'plus_bylines';
		return array('drop_keys'=>array($t=>array('author_key')),'drop_columns'=>array($t=>array('author_clean','author_key')));
	}

	public function update_data() { return array(array('custom',array(array($this,'backfill')))); }

	public function backfill($after)
	{
		$t = $this->table_prefix . 'plus_bylines';
		$r = $this->db->sql_query_limit('SELECT p.post_id,p.post_username,b.post_id AS indexed,b.author_name FROM ' . POSTS_TABLE . " p
			LEFT JOIN $t b ON b.post_id=p.post_id WHERE p.poster_id=" . ANONYMOUS . " AND (p.post_username<>'' OR b.author_name<>'')
			AND p.post_id>" . (int) $after . ' ORDER BY p.post_id',500);
		$rows = $this->db->sql_fetchrowset($r);
		$this->db->sql_freeresult($r);
		foreach ($rows as $row)
		{
			$after = (int) $row['post_id'];
			$clean = former_members::clean($row['author_name'] !== null && $row['author_name'] !== '' ? $row['author_name'] : $row['post_username']);
			$fields = array('author_clean'=>utf8_encode_ucr($clean),'author_key'=>$clean === '' ? '' : hash('sha256',$clean));
			if ($row['indexed'] === null)
			{
				$this->db->sql_query("INSERT INTO $t " . $this->db->sql_build_array('INSERT',$fields + array('post_id'=>$after,'author_name'=>'','editor_name'=>'')));
			}
			else
			{
				$this->db->sql_query("UPDATE $t SET " . $this->db->sql_build_array('UPDATE',$fields) . " WHERE post_id=$after");
			}
		}
		return count($rows) === 500 ? $after : true;
	}
}
