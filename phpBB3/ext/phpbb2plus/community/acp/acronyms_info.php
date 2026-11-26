<?php
namespace phpbb2plus\community\acp;

class acronyms_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\community\acp\acronyms_module', 'title' => 'ACP_PLUS_ACRONYMS',
			'modes' => array('manage' => array('title' => 'ACP_PLUS_ACRONYMS', 'auth' => 'ext_phpbb2plus/community && acl_a_plus_acronyms',
				'cat' => array('ACP_MESSAGES'))));
	}
}
