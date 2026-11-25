<?php
namespace phpbb2plus\community\migrations;

class v133_post_delete_policy extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\\phpbb2plus\\community\\migrations\\v114_removed_posts'); }

	public function update_data()
	{
		// A previous conversion cannot tell us which choice its source board allowed.
		return array(array('config.add', array('plus_post_delete_policy', -1)));
	}
}
