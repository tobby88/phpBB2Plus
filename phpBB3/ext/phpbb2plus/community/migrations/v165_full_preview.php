<?php
namespace phpbb2plus\community\migrations;

class v165_full_preview extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\\phpbb2plus\\community\\migrations\\v100_style'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'users' => array('plus_full_preview' => array('INT:2', -1))));
	}
	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'users' => array('plus_full_preview')));
	}
	public function update_data()
	{
		return array(array('config.add', array('plus_full_preview', 1)), array('config.add', array('plus_full_preview_over', 0)));
	}
}
