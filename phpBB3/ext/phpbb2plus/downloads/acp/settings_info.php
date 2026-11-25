<?php
namespace phpbb2plus\downloads\acp;

class settings_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\downloads\acp\settings_module','title'=>'ACP_PLUS_DL_SETTINGS',
			'modes'=>array('manage'=>array('title'=>'ACP_PLUS_DL_SETTINGS','cat'=>array('ACP_PLUS_DOWNLOADS'),
				'auth'=>'ext_phpbb2plus/downloads && (acl_a_plus_downloads || acl_a_plus_dl_settings)')));
	}
}
