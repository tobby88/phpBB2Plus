<?php
namespace phpbb2plus\community\migrations;

use phpbb2plus\community\service\removed_topic_shadows;

class v136_removed_topic_shadows extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v132_removed_topics'); }
	public function update_schema()
	{
		return array('add_index' => array($this->table_prefix . 'topics' => array('plus_moved_target' => array('topic_moved_id','topic_status','topic_visibility'))));
	}
	public function revert_schema() { return array('drop_keys' => array($this->table_prefix . 'topics' => array('plus_moved_target'))); }
	public function update_data() { return array(array('custom', array(array($this, 'retire_shadows')))); }
	public function retire_shadows()
	{
		$db = $this->db;
		$db->sql_transaction('begin');
		try
		{
			if (removed_topic_shadows::hide($db, 0, true))
			{
				$r = $db->sql_query('SELECT COUNT(*) AS total FROM ' . TOPICS_TABLE . ' WHERE topic_visibility=' . ITEM_APPROVED);
				$this->config->set('num_topics', (int) $db->sql_fetchfield('total'), false);
				$db->sql_freeresult($r);
			}
			$db->sql_transaction('commit');
		}
		catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
	}
}
