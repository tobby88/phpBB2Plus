<?php
namespace phpbb2plus\arcade\acp;

class import_game_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\arcade\acp\import_game_module','title'=>'ACP_PLUS_ARC_IMPORT',
			'modes'=>array('manage'=>array('title'=>'ACP_PLUS_ARC_IMPORT','auth'=>'ext_phpbb2plus/arcade && acl_a_plus_arc_import',
				'cat'=>array('ACP_BOARD_CONFIGURATION'))));
	}
}
