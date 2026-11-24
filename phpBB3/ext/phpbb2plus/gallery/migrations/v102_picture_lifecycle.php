<?php
namespace phpbb2plus\gallery\migrations;

class v102_picture_lifecycle extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\gallery\migrations\v101_publication'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_album_';
		return array('add_columns' => array($p . 'comments' => array('retired_editor' => array('VCHAR_UNI', ''))),
			'add_tables' => array($p . 'trash' => array('COLUMNS' => array('storage_name' => array('VCHAR:80', ''), 'removed_at' => array('TIMESTAMP', 0)),
				'PRIMARY_KEY' => 'storage_name', 'KEYS' => array('age' => array('INDEX', 'removed_at')))));
	}

	public function update_data()
	{
		return array(array('config.add', array('plus_gallery_gc_time', 0, true)));
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'plus_album_comments' => array('retired_editor')),
			'drop_tables' => array($this->table_prefix . 'plus_album_trash'));
	}
}
