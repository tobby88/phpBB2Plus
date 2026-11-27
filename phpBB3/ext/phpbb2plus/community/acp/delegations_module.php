<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;
use phpbb2plus\community\auth\delegations;
use phpbb2plus\community\auth\junior_admin;

class delegations_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_jr_manage';
	public $page_title = 'ACP_PLUS_JR_MANAGE';

	private function deny()
	{
		send_status_line(403, 'Forbidden');
		trigger_error('NO_AUTH_OPERATION', E_USER_WARNING);
	}

	public function main($id, $mode)
	{
		global $auth, $user, $request, $template, $phpbb_container;
		if ($mode !== 'manage' || !delegations::permitted($auth, $user->data)) { $this->deny(); }
		$user->add_lang_ext('phpbb2plus/community', 'info_acp_junior');
		$management = $phpbb_container->get('phpbb2plus.community.delegations');
		$subject = $request->variable('account', 0, false, request_interface::GET);
		$lookup = trim($request->variable('username', '', true, request_interface::POST));
		if ($lookup !== '' && !$subject)
		{
			$subject = $management->lookup($lookup);
			if (!$subject) { $template->assign_var('JR_ERROR', $user->lang('NO_USER')); }
		}
		$template->assign_var('U_JR_LIST', $this->u_action);
		if (!$subject)
		{
			$start = max(0, $request->variable('start', 0));
			$rows = $management->listing($start, 26);
			foreach (array_slice($rows, 0, 25) as $row)
			{
				$template->assign_block_vars('jr_accounts', array('NAME' => htmlspecialchars_decode($row['username'], ENT_QUOTES), 'COUNT' => count(json_decode($row['rights_json'], true) ?: array()),
					'URL' => (int) $row['user_id'] === (int) $user->data['user_id'] ? '' : $this->u_action . '&amp;account=' . (int) $row['user_id']));
			}
			$template->assign_vars(array('U_JR_PREV' => $start ? $this->u_action . '&amp;start=' . max(0, $start - 25) : '',
				'U_JR_NEXT' => count($rows) > 25 ? $this->u_action . '&amp;start=' . ($start + 25) : ''));
			return;
		}
		if ($subject === (int) $user->data['user_id']) { $this->deny(); }
		$row = $management->account($subject);
		if (!$row) { trigger_error('NO_USER', E_USER_WARNING); }
		$form = 'plus_jr_delegations_' . $subject;
		if ($request->is_set_post('save'))
		{
			if (!check_form_key($form)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
			try
			{
				$changed = $management->save($auth, $user->data, $user->ip, $subject, $request->variable('revision', -1, false, request_interface::POST),
					$request->variable('grant', array(''), false, request_interface::POST), htmlspecialchars_decode($request->variable('note', '', true, request_interface::POST), ENT_QUOTES),
					(bool) $request->variable('visible', 0, false, request_interface::POST));
				$template->assign_var('JR_SAVED', $user->lang($changed ? 'PLUS_JR_SAVED' : 'PLUS_JR_UNCHANGED'));
			}
			catch (\DomainException $e)
			{
				if ($e->getMessage() === 'PLUS_JR_NO_CHANGE') { $this->deny(); }
				$template->assign_var('JR_ERROR', $user->lang($e->getMessage()));
			}
			$row = $management->account($subject);
			if (!$row) { trigger_error('NO_USER', E_USER_WARNING); }
		}
		add_permission_language();
		$permissions = $phpbb_container->get('acl.permissions');
		$states = $phpbb_container->get('phpbb2plus.community.junior_admin')->states($subject, $auth, array_keys(junior_admin::targets()));
		foreach ($states as $option => $state)
		{
			$selected = in_array($option, $row['rights'], true);
			$template->assign_block_vars('jr_modules', array('OPTION' => $option, 'LABEL' => $permissions->get_permission_lang($option),
				'SELECTED' => $selected, 'DISABLED' => !$selected && ((int) $row['user_type'] !== USER_NORMAL || !in_array($state, array('available', 'native'), true)),
				'STATE' => $state === 'available' ? '' : $user->lang('PLUS_JR_STATE_' . strtoupper($state))));
		}
		foreach ($row['reports'] as $report)
		{
			$result = $report['result'] ?? '';
			$key = 'PLUS_JR_DECISION_' . strtoupper(str_replace('-', '_', $result));
			$label = $user->lang($key);
			if ($result === 'delegated' && !empty($report['unmapped_attachment_modes']))
			{
				$label .= ' ' . $user->lang('PLUS_ATTACH_BLOCK_PARTIAL');
			}
			$template->assign_block_vars('jr_reports', array('ROUTE' => $report['route'] ?? '', 'HASH' => $report['hash'] ?? '',
				'RESULT' => $label === $key ? $result : $label));
		}
		add_form_key($form);
		$template->assign_vars(array('S_JR_ACCOUNT' => true, 'JR_USER' => htmlspecialchars_decode($row['username'], ENT_QUOTES), 'JR_REVISION' => (int) $row['revision'],
			'U_JR_SAVE' => $this->u_action . '&amp;account=' . $subject, 'JR_NOTE' => $row['note'], 'S_JR_VISIBLE' => (bool) $row['notes_view'],
			'S_JR_FOUNDER' => (int) $row['user_type'] === USER_FOUNDER, 'S_JR_INACTIVE' => (int) $row['user_type'] === USER_INACTIVE,
			'JR_START' => $row['start_date'] ? $user->format_date($row['start_date']) : '', 'JR_UPDATED' => $row['update_date'] ? $user->format_date($row['update_date']) : '',
			'S_JR_SOURCE' => $row['source_user'] !== null, 'S_JR_OLD_NOTE' => ($row['old_note'] ?? '') !== '', 'JR_OLD_NOTE' => base64_decode($row['old_note'] ?? '', true),
			'S_JR_OLD_VISIBLE' => (bool) $row['old_visible']));
	}
}
