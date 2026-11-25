<?php
namespace phpbb2plus\community\acp;

class quotas_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\community\acp\quotas_module','title'=>'ACP_PLUS_ATTACH_QUOTAS','modes'=>array(
			'manage'=>array('title'=>'ACP_PLUS_ATTACH_QUOTAS','auth'=>'ext_phpbb2plus/community && acl_a_attach','cat'=>array('ACP_ATTACHMENTS'))));
	}
}
