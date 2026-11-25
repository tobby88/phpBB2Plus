<?php
namespace phpbb2plus\knowledgebase\acp;

class articles_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\knowledgebase\acp\articles_module', 'title' => 'ACP_PLUS_KB_ARTICLES',
			'modes' => array('manage' => array('title' => 'ACP_PLUS_KB_ARTICLES',
				'auth' => 'ext_phpbb2plus/knowledgebase && (acl_a_plus_kb || acl_a_plus_kb_articles)', 'cat' => array('ACP_PLUS_KB'))));
	}
}
