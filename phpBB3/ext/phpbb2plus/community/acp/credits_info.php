<?php
namespace phpbb2plus\community\acp;

class credits_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\community\acp\credits_module', 'title' => 'ACP_PLUS_CREDITS',
			'modes' => array('manage' => array('title' => 'ACP_PLUS_CREDITS', 'auth' => 'ext_phpbb2plus/community && acl_a_plus_credits',
				'cat' => array('ACP_BOARD_CONFIGURATION'))));
	}
}
