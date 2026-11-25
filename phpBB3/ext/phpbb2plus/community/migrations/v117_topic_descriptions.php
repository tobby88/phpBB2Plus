<?php
namespace phpbb2plus\community\migrations;

class v117_topic_descriptions extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v116_attachment_administration'); }
	public function update_schema()
	{
		return array('add_columns'=>array($this->table_prefix . 'topics'=>array('plus_description'=>array('VCHAR_UNI',''))));
	}
	public function revert_schema()
	{
		return array('drop_columns'=>array($this->table_prefix . 'topics'=>array('plus_description')));
	}
}
