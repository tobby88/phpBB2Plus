<?php
namespace phpbb2plus\community\acp;

class member_directory_info
{
	public function module()
	{
		$modes = array();
		foreach (member_directory_module::RIGHTS as $mode => $permission)
		{
			$modes[$mode] = array('title' => 'ACP_PLUS_DIRECTORY_' . strtoupper($mode),
				'auth' => 'ext_phpbb2plus/community && acl_' . $permission, 'cat' => array('ACP_CAT_USERS'));
		}
		return array('filename' => '\phpbb2plus\community\acp\member_directory_module', 'title' => 'ACP_PLUS_DIRECTORY_MEMBERS', 'modes' => $modes);
	}
}
