<?php
namespace phpbb2plus\community\migrations;

class v168_blue_reports extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v132_removed_topics'); }

	public function update_schema()
	{
		return array('add_columns' => array(
			$this->table_prefix . 'posts' => array('plus_blue_imported' => array('BOOL', 0)),
			$this->table_prefix . 'reports' => array(
				'plus_blue_count' => array('VCHAR:20', ''), 'plus_blue_only' => array('BOOL', 0))));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array(
			$this->table_prefix . 'posts' => array('plus_blue_imported'),
			$this->table_prefix . 'reports' => array('plus_blue_count', 'plus_blue_only')));
	}
}
