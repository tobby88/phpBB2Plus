<?php
namespace phpbb2plus\community\acp;

class poll_recovery_info
{
	public function module()
	{
		return array('filename'=>'\\phpbb2plus\\community\\acp\\poll_recovery_module', 'title'=>'ACP_PLUS_POLL_REVIEW',
			'modes'=>array('review'=>array('title'=>'ACP_PLUS_POLL_REVIEW', 'auth'=>'ext_phpbb2plus/community && acl_a_backup',
				'cat'=>array('ACP_CAT_DATABASE'))));
	}
}
