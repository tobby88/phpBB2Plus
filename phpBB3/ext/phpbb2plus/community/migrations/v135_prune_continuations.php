<?php
namespace phpbb2plus\community\migrations;

class v135_prune_continuations extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v134_erased_files'); }
	public function update_schema()
	{
		return array('add_tables' => array($this->table_prefix . 'plus_prune_queue' => array(
			'COLUMNS' => array('forum_id' => array('UINT', 0), 'prune_mode' => array('VCHAR:6', ''),
				'cutoff' => array('TIMESTAMP', 0), 'flags' => array('UINT', 0), 'days' => array('USINT', 0),
				'frequency' => array('USINT', 0), 'cursor_id' => array('UINT', 0)),
			'PRIMARY_KEY' => array('forum_id', 'prune_mode', 'cutoff', 'flags', 'days', 'frequency'))));
	}
	public function revert_schema() { return array('drop_tables' => array($this->table_prefix . 'plus_prune_queue')); }
}
