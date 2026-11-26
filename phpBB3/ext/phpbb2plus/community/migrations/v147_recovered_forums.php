<?php
namespace phpbb2plus\community\migrations;

class v147_recovered_forums extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\\phpbb2plus\\community\\migrations\\v146_poll_admin'); }

	public function update_schema()
	{
		return array('add_columns'=>array($this->table_prefix . 'forums'=>array('plus_recovery_role'=>array('USINT', 0),
			'plus_recovery_parent'=>array('VCHAR_UNI:32', ''), 'plus_recovery_reason'=>array('VCHAR:16', ''))));
	}

	public function revert_schema()
	{
		return array('drop_columns'=>array($this->table_prefix . 'forums'=>array('plus_recovery_role', 'plus_recovery_parent', 'plus_recovery_reason')));
	}
}
