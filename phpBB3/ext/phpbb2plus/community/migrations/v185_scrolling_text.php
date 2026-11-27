<?php
namespace phpbb2plus\community\migrations;

use phpbb2plus\community\service\formatting_codes;

class v185_scrolling_text extends v172_formatting_codes
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v172_formatting_codes'); }

	public function update_data()
	{
		return array(array('custom', array(array($this, 'install_scrolling'))));
	}

	public function install_scrolling()
	{
		$this->install_definitions(formatting_codes::scrolling());
	}
}
