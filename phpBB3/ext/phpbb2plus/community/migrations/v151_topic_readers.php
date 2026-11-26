<?php
namespace phpbb2plus\community\migrations;

class v151_topic_readers extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v132_removed_topics'); }

	public function update_schema()
	{
		$columns = array('topic_id' => array('UINT', 0), 'user_id' => array('UINT', 0),
			'last_read' => array('TIMESTAMP', 0), 'visit_count' => array('ULINT', 0));
		return array('add_tables' => array(
			$this->table_prefix . 'plus_topic_readers' => array('COLUMNS' => $columns + array('forum_id' => array('UINT', 0)),
				'PRIMARY_KEY' => array('topic_id', 'user_id', 'forum_id'),
				'KEYS' => array('member' => array('INDEX', 'user_id'), 'origin' => array('INDEX', 'forum_id'))),
			// Source rows have no unique key. The converter copies them in batches before combining duplicates.
			$this->table_prefix . 'plus_readers_import' => array('COLUMNS' => array('row_id' => array('ULINT', null, 'auto_increment')) + $columns,
				'PRIMARY_KEY' => 'row_id'),
		));
	}

	public function update_data() { return array(array('config.add', array('plus_topic_readers', 0))); }

	public function revert_schema()
	{
		return array('drop_tables' => array($this->table_prefix . 'plus_topic_readers', $this->table_prefix . 'plus_readers_import'));
	}
}
