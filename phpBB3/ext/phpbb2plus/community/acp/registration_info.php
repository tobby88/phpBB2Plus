<?php
namespace phpbb2plus\community\acp;

class registration_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\community\acp\registration_module', 'title' => 'ACP_PLUS_USER_REGISTER',
			'modes' => array('create' => array('title' => 'ACP_PLUS_USER_REGISTER',
				'auth' => 'ext_phpbb2plus/community && acl_a_plus_user_register', 'cat' => array('ACP_CAT_USERS'))));
	}
}
