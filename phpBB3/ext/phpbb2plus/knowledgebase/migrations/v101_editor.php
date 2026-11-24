<?php
namespace phpbb2plus\knowledgebase\migrations;

class v101_editor extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\knowledgebase\migrations\v100_articles'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_kb_';
		return array('add_columns' => array($p . 'articles' => array('revision' => array('UINT:10', 1), 'managed_topic' => array('BOOL', 0))),
			'add_tables' => array(
				$p . 'state' => array('COLUMNS' => array('state_id' => array('USINT', 0)), 'PRIMARY_KEY' => 'state_id'),
				$p . 'submissions' => array('COLUMNS' => array('form_key' => array('CHAR:64', ''), 'payload' => array('CHAR:64', ''),
					'article_id' => array('UINT', 0), 'created_at' => array('TIMESTAMP', 0)), 'PRIMARY_KEY' => 'form_key',
					'KEYS' => array('created' => array('INDEX', 'created_at'))),
			));
	}

	public function update_data() { return array(array('custom', array(array($this, 'defaults')))); }

	public function defaults()
	{
		$p = $this->table_prefix . 'plus_kb_';
		foreach (array('state' => array('state_id' => 1), 'cats' => array('cat_id' => 1, 'title' => $this->config['default_lang'] === 'de' ? 'Allgemeines' : 'General',
			'description' => '', 'parent_id' => 0, 'sort_order' => 1), 'types' => array('type_id' => 1, 'title' => $this->config['default_lang'] === 'de' ? 'Artikel' : 'Article')) as $table => $row)
		{
			$result = $this->db->sql_query_limit('SELECT * FROM ' . $p . $table, 1);
			$exists = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if (!$exists) { $this->db->sql_query('INSERT INTO ' . $p . $table . ' ' . $this->db->sql_build_array('INSERT', $row)); }
		}
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'plus_kb_articles' => array('revision','managed_topic')),
			'drop_tables' => array_keys($this->update_schema()['add_tables']));
	}
}
