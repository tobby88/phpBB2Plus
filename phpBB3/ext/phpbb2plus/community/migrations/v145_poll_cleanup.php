<?php
namespace phpbb2plus\community\migrations;

class v145_poll_cleanup extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\\phpbb2plus\\community\\migrations\\v144_poll_recovery'); }

	public function update_schema()
	{
		return array('add_index' => array(
			$this->table_prefix . 'plus_poll_review' => array('topic_id' => array('topic_id')),
			$this->table_prefix . 'plus_poll_receipts' => array('user_id' => array('user_id')),
		));
	}

	public function revert_schema()
	{
		return array('drop_keys' => array($this->table_prefix . 'plus_poll_review' => array('topic_id'),
			$this->table_prefix . 'plus_poll_receipts' => array('user_id')));
	}
}
