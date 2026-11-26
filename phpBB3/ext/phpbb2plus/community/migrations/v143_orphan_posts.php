<?php
namespace phpbb2plus\community\migrations;

class v143_orphan_posts extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\\phpbb2plus\\community\\migrations\\v142_password_rules'); }

	public function update_schema()
	{
		return array('add_tables' => array($this->table_prefix . 'plus_orphan_posts' => array(
			'COLUMNS' => array('source_id' => array('UINT', 0), 'source_json' => array('MTEXT_UNI', '')),
			'PRIMARY_KEY' => 'source_id',
		)));
	}

	public function revert_schema()
	{
		return array('drop_tables' => array($this->table_prefix . 'plus_orphan_posts'));
	}
}
