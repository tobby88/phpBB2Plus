<?php
namespace phpbb2plus\community\acp;

class absence_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\community\acp\absence_module','title'=>'ACP_PLUS_ABSENCE',
			'modes'=>array('edit'=>array('title'=>'ACP_PLUS_ABSENCE','auth'=>'ext_phpbb2plus/community && acl_a_user','display'=>false,'cat'=>array('ACP_CAT_USERS'))));
	}
}
