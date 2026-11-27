<?php
namespace phpbb2plus\community\migrations;

class v175_post_bookmarks extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v100_style'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'users' => array('plus_post_bookmark' => array('BOOL', 0))));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'users' => array('plus_post_bookmark')));
	}
}
