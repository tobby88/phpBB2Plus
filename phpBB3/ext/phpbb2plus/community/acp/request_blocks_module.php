<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;
use phpbb2plus\community\auth\admin_session;

class request_blocks_module
{
	public $u_action;
	public $page_title, $tpl_name = 'acp_plus_request_blocks';

	private function allowed($permission, $fresh = false)
	{
		global $db, $auth, $user;
		return admin_session::allows($db, $auth, $user, $permission, $fresh);
	}

	public function main($module, $mode)
	{
		global $phpbb_container, $phpbb_log, $request, $template, $user;
		$rules = $mode === 'rules';
		$right = $rules ? 'a_plus_ct_blocks' : 'a_viewlogs';
		if ((!$rules && $mode !== 'history') || !$this->allowed($right)) { send_status_line(403, 'Forbidden'); trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$this->page_title = $rules ? 'ACP_PLUS_CT_BLOCKS' : 'ACP_PLUS_CT_BLOCK_LOG';
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_request_blocks', 'phpbb2plus/community');
		$blocks = $phpbb_container->get('phpbb2plus.community.request_blocks');
		$key = 'plus_ct_block_' . $mode;
		if ($request->server('REQUEST_METHOD') === 'POST')
		{
			$latest = $rules ? '' : $request->variable('latest', '', false, request_interface::POST);
			$confirming = !$rules && $request->is_set_post('confirm_clear');
			if ((!$rules && !preg_match('/^[1-9][0-9]{0,18}$/D', $latest)) ||
				!check_form_key($confirming ? $key . '_clear_' . $latest : $key)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
			$action = $request->variable('action', '', false, request_interface::POST);
			try
			{
				if ($rules)
				{
					$id = $blocks->save_rule($action, $request->variable('rule_id', 0, false, request_interface::POST),
						$request->variable('revision', -1, false, request_interface::POST), $request->raw_variable('entry', '', request_interface::POST),
						function () use ($right) { return $this->allowed($right, true); });
					$phpbb_log->add('admin', $user->data['user_id'], $user->ip, $action === 'add' ? 'LOG_PLUS_CT_RULE_ADD' : 'LOG_PLUS_CT_RULE_DELETE', false, array($id));
				}
				else
				{
					if ($action !== 'clear' || !$this->allowed('a_clearlogs', true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
					if (!$confirming)
					{
						add_form_key($key . '_clear_' . $latest);
						$template->assign_vars(array('S_CLEAR' => true, 'LATEST' => $latest, 'U_ACTION' => $this->u_action));
						return;
					}
					$blocks->clear($latest, function () use ($right) { return $this->allowed($right, true) && $this->allowed('a_clearlogs', true); });
					$phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_PLUS_CT_BLOCK_LOG_CLEARED');
				}
				redirect($this->u_action);
			}
			catch (\DomainException $error)
			{
				if ($error->getMessage() === 'NO_AUTH_OPERATION') { send_status_line(403, 'Forbidden'); }
				trigger_error($language->lang($error->getMessage()) . adm_back_link($this->u_action), E_USER_WARNING);
			}
		}
		add_form_key($key);
		$state = $blocks->state();
		$template->assign_vars(array('S_RULES' => $rules, 'U_ACTION' => $this->u_action, 'REVISION' => $state['rules_version']));
		if ($rules)
		{
			$start = max(0, $request->variable('start', 0));
			$rows = $blocks->rules($start, 51);
			$template->assign_vars(array('U_PREVIOUS' => $start ? $this->u_action . '&amp;start=' . max(0, $start - 50) : '',
				'U_NEXT' => count($rows) > 50 ? $this->u_action . '&amp;start=' . ($start + 50) : ''));
			foreach (array_slice($rows, 0, 50) as $row)
			{
				$value = $row['rule_value'];
				if (!$row['rule_active'])
				{
					$value = preg_replace_callback('/[\x00-\x1f\x7f]/', static function ($m) { return sprintf('\\x%02X', ord($m[0])); }, $value);
				}
				$template->assign_block_vars('rule', array('ID' => (int) $row['rule_id'], 'VALUE' => $value, 'ACTIVE' => $row['rule_active']));
			}
			return;
		}
		$events = $blocks->history();
		$markers = array('rotated' => 'PLUS_CT_ROTATED', 'cleared' => 'PLUS_CT_CLEARED', 'created' => 'PLUS_CT_CREATED');
		$template->assign_vars(array('ARCHIVED' => $state['archived'], 'RESET_LABEL' => $markers[$state['reset_kind']] ?? 'PLUS_CT_LEGACY_MARKER',
			'RESET_DATE' => $state['reset_time'] ? $user->format_date($state['reset_time']) : '',
			'S_CAN_CLEAR' => $events && $this->allowed('a_clearlogs'), 'LATEST' => $events ? max(array_column($events, 'event_id')) : ''));
		foreach ($events as $event)
		{
			$template->assign_block_vars('record', array('DATE' => $user->format_date($event['event_time']), 'IP' => $event['address'],
				'AGENT' => $event['agent'], 'HOST' => $event['hostname'], 'SCRIPT' => $event['script'], 'ORIGIN' => $event['referrer_origin']));
		}
	}
}
