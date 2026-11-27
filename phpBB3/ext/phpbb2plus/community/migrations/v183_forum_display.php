<?php
namespace phpbb2plus\community\migrations;

class v183_forum_display extends \phpbb\db\migration\migration
{
	// -1 inherits the installed board setting for new members and earlier conversions.
	private const DEFAULTS = array('sub_forum'=>1, 'split_cat'=>1, 'last_topic_title'=>1, 'sub_level_links'=>2,
		'split_global_announce'=>1, 'split_announce'=>1, 'split_news'=>1, 'split_sticky'=>1, 'split_topic_split'=>0);

	public static function depends_on() { return array('\phpbb2plus\community\migrations\v132_removed_topics'); }

	public function update_schema()
	{
		$columns = array();
		foreach (self::DEFAULTS as $name => $unused) { $columns['plus_' . $name] = array('INT:2', -1); }
		return array('add_columns' => array($this->table_prefix . 'users' => $columns));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'users' => array_keys($this->update_schema()['add_columns'][$this->table_prefix . 'users'])));
	}

	public function update_data()
	{
		$result = $this->db->sql_query_limit('SELECT user_id FROM ' . $this->table_prefix . 'users WHERE plus_legacy_id>0', 1);
		$older = (bool) $this->db->sql_fetchfield('user_id');
		$this->db->sql_freeresult($result);
		$defaults = self::DEFAULTS;
		// This is the native layout previously seen on converted boards whose source choices were never saved.
		if ($older) { $defaults = array_replace($defaults, array('split_global_announce'=>0, 'split_news'=>0, 'split_sticky'=>0, 'split_topic_split'=>1)); }
		$changes = array(array('config.add', array('plus_last_topic_title_length', $older ? 30 : 24)));
		foreach ($defaults as $name => $value)
		{
			$changes[] = array('config.add', array('plus_' . $name, $value));
			$changes[] = array('config.add', array('plus_' . $name . '_over', 0));
		}
		return $changes;
	}
}
