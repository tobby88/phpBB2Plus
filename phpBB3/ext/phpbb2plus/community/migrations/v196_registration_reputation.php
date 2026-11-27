<?php
namespace phpbb2plus\community\migrations;

class v196_registration_reputation extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v189_last_login_display'); }

	public function update_data()
	{
		return array(array('config.add', array('plus_sfs_enable', 0)), array('config.add', array('plus_sfs_fail_closed', 0)));
	}
}
