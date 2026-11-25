<?php
namespace phpbb2plus\arcade\acp;

class settings_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\arcade\acp\settings_module', 'title'=>'ACP_PLUS_ARCADE_SETTINGS', 'modes'=>array(
			'settings'=>array('title'=>'ACP_PLUS_ARCADE_SETTINGS', 'auth'=>'ext_phpbb2plus/arcade && acl_a_plus_arcade',
				'cat'=>array('ACP_BOARD_CONFIGURATION'))));
	}
}
