<?php
namespace phpbb2plus\knowledgebase\acp;

class types_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\knowledgebase\acp\types_module', 'title' => 'ACP_PLUS_KB_TYPES',
			'modes' => array('manage' => array('title' => 'ACP_PLUS_KB_TYPES',
				'auth' => 'ext_phpbb2plus/knowledgebase && (acl_a_plus_kb || acl_a_plus_kb_types)', 'cat' => array('ACP_PLUS_KB'))));
	}
}
