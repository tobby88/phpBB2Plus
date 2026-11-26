<?php
namespace phpbb2plus\community\acp;

class structure_info
{
	public function module()
	{
		return array('filename'=>'\\phpbb2plus\\community\\acp\\structure_module', 'title'=>'ACP_PLUS_STRUCTURE',
			'modes'=>array('review'=>array('title'=>'ACP_PLUS_STRUCTURE', 'auth'=>'ext_phpbb2plus/community && acl_a_backup',
				'cat'=>array('ACP_CAT_DATABASE'))));
	}
}
