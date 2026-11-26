<?php
namespace phpbb2plus\community\acp;

class absence_module
{
	public $u_action;
	public $tpl_name='acp_plus_absence';
	public $page_title='ACP_PLUS_ABSENCE';
	public function main($id,$mode)
	{
		global $request,$phpbb_container;
		$target=$request->variable('u',0,false,\phpbb\request\request_interface::GET);
		try { $phpbb_container->get('phpbb2plus.community.absence_editor')->show($target,$this->u_action . '&amp;u=' . $target,true); }
		catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e)
		{
			send_status_line($e->getStatusCode(),$e->getStatusCode()===404 ? 'Not Found' : 'Forbidden');
			trigger_error('NO_AUTH_OPERATION',E_USER_WARNING);
		}
	}
}
