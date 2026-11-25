<?php
namespace phpbb2plus\community\migrations;

use phpbb2plus\community\service\removed_topics;

class v132_removed_topics extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return array('\phpbb2plus\community\migrations\v114_removed_posts', '\phpbb2plus\community\migrations\v117_topic_descriptions');
	}
	public function update_schema()
	{
		$topics = $this->table_prefix . 'topics';
		return array('add_columns' => array($topics => array('plus_legacy_removed' => array('BOOL', 0)),
			$this->table_prefix . 'posts' => array('plus_legacy_erased' => array('BOOL', 0))),
			'add_index' => array($topics => array('plus_removed_forum' => array('forum_id', 'plus_legacy_removed'))));
	}
	public function revert_schema()
	{
		$topics = $this->table_prefix . 'topics';
		return array('drop_keys' => array($topics => array('plus_removed_forum')),
			'drop_columns' => array($topics => array('plus_legacy_removed'), $this->table_prefix . 'posts' => array('plus_legacy_erased')));
	}
	public function update_data() { return array(array('custom', array(array($this, 'previous_import')))); }
	public function previous_import()
	{
		removed_topics::classify($this->db, $this->table_prefix, '1=1', false);
		$r = $this->db->sql_query_limit('SELECT topic_id FROM ' . TOPICS_TABLE . ' WHERE plus_legacy_removed=1', 1);
		$found = $this->db->sql_fetchfield('topic_id');
		$this->db->sql_freeresult($r);
		if (!$found) { return; }
		removed_topics::clear_posts($this->db, $this->table_prefix);
		removed_topics::refresh_counts($this->db, $this->config);
	}
}
