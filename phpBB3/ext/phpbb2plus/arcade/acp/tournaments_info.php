<?php
namespace phpbb2plus\arcade\acp;

class tournaments_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\arcade\acp\tournaments_module', 'title'=>'ACP_PLUS_ARCADE_TOURNAMENTS', 'modes'=>array(
			'manage'=>array('title'=>'ACP_PLUS_ARCADE_TOURNAMENTS', 'auth'=>'ext_phpbb2plus/arcade && acl_a_plus_arcade',
				'cat'=>array('ACP_BOARD_CONFIGURATION'))));
	}
}
