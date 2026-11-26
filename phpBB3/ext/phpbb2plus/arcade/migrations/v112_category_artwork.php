<?php
namespace phpbb2plus\arcade\migrations;

class v112_category_artwork extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\arcade\migrations\v111_score_moderation'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'plus_arc_categories' => array(
			'icon_storage' => array('VCHAR:80', ''), 'icon_mime' => array('VCHAR:32', ''), 'icon_bytes' => array('BINT', 0))));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'plus_arc_categories' => array('icon_storage','icon_mime','icon_bytes')));
	}
}
