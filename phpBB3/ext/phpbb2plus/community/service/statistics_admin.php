<?php
namespace phpbb2plus\community\service;

class statistics_admin
{
	public const RIGHTS = array('manage' => 'a_plus_stats_modules', 'settings' => 'a_plus_stats_settings');
	public const AUDIENCES = array(0 => 'PLUS_STATS_EVERYONE', 1 => 'PLUS_STATS_MEMBERS_ONLY', 5 => 'PLUS_STATS_ADMINS_ONLY');
	private $db;
	private $config;
	private $text;
	private $table;
	private $user;
	private $auth;
	private $sizes;
	private $cache;

	public function __construct($db, $config, $text, $table, $user, $auth, $sizes, $cache)
	{
		$this->db = $db;
		$this->config = $config;
		$this->text = $text;
		$this->table = $table;
		$this->user = $user;
		$this->auth = $auth;
		$this->sizes = $sizes;
		$this->cache = $cache;
	}

	public function allowed($mode, $reload = false)
	{
		if (!isset(self::RIGHTS[$mode]) || !defined('IN_ADMIN') || !IN_ADMIN || empty($this->user->data['is_registered']) ||
			empty($this->user->data['session_admin']) || !empty($this->user->data['user_perm_from'])) { return false; }
		if ($reload)
		{
			$sql = 'SELECT u.*,s.session_admin FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE . ' s ON s.session_user_id=u.user_id
				WHERE u.user_id=' . (int) $this->user->data['user_id'] . " AND s.session_id='" . $this->db->sql_escape($this->user->session_id) . "'";
			$result = $this->db->sql_query($sql);
			$actor = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if (!$actor || !$actor['session_admin'] || $actor['user_perm_from'] ||
				!in_array((int) $actor['user_type'], array(USER_NORMAL, USER_FOUNDER), true)) { return false; }
			$actor['is_registered'] = true;
			$actor['is_bot'] = false;
			$this->auth->acl($actor);
		}
		return (bool) $this->auth->acl_get(self::RIGHTS[$mode]);
	}

	public function snapshot($mode, $lock = false)
	{
		$suffix = $lock ? ' FOR UPDATE' : '';
		if ($mode === 'manage')
		{
			$result = $this->db->sql_query("SELECT config_value FROM {$this->table} WHERE config_name='plus_statistics_modules'" . $suffix);
			$value = $this->db->sql_fetchfield('config_value');
			$this->db->sql_freeresult($result);
			$records = json_decode((string) $value, true);
			if (!is_array($records)) { throw new \UnexpectedValueException('Missing statistics module registry'); }
			return $records;
		}
		$result = $this->db->sql_query('SELECT config_name,config_value FROM ' . CONFIG_TABLE .
			" WHERE config_name IN ('plus_statistics_limit','plus_statistics_storage_ttl') ORDER BY config_name" . $suffix);
		$values = array_column($this->db->sql_fetchrowset($result), 'config_value', 'config_name');
		$this->db->sql_freeresult($result);
		return $values;
	}

	public static function revision($snapshot) { return hash('sha256', serialize($snapshot)); }

	public static function available($records)
	{
		$names = statistics_modules::SOURCES;
		foreach ($records as $row)
		{
			if (statistics_modules::supported($row)) { unset($names[trim($row['name'])]); }
		}
		return array_keys($names);
	}

	public static function state($record)
	{
		return (int) $record['installed'] === 1 ? ((int) $record['active'] === 1 ? 2 : 1) : 0;
	}

	private function update_module($records, $data)
	{
		$id = $data['id'];
		$position = $data['position'];
		$audience = $data['audience'];
		$state = $data['state'];
		if (!isset(self::AUDIENCES[$audience]) || !in_array($state, array(0,1,2), true) ||
			$position < 1 || $position > count($records) + ($id === 0 ? 1 : 0)) { throw new \DomainException('FORM_INVALID'); }
		if ($id === 0)
		{
			$name = $data['name'];
			if (!in_array($name, self::available($records), true) || !$state) { throw new \DomainException('FORM_INVALID'); }
			$highest = $records ? max(array_column($records, 'module_id')) : 0;
			if ($highest >= PHP_INT_MAX - 1) { throw new \DomainException('FORM_INVALID'); }
			$row = array('module_id' => $highest + 1, 'name' => $name, 'renderer' => 'native_install', 'source_sha256' => '', 'update_time' => 0);
		}
		else
		{
			$index = array_search($id, array_column($records, 'module_id'), true);
			if ($index === false || !statistics_modules::supported($records[$index])) { throw new \DomainException('FORM_INVALID'); }
			$row = $records[$index];
		}
		$row['installed'] = $state ? 1 : 0;
		$row['active'] = $state === 2 ? 1 : 0;
		$row['auth_value'] = $audience;
		if ($id && $position === $index + 1)
		{
			$records[$index] = $row;
			return $records;
		}
		if ($id) { array_splice($records, $index, 1); }
		array_splice($records, $position - 1, 0, array($row));
		foreach ($records as $index => &$record) { $record['display_order'] = $index + 1; }
		unset($record);
		return $records;
	}

	public function save($mode, $revision, $data)
	{
		if (!$this->allowed($mode)) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$this->db->sql_transaction('begin');
		try
		{
			$old = $this->snapshot($mode, true);
			if (!$this->allowed($mode, true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
			if (!hash_equals(self::revision($old), $revision)) { throw new \DomainException('PLUS_STATS_CHANGED'); }
			if ($mode === 'manage')
			{
				$this->text->set('plus_statistics_modules', json_encode($this->update_module($old, $data), JSON_INVALID_UTF8_SUBSTITUTE));
			}
			else
			{
				if ($data['limit'] < 1 || $data['limit'] > 100 || $data['ttl'] < 0 || $data['ttl'] > 86400) { throw new \DomainException('FORM_INVALID'); }
				$this->config->set('plus_statistics_limit', $data['limit']);
				$this->config->set('plus_statistics_storage_ttl', $data['ttl']);
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
		if ($mode === 'settings')
		{
			$this->cache->destroy('config');
			if ($data['clear'] || (int) $old['plus_statistics_storage_ttl'] !== $data['ttl']) { $this->sizes->clear(); }
		}
	}
}
