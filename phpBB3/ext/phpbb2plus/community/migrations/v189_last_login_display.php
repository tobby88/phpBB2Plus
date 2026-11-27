<?php
namespace phpbb2plus\community\migrations;

class v189_last_login_display extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v181_profile_visits'); }

	public function update_data()
	{
		// An existing target no longer has the original board's disclosure preference.
		return array(array('config.add', array('plus_hide_last_login', 1)));
	}
}
