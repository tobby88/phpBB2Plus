<?php
namespace phpbb2plus\arcade\acp;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class scores_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $phpbb_container;
		$this->tpl_name = 'acp_plus_arcade_scores';
		$this->page_title = 'ACP_PLUS_ARCADE_SCORES';
		try
		{
			$response = $phpbb_container->get('phpbb2plus.arcade.score_panel')->display($this->u_action, true);
			if ($response instanceof RedirectResponse) { redirect($response->getTargetUrl()); }
			if ($response instanceof Response)
			{
				$response->prepare($phpbb_container->get('symfony_request'))->send();
				garbage_collection();
				exit_handler();
			}
			if ($response !== 200) { send_status_line($response, Response::$statusTexts[$response]); }
		}
		catch (NotFoundHttpException $e) { send_status_line(403, 'Forbidden'); trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
	}
}
