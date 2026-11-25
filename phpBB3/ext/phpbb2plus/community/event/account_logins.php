<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class account_logins implements EventSubscriberInterface
{
	private $history;
	private $config;
	private $user;
	private $language;
	private $template;
	private $auth;
	private $pending_user = 0;

	public function __construct($history, $config, $user, $language, $template, $auth)
	{
		$this->history = $history;
		$this->config = $config;
		$this->user = $user;
		$this->language = $language;
		$this->template = $template;
		$this->auth = $auth;
	}

	public static function getSubscribedEvents()
	{
		return array('core.auth_login_session_create_before' => 'authenticate',
			'core.session_create_after' => array('started', -100),
			'core.delete_user_before' => 'remove', 'core.page_header' => 'notice');
	}

	public function authenticate($event)
	{
		$login = $event['login'];
		$this->pending_user = $login['status'] === LOGIN_SUCCESS ? (int) $login['user_row']['user_id'] : 0;
	}

	public function started($event)
	{
		$session = $event['session_data'];
		$id = $this->pending_user;
		$this->pending_user = 0;
		if ($id > ANONYMOUS && $id === (int) $session['session_user_id'])
		{
			$this->history->record($id, $session['session_ip'], $session['session_start']);
		}
	}

	public function remove($event) { $this->history->remove_accounts($event['user_ids']); }

	public function notice($event)
	{
		global $phpbb_root_path, $phpEx;
		if (empty($this->user->data['is_registered'])) { return; }
		if (!empty($this->config['plus_pw_reminder']))
		{
			$days = (int) $this->config['plus_pw_reminder_days'];
			$row = $this->user->data;
			// The two source clocks can disagree. Only a subsequent native change
			// supersedes the tracker's own date, including its "unknown" value.
			$native = (int) $row['user_passchg'];
			$changed = $native === (int) $row['plus_pw_native_origin'] ? (int) $row['plus_pw_notice_change'] : $native;
			if ($changed > 0 && $days >= 6 && $days <= 365 && time() > $changed + $days * 86400)
			{
				$this->language->add_lang('info_ucp_logins', 'phpbb2plus/community');
				$this->template->assign_vars(array('PLUS_PW_REMINDER' => $this->language->lang('PLUS_PW_REMINDER_NOTICE', $days),
					'U_PLUS_CHANGE_PASSWORD' => $this->auth->acl_get('u_chgpasswd')
						? append_sid($phpbb_root_path . 'ucp.' . $phpEx, 'i=ucp_profile&amp;mode=reg_details') : ''));
			}
		}
		if (empty($this->config['plus_login_network_check'])) { return; }
		$notice = $this->history->notice($this->user->data);
		if (!$notice) { return; }
		$this->language->add_lang('info_ucp_logins', 'phpbb2plus/community');
		$this->template->assign_vars(array(
			'PLUS_LOGIN_NOTICE' => $this->language->lang('PLUS_LOGIN_CHANGED', $notice['before'], $notice['now']),
			'U_PLUS_ACCOUNT_LOGINS' => append_sid($phpbb_root_path . 'ucp.' . $phpEx, 'i=-phpbb2plus-community-ucp-logins_module&amp;mode=history'),
		));
	}

}
