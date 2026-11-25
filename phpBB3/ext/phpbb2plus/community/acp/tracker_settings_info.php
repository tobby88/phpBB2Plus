<?php
namespace phpbb2plus\community\acp;

class tracker_settings_info
{
	public function module()
	{
		return array('filename' => '\phpbb2plus\community\acp\tracker_settings_module', 'title' => 'ACP_PLUS_TRACKER_SETTINGS',
			'modes' => array('manage' => array('title' => 'ACP_PLUS_TRACKER_SETTINGS',
				'auth' => 'ext_phpbb2plus/community && (acl_a_server || acl_a_plus_ct_settings)', 'cat' => array('ACP_SERVER_CONFIGURATION'))));
	}
}
