<?php
namespace phpbb2plus\banners\acp;

class manage_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\banners\acp\manage_module','title'=>'ACP_PLUS_BANNERS','modes'=>array(
			'manage'=>array('title'=>'ACP_PLUS_BANNERS','auth'=>'ext_phpbb2plus/banners && acl_a_board','cat'=>array('ACP_BOARD_CONFIGURATION'))));
	}
}
