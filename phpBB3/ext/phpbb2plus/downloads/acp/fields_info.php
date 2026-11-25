<?php
namespace phpbb2plus\downloads\acp;

class fields_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\downloads\acp\fields_module','title'=>'ACP_PLUS_DL_FIELDS',
			'modes'=>array('manage'=>array('title'=>'ACP_PLUS_DL_FIELDS','cat'=>array('ACP_PLUS_DOWNLOADS'),
				'auth'=>'ext_phpbb2plus/downloads && (acl_a_plus_downloads || acl_a_plus_dl_fields)')));
	}
}
