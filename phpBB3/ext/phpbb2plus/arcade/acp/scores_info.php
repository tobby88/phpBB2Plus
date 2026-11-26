<?php
namespace phpbb2plus\arcade\acp;

class scores_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\arcade\acp\scores_module', 'title' => 'ACP_PLUS_ARCADE_SCORES', 'modes' => array(
			'manage' => array('title' => 'ACP_PLUS_ARCADE_SCORES', 'auth' => 'ext_phpbb2plus/arcade && acl_a_plus_arc_scores',
				'cat' => array('ACP_BOARD_CONFIGURATION'))));
	}
}
