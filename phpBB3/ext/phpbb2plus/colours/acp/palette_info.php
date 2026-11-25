<?php
namespace phpbb2plus\colours\acp;

class palette_info
{
	public function module()
	{
		return array('filename'=>'\phpbb2plus\colours\acp\palette_module','title'=>'ACP_PLUS_COLOURS','modes'=>array(
			'manage'=>array('title'=>'ACP_PLUS_COLOURS','auth'=>'ext_phpbb2plus/colours && acl_a_group','cat'=>array('ACP_GROUPS'))));
	}
}
