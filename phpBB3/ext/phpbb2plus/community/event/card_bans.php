<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class card_bans implements EventSubscriberInterface
{
	private $db, $user, $config;
	private $checking = false;

	public function __construct($db, $user, $config)
	{
		$this->db = $db;
		$this->user = $user;
		$this->config = $config;
	}

	public static function getSubscribedEvents()
	{
		// Apply a migrated founder restriction before other listeners record an accepted login.
		return array('core.auth_login_session_create_before' => array('founder_login', 200),
			'core.session_set_custom_ban' => 'email', 'core.session_create_after' => array('founder', 200), 'core.user_setup_after' => 'founder');
	}

	private function addresses()
	{
		$ips = $this->config['forwarded_for_check'] ? explode(' ', $this->user->forwarded_for) : array();
		$ips[] = $this->user->ip;
		return $ips;
	}

	private function matches($founder, $ips, $account)
	{
		// A fresh phpBB board and native bans never receive this marker. The native
		// ban-management functions invalidate the SQL cache when rules change.
		$sql = 'SELECT ban_userid,ban_email,ban_ip,ban_end FROM ' . BANLIST_TABLE . ' WHERE plus_card_legacy=1 AND ban_exclude=0';
		if (!$founder) { $sql .= " AND ban_email<>''"; }
		$result = $this->db->sql_query($sql, 3600);
		$rules = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		if (!$rules) { return false; }

		require_once dirname(__DIR__) . '/legacy/address_bans.php';
		foreach ($rules as $rule)
		{
			if ($rule['ban_end'] && (int) $rule['ban_end'] < time()) { continue; }
			if ((int) $rule['ban_userid'] === (int) $account['user_id']
				|| phpbb_email_ban_matches($rule['ban_email'], $account['user_email'])) { return true; }
			$range = phpbb_ip_ban_parse($rule['ban_ip']);
			if (!$range) { continue; }
			foreach ($ips as $ip)
			{
				if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
					phpbb_ip_ban_matches($range['ip'], $range['mask'], bin2hex(inet_pton($ip)))) { return true; }
			}
		}
		return false;
	}

	public function email($event)
	{
		// Core passes no email when checking an existing regular account's session.
		// Registration and explicit ban lookups already pass their own subject.
		if ($this->checking || $event['return'] || $event['banned'] || empty($this->config['plus_card_has_bans'])
			|| empty($this->user->data['user_email']) || (int) $this->user->data['user_id'] === ANONYMOUS) { return; }
		$ips = $this->addresses();
		if (!$this->matches(false, $ips, $this->user->data)) { return; }
		// Native user/IP/email exclusions still override an imported email ban.
		$row = $this->user->check_ban((int) $this->user->data['user_id'], $ips, $this->user->data['user_email'], true);
		if ($row)
		{
			$event['banned'] = true;
			$event['ban_row'] = $row;
			$event['ban_triggered_by'] = $row['ban_triggered_by'];
		}
	}

	public function founder_login($event)
	{
		global $phpbb_root_path, $phpEx;
		$login = $event['login'];
		if (empty($this->config['plus_card_has_bans']) || $login['status'] !== LOGIN_SUCCESS) { return; }
		$account = $login['user_row'];
		if (!isset($account['user_type'], $account['user_email']))
		{
			$result = $this->db->sql_query('SELECT user_id, user_type, user_email FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) $account['user_id']);
			$account = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
		}
		if (!$account || (int) $account['user_type'] !== USER_FOUNDER) { return; }
		$ips = $this->addresses();
		if (!$this->matches(true, $ips, $account)) { return; }
		$ban = $this->user->check_ban((int) $account['user_id'], $ips, $account['user_email'], true);
		if (!is_array($ban)) { return; }

		// The native founder recovery form can authenticate twice. End a confirmed
		// imported ban before either login can consume an account's first renewal.
		$contact = phpbb_get_board_contact_link($this->config, $phpbb_root_path, $phpEx);
		$message = $this->user->lang($ban['ban_end'] ? 'BOARD_BAN_TIME' : 'BOARD_BAN_PERM',
			$ban['ban_end'] ? $this->user->format_date($ban['ban_end']) : '', '<a href="' . $contact . '">', '</a>');
		if ($ban['ban_give_reason'] !== '')
		{
			$reason = htmlspecialchars(html_entity_decode($ban['ban_give_reason'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$message .= '<br><br>' . $this->user->lang('BOARD_BAN_REASON', $reason);
		}
		$message .= '<br><br><em>' . $this->user->lang('BAN_TRIGGERED_BY_' . strtoupper($ban['ban_triggered_by'])) . '</em>';
		$event['login'] = array('status' => LOGIN_BREAK, 'error_msg' => $message, 'user_row' => array('user_id' => ANONYMOUS));
	}

	public function founder()
	{
		// The upstream converter promotes all old administrators to founders.
		// Preserve the restrictions that already applied to them in phpBB2 Plus.
		if ($this->checking || empty($this->config['plus_card_has_bans']) || empty($this->user->data['user_type'])
			|| (int) $this->user->data['user_type'] !== USER_FOUNDER || defined('SKIP_CHECK_BAN') || defined('IN_CHECK_BAN')) { return; }
		$ips = $this->addresses();
		if (!$this->matches(true, $ips, $this->user->data)) { return; }
		$this->checking = true;
		try { $this->user->check_ban((int) $this->user->data['user_id'], $ips, $this->user->data['user_email']); }
		finally { $this->checking = false; }
	}
}
