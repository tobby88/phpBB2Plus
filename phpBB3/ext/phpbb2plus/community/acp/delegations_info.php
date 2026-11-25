<?php
namespace phpbb2plus\community\acp;

class delegations_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\community\acp\delegations_module', 'title' => 'ACP_PLUS_JR_MANAGE',
			'modes' => array('manage' => array('title' => 'ACP_PLUS_JR_MANAGE', 'auth' => 'ext_phpbb2plus/community && acl_a_aauth && acl_a_authusers',
				'cat' => array('ACP_GLOBAL_PERMISSIONS'))));
	}
}
