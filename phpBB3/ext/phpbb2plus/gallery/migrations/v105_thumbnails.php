<?php
namespace phpbb2plus\gallery\migrations;

class v105_thumbnails extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\gallery\migrations\v104_personal_settings'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'plus_album_pics' => array('preview_hash' => array('VCHAR:64', ''))));
	}
}
