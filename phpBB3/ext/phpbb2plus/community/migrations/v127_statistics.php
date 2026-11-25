<?php
namespace phpbb2plus\community\migrations;

class v127_statistics extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v110_portal'); }

	public function update_data()
	{
		return array(
			array('config_text.add', array('plus_statistics_modules', '[]')),
			array('config.add', array('plus_statistics_limit', 10)),
			array('config.add', array('plus_statistics_installed', 0)),
			array('config.add', array('plus_statistics_views', 0, true)),
			array('config.add', array('plus_statistics_version', '')),
			array('permission.add', array('a_plus_stats_private')),
			array('permission.permission_set', array('ROLE_ADMIN_FULL', 'a_plus_stats_private')),
		);
	}
}
