<?php
namespace phpbb2plus\community\acp;

class file_integrity_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\community\acp\file_integrity_module', 'title' => 'ACP_PLUS_CT_FILES',
			'modes' => array('main' => array('title' => 'ACP_PLUS_CT_FILES', 'auth' => 'ext_phpbb2plus/community && acl_a_plus_ct_files',
				'cat' => array('ACP_SERVER_CONFIGURATION'))));
	}
}
