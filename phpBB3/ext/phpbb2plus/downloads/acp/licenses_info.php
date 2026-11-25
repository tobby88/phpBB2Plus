<?php
namespace phpbb2plus\downloads\acp;

class licenses_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\downloads\acp\licenses_module','title'=>'ACP_PLUS_DL_LICENSES',
			'modes'=>array('manage'=>array('title'=>'ACP_PLUS_DL_LICENSES','cat'=>array('ACP_PLUS_DOWNLOADS'),
				'auth'=>'ext_phpbb2plus/downloads && (acl_a_plus_downloads || acl_a_plus_dl_licenses)')));
	}
}
