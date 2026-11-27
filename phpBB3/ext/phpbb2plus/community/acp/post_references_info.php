<?php
namespace phpbb2plus\community\acp;

class post_references_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\community\acp\post_references_module', 'title'=>'ACP_PLUS_POST_REFERENCES',
			'modes'=>array('check'=>array('title'=>'ACP_PLUS_POST_REFERENCES', 'auth'=>'ext_phpbb2plus/community && acl_a_backup',
				'cat'=>array('ACP_CAT_DATABASE'))));
	}
}
