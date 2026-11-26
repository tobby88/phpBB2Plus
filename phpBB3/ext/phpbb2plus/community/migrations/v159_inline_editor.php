<?php
namespace phpbb2plus\community\migrations;

class v159_inline_editor extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v100_style'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'users' => array('plus_inline_edit' => array('INT:2', -1))));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'users' => array('plus_inline_edit')));
	}

	public function update_data()
	{
		return array(array('config.add', array('plus_inline_edit', 1)), array('config.add', array('plus_inline_edit_over', 0)));
	}
}
