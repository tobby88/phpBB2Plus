<?php
namespace phpbb2plus\weblinks\acp;

class management_info
{
	public function module()
	{
		$modes = array();
		foreach (array('create'=>'acl_a_plus_wl_create','entries'=>'(acl_a_plus_wl_edit || acl_a_plus_wl_delete)',
			'categories'=>'acl_a_plus_wl_categories','settings'=>'acl_a_plus_wl_settings') as $mode=>$rule)
		{
			$modes[$mode] = array('title'=>'ACP_PLUS_WL_' . strtoupper($mode), 'cat'=>array('ACP_BOARD_CONFIGURATION'),
				'auth'=>'ext_phpbb2plus/weblinks && ' . $rule);
		}
		return array('filename'=>'\phpbb2plus\weblinks\acp\management_module','title'=>'WL_TITLE','modes'=>$modes);
	}
}
