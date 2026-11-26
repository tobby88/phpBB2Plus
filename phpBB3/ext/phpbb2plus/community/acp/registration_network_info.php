<?php
namespace phpbb2plus\community\acp;

class registration_network_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\community\acp\registration_network_module', 'title' => 'ACP_PLUS_REG_NETWORK',
			'modes' => array('view' => array('title' => 'ACP_PLUS_REG_NETWORK', 'auth' => 'ext_phpbb2plus/community && acl_a_plus_reg_network', 'cat' => array('ACP_CAT_USERS'))));
	}
}
