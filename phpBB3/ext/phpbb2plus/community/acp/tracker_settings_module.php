<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;
use phpbb2plus\community\service\member_notice;
use phpbb2plus\community\service\tracker_settings;

class tracker_settings_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_tracker_settings';
	public $page_title = 'ACP_PLUS_TRACKER_SETTINGS';

	private function permitted($mode, $lock)
	{
		global $db, $auth, $user, $table_prefix;
		$permissions = array('manage' => 'a_plus_ct_settings', 'notice' => 'a_plus_ct_notice');
		if (!isset($permissions[$mode]) || !defined('IN_ADMIN') || !IN_ADMIN || empty($user->data['session_admin']) || !empty($user->data['user_perm_from'])) { return false; }
		$permission = $permissions[$mode];
		$id = (int) $user->data['user_id'];
		$sid = $db->sql_escape($user->session_id);
		$result = $db->sql_query('SELECT u.* FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE . ' s ON s.session_user_id=u.user_id
			WHERE u.user_id=' . $id . " AND s.session_id='$sid' AND s.session_admin=1" . ($lock ? ' LOCK IN SHARE MODE' : ''));
		$actor = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if (!$actor || $actor['user_perm_from'] || !in_array((int) $actor['user_type'], array(USER_NORMAL, USER_FOUNDER), true)) { return false; }
		if ((int) $actor['user_type'] === USER_FOUNDER) { return true; }
		$raw = $auth->acl_raw_data($id, array('a_', 'a_server', $permission), 0);
		$options = $raw[$id][0] ?? array();
		if ((int) ($options['a_'] ?? ACL_NO) === ACL_NEVER) { return false; }
		if ((int) ($options['a_server'] ?? ACL_NO) === ACL_YES || (int) ($options[$permission] ?? ACL_NO) === ACL_YES) { return true; }
		if ((int) ($options[$permission] ?? ACL_NO) === ACL_NEVER || empty($actor['plus_jr_delegate'])) { return false; }
		if ($lock)
		{
			$result = $db->sql_query("SELECT user_id FROM {$table_prefix}plus_jr_delegations WHERE user_id=$id FOR UPDATE");
			$db->sql_freeresult($result);
		}
		$actor['is_registered'] = true;
		$auth->acl($actor);
		return $auth->delegated_module(__CLASS__, $mode);
	}

	public function main($module, $mode)
	{
		global $config, $db, $request, $template, $phpbb_container, $phpbb_log, $user;
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_tracker', 'phpbb2plus/community');
		if (!$this->permitted($mode, false)) { send_status_line(403, 'Forbidden'); trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		if ($mode === 'notice') { $this->member_notice($language); return; }
		$language->add_lang('info_ucp_logins', 'phpbb2plus/community');
		$return = adm_back_link($this->u_action);
		if ($request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key('plus_tracker_settings')) { trigger_error($language->lang('FORM_INVALID') . $return, E_USER_WARNING); }
			$values = $request->variable('config', array('' => ''), false, request_interface::POST);
			if (count($values) !== count(tracker_settings::RANGES)) { trigger_error($language->lang('FORM_INVALID') . $return, E_USER_WARNING); }
			foreach ($values as $key => $value)
			{
				if (!tracker_settings::accepts($key, $value))
				{
					trigger_error($language->lang('FORM_INVALID') . $return, E_USER_WARNING);
				}
			}
			$db->sql_transaction('begin');
			try
			{
				$current = tracker_settings::read($db, 'write');
				if (!$this->permitted($mode, true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
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
		$current = tracker_settings::read($db);
		add_form_key('plus_tracker_settings');
		$template->assign_vars(array('U_ACTION' => $this->u_action, 'REVISION' => hash('sha256', serialize($current)),
			'S_HISTORY' => (bool) $current['plus_login_history'], 'LOGIN_LIMIT' => $current['plus_login_limit'],
			'S_NETWORK' => (bool) $current['plus_login_network_check'], 'S_PW_REMINDER' => (bool) $current['plus_pw_reminder'],
			'PW_DAYS' => $current['plus_pw_reminder_days']));
	}

	private function member_notice($language)
	{
		global $db, $request, $template, $phpbb_container, $phpbb_log, $user;
		$this->page_title = 'ACP_PLUS_MEMBER_NOTICE';
		$this->tpl_name = 'acp_plus_member_notice';
		$notices = $phpbb_container->get('phpbb2plus.community.member_notice');
		$return = adm_back_link($this->u_action);
		if ($request->server('REQUEST_METHOD') === 'POST')
		{
			$action = $request->variable('action', '', false, request_interface::POST);
			if (!check_form_key('plus_member_notice_manage') || !in_array($action, array('publish', 'withdraw'), true))
			{
				trigger_error($language->lang('FORM_INVALID') . $return, E_USER_WARNING);
			}
			$publish = $action === 'publish';
			$format = $request->variable('format', '', false, request_interface::POST);
			$message = $request->raw_variable('message', null, request_interface::POST);
			if ($publish && !member_notice::valid($format, $message)) { trigger_error($language->lang('PLUS_MEMBER_NOTICE_INVALID') . $return, E_USER_WARNING); }
			$db->sql_transaction('begin');
			try
			{
				$current = $notices->current('write');
				if (!$this->permitted('notice', true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
				if (!hash_equals($current['revision'], $request->variable('revision', '', false, request_interface::POST)))
				{
					throw new \DomainException('PLUS_MEMBER_NOTICE_CHANGED');
				}
				$notices->distribute($publish ? $format : $current['format'], $publish ? $message : $current['message'], $publish);
				$phpbb_log->add('admin', $user->data['user_id'], $user->ip, $publish ? 'LOG_PLUS_NOTICE_PUBLISH' : 'LOG_PLUS_NOTICE_WITHDRAW');
				$db->sql_transaction('commit');
			}
			catch (\Throwable $error)
			{
				$db->sql_transaction('rollback');
				if (!$error instanceof \DomainException) { throw $error; }
				if ($error->getMessage() === 'NO_AUTH_OPERATION') { send_status_line(403, 'Forbidden'); }
				trigger_error($language->lang($error->getMessage()) . $return, E_USER_WARNING);
			}
			trigger_error($language->lang($publish ? 'PLUS_MEMBER_NOTICE_PUBLISHED' : 'PLUS_MEMBER_NOTICE_WITHDRAWN') . $return);
		}
		$current = $notices->current();
		add_form_key('plus_member_notice_manage');
		$template->assign_vars(array('U_ACTION' => $this->u_action, 'REVISION' => $current['revision'],
			'MESSAGE' => $current['message'], 'S_LINK' => $current['format'] === 'link'));
	}
}
