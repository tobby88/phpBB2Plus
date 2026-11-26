<?php
namespace phpbb2plus\arcade\acp;

class diagnostics_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\arcade\acp\diagnostics_module','title'=>'ACP_PLUS_ARC_LOG',
			'modes'=>array('manage'=>array('title'=>'ACP_PLUS_ARC_LOG','auth'=>'ext_phpbb2plus/arcade && acl_a_plus_arc_log',
				'cat'=>array('ACP_BOARD_CONFIGURATION'))));
	}
}
