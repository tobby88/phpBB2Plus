<?php
namespace phpbb2plus\community\migrations;

class v178_daily_visitors extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v110_portal'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'users' => array('plus_daily_scope' => array('INT:2', -1))),
			'add_index' => array($this->table_prefix . 'users' => array('plus_activity' => array('user_last_active'))),
			'add_tables' => array($this->table_prefix . 'plus_day_visits' => array('COLUMNS' => array(
				'visitor' => array('VCHAR:64', ''), 'is_guest' => array('BOOL', 0),
				'day_start' => array('TIMESTAMP', 0), 'last_seen' => array('TIMESTAMP', 0)),
				'PRIMARY_KEY' => 'visitor', 'KEYS' => array('day_guests' => array('INDEX', array('day_start', 'is_guest'))))));
	}
	public function revert_schema()
	{
		return array('drop_tables' => array($this->table_prefix . 'plus_day_visits'),
			'drop_keys' => array($this->table_prefix . 'users' => array('plus_activity')),
			'drop_columns' => array($this->table_prefix . 'users' => array('plus_daily_scope')));
	}
	public function update_data()
	{
		return array(array('config.add', array('plus_daily_mode', 0)), array('config.add', array('plus_daily_scope', 2)),
			array('config.add', array('plus_daily_force', 0)), array('config.add', array('plus_daily_key', bin2hex(random_bytes(32)))),
			array('config.add', array('plus_daily_gc', '', true)));
	}
}
