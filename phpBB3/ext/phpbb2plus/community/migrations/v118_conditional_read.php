<?php
namespace phpbb2plus\community\migrations;

class v118_conditional_read extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v117_topic_descriptions'); }
	public function update_data()
	{
		return array(array('permission.add',array('f_plus_read_if_listed',false)),
			array('config.add',array('plus_conditional_read',0)),array('config_text.add',array('plus_read_scopes','')));
	}
}
