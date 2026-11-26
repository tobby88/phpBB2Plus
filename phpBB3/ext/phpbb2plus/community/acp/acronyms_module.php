<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;
use phpbb2plus\community\service\acronym_dictionary;

class acronyms_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_acronyms';
	public $page_title = 'ACP_PLUS_ACRONYMS';

	private function url($action, $id = 0)
	{
		return $this->u_action . '&amp;action=' . $action . ($id ? '&amp;acronym=' . (int) $id : '');
	}

	public function main($module, $mode)
	{
		global $phpbb_container, $request, $template;
		$entries = $phpbb_container->get('phpbb2plus.community.acronym_entries');
		if (!$entries->allowed()) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$lang = $phpbb_container->get('language');
		$lang->add_lang('info_acp_acronyms', 'phpbb2plus/community');
		$action = $request->variable('action', '');
		if ($action === '')
		{
			$pagination = $phpbb_container->get('pagination');
			$count = $entries->total();
			$start = $pagination->validate_start($request->variable('start', 0), 30, $count);
			$pagination->generate_template_pagination($this->u_action, 'pagination', 'start', $count, 30, $start);
			foreach ($entries->page($start, 30) as $row)
			{
				$template->assign_block_vars('acronyms', array('TERM' => acronym_dictionary::decode($row['term_text']),
					'MEANING' => acronym_dictionary::decode($row['definition_text']), 'U_EDIT' => $this->url('edit', $row['acronym_id']),
					'U_DELETE' => $this->url('delete', $row['acronym_id'])));
			}
			$template->assign_var('U_ADD', $this->url('add'));
			return;
		}
		$id = $request->variable('acronym', 0);
		if (!in_array($action, array('add', 'edit', 'delete'), true) || ($action === 'add' ? $id !== 0 : $id < 1))
		{ trigger_error('FORM_INVALID', E_USER_WARNING); }
		$row = $id ? $entries->find($id) : array();
		if ($id && !$row) { trigger_error('PLUS_ACRONYM_STALE', E_USER_WARNING); }
		$term = acronym_dictionary::decode($row['term_text'] ?? '');
		$definition = acronym_dictionary::decode($row['definition_text'] ?? '');
		$revision = $id ? (int) $row['revision'] : $entries->sequence();
		$key = 'plus_acronym_' . $action . '_' . $id;
		$error = '';
		if ($request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key($key) || ($action === 'delete' && !$request->variable('confirm', 0, false, request_interface::POST)))
			{ trigger_error('FORM_INVALID', E_USER_WARNING); }
			if ($action !== 'delete')
			{
				$term = trim(htmlspecialchars_decode($request->variable('term', '', true, request_interface::POST), ENT_QUOTES));
				$definition = trim(htmlspecialchars_decode($request->variable('meaning', '', true, request_interface::POST), ENT_QUOTES));
			}
			try
			{
				$entries->save($action, $id, $request->variable('revision', -1, false, request_interface::POST), $term, $definition);
				redirect($this->u_action);
			}
			catch (\DomainException $exception)
			{
				if (in_array($exception->getMessage(), array('PLUS_ACRONYM_STALE', 'NO_AUTH_OPERATION'), true))
				{ trigger_error($lang->lang($exception->getMessage()) . adm_back_link($this->u_action), E_USER_WARNING); }
				$error = $lang->lang($exception->getMessage());
			}
		}
		add_form_key($key);
		$template->assign_vars(array('S_ACRONYM_FORM' => true, 'S_DELETE' => $action === 'delete', 'TERM' => $term, 'MEANING' => $definition,
			'REVISION' => $revision, 'FORM_ERROR' => $error, 'U_SAVE' => $this->url($action, $id), 'U_BACK' => $this->u_action));
	}
}
