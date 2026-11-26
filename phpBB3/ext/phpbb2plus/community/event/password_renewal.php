<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class password_renewal implements EventSubscriberInterface
{
	private $db, $config, $user, $auth, $template, $helper;
	private $explicit_user = 0;
	private $weak_digest = '';

	public function __construct($db, $config, $user, $auth, $template, $helper)
	{
		$this->db = $db;
		$this->config = $config;
		$this->user = $user;
		$this->auth = $auth;
		$this->template = $template;
		$this->helper = $helper;
	}

	public static function getSubscribedEvents()
	{
		return array(
			'core.auth_login_session_create_before' => array('login', 100),
			'core.login_box_failed' => 'recovery',
			'core.session_create_after' => array('created', 100),
			'core.page_header' => 'notice',
			'core.acp_board_config_edit_add' => 'settings',
		);
	}

	private function interval()
	{
		return empty($this->config['plus_pw_age_policy']) ? 0 : (int) $this->config['chg_passforce'];
	}

	private function remaining($row, $days)
	{
		$changed = (int) $row['user_passchg'];
		if ($this->marked_weak($row)) { $changed = -9999; }
		elseif ($changed === 0)
		{
			// Plus distinguished a first login of unknown age from a previous renewal prompt.
			if (empty($row['plus_pw_renewal_seen'])) { return null; }
			$changed = -9999;
		}
		return $days - intdiv(time() - $changed, 86400);
	}

	private function marked_weak($row)
	{
		return !empty($row['plus_pw_weak_hash']) && hash_equals($row['plus_pw_weak_hash'], hash('sha256', $row['user_password']));
	}

	private function load_account($id)
	{
		$result = $this->db->sql_query('SELECT user_password, user_passchg, plus_pw_renewal_seen, plus_pw_weak_hash FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) $id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	private function expired_message()
	{
		$this->user->add_lang_ext('phpbb2plus/community', 'info_ucp_logins');
		return $this->config['email_enable'] && $this->config['allow_password_reset'] ? 'PLUS_PW_RENEW_EXPIRED' : 'PLUS_PW_RENEW_CONTACT';
	}

	public function recovery($event)
	{
		if (($event['result']['error_msg'] ?? '') === 'PLUS_PW_RENEW_EXPIRED')
		{
			$event['err'] .= ' <a href="' . $this->helper->route('phpbb_ucp_forgot_password_controller') . '">' . $this->user->lang('FORGOT_PASS') . '</a>';
		}
	}

	public function login($event)
	{
		$this->explicit_user = 0;
		$this->weak_digest = '';
		$login = $event['login'];
		$days = $this->interval();
		if ($login['status'] !== LOGIN_SUCCESS || (empty($this->config['plus_pw_age_policy']) && empty($login['plus_weak_password']))) { return; }
		$id = (int) $login['user_row']['user_id'];
		if ($days > 0)
		{
			$row = $this->load_account($id);
			$remaining = $row ? $this->remaining($row, $days) : 0;
			if ($remaining !== null && $remaining <= 0)
			{
				$event['login'] = array('status' => LOGIN_ERROR_EXTERNAL_AUTH, 'error_msg' => $row ? $this->expired_message() : 'LOGIN_ERROR_USERNAME',
					'user_row' => array('user_id' => ANONYMOUS));
				return;
			}
		}
		$this->explicit_user = $id;
		if (!empty($login['plus_weak_password']))
		{
			// Bind the prompt to the verified, already salted storage hash. A
			// simultaneous password reset (even in the same second) supersedes it.
			$this->weak_digest = hash('sha256', $login['user_row']['user_password']);
		}
	}

	public function created($event)
	{
		$session = $event['session_data'];
		$id = (int) $session['session_user_id'];
		$explicit = $id === $this->explicit_user;
		$digest = $explicit ? $this->weak_digest : '';
		$this->explicit_user = 0;
		$this->weak_digest = '';
		$days = $this->interval();
		if ($id <= ANONYMOUS || (!$explicit && ($days <= 0 || empty($session['session_autologin'])))) { return; }
		$row = $this->user->data;
		$remaining = $days > 0 ? $this->remaining($row, $days) : null;
		$first = $explicit && !empty($this->config['plus_pw_age_policy']) && (int) $row['user_passchg'] === 0 &&
			empty($row['plus_pw_renewal_seen']) && !$this->marked_weak($row);
		if ($first)
		{
			// Claim the first prompt only after phpBB has accepted the account and created the session.
			$this->db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_pw_renewal_seen = 1
				WHERE user_id = ' . $id . ' AND user_passchg = 0 AND plus_pw_renewal_seen = 0');
			if (!$this->db->sql_affectedrows())
			{
				$row = $this->load_account($id);
				$first = false;
				$remaining = $days > 0 && $row ? $this->remaining($row, $days) : null;
			}
		}
		elseif ($days > 0 && $remaining === null && !$explicit)
		{
			// The old remember-me calculation used zero as its timestamp, with no first-login allowance.
			$remaining = $days - intdiv(time(), 86400);
		}
		$weak = $row && $digest !== '' && hash_equals($digest, hash('sha256', $row['user_password']));
		if ($weak && !$this->marked_weak($row) && ($remaining === null || $remaining > 0))
		{
			$this->db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_pw_weak_hash = '" . $this->db->sql_escape($digest) . "'
				WHERE user_id = " . $id . ' AND user_passchg = ' . (int) $row['user_passchg'] .
				" AND user_password = '" . $this->db->sql_escape($row['user_password']) . "' AND plus_pw_weak_hash <> '" . $this->db->sql_escape($digest) . "'");
			if (!$this->db->sql_affectedrows())
			{
				// A simultaneous login may have claimed the first reminder, or a
				// password change may have invalidated the credential we just saw.
				$row = $this->load_account($id);
				$weak = $row && hash_equals($digest, (string) $row['plus_pw_weak_hash']) && $this->marked_weak($row);
				$remaining = $days > 0 && $row ? $this->remaining($row, $days) : null;
			}
		}
		if ($days > 0 && (!$row || ($remaining !== null && $remaining <= 0)))
		{
			$event->stopPropagation();
			$this->user->session_kill(true);
			if ($explicit)
			{
				trigger_error($this->expired_message());
			}
			return;
		}
		if ($explicit && empty($session['session_admin']) && ($weak || $first || ($days > 0 && $remaining !== null && $remaining <= ($days < 14 ? 1 : 14))))
		{
			$this->db->sql_query('UPDATE ' . SESSIONS_TABLE . ' SET plus_pw_login_notice = 1
				WHERE session_user_id = ' . $id . " AND session_id = '" . $this->db->sql_escape($session['session_id']) . "'");
		}
	}

	public function notice()
	{
		global $phpbb_root_path, $phpEx;
		$row = $this->user->data;
		if (empty($row['is_registered']) || empty($row['plus_pw_login_notice'])) { return; }
		$this->db->sql_query('UPDATE ' . SESSIONS_TABLE . ' SET plus_pw_login_notice = 0 WHERE plus_pw_login_notice = 1
			AND session_user_id = ' . (int) $row['user_id'] . " AND session_id = '" . $this->db->sql_escape($this->user->session_id) . "'");
		if (!$this->db->sql_affectedrows()) { return; }
		$days = $this->interval();
		$weak = !empty($this->config['plus_pw_rules']) && $this->marked_weak($row);
		$unknown = !empty($this->config['plus_pw_age_policy']) && (int) $row['user_passchg'] === 0;
		$remaining = $days > 0 ? $this->remaining($row, $days) : null;
		if (!$weak && !$unknown && ($remaining === null || $remaining > ($days < 14 ? 1 : 14))) { return; }
		$this->user->add_lang_ext('phpbb2plus/community', 'info_ucp_logins');
		$message = $weak ? $this->user->lang('PLUS_PW_RENEW_WEAK') : ($unknown || $remaining <= 0
			? $this->user->lang('PLUS_PW_RENEW_UNKNOWN') : $this->user->lang('PLUS_PW_RENEW_SOON', $remaining));
		$this->template->assign_vars(array(
			'PLUS_PW_RENEW_NOTICE' => $message,
			'U_PLUS_RENEW_PASSWORD' => $this->auth->acl_get('u_chgpasswd')
				? append_sid($phpbb_root_path . 'ucp.' . $phpEx, 'i=ucp_profile&amp;mode=reg_details') : '',
		));
	}

	public function settings($event)
	{
		$display = $event['display_vars'];
		if (!isset($display['vars']['chg_passforce'])) { return; }
		$this->user->add_lang_ext('phpbb2plus/community', 'info_ucp_logins');
		$fields = array();
		foreach ($display['vars'] as $name => $definition)
		{
			$fields[$name] = $definition;
			if ($name === 'chg_passforce')
			{
				$fields[$name]['validate'] = 'int:0:2147483647';
				$fields[$name]['type'] = 'number:0:2147483647';
				$fields['plus_pw_age_policy'] = array('lang' => 'PLUS_PW_RENEW_POLICY', 'validate' => 'bool',
					'type' => 'radio:yes_no', 'explain' => true);
			}
		}
		$display['vars'] = $fields;
		$event['display_vars'] = $display;
	}
}
