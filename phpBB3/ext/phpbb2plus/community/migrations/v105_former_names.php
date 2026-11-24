<?php
namespace phpbb2plus\community\migrations;

class v105_former_names extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\community\migrations\v104_media'); }

	public function update_schema()
	{
		return array('add_tables' => array($this->table_prefix . 'plus_former_names' => array(
			'COLUMNS' => array('source_id' => array('UINT', 0), 'native_id' => array('UINT', 0),
				'name_hash' => array('CHAR:64', ''), 'name_key' => array('CHAR:64', ''), 'name_clean' => array('TEXT_UNI', ''),
				'author_name' => array('VCHAR_UNI', ''), 'recorded_at' => array('TIMESTAMP', 0)),
			'PRIMARY_KEY' => array('source_id', 'native_id', 'name_hash'),
			'KEYS' => array('name_key' => array('INDEX', 'name_key'), 'native_id' => array('INDEX', 'native_id')),
		)));
	}

	public function revert_schema()
	{
		return array('drop_tables' => array($this->table_prefix . 'plus_former_names'));
	}
}
