<?php
namespace phpbb2plus\community\migrations;

class v128_legacy_groups extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v109_legacy_links'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'groups' => array('plus_legacy_group' => array('BOOL', 0))));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'groups' => array('plus_legacy_group')));
	}
}
