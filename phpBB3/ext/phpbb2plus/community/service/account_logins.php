<?php
namespace phpbb2plus\community\service;

class account_logins
{
	private $db;
	private $config;
	private $table;

	public function __construct($db, $config, $prefix)
	{
		$this->db = $db;
		$this->config = $config;
		$this->table = $prefix . 'plus_account_logins';
	}

	private function account($id)
	{
		$result = $this->db->sql_query('SELECT plus_login_warn,plus_login_binding,plus_login_previous,plus_login_current FROM ' . USERS_TABLE .
			' WHERE user_id=' . (int) $id . ' AND user_type IN (' . USER_NORMAL . ',' . USER_FOUNDER . ') FOR UPDATE');
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	/** Caller holds the account row lock. A recycled numeric ID starts with a new binding. */
	public static function bind_owner($db, $id, $binding)
	{
		if ($binding !== '') { return $binding; }
		$binding = bin2hex(random_bytes(16));
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_login_binding='$binding' WHERE user_id=" . (int) $id);
		return $binding;
	}

	private function policy()
	{
		// A login already in flight must honour a change to the board's collection policy.
		$names = array('plus_login_history', 'plus_login_limit', 'plus_login_network_check');
		$result = $this->db->sql_query('SELECT config_name,config_value FROM ' . CONFIG_TABLE . ' WHERE ' .
			$this->db->sql_in_set('config_name', $names) . ' ORDER BY config_name LOCK IN SHARE MODE');
		$values = array();
		while ($row = $this->db->sql_fetchrow($result)) { $values[$row['config_name']] = (int) $row['config_value']; }
		$this->db->sql_freeresult($result);
		return $values + array('plus_login_history' => 0, 'plus_login_limit' => 10, 'plus_login_network_check' => 0);
	}

	public function record($id, $ip, $time)
	{
		$id = (int) $id;
		if ($id <= ANONYMOUS || filter_var($ip, FILTER_VALIDATE_IP) === false ||
			(empty($this->config['plus_login_history']) && empty($this->config['plus_login_network_check']))) { return; }
		$db = $this->db;
		$db->sql_transaction('begin');
		try
		{
			$settings = $this->policy();
			$account = $this->account($id);
			if ($account && $settings['plus_login_history'])
			{
				$binding = self::bind_owner($db, $id, $account['plus_login_binding']);
				$owner = "user_id=$id AND owner_binding='" . $db->sql_escape($binding) . "'";
				$db->sql_query('INSERT INTO ' . $this->table . ' ' . $db->sql_build_array('INSERT',
					array('user_id' => $id, 'owner_binding' => $binding, 'login_time' => (int) $time, 'login_ip' => $ip)));
				$limit = max(1, min(60, $settings['plus_login_limit']));
				$result = $db->sql_query_limit('SELECT login_time,entry_id FROM ' . $this->table .
					" WHERE $owner ORDER BY login_time DESC,entry_id DESC", 1, $limit - 1);
				$cut = $db->sql_fetchrow($result);
				$db->sql_freeresult($result);
				if ($cut)
				{
					$stamp = (int) $cut['login_time'];
					$entry = (string) $cut['entry_id'];
					if (!ctype_digit($entry)) { throw new \UnexpectedValueException('Invalid login history entry ID.'); }
					$db->sql_query('DELETE FROM ' . $this->table . " WHERE $owner AND
						(login_time<$stamp OR (login_time=$stamp AND entry_id<$entry))");
				}
			}
			if ($account && $settings['plus_login_network_check'])
			{
				$db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_login_previous=plus_login_current,plus_login_current='" .
					$db->sql_escape($ip) . "' WHERE user_id=$id");
			}
			$db->sql_transaction('commit');
		}
		catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
	}

	public function recent($id)
	{
		if ((int) $id <= ANONYMOUS || empty($this->config['plus_login_history'])) { return array(); }
		$limit = max(1, min(60, (int) $this->config['plus_login_limit']));
		$result = $this->db->sql_query_limit('SELECT h.login_time,h.login_ip FROM ' . $this->table . ' h JOIN ' . USERS_TABLE . ' u
			ON u.user_id=h.user_id AND u.plus_login_binding=h.owner_binding WHERE h.user_id=' . (int) $id .
			" AND u.plus_login_binding<>'' ORDER BY h.login_time DESC,h.entry_id DESC", $limit);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	private static function network($ip)
	{
		if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) { return ''; }
		$bytes = inet_pton($ip);
		if (strlen($bytes) === 16 && substr($bytes, 0, 12) === str_repeat("\0", 10) . "\xff\xff") { $bytes = substr($bytes, 12); }
		if (strlen($bytes) === 4)
		{
			return $bytes === "\0\0\0\0" ? '' : ord($bytes[0]) . '.' . ord($bytes[1]) . '.x.x';
		}
		return vsprintf('%x:%x:%x::/48', unpack('n3', substr($bytes, 0, 6)));
	}

	public static function notice($row)
	{
		if (empty($row['plus_login_warn'])) { return false; }
		$before = self::network($row['plus_login_previous']);
		$now = self::network($row['plus_login_current']);
		if ($before === '' || $now === '' || $before === $now) { return false; }
		return array('before' => $before, 'now' => $now,
			'context' => hash('sha256', $row['plus_login_previous'] . "\0" . $row['plus_login_current']));
	}

	public function update_choice($id, $action, $value)
	{
		$id = (int) $id;
		$db = $this->db;
		$db->sql_transaction('begin');
		try
		{
			$settings = $this->policy();
			$account = $this->account($id);
			$saved = false;
			if ($account && $settings['plus_login_network_check'])
			{
				if ($action === 'preference' && in_array($value, array('0', '1'), true))
				{
					$db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_login_warn=' . (int) $value . " WHERE user_id=$id");
					$saved = true;
				}
				elseif ($action === 'acknowledge')
				{
					$notice = self::notice($account);
					if ($notice && hash_equals($notice['context'], $value))
					{
						$db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_login_previous=plus_login_current WHERE user_id=$id");
						$saved = true;
					}
				}
			}
			$db->sql_transaction('commit');
			return $saved;
		}
		catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
	}

	public function remove_accounts($ids)
	{
		if (!$ids) { return; }
		// The native deletion event runs inside its account transaction. Take the
		// same account locks as a successful login before removing private records.
		$where = $this->db->sql_in_set('user_id', array_map('intval', $ids));
		$result = $this->db->sql_query('SELECT user_id FROM ' . USERS_TABLE . " WHERE $where ORDER BY user_id FOR UPDATE");
		$this->db->sql_freeresult($result);
		$this->db->sql_query('DELETE FROM ' . $this->table . " WHERE $where");
	}
}
