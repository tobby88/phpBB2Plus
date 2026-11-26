<?php
namespace phpbb2plus\community\acp;

class user_pruning_info
{
	public function module()
	{
		return array(
			'filename' => '\phpbb2plus\community\acp\user_pruning_module',
			'title' => 'ACP_PLUS_USER_PRUNING',
			'modes' => array('manage' => array(
				'title' => 'ACP_PLUS_USER_PRUNING',
				'auth' => 'ext_phpbb2plus/community && acl_a_userdel',
				'cat' => array('ACP_CAT_USERS'),
			)),
		);
	}
}
