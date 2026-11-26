<?php
namespace phpbb2plus\community\migrations;

class v144_poll_recovery extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\\phpbb2plus\\community\\migrations\\v143_orphan_posts'); }

	public function update_schema()
	{
		$p = $this->table_prefix;
		return array(
			// A 255-character Plus question can expand to ten ASCII characters per
			// code point on the native connection. Plus totals are signed 32-bit.
			'change_columns' => array($p . 'topics' => array('poll_title' => array('VCHAR_UNI:2550', '')),
				$p . 'poll_options' => array('poll_option_total' => array('ULINT', 0))),
			'add_columns' => array($p . 'poll_options' => array('plus_source_option' => array('USINT', 0)),
				$p . 'topics' => array('plus_poll_epoch' => array('BOOL', 0))),
			'add_tables' => array(
				$p . 'plus_poll_review' => array(
					'COLUMNS' => array('source_id' => array('UINT', 0), 'topic_id' => array('UINT', 0),
						'reason' => array('VCHAR:32', ''), 'header_json' => array('MTEXT_UNI', '')),
					'PRIMARY_KEY' => 'source_id'),
				$p . 'plus_poll_choices' => array(
					'COLUMNS' => array('source_id' => array('UINT', 0), 'position' => array('ULINT', 0),
						'choice_json' => array('MTEXT_UNI', '')), 'PRIMARY_KEY' => array('source_id', 'position')),
				$p . 'plus_poll_receipts' => array(
					'COLUMNS' => array('source_id' => array('UINT', 0), 'topic_id' => array('UINT', 0),
						'user_id' => array('ULINT', 0), 'vote_ip' => array('VCHAR:45', '')),
					'KEYS' => array('source_id' => array('INDEX', 'source_id'))),
			),
		);
	}

	public function revert_schema()
	{
		$p = $this->table_prefix;
		// Native phpBB can use the wider fields after uninstall. Shrinking them
		// could truncate a published question or an existing result.
		return array('drop_columns' => array($p . 'poll_options' => array('plus_source_option'),
			$p . 'topics' => array('plus_poll_epoch')),
			'drop_tables' => array($p . 'plus_poll_review', $p . 'plus_poll_choices', $p . 'plus_poll_receipts'));
	}
}
