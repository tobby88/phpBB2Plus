<?php
namespace phpbb2plus\community\migrations;

class v123_warning_cards extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v120_junior_admin'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'users' => array('plus_card_count' => array('USINT', 0)),
			$this->table_prefix . 'banlist' => array('plus_card_legacy' => array('BOOL', 0))));
	}

	public function update_data()
	{
		return array(array('config.add', array('plus_card_threshold', 0)), array('config.add', array('plus_card_has_bans', 0)));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'users' => array('plus_card_count'),
			$this->table_prefix . 'banlist' => array('plus_card_legacy')));
	}
}
