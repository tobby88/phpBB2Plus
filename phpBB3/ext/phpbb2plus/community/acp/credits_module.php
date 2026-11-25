<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;
use phpbb2plus\community\service\credit_entries;

class credits_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_credits';
	public $page_title = 'ACP_PLUS_CREDITS';

	private function link($action, $id = 0)
	{
		return $this->u_action . '&amp;action=' . $action . ($id ? '&amp;credit=' . (int) $id : '');
	}

	public function main($module, $mode)
	{
		global $phpbb_container, $request, $template;
		$entries = $phpbb_container->get('phpbb2plus.community.credit_entries');
		if (!$entries->may_manage()) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_credits', 'phpbb2plus/community');
		$action = $request->variable('action', '');
		if ($action === '')
		{
			$count = $entries->count(false);
			$pagination = $phpbb_container->get('pagination');
			$start = $pagination->validate_start($request->variable('start', 0), 30, $count);
			$pagination->generate_template_pagination($this->u_action, 'pagination', 'start', $count, 30, $start);
			foreach ($entries->listing(false, 30, $start) as $row)
			{
				$data = $entries->values($row);
				$template->assign_block_vars('credit_list', array('NAME' => $data['title'], 'AUTHOR' => $data['author'],
					'VISIBLE' => $data['visible'], 'EDIT' => $this->link('edit', $row['credit_id']), 'DELETE' => $this->link('delete', $row['credit_id'])));
			}
			$template->assign_var('U_CREDIT_CREATE', $this->link('create'));
			return;
		}
		$id = $request->variable('credit', 0);
		$row = $id > 0 ? $entries->find($id) : array();
		if (!in_array($action, array('create', 'edit', 'delete'), true) || ($action === 'create' ? $id !== 0 : !$row))
		{ trigger_error('FORM_INVALID', E_USER_WARNING); }
		$key = 'plus_credit_' . $action . '_' . $id;
		$data = $entries->values($row);
		$revision = $id ? (int) $row['revision'] : $entries->sequence();
		$error = '';
		if ($request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key($key) || ($action === 'delete' && !$request->variable('confirm', 0, false, request_interface::POST)))
			{ trigger_error('FORM_INVALID', E_USER_WARNING); }
			if ($action !== 'delete')
			{
				foreach (credit_entries::LENGTHS as $field => $limit)
				{
					$data[$field] = htmlspecialchars_decode($request->variable($field, '', true, request_interface::POST), ENT_QUOTES);
				}
				$data['visible'] = $request->variable('visible', 0, false, request_interface::POST) === 1;
			}
			try
			{
				$entries->save($action, $id, $request->variable('revision', -1, false, request_interface::POST), $data);
				redirect($this->u_action);
			}
			catch (\DomainException $e)
			{
				if (in_array($e->getMessage(), array('ACP_PLUS_CREDIT_CHANGED', 'NO_AUTH_OPERATION'), true))
				{ trigger_error($language->lang($e->getMessage()) . adm_back_link($this->u_action), E_USER_WARNING); }
				$error = $language->lang($e->getMessage());
			}
		}
		add_form_key($key);
		foreach (credit_entries::LENGTHS as $field => $limit)
		{
			$template->assign_block_vars('credit_fields', array('KEY' => $field, 'LABEL' => $language->lang('ACP_PLUS_CREDIT_' . strtoupper($field)),
				'VALUE' => $data[$field], 'LONG' => $field === 'description', 'REQUIRED' => in_array($field, array('title','description','author'), true)));
		}
		$template->assign_vars(array('CREDIT_FORM' => true, 'CREDIT_DELETE' => $action === 'delete', 'CREDIT_TITLE' => $data['title'],
			'CREDIT_HEADING' => $language->lang('ACP_PLUS_CREDIT_' . strtoupper($action)), 'CREDIT_VISIBLE' => $data['visible'],
			'CREDIT_REVISION' => $revision, 'CREDIT_ERROR' => $error, 'U_CREDIT_SAVE' => $this->link($action, $id), 'U_BACK' => $this->u_action));
	}
}
