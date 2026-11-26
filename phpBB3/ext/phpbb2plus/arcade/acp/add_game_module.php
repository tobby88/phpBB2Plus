<?php
namespace phpbb2plus\arcade\acp;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class add_game_module extends games_module
{
	public $page_title = 'ACP_PLUS_ARC_ADD';

	public function main($id, $mode)
	{
		global $phpbb_container,$request,$template;
		$service = $phpbb_container->get('phpbb2plus.arcade.game_editor');
		$lang = $phpbb_container->get('language');
		$lang->add_lang('info_acp_arc_games','phpbb2plus/arcade');
		$created = $request->variable('created',0);
		if ($created>0) { $template->assign_var('ARC_CREATED',$lang->lang('PLUS_ARC_ADD_DONE',$created)); }
		try { $this->edit($service,0,$lang,$request,$template,$this->u_action,true); }
		catch (AccessDeniedHttpException $e) { send_status_line(403,'Forbidden'); trigger_error('NO_AUTH_OPERATION',E_USER_WARNING); }
		catch (\DomainException $e) { trigger_error($lang->lang($e->getMessage()),E_USER_WARNING); }
	}
}
