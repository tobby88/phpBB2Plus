<?php
namespace phpbb2plus\downloads\acp;

class file_actions_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\downloads\acp\file_actions_module','title'=>'ACP_PLUS_DL_FILE_ACTIONS',
			'modes'=>array('manage'=>array('title'=>'ACP_PLUS_DL_FILE_ACTIONS','cat'=>array('ACP_PLUS_DOWNLOADS'),
				'auth'=>'ext_phpbb2plus/downloads && (acl_a_plus_downloads || acl_a_plus_dl_approval || acl_a_plus_dl_delete)')));
	}
}
