<?php
namespace phpbb2plus\community\acp;

class attachment_blocks_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\community\acp\attachment_blocks_module', 'title'=>'ACP_PLUS_ATTACH_BLOCKS',
			'modes'=>array('manage'=>array('title'=>'ACP_PLUS_ATTACH_BLOCKS', 'auth'=>'ext_phpbb2plus/community && acl_a_plus_attach_block', 'cat'=>array('ACP_ATTACHMENTS'))));
	}
}
