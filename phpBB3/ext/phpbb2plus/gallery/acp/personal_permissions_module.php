<?php
namespace phpbb2plus\gallery\acp;

use phpbb\request\request_interface;
use phpbb2plus\gallery\service\permissions;
use phpbb2plus\gallery\service\personal_grants;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class personal_permissions_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_personal_grants';
	public $page_title = 'ACP_PLUS_PERSONAL_GRANTS';

	private function url($group, $page)
	{
		return $this->u_action . '&amp;group=' . $group . '&amp;page=' . $page;
	}

	public function main($module, $mode)
	{
		global $phpbb_container, $request, $template;
		$lang = $phpbb_container->get('language');
		$lang->add_lang('info_acp_gallery', 'phpbb2plus/gallery');
		$service = $phpbb_container->get('phpbb2plus.gallery.personal_grants');
		$group = $request->variable('group', 0, false, request_interface::GET);
		$page = max(1, $request->variable('page', 1, false, request_interface::GET));
		$template->assign_vars(array('U_BACK' => $this->url(0, $page), 'U_EDIT' => $this->url($group, $page)));
		try
		{
			if (!$group)
			{
				$listing = $service->groups($page);
				foreach ($listing['groups'] as $id => $name) { $template->assign_block_vars('groups', array('NAME' => $name, 'U_EDIT' => $this->url($id, $page))); }
				$template->assign_vars(array('S_LIST' => true, 'ALBUM_PAGE' => $page, 'ALBUM_PAGES' => $listing['pages'],
					'U_PREV' => $page > 1 ? $this->url(0, $page-1) : '', 'U_NEXT' => $page < $listing['pages'] ? $this->url(0, $page+1) : ''));
				return;
			}
			$current = $service->review($group);
			$template->assign_vars(array('GROUP_NAME' => $current['name'], 'S_NATIVE' => $current['native'], 'TOTAL' => $current['total'], 'STATE' => $current['state']));
			$key = 'plus_personal_grants_review_' . $group;
			if ($request->server('REQUEST_METHOD') === 'POST')
			{
				$confirm = $request->variable('confirm', 0, false, request_interface::POST);
				if (!$confirm && !check_form_key($key)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
				$state = $request->variable('state', '', false, request_interface::POST);
				if (!hash_equals($current['state'], $state)) { throw new \DomainException('PLUS_ALBUM_GRANTS_CHANGED'); }
				$scope = $request->variable('scope', '', false, request_interface::POST);
				$fields = $request->variable('change', array('' => ''), false, request_interface::POST);
				$changes = $service->changes($current, $fields, $scope);
				$token = personal_grants::confirmation($group, $state, $scope, $changes);
				if ($confirm)
				{
					if (!check_form_key($token)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
					$service->save($group, $fields, $scope, $state);
					redirect($this->url($group, $page));
				}
				foreach ($fields as $action => $value) { $template->assign_block_vars('fields', array('NAME' => $action, 'VALUE' => $value)); }
				foreach ($changes as $action => $change)
				{
					if ($action === 'create') { $impact = $lang->lang('PLUS_PERSONAL_GRANTS_CREATION_IMPACT'); }
					elseif (!$change['affected']) { $impact = $lang->lang('PLUS_PERSONAL_GRANTS_FUTURE'); }
					else { $impact = $lang->lang($change['default'] ? 'PLUS_PERSONAL_GRANTS_IMPACT' : 'PLUS_PERSONAL_GRANTS_EXISTING_IMPACT', $change['affected']); }
					$template->assign_block_vars('changes', array('LABEL' => $lang->lang('PLUS_ALBUM_GRANT_' . strtoupper($action)),
						'VALUE' => $lang->lang($change['grant'] ? 'PLUS_PERSONAL_GRANTS_GRANT' : 'PLUS_PERSONAL_GRANTS_REMOVE'), 'IMPACT' => $impact));
				}
				add_form_key($token);
				$template->assign_vars(array('S_PREVIEW' => true, 'SCOPE_LABEL' => $lang->lang('PLUS_PERSONAL_GRANTS_' . strtoupper($scope)), 'SCOPE' => $scope));
				return;
			}
			$creation = $current['creation'];
			$template->assign_vars(array('S_FORM' => true, 'CREATE_YES' => $creation['direct'] === ACL_YES,
				'CREATE_NEVER' => $creation['direct'] === ACL_NEVER || $creation['role'] === ACL_NEVER, 'ROLE_YES' => $creation['role'] === ACL_YES));
			foreach (permissions::ACTIONS as $action)
			{
				$template->assign_block_vars('actions', array('NAME' => $action, 'LABEL' => $lang->lang('PLUS_ALBUM_GRANT_' . strtoupper($action)),
					'DEFAULT' => isset($current['defaults'][$action]), 'EXISTING' => $current['counts'][$action]));
			}
			add_form_key($key);
		}
		catch (AccessDeniedHttpException $e)
		{
			send_status_line(403, 'Forbidden');
			trigger_error('NO_AUTH_OPERATION', E_USER_WARNING);
		}
		catch (\DomainException $e) { $template->assign_var('ALBUM_ERROR', $lang->lang($e->getMessage())); }
	}
}
