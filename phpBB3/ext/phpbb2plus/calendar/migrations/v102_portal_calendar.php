<?php
namespace phpbb2plus\calendar\migrations;

class v102_portal_calendar extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\calendar\migrations\v101_posting'); }
	public function update_data() { return array(array('config.add',array('plus_cal_birthday_days',7))); }
}
