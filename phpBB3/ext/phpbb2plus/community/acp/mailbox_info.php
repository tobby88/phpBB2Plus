<?php
namespace phpbb2plus\community\acp;

class mailbox_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\community\acp\mailbox_module', 'title' => 'ACP_PLUS_MAILBOX',
			'modes' => array('counts' => array('title' => 'ACP_PLUS_MAILBOX', 'auth' => 'ext_phpbb2plus/community && acl_a_user',
				'cat' => array('ACP_CAT_DATABASE'))));
	}
}
