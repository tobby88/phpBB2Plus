<?php
namespace phpbb2plus\community\migrations;

class v162_acronym_formatter extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v100_style'); }
	public function update_data() { return array(array('custom', array(array($this, 'refresh')))); }
	public function refresh()
	{
		global $phpbb_container;
		\phpbb2plus\community\service\smilie_sign::reset_formatter($phpbb_container);
	}
}
