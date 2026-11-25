<?php
namespace phpbb2plus\community\acp;

use phpbb2plus\community\service\statistics_admin;

class statistics_info
{
	public function module()
	{
		$modes = array();
		foreach (statistics_admin::RIGHTS as $mode => $right)
		{
			$modes[$mode] = array('title' => 'ACP_PLUS_STATS_' . strtoupper($mode), 'auth' => 'ext_phpbb2plus/community && acl_' . $right,
				'cat' => array('ACP_BOARD_CONFIGURATION'));
		}
		return array('filename' => '\\phpbb2plus\\community\\acp\\statistics_module', 'title' => 'ACP_PLUS_STATS_MANAGE', 'modes' => $modes);
	}
}
