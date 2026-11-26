<?php
namespace phpbb2plus\arcade\acp;

class games_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\arcade\acp\games_module','title'=>'ACP_PLUS_ARC_GAMES',
			'modes'=>array('manage'=>array('title'=>'ACP_PLUS_ARC_GAMES','auth'=>'ext_phpbb2plus/arcade && acl_a_plus_arc_games',
				'cat'=>array('ACP_BOARD_CONFIGURATION'))));
	}
}
