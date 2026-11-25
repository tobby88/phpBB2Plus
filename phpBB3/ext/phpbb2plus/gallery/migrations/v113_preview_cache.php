<?php
namespace phpbb2plus\gallery\migrations;

class v113_preview_cache extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\gallery\migrations\v112_member_picture_indexes'); }

	public function update_data()
	{
		return array(array('config.add', array('plus_gallery_cache_generation', 0, true)),
			array('config.add', array('plus_gallery_cache_cleanup_after', 0, true)),
			array('permission.add', array('a_plus_album_cache')),
			array('module.add', array('acp', 'ACP_BOARD_CONFIGURATION', array(
				'module_basename' => '\phpbb2plus\gallery\acp\cache_module', 'modes' => array('manage')))));
	}
}
