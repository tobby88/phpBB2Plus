<?php
namespace phpbb2plus\gallery\acp;

use phpbb\request\request_interface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class cache_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_album_cache';
	public $page_title = 'ACP_PLUS_ALBUM_CACHE';

	public function main($id, $mode)
	{
		global $phpbb_container, $request, $template;
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_gallery', 'phpbb2plus/gallery');
		try
		{
			$phpbb_container->get('phpbb2plus.gallery.admin_access')->authorize('cache');
			if ($request->server('REQUEST_METHOD') === 'POST')
			{
				if (!check_form_key('plus_album_clear_cache') || !$request->is_set('confirm', request_interface::POST)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
				$phpbb_container->get('phpbb2plus.gallery.preview_cache')->invalidate();
				trigger_error($language->lang('PLUS_ALBUM_CACHE_CLEARED') . adm_back_link($this->u_action));
			}
			add_form_key('plus_album_clear_cache');
			$template->assign_var('U_ACTION', $this->u_action);
		}
		catch (AccessDeniedHttpException $e) { trigger_error('NOT_AUTHORISED_OPERATION', E_USER_WARNING); }
	}
}
