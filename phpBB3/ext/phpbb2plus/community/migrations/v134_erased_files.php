<?php
namespace phpbb2plus\community\migrations;

class v134_erased_files extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return array('\phpbb2plus\community\migrations\v132_removed_topics', '\phpbb2plus\community\migrations\v133_post_delete_policy');
	}
	public function update_schema()
	{
		return array('add_tables' => array($this->table_prefix . 'plus_erased_files' => array(
			'COLUMNS' => array('attach_id' => array('UINT', 0), 'storage_name' => array('VCHAR', ''),
				'thumbnail' => array('BOOL', 0), 'retry_at' => array('TIMESTAMP', 0)),
			'PRIMARY_KEY' => 'attach_id', 'KEYS' => array('retry' => array('INDEX', 'retry_at')))));
	}
	public function revert_schema() { return array('drop_tables' => array($this->table_prefix . 'plus_erased_files')); }
	public function update_data() { return array(array('config.add', array('plus_erased_files_due', 0, true))); }
}
