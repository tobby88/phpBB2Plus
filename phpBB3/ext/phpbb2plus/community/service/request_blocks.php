<?php
namespace phpbb2plus\community\service;

class request_blocks
{
	private $db, $rules, $events, $state;
	public function __construct($db, $prefix)
	{
		$this->db = $db;
		$this->rules = $prefix . 'plus_ct_blocks'; $this->events = $prefix . 'plus_ct_block_events'; $this->state = $prefix . 'plus_ct_block_state';
	}

	private function query($sql)
	{
		$result = $this->db->sql_query($sql);
		if ($result === false) { throw new \RuntimeException('Could not access the CrackerTracker block records.'); }
		return $result;
	}

	private function row($sql)
	{
		$result = $this->query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	public function state($lock = false)
	{
		$row = $this->row("SELECT * FROM $this->state WHERE state_id=1" . ($lock ? ' FOR UPDATE' : ''));
		if (!$row) { throw new \RuntimeException('The CrackerTracker block state is missing.'); }
		return $row;
	}

	public function matches($ip, $agent, $host)
	{
		// One current read includes both policy and rules, also for sessions that are already open.
		$result = $this->query("SELECT r.rule_value FROM $this->rules r JOIN " . CONFIG_TABLE . " c
			ON c.config_name='plus_ct_block_enabled' AND c.config_value='1' WHERE r.rule_active=1");
		try
		{
			while ($row = $this->db->sql_fetchrow($result))
			{
				if (request_pattern::matches($row['rule_value'], $ip, $agent, $host)) { return true; }
			}
			return false;
		}
		finally { $this->db->sql_freeresult($result); }
	}

	public function rules($start, $limit)
	{
		$result = $this->db->sql_query_limit("SELECT * FROM $this->rules ORDER BY rule_value,rule_id", $limit, $start);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function save_rule($action, $id, $revision, $value, callable $permitted)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$state = $this->state(true);
			if (!$permitted()) { throw new \DomainException('NO_AUTH_OPERATION'); }
			if ((int) $revision !== (int) $state['rules_version']) { throw new \DomainException('PLUS_CT_RULE_STALE'); }
			if ($action === 'add')
			{
				$value = request_pattern::normalize($value);
				if ($value === '') { throw new \DomainException('PLUS_CT_RULE_INVALID'); }
				$this->query("INSERT INTO $this->rules " . $this->db->sql_build_array('INSERT', array('rule_value' => $value, 'rule_active' => 1)));
				$id = (int) $this->db->sql_nextid();
			}
			elseif ($action === 'delete' && $id > 0)
			{
				$this->query("DELETE FROM $this->rules WHERE rule_id=" . (int) $id);
				if (!$this->db->sql_affectedrows()) { throw new \DomainException('PLUS_CT_RULE_STALE'); }
			}
			else { throw new \DomainException('FORM_INVALID'); }
			$this->query("UPDATE $this->state SET rules_version=rules_version+1 WHERE state_id=1");
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $error) { $this->db->sql_transaction('rollback'); throw $error; }
		return $id;
	}

	private static function plain($value, $length)
	{
		$value = preg_replace('/[\x00-\x1f\x7f]/', '', (string) $value);
		$value = htmlspecialchars_decode(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES);
		return mb_strcut($value, 0, $length, 'UTF-8');
	}

	/** Store the script and referrer's origin. Query strings and path-info can contain private form data. */
	public static function event_fields($when, $ip, $agent, $host, $script, $referrer)
	{
		$script = self::plain($script, 2048);
		$script = preg_match('~^(/[^?#]*?\.php)(?:[/?#]|$)~iD', $script, $m) && substr($m[1], 0, 2) !== '//' ? $m[1] : '';
		$url = parse_url(self::plain($referrer, 2048));
		$origin = '';
		if (is_array($url) && isset($url['scheme'], $url['host']) && in_array(strtolower($url['scheme']), array('http', 'https'), true) &&
			!isset($url['user']) && !isset($url['pass']))
		{
			$origin = strtolower($url['scheme']) . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
			if (!filter_var($origin, FILTER_VALIDATE_URL) || strpos($origin, '\\') !== false) { $origin = ''; }
		}
		return array('event_time' => (int) $when, 'address' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '',
			'agent' => self::plain($agent, 1024), 'hostname' => self::plain($host, 255), 'script' => $script, 'referrer_origin' => $origin);
	}

	private function reset_log($kind, $count)
	{
		$this->query("DELETE FROM $this->events");
		// Counted logs in Plus preserve their historical total on automatic and manual resets.
		$this->query("UPDATE $this->state SET archived=CASE WHEN archived>9223372036854775807-" . (int) $count .
			' THEN 9223372036854775807 ELSE archived+' . (int) $count . " END, reset_kind='$kind',reset_time=" . time() . ' WHERE state_id=1');
	}

	public function record($ip, $agent, $host, $script, $referrer)
	{
		// This is called only for a request already being refused. A log write failure must still return that refusal.
		$this->db->sql_return_on_error(true);
		try
		{
			if (!$this->db->sql_transaction('begin')) { throw new \RuntimeException(); }
			$this->state(true);
			$result = $this->query('SELECT config_name, config_value FROM ' . CONFIG_TABLE .
				" WHERE config_name IN ('plus_ct_block_limit','plus_ct_block_logging') ORDER BY config_name LOCK IN SHARE MODE");
			$options = array_column($this->db->sql_fetchrowset($result), 'config_value', 'config_name');
			$this->db->sql_freeresult($result);
			if (($options['plus_ct_block_logging'] ?? '') === '1')
			{
				$limit = max(1, min(400, (int) ($options['plus_ct_block_limit'] ?? 100)));
				$count = (int) $this->row("SELECT COUNT(*) AS n FROM $this->events")['n'];
				if ($count >= $limit) { $this->reset_log('rotated', $count); }
				$this->query("INSERT INTO $this->events " . $this->db->sql_build_array('INSERT', self::event_fields(time(), $ip, $agent, $host, $script, $referrer)));
			}
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException(); }
		}
		catch (\Throwable $error)
		{
			$this->db->sql_transaction('rollback');
			error_log('CrackerTracker could not write its blocked-request log.');
		}
		finally { $this->db->sql_return_on_error(false); }
	}

	public function history()
	{
		$result = $this->query("SELECT * FROM $this->events ORDER BY event_time DESC,event_id DESC");
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function clear($latest, callable $permitted)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$this->state(true);
			if (!$permitted()) { throw new \DomainException('NO_AUTH_OPERATION'); }
			$row = $this->row("SELECT MAX(event_id) AS latest,COUNT(*) AS total FROM $this->events");
			if ($row['latest'] === null || (string) $row['latest'] !== (string) $latest) { throw new \DomainException('PLUS_CT_LOG_STALE'); }
			$this->reset_log('cleared', (int) $row['total']);
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $error) { $this->db->sql_transaction('rollback'); throw $error; }
	}
}
