<?php
namespace phpbb2plus\community\migrations;

class v102_shoutbox extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\community\migrations\v101_bylines'); }

	public function update_schema()
	{
		return array(
			'add_columns' => array($this->table_prefix . 'users' => array('plus_last_shout' => array('TIMESTAMP', 0))),
			'add_tables' => array($this->table_prefix . 'plus_shouts' => array(
				'COLUMNS' => array(
					'shout_id' => array('UINT', null, 'auto_increment'), 'author_id' => array('UINT', 0),
					'author_name' => array('VCHAR_UNI', ''), 'author_removed' => array('BOOL', 0),
					'shout_time' => array('TIMESTAMP', 0), 'author_ip' => array('VCHAR:45', ''),
					'hidden_by' => array('UINT', 0), 'message' => array('MTEXT_UNI', ''),
					'bbcode_uid' => array('VCHAR:8', ''), 'bbcode_bitfield' => array('VCHAR:255', ''),
					'bbcode_options' => array('UINT', 7),
				),
				'PRIMARY_KEY' => 'shout_id',
				'KEYS' => array('shout_order' => array('INDEX', array('shout_time', 'shout_id'))),
			)),
		);
	}

	public function revert_schema()
	{
		return array('drop_columns' => array($this->table_prefix . 'users' => array('plus_last_shout')),
			'drop_tables' => array($this->table_prefix . 'plus_shouts'));
	}

	public function update_data()
	{
		return array(
			array('permission.add', array('u_plus_shout')),
			array('permission.add', array('m_plus_shout')),
			array('permission.permission_set', array('ROLE_USER_STANDARD', 'u_plus_shout')),
			array('permission.permission_set', array('ROLE_USER_FULL', 'u_plus_shout')),
			array('permission.permission_set', array('ROLE_USER_LIMITED', 'u_plus_shout')),
			array('permission.permission_set', array('ROLE_USER_NOPM', 'u_plus_shout')),
			array('permission.permission_set', array('ROLE_MOD_FULL', 'm_plus_shout')),
			array('permission.permission_set', array('ROLE_MOD_STANDARD', 'm_plus_shout')),
		);
	}
}
