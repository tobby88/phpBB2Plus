<?php
namespace phpbb2plus\community\acp;

class request_blocks_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\community\acp\request_blocks_module', 'title' => 'ACP_PLUS_CT_BLOCKS', 'modes' => array(
			'rules' => array('title' => 'ACP_PLUS_CT_BLOCKS', 'auth' => 'ext_phpbb2plus/community && acl_a_plus_ct_blocks', 'cat' => array('ACP_SERVER_CONFIGURATION')),
			'history' => array('title' => 'ACP_PLUS_CT_BLOCK_LOG', 'auth' => 'ext_phpbb2plus/community && acl_a_viewlogs', 'cat' => array('ACP_FORUM_LOGS'))));
	}
}
