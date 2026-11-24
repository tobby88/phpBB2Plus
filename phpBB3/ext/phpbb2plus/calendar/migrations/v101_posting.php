<?php
namespace phpbb2plus\calendar\migrations;

class v101_posting extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb2plus\calendar\migrations\v100_calendar'); }

	public function update_data()
	{
		return array(array('permission.add', array('f_plus_calendar', false, 'f_post')));
	}
}
