<?php
namespace phpbb2plus\community\migrations;

class v184_news_topics extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v183_forum_display'); }
	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'topics' => array('plus_list_news' => array('TINT:2', 0))),
			'add_index' => array($this->table_prefix . 'topics' => array('plus_list_news' => array('forum_id','plus_list_news'))));
	}
	public function revert_schema()
	{
		return array('drop_keys' => array($this->table_prefix . 'topics' => array('plus_list_news')),
			'drop_columns' => array($this->table_prefix . 'topics' => array('plus_list_news')));
	}
	public function update_data()
	{
		return array(array('custom', array(array($this, 'older_topics'))), array('config.add', array('plus_topic_groups_ready', 1)));
	}
	public function older_topics()
	{
		$result = $this->db->sql_query_limit('SELECT user_id FROM ' . $this->table_prefix . 'users WHERE plus_legacy_id>0', 1);
		$older = (bool) $this->db->sql_fetchfield('user_id');
		$this->db->sql_freeresult($result);
		if (!$older) { return; }
		// Earlier converters mapped type 4 to ordinary topics, including news whose category had already disappeared.
		// Keep current native types intact. Only the optional source-backed command resolves these unknowns.
		$this->db->sql_query('UPDATE ' . $this->table_prefix . 'topics SET plus_list_news=-1
			WHERE topic_type=' . POST_NORMAL . ' AND topic_visibility=' . ITEM_APPROVED . ' AND topic_status<>' . ITEM_MOVED);
	}
}
