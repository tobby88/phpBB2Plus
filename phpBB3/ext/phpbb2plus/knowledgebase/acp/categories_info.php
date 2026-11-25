<?php
namespace phpbb2plus\knowledgebase\acp;

class categories_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\knowledgebase\acp\categories_module', 'title' => 'ACP_PLUS_KB_CATEGORIES',
			'modes' => array('manage' => array('title' => 'ACP_PLUS_KB_CATEGORIES',
				'auth' => 'ext_phpbb2plus/knowledgebase && (acl_a_plus_kb || acl_a_plus_kb_categories)', 'cat' => array('ACP_PLUS_KB'))));
	}
}
