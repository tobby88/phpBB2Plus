<?php
namespace phpbb2plus\community\migrations;

class v146_poll_admin extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\\phpbb2plus\\community\\migrations\\v145_poll_cleanup'); }

	public function update_data()
	{
		return array(array('module.add', array('acp', 'ACP_CAT_DATABASE', array(
			'module_basename'=>'\\phpbb2plus\\community\\acp\\poll_recovery_module', 'modes'=>array('review')))));
	}
}
