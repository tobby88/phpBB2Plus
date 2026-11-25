<?php
namespace phpbb2plus\gallery\acp;

class permissions_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\gallery\acp\permissions_module', 'title' => 'ACP_PLUS_ALBUM_AUTH',
			'modes' => array('manage' => array('title' => 'ACP_PLUS_ALBUM_AUTH',
				'auth' => 'ext_phpbb2plus/gallery && (acl_a_plus_gallery || acl_a_plus_album_auth)', 'cat' => array('ACP_BOARD_CONFIGURATION'))));
	}
}
