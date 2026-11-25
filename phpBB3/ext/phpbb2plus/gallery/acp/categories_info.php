<?php
namespace phpbb2plus\gallery\acp;

class categories_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\gallery\acp\categories_module', 'title' => 'ACP_PLUS_ALBUM_CATEGORIES',
			'modes' => array('manage' => array('title' => 'ACP_PLUS_ALBUM_CATEGORIES',
				'auth' => 'ext_phpbb2plus/gallery && (acl_a_plus_gallery || acl_a_plus_album_categories)', 'cat' => array('ACP_BOARD_CONFIGURATION'))));
	}
}
