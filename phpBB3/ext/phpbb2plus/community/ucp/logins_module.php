<?php
namespace phpbb2plus\community\ucp;

use phpbb\request\request_interface;

class logins_module
{
	public $u_action;
	public $tpl_name = 'ucp_plus_account_logins';
	public $page_title = 'UCP_PLUS_LOGINS';

	public function main($module, $mode)
	{
		global $config, $user, $request, $template, $phpbb_container;
		if (empty($user->data['is_registered'])) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang('info_ucp_logins', 'phpbb2plus/community');
		$history = $phpbb_container->get('phpbb2plus.community.account_logins');
		$id = (int) $user->data['user_id'];
		if ($request->is_set_post('change'))
		{
			if (!check_form_key('plus_account_logins')) { trigger_error('FORM_INVALID', E_USER_WARNING); }
			$action = $request->variable('change', '', false, request_interface::POST);
			$field = $action === 'preference' ? 'network_warning' : 'notice_context';
			$value = $request->variable($field, '', false, request_interface::POST);
			if (!$history->update_choice($id, $action, $value)) { trigger_error('PLUS_LOGIN_CHANGED_AGAIN', E_USER_WARNING); }
			redirect($this->u_action);
		}
		add_form_key('plus_account_logins');
		foreach ($history->recent($id) as $row)
		{
			$template->assign_block_vars('account_login', array('WHEN' => $user->format_date((int) $row['login_time']),
				'ADDRESS' => $row['login_ip'] === '' ? $language->lang('PLUS_LOGIN_UNKNOWN_IP') : html_entity_decode($row['login_ip'], ENT_QUOTES, 'UTF-8')));
		}
		$notice = !empty($config['plus_login_network_check']) ? $history->notice($user->data) : false;
		$template->assign_vars(array('U_ACTION' => $this->u_action, 'S_PLUS_LOGIN_PANEL' => true,
			'S_PLUS_LOGIN_HISTORY' => (bool) $config['plus_login_history'], 'S_PLUS_LOGIN_NETWORK' => (bool) $config['plus_login_network_check'],
			'S_PLUS_LOGIN_WARN' => !empty($user->data['plus_login_warn']), 'PLUS_LOGIN_CONTEXT' => $notice ? $notice['context'] : '',
			'PLUS_ACCOUNT_NOTICE' => $notice ? $language->lang('PLUS_LOGIN_CHANGED', $notice['before'], $notice['now']) : ''));
	}
}
