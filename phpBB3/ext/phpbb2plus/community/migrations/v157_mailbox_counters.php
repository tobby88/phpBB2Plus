<?php
namespace phpbb2plus\community\migrations;

class v157_mailbox_counters extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v149_structure_review'); }
	public function update_data()
	{
		return array(array('module.add', array('acp', 'ACP_CAT_DATABASE', array(
			'module_basename' => '\phpbb2plus\community\acp\mailbox_module', 'modes' => array('counts')))));
	}
}
