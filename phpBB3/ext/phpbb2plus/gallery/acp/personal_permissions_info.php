<?php
namespace phpbb2plus\gallery\acp;

class personal_permissions_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\gallery\acp\personal_permissions_module', 'title' => 'ACP_PLUS_PERSONAL_GRANTS',
			'modes' => array('manage' => array('title' => 'ACP_PLUS_PERSONAL_GRANTS',
				'auth' => 'ext_phpbb2plus/gallery && (acl_a_plus_gallery || acl_a_plus_album_personal_auth)', 'cat' => array('ACP_BOARD_CONFIGURATION'))));
	}
}
