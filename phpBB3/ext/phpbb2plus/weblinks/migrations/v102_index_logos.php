<?php
namespace phpbb2plus\weblinks\migrations;

class v102_index_logos extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\weblinks\migrations\v100_directory'); }

	public function update_data()
	{
		// Earlier conversions did not record show_links. Keep their index unchanged
		// until an administrator enables the row in Board features.
		return array(array('config.add',array('plus_wl_index_logos',0)));
	}
}
