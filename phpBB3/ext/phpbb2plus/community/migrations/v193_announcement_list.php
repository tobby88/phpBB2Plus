<?php
namespace phpbb2plus\community\migrations;

class v193_announcement_list extends \phpbb\db\migration\migration
{
	private const FIELDS = array('announce_index', 'announce_forum', 'announce_dates', 'announce_groups', 'announce_path');

	public static function depends_on()
	{
		return array('\phpbb2plus\community\migrations\v184_news_topics', '\phpbb2plus\community\migrations\v119_announcement_duration');
	}

	public function update_schema()
	{
		$columns = array();
		foreach (self::FIELDS as $name) { $columns['plus_' . $name] = array('INT:2', -1); }
		return array('add_columns' => array($this->table_prefix . 'users' => $columns));
	}

	public function revert_schema()
	{
		$table = $this->table_prefix . 'users';
		return array('drop_columns' => array($table => array_keys($this->update_schema()['add_columns'][$table])));
	}

	public function update_data()
	{
		$r = $this->db->sql_query_limit('SELECT user_id FROM ' . $this->table_prefix . 'users WHERE plus_legacy_id>0', 1);
		$converted = (bool) $this->db->sql_fetchfield('user_id');
		$this->db->sql_freeresult($r);
		$changes = array();
		foreach (self::FIELDS as $name)
		{
			// Earlier conversions had no cross-forum panel and did not save these five choices.
			$value = $converted && in_array($name, array('announce_index','announce_forum'), true) ? 0 : 1;
			$changes[] = array('config.add', array('plus_' . $name, $value));
			$changes[] = array('config.add', array('plus_' . $name . '_over', 0));
		}
		return $changes;
	}
}
