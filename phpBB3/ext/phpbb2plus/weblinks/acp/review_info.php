<?php
namespace phpbb2plus\weblinks\acp;

class review_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\weblinks\acp\review_module','title'=>'ACP_PLUS_WL',
			'modes'=>array('review'=>array('title'=>'ACP_PLUS_WL','cat'=>array('ACP_BOARD_CONFIGURATION'),
				'auth'=>'ext_phpbb2plus/weblinks && acl_a_plus_wl_review')));
	}
}
