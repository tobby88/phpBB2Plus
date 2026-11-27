<?php
namespace phpbb2plus\community\migrations;

class v197_registration_notice extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v164_acp_registration'); }
	public function update_data() { return array(array('config_text.add', array('plus_registration_notice', ''))); }
}
