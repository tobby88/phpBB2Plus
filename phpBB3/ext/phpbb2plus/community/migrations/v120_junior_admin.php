<?php
namespace phpbb2plus\community\migrations;

class v120_junior_admin extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v119_announcement_duration'); }

	public function update_schema()
	{
		return array('add_columns' => array($this->table_prefix . 'users' => array('plus_jr_delegate' => array('BOOL', 0))),
			'add_tables' => array($this->table_prefix . 'plus_jr_records' => array(
				'COLUMNS' => array('source_user' => array('INT:11', 0), 'user_id' => array('UINT', 0),
					'account_state' => array('VCHAR:32', ''), 'start_date' => array('BINT', 0), 'update_date' => array('BINT', 0),
					'notes_view' => array('BOOL', 0), 'modules_b64' => array('MTEXT', ''), 'notes_b64' => array('MTEXT', ''),
					'report_json' => array('MTEXT', ''), 'rights_json' => array('TEXT', '')),
				'PRIMARY_KEY' => 'source_user', 'KEYS' => array('delegate' => array('INDEX', 'user_id')))));
	}

	public function revert_schema()
	{
		return array('drop_tables' => array($this->table_prefix . 'plus_jr_records'),
			'drop_columns' => array($this->table_prefix . 'users' => array('plus_jr_delegate')));
	}
}
