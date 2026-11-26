<?php
namespace phpbb2plus\arcade\acp;

class categories_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\arcade\acp\categories_module', 'title' => 'ACP_PLUS_ARC_CATEGORIES',
			'modes' => array('manage' => array('title' => 'ACP_PLUS_ARC_CATEGORIES',
				'auth' => 'ext_phpbb2plus/arcade && acl_a_plus_arc_categories', 'cat' => array('ACP_BOARD_CONFIGURATION'))));
	}
}
