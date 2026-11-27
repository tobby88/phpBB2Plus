<?php
namespace phpbb2plus\community\service;

class daily_visitors
{
	private $db;
	private $config;
	private $table;

	public function __construct($db, $config, $table) { $this->db = $db; $this->config = $config; $this->table = $table; }

	public function mode()
	{
		$mode = (int) ($this->config['plus_daily_mode'] ?? 0);
		if (!in_array($mode, array(1, 2), true) || empty($this->config['plus_daily_key']) ||
			empty($this->config['load_online']) || empty($this->config['load_online_time']) ||
			(!empty($this->config['plus_daily_force']) && empty($this->config['plus_daily_scope']))) { return 0; }
		return $mode === 2 && !empty($this->config['load_online_guests']) ? 2 : 1;
	}

	public function midnight(int $now)
	{
		return (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($this->config['board_timezone'] ?: 'UTC'))->setTime(0, 0)->getTimestamp();
	}

	private function cleanup_mark()
	{
		$mode = $this->mode();
		return $mode ? $mode . '/' . $this->midnight(time()) : 'off';
	}
	public function due()
	{
		return isset($this->config['plus_daily_gc']) && $this->config['plus_daily_gc'] !== $this->cleanup_mark();
	}
	public function cleanup()
	{
		if (!$this->due()) { return; }
		$mark = $this->cleanup_mark();
		$mode = $this->mode();
		$where = $mode ? ' WHERE day_start <> ' . $this->midnight(time()) . ($mode === 1 ? ' OR is_guest = 1' : '') : '';
		$this->db->sql_query('DELETE FROM ' . $this->table . $where);
		$this->config->set('plus_daily_gc', $mark, false);
	}

	public function remember(int $user_id, bool $hidden, string $ip, int $stamp)
	{
		$guest = $user_id === ANONYMOUS;
		$mode = $this->mode();
		if (!$mode || ($guest ? $mode !== 2 : !$hidden)) { return; }
		$start = $this->midnight($stamp);
		if ($guest)
		{
			$address = inet_pton($ip);
			if ($address === false) { return; }
			$key = hash_hmac('sha256', $start . '/' . $address, $this->config['plus_daily_key']);
		}
		else
		{
			// Remember a hidden session until midnight, even if native session cleanup removes it first.
			$key = 'u' . $user_id;
		}
		// Check the opt-in again during the write if an administrator changed it since session setup.
		$setting = $guest ? "config_value='2' AND EXISTS (SELECT 1 FROM " . CONFIG_TABLE .
			" WHERE config_name='load_online_guests' AND config_value='1')" : "config_value IN ('1','2')";
		$this->db->sql_query("INSERT INTO {$this->table} (visitor,is_guest,day_start,last_seen)
			SELECT '$key'," . (int) $guest . ",$start,$stamp FROM " . CONFIG_TABLE . " WHERE config_name='plus_daily_mode' AND $setting
			ON DUPLICATE KEY UPDATE day_start=GREATEST(day_start,$start),last_seen=GREATEST(last_seen,$stamp)");
	}

	public function read(int $now, bool $show_names, bool $show_hidden)
	{
		$mode = $this->mode();
		if (!$mode) { return array('visible' => 0, 'hidden' => 0, 'guests' => 0, 'recent' => 0, 'names' => array()); }
		$start = $this->midnight($now);
		$hour = $now - 3600;
		$guests = $recent = $visible = $hidden = 0;
		$names = array();
		if ($mode === 2)
		{
			$result = $this->db->sql_query("SELECT COUNT(*) AS total, COUNT(CASE WHEN last_seen >= $hour THEN 1 END) AS recent
				FROM {$this->table} WHERE is_guest=1 AND day_start=$start AND last_seen BETWEEN $start AND $now");
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			$guests = (int) $row['total']; $recent = (int) $row['recent'];
		}
		$fields = $show_names ? 'u.user_id,u.username,u.username_clean,u.user_colour,' : '';
		$members = 'SELECT ' . $fields . 'u.user_last_active,
			CASE WHEN u.user_allow_viewonline=0 OR u.plus_login_hidden=1 OR h.last_seen IS NOT NULL OR EXISTS
			(SELECT 1 FROM ' . SESSIONS_TABLE . ' s WHERE s.session_user_id=u.user_id AND s.session_viewonline=0
			AND s.session_time BETWEEN ' . $start . ' AND ' . $now . ') THEN 1 ELSE 0 END AS hidden
			FROM ' . USERS_TABLE . " u LEFT JOIN {$this->table} h ON h.visitor=CONCAT('u',u.user_id) AND h.last_seen BETWEEN $start AND $now
			WHERE u.user_last_active BETWEEN $start AND $now AND u.user_id <> " . ANONYMOUS .
			' AND u.user_type IN (' . USER_NORMAL . ',' . USER_FOUNDER . ')';
		if ($show_names)
		{
			$result = $this->db->sql_query($members . ' ORDER BY u.username_clean,u.user_id');
			while ($row = $this->db->sql_fetchrow($result))
			{
				if ($row['user_last_active'] >= $hour) { ++$recent; }
				if ($row['hidden']) { ++$hidden; } else { ++$visible; }
				if (!$row['hidden'] || $show_hidden) { $names[] = $row; }
			}
		}
		else
		{
			$result = $this->db->sql_query("SELECT COUNT(*) AS total,SUM(hidden) AS hidden,COUNT(CASE WHEN user_last_active >= $hour THEN 1 END) AS recent
				FROM ($members) daily_members");
			$row = $this->db->sql_fetchrow($result);
			$hidden = (int) $row['hidden']; $visible = (int) $row['total'] - $hidden; $recent += (int) $row['recent'];
		}
		$this->db->sql_freeresult($result);
		return array('visible' => $visible, 'hidden' => $hidden, 'guests' => $guests, 'recent' => $recent, 'names' => $names);
	}
}
