<?php
namespace phpbb2plus\community\acp;

class flags_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\community\acp\flags_module', 'title' => 'ACP_PLUS_FLAGS',
			'modes' => array('manage' => array('title' => 'ACP_PLUS_FLAGS', 'auth' => 'ext_phpbb2plus/community && acl_a_plus_flags',
				'cat' => array('ACP_CAT_USERS'))));
	}
}
