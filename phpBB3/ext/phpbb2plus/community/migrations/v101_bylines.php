<?php
namespace phpbb2plus\community\migrations;

class v101_bylines extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\community\migrations\v100_style'); }

	public function update_schema()
	{
		return array('add_tables' => array($this->table_prefix . 'plus_bylines' => array(
			'COLUMNS' => array('post_id' => array('UINT', 0), 'author_name' => array('TEXT_UNI', ''),
				'editor_name' => array('VCHAR_UNI', '')),
			'PRIMARY_KEY' => 'post_id',
		)));
	}

	public function revert_schema()
	{
		return array('drop_tables' => array($this->table_prefix . 'plus_bylines'));
	}

	public function update_data()
	{
		return array(array('config.add', array('plus_hide_former_names', 1)));
	}
}
