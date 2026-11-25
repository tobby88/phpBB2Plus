<?php
namespace phpbb2plus\community\migrations;

class v131_print_topic extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\\phpbb2plus\\community\\migrations\\v118_conditional_read'); }

	public function update_data()
	{
		// Older conversions may have had their native printing rights edited.
		// Only a new phpBB2-Plus import opts in to the historical mapping.
		return array(array('config.add', array('plus_print_read_acl', 0)));
	}
}
