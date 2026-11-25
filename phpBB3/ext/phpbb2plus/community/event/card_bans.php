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
		return array('core.session_set_custom_ban' => 'email', 'core.session_create_after' => 'founder', 'core.user_setup_after' => 'founder');
	}

	private function addresses()
	{
		$ips = $this->config['forwarded_for_check'] ? explode(' ', $this->user->forwarded_for) : array();
		$ips[] = $this->user->ip;
		return $ips;
	}

	private function matches($founder, $ips)
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
			if ((int) $rule['ban_userid'] === (int) $this->user->data['user_id']
				|| phpbb_email_ban_matches($rule['ban_email'], $this->user->data['user_email'])) { return true; }
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
		if (!$this->matches(false, $ips)) { return; }
		// Native user/IP/email exclusions still override an imported email ban.
		$row = $this->user->check_ban((int) $this->user->data['user_id'], $ips, $this->user->data['user_email'], true);
		if ($row)
		{
			$event['banned'] = true;
			$event['ban_row'] = $row;
			$event['ban_triggered_by'] = $row['ban_triggered_by'];
		}
	}

	public function founder()
	{
		// The upstream converter promotes all old administrators to founders.
		// Preserve the restrictions that already applied to them in phpBB2 Plus.
		if ($this->checking || empty($this->config['plus_card_has_bans']) || empty($this->user->data['user_type'])
			|| (int) $this->user->data['user_type'] !== USER_FOUNDER || defined('SKIP_CHECK_BAN') || defined('IN_CHECK_BAN')) { return; }
		$ips = $this->addresses();
		if (!$this->matches(true, $ips)) { return; }
		$this->checking = true;
		try { $this->user->check_ban((int) $this->user->data['user_id'], $ips, $this->user->data['user_email']); }
		finally { $this->checking = false; }
	}
}
