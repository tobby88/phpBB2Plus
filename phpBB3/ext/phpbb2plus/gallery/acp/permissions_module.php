<?php
namespace phpbb2plus\gallery\acp;

use phpbb\request\request_interface;
use phpbb2plus\gallery\service\permissions;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class permissions_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_album_permissions';
	public $page_title = 'ACP_PLUS_ALBUM_AUTH';

	private function url($id, $page)
	{
		return $this->u_action . '&amp;category=' . $id . '&amp;page=' . $page;
	}

	public function main($module, $mode)
	{
		global $phpbb_container, $request, $template;
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_gallery', 'phpbb2plus/gallery');
		$service = $phpbb_container->get('phpbb2plus.gallery.permissions');
		$id = $request->variable('category', 0, false, request_interface::GET);
		$page = $request->variable('page', 1, false, request_interface::GET);
		$template->assign_var('U_BACK', $this->u_action);
		try
		{
			if (!$id)
			{
				foreach ($service->tree() as $other => $cat)
				{
					$template->assign_block_vars('categories', array('TITLE' => $cat['title'], 'INDENT' => str_repeat('— ', min(12, $cat['depth'])),
						'U_EDIT' => $this->url($other, 1)));
				}
				$template->assign_var('S_DIRECTORY', true);
				return;
			}
			$key = 'plus_album_grants_' . $id . '_' . $page;
			if ($request->server('REQUEST_METHOD') === 'POST')
			{
				if (!check_form_key($key)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
				if (!$request->variable('complete', 0, false, request_interface::POST)) { throw new \DomainException('PLUS_ALBUM_GRANTS_CHANGED'); }
				$submitted = $request->variable('grant', array(0 => array('' => '')), false, request_interface::POST);
				$service->save($id, $page, $submitted, $request->variable('state', '', false, request_interface::POST));
				redirect($this->url($id, $page));
			}
			$current = $service->page($id, $page);
			foreach (permissions::ACTIONS as $action) { $template->assign_block_vars('actions', array('LABEL' => $language->lang('PLUS_ALBUM_GRANT_' . strtoupper($action)))); }
			foreach ($current['groups'] as $group => $name)
			{
				$template->assign_block_vars('groups', array('ID' => $group, 'NAME' => $name));
				foreach (permissions::ACTIONS as $action)
				{
					$template->assign_block_vars('groups.actions', array('NAME' => $action, 'LABEL' => $language->lang('PLUS_ALBUM_GRANT_' . strtoupper($action)),
						'CHECKED' => isset($current['selected'][$group][$action])));
				}
			}
			add_form_key($key);
			$template->assign_vars(array('S_MATRIX' => true, 'ALBUM_CATEGORY' => $current['category']['title'],
				'ALBUM_STATE' => $current['state'], 'U_SAVE' => $this->url($id, $page), 'ALBUM_PAGE' => $page, 'ALBUM_PAGES' => $current['pages'],
				'U_PREV' => $page > 1 ? $this->url($id, $page - 1) : '', 'U_NEXT' => $page < $current['pages'] ? $this->url($id, $page + 1) : ''));
		}
		catch (AccessDeniedHttpException $e) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		catch (\DomainException $e) { $template->assign_var('ALBUM_ERROR', $language->lang($e->getMessage())); }
	}
}
