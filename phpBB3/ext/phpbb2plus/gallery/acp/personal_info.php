<?php
namespace phpbb2plus\gallery\acp;

class personal_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\gallery\acp\personal_module', 'title' => 'ACP_PLUS_PERSONAL_ALBUMS', 'modes' => array(
			'settings' => array('title' => 'ACP_PLUS_PERSONAL_ALBUMS', 'auth' => 'ext_phpbb2plus/gallery && acl_a_plus_gallery',
				'cat' => array('ACP_BOARD_CONFIGURATION')),
			'display' => array('title' => 'ACP_PLUS_ALBUM_DISPLAY', 'auth' => 'ext_phpbb2plus/gallery && acl_a_plus_gallery',
				'cat' => array('ACP_BOARD_CONFIGURATION')),
			'ratings' => array('title' => 'ACP_PLUS_ALBUM_RATINGS', 'auth' => 'ext_phpbb2plus/gallery && acl_a_plus_gallery',
				'cat' => array('ACP_BOARD_CONFIGURATION')),
			'media' => array('title' => 'ACP_PLUS_ALBUM_UPLOADS', 'auth' => 'ext_phpbb2plus/gallery && acl_a_plus_gallery',
				'cat' => array('ACP_BOARD_CONFIGURATION'))));
	}
}
