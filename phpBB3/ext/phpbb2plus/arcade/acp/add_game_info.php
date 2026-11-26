<?php
namespace phpbb2plus\arcade\acp;

class add_game_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\arcade\acp\add_game_module','title'=>'ACP_PLUS_ARC_ADD',
			'modes'=>array('manage'=>array('title'=>'ACP_PLUS_ARC_ADD','auth'=>'ext_phpbb2plus/arcade && acl_a_plus_arc_add',
				'cat'=>array('ACP_BOARD_CONFIGURATION'))));
	}
}
