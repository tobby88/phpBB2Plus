<?php
namespace phpbb2plus\downloads\acp;

class checker_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\downloads\acp\checker_module', 'title'=>'ACP_PLUS_DL_CHECKER',
			'modes'=>array('report'=>array('title'=>'ACP_PLUS_DL_CHECKER', 'cat'=>array('ACP_PLUS_DOWNLOADS'),
				'auth'=>'ext_phpbb2plus/downloads && (acl_a_plus_downloads || acl_a_plus_dl_checker)')));
	}
}
