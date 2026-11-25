<?php
namespace phpbb2plus\knowledgebase\acp;

class settings_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\knowledgebase\acp\settings_module', 'title' => 'ACP_PLUS_KB_SETTINGS',
			'modes' => array('manage' => array('title' => 'ACP_PLUS_KB_SETTINGS',
				'auth' => 'ext_phpbb2plus/knowledgebase && (acl_a_plus_kb || acl_a_plus_kb_settings)', 'cat' => array('ACP_PLUS_KB'))));
	}
}
