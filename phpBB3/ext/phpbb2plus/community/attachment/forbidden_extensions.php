<?php
namespace phpbb2plus\community\attachment;

class forbidden_extensions
{
	public const PERMISSION = 'a_plus_attach_block';
	public const MODULE = 'phpbb2plus\community\acp\attachment_blocks_module';
	private const BUILT_IN = array('asp','aspx','cgi','jsp','phar','php','php3','php4','phtml','pl','py','sh','shtm','shtml');
	private $db;
	private $config;
	private $table;
	private $lock;
	private $held = false;

	public function __construct($db, $config, $prefix)
	{
		$this->db = $db;
		$this->config = $config;
		$this->table = $prefix . 'plus_attach_forbidden';
		$this->lock = "CONCAT('plus-attach:', MD5(CONCAT(DATABASE(), ':', '" . $db->sql_escape($this->table) . "')))";
	}

	private function rows($sql)
	{
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public static function key($extension)
	{
		$key = strtolower(trim($extension));
		return preg_match('/^[a-z0-9]{1,16}$/D', $key) ? $key : '';
	}

	private static function fixed($key)
	{
		return in_array($key, self::BUILT_IN, true) || (bool) preg_match('/^php[0-9]*$/D', $key);
	}

	public function blocked($extension)
	{
		$key = self::key($extension);
		if ($key === '' || self::fixed($key)) { return true; }
		if (!isset($this->config['plus_attach_block_rev'])) { return false; }
		return (bool) $this->rows("SELECT rule_id FROM {$this->table} WHERE extension_key='" . $this->db->sql_escape($key) . "' LIMIT 1");
	}

	public function native_extensions()
	{
		return $this->rows('SELECT extension_id,extension,group_id FROM ' . EXTENSIONS_TABLE);
	}

	public function enabling_group($id, $posts, $messages)
	{
		$row = $this->rows('SELECT allow_group,allow_in_pm FROM ' . EXTENSION_GROUPS_TABLE . ' WHERE group_id=' . (int) $id)[0] ?? array();
		return $row && (($posts && !$row['allow_group']) || ($messages && !$row['allow_in_pm']));
	}

	public function listing()
	{
		$native = array();
		foreach ($this->native_extensions() as $row) { $native[self::key($row['extension'])] = true; }
		$fixed = array_fill_keys(self::BUILT_IN, true);
		$rows = $this->rows("SELECT * FROM {$this->table}");
		foreach ($rows as &$row)
		{
			$key = $row['extension_key'];
			$row['fixed'] = self::fixed($key);
			$row['conflict'] = $key !== '' && isset($native[$key]);
			unset($fixed[$key]);
		}
		unset($row);
		foreach ($fixed as $key => $unused)
		{
			$rows[] = array('rule_id'=>0, 'extension_name'=>$key, 'extension_key'=>$key, 'fixed'=>true, 'conflict'=>isset($native[$key]));
		}
		usort($rows, static function ($a, $b) { return strcasecmp(trim($a['extension_name']), trim($b['extension_name'])) ?: $a['rule_id'] <=> $b['rule_id']; });
		return $rows;
	}

	public function revision($lock = false)
	{
		$rows = $this->rows('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='plus_attach_block_rev'" . ($lock ? ' FOR UPDATE' : ''));
		if (!$rows) { throw new \DomainException('PLUS_ATTACH_BLOCK_CHANGED'); }
		return (int) $rows[0]['config_value'];
	}

	// The native allow-list editor writes through multiple SQL statements. Serialize it
	// with custom rule changes without extending a phpBB transaction across the request.
	public function enter()
	{
		$rows = $this->rows("SELECT GET_LOCK({$this->lock},10) AS acquired");
		if (!$rows || (int) $rows[0]['acquired'] !== 1) { throw new \DomainException('PLUS_ATTACH_BLOCK_CHANGED'); }
		$this->held = true;
	}

	public function leave()
	{
		if ($this->held)
		{
			$this->held = false;
			$this->rows("SELECT RELEASE_LOCK({$this->lock})");
		}
	}

	public function save($revision, $add, $remove, $user, $auth, $log, $prefix)
	{
		$this->enter();
		$this->db->sql_transaction('begin');
		try
		{
			if ($this->revision(true) !== $revision) { throw new \DomainException('PLUS_ATTACH_BLOCK_CHANGED'); }
			$this->authorize_write($user, $auth, $prefix);
			$added = self::key($add);
			$remove = array_values(array_unique(array_map('intval', $remove)));
			if ($add !== '')
			{
				if ($added === '' || $this->blocked($added)) { throw new \DomainException('PLUS_ATTACH_BLOCK_INVALID'); }
				foreach ($this->native_extensions() as $row)
				{
					if (self::key($row['extension']) === $added) { throw new \DomainException('PLUS_ATTACH_BLOCK_ALLOWED'); }
				}
			}
			if ($remove)
			{
				$where = $this->db->sql_in_set('rule_id', $remove);
				$rows = $this->rows("SELECT extension_name,extension_key FROM {$this->table} WHERE $where FOR UPDATE");
				if (count($rows) !== count($remove)) { throw new \DomainException('PLUS_ATTACH_BLOCK_CHANGED'); }
				foreach ($rows as $row)
				{
					if (self::fixed($row['extension_key'])) { throw new \DomainException('PLUS_ATTACH_BLOCK_FIXED'); }
				}
				$this->db->sql_query("DELETE FROM {$this->table} WHERE $where");
				foreach ($rows as $row) { $log->add('admin', $user->data['user_id'], $user->ip, 'LOG_PLUS_ATTACH_UNBLOCK', false, array(utf8_htmlspecialchars($row['extension_name']))); }
			}
			if ($added !== '')
			{
				$this->db->sql_query("INSERT INTO {$this->table} " . $this->db->sql_build_array('INSERT', array('extension_key'=>$added, 'extension_name'=>$added)));
				$log->add('admin', $user->data['user_id'], $user->ip, 'LOG_PLUS_ATTACH_BLOCK', false, array($added));
			}
			if ($remove || $added !== '') { $this->db->sql_query('UPDATE ' . CONFIG_TABLE . " SET config_value=config_value+1 WHERE config_name='plus_attach_block_rev'"); }
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
		finally { $this->leave(); }
	}

	private function authorize_write($user, $auth, $prefix)
	{
		$id = (int) $user->data['user_id'];
		$sid = $this->db->sql_escape($user->session_id);
		$actor = $this->rows('SELECT u.* FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE . " s ON s.session_user_id=u.user_id
			WHERE u.user_id=$id AND s.session_id='$sid' AND s.session_admin=1 LOCK IN SHARE MODE")[0] ?? null;
		if (!$actor || $actor['user_perm_from'] || !in_array((int) $actor['user_type'], array(USER_FOUNDER,USER_NORMAL), true))
		{ throw new \DomainException('NO_AUTH_OPERATION'); }
		if ((int) $actor['user_type'] === USER_FOUNDER) { return; }
		if (!empty($actor['plus_jr_delegate'])) { $this->rows("SELECT user_id FROM {$prefix}plus_jr_delegations WHERE user_id=$id LOCK IN SHARE MODE"); }
		$raw = $auth->acl_raw_data($id, array('a_', self::PERMISSION), 0)[$id][0] ?? array();
		$permission = (int) ($raw[self::PERMISSION] ?? ACL_NO);
		if ((int) ($raw['a_'] ?? ACL_NO) !== ACL_NEVER && $permission !== ACL_NEVER)
		{
			if ($permission === ACL_YES) { return; }
			$actor['is_registered'] = true;
			$auth->acl($actor);
			if ($auth->delegated_module(self::MODULE, 'manage')) { return; }
		}
		throw new \DomainException('NO_AUTH_OPERATION');
	}
}
