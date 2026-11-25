<?php
namespace phpbb2plus\gallery\acp;

class cache_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\gallery\acp\cache_module', 'title' => 'ACP_PLUS_ALBUM_CACHE',
			'modes' => array('manage' => array('title' => 'ACP_PLUS_ALBUM_CACHE',
				'auth' => 'ext_phpbb2plus/gallery && (acl_a_plus_gallery || acl_a_plus_album_cache)', 'cat' => array('ACP_BOARD_CONFIGURATION'))));
	}
}
