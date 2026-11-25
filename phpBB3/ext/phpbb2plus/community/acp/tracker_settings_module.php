<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;
use phpbb2plus\community\service\account_logins;

class tracker_settings_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_tracker_settings';
	public $page_title = 'ACP_PLUS_TRACKER_SETTINGS';

	private function permitted($lock)
	{
		global $db, $auth, $user, $table_prefix;
		if (!defined('IN_ADMIN') || !IN_ADMIN || empty($user->data['session_admin']) || !empty($user->data['user_perm_from'])) { return false; }
		$id = (int) $user->data['user_id'];
		$sid = $db->sql_escape($user->session_id);
		$result = $db->sql_query('SELECT u.* FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE . ' s ON s.session_user_id=u.user_id
			WHERE u.user_id=' . $id . " AND s.session_id='$sid' AND s.session_admin=1" . ($lock ? ' LOCK IN SHARE MODE' : ''));
		$actor = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if (!$actor || $actor['user_perm_from'] || !in_array((int) $actor['user_type'], array(USER_NORMAL, USER_FOUNDER), true)) { return false; }
		if ((int) $actor['user_type'] === USER_FOUNDER) { return true; }
		$raw = $auth->acl_raw_data($id, array('a_', 'a_server', 'a_plus_ct_settings'), 0);
		$options = $raw[$id][0] ?? array();
		if ((int) ($options['a_'] ?? ACL_NO) === ACL_NEVER) { return false; }
		if ((int) ($options['a_server'] ?? ACL_NO) === ACL_YES || (int) ($options['a_plus_ct_settings'] ?? ACL_NO) === ACL_YES) { return true; }
		if ((int) ($options['a_plus_ct_settings'] ?? ACL_NO) === ACL_NEVER || empty($actor['plus_jr_delegate'])) { return false; }
		if ($lock)
		{
			$result = $db->sql_query("SELECT user_id FROM {$table_prefix}plus_jr_delegations WHERE user_id=$id FOR UPDATE");
			$db->sql_freeresult($result);
		}
		$actor['is_registered'] = true;
		$auth->acl($actor);
		return $auth->delegated_module(__CLASS__, 'manage');
	}

	public function main($module, $mode)
	{
		global $config, $db, $request, $template, $phpbb_container, $phpbb_log, $user;
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_tracker', 'phpbb2plus/community');
		$language->add_lang('info_ucp_logins', 'phpbb2plus/community');
		if (!$this->permitted(false)) { send_status_line(403, 'Forbidden'); trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$history = $phpbb_container->get('phpbb2plus.community.account_logins');
		$return = adm_back_link($this->u_action);
		if ($request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key('plus_tracker_settings')) { trigger_error($language->lang('FORM_INVALID') . $return, E_USER_WARNING); }
			$values = $request->variable('config', array('' => ''), false, request_interface::POST);
			if (count($values) !== count(account_logins::SETTINGS)) { trigger_error($language->lang('FORM_INVALID') . $return, E_USER_WARNING); }
			foreach (account_logins::SETTINGS as $key => $range)
			{
				$value = $values[$key] ?? '';
				if (!preg_match('/^(?:0|[1-9][0-9]?)$/D', $value) || (int) $value < $range[0] || (int) $value > $range[1])
				{
					trigger_error($language->lang('FORM_INVALID') . $return, E_USER_WARNING);
				}
			}
			$db->sql_transaction('begin');
			try
			{
				$current = $history->policy('write');
				if (!$this->permitted(true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
				if (!hash_equals(hash('sha256', serialize($current)), $request->variable('revision', '', false, request_interface::POST)))
				{
					throw new \DomainException('PLUS_TRACKER_SETTINGS_CHANGED');
				}
				foreach ($values as $key => $value) { $config->set($key, (int) $value); }
				$phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_PLUS_TRACKER_SETTINGS');
				$db->sql_transaction('commit');
			}
			catch (\Throwable $error)
			{
				$db->sql_transaction('rollback');
				if (!$error instanceof \DomainException) { throw $error; }
				if ($error->getMessage() === 'NO_AUTH_OPERATION') { send_status_line(403, 'Forbidden'); }
				trigger_error($language->lang($error->getMessage()) . $return, E_USER_WARNING);
			}
			$phpbb_container->get('cache.driver')->destroy('config');
			trigger_error($language->lang('CONFIG_UPDATED') . $return);
		}
		$current = $history->policy();
		add_form_key('plus_tracker_settings');
		$template->assign_vars(array('U_ACTION' => $this->u_action, 'REVISION' => hash('sha256', serialize($current)),
			'S_HISTORY' => (bool) $current['plus_login_history'], 'LOGIN_LIMIT' => $current['plus_login_limit'],
			'S_NETWORK' => (bool) $current['plus_login_network_check']));
	}
}
