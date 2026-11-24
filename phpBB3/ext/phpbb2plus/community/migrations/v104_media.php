<?php
namespace phpbb2plus\community\migrations;

class v104_media extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\community\migrations\v103_contacts'); }

	public function update_schema()
	{
		return array('add_tables' => array($this->table_prefix . 'plus_attach_refs' => array(
			'COLUMNS' => array('source_id' => array('UINT', 0), 'in_message' => array('BOOL', 0),
				'parent_id' => array('UINT', 0), 'attach_id' => array('UINT', 0)),
			'PRIMARY_KEY' => array('source_id', 'in_message', 'parent_id'),
			'KEYS' => array('target' => array('UNIQUE', 'attach_id')),
		)));
	}

	public function revert_schema()
	{
		return array('drop_tables' => array($this->table_prefix . 'plus_attach_refs'));
	}

	public function update_data()
	{
		return array(array('config.add', array('plus_media_key', bin2hex(random_bytes(32)))));
	}
}
