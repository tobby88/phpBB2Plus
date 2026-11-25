<?php
namespace phpbb2plus\community\migrations;

class v126_legacy_mail extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v109_legacy_links'); }

	public function update_schema()
	{
		return array('add_columns' => array(
			$this->table_prefix . 'privmsgs' => array('plus_legacy_pm' => array('BOOL', 0)),
			$this->table_prefix . 'privmsgs_folder' => array('plus_legacy_savebox' => array('BOOL', 0)),
		));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array(
			$this->table_prefix . 'privmsgs' => array('plus_legacy_pm'),
			$this->table_prefix . 'privmsgs_folder' => array('plus_legacy_savebox'),
		));
	}
}
