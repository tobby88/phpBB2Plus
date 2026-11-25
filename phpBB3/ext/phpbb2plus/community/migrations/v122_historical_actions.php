<?php
namespace phpbb2plus\community\migrations;

class v122_historical_actions extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v120_junior_admin'); }

	public function update_schema()
	{
		return array('add_tables' => array($this->table_prefix . 'plus_action_import' => array(
			'COLUMNS' => array('source_id' => array('UINT', 0), 'actor_id' => array('UINT', 0), 'topic_id' => array('UINT', 0),
				'event_time' => array('TIMESTAMP', 0), 'client_ip' => array('VCHAR:40', ''), 'operation' => array('VCHAR:40', ''),
				'details' => array('MTEXT_UNI', '')),
			'PRIMARY_KEY' => 'source_id')));
	}

	public function revert_schema()
	{
		// Successfully imported records belong to the native phpBB log.
		return array('drop_tables' => array($this->table_prefix . 'plus_action_import'));
	}
}
