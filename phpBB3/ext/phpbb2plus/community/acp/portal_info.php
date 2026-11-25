<?php
namespace phpbb2plus\community\acp;

class portal_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\community\acp\portal_module','title'=>'ACP_PLUS_PORTAL','modes'=>array(
			'settings'=>array('title'=>'ACP_PLUS_PORTAL','auth'=>'ext_phpbb2plus/community && acl_a_board','cat'=>array('ACP_BOARD_CONFIGURATION'))));
	}
}
