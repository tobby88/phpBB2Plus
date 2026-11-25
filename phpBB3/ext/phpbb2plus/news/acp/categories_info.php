<?php
namespace phpbb2plus\news\acp;

class categories_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\news\acp\categories_module', 'title'=>'ACP_PLUS_NEWS_CATEGORIES', 'modes'=>array(
			'manage'=>array('title'=>'ACP_PLUS_NEWS_CATEGORIES','auth'=>'ext_phpbb2plus/news && acl_a_board','cat'=>array('ACP_MESSAGES'))));
	}
}
