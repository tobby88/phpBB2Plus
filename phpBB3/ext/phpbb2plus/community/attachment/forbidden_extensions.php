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
		return (bool) $this->blocked_types(array($extension));
	}

	/** Effective blocks, keyed by the supplied native suffix. The complete selection is read in one snapshot. */
	public function blocked_types(array $extensions)
	{
		if (!$extensions) { return array(); }
		$names = $keys = $explicit = $blocked = array();
		foreach ($extensions as $extension)
		{
			$key = self::key($extension);
			$keys[$key] = true;
			$names[$extension] = $key !== '' ? $key : utf8_strtolower(trim($extension));
		}
		$plus = (int) ($this->config['plus_attach_upload_types'] ?? -1) === 1;
		if (isset($this->config['plus_attach_block_rev']))
		{
			// Use the current mode and indexed rules, even if another request changed them
			// after an editor was opened. Historical out-of-format names share the empty index key.
			$mode = 'SELECT (SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='plus_attach_upload_types') AS plus_mode";
			$match = $this->db->sql_in_set('f.extension_key', array_map('strval', array_keys($keys)));
			foreach ($this->rows("SELECT p.plus_mode,f.extension_key,f.extension_name FROM ($mode) p LEFT JOIN {$this->table} f ON $match") as $row)
			{
				$plus = (int) $row['plus_mode'] === 1;
				if ($row['extension_key'] !== null)
				{
					$name = $row['extension_key'] !== '' ? $row['extension_key'] : utf8_strtolower(trim($row['extension_name']));
					$explicit[$name] = true;
				}
			}
		}
		foreach ($names as $extension => $name)
		{
			if (isset($explicit[$name]) || ($plus && (self::key($name) === '' || self::fixed($name)))) { $blocked[$extension] = true; }
		}
		return $blocked;
	}

	public function native_extensions($ids = null)
	{
		if ($ids === array()) { return array(); }
		$where = $ids === null ? '' : ' WHERE ' . $this->db->sql_in_set('extension_id', array_map('intval', $ids));
		return $this->rows('SELECT extension_id,extension,group_id FROM ' . EXTENSIONS_TABLE . $where);
	}

	public function enabling_group($id, $posts, $messages)
	{
		$row = $this->rows('SELECT allow_group,allow_in_pm FROM ' . EXTENSION_GROUPS_TABLE . ' WHERE group_id=' . (int) $id)[0] ?? array();
		return $row && (($posts && !$row['allow_group']) || ($messages && !$row['allow_in_pm']));
	}

	public function listing()
	{
		$native = array();
		foreach ($this->native_extensions() as $row) { $native[utf8_strtolower(trim($row['extension']))] = true; }
		$plus = (int) ($this->config['plus_attach_upload_types'] ?? -1) === 1;
		$fixed = $plus ? array_fill_keys(self::BUILT_IN, true) : array();
		if ($plus)
		{
			foreach (array_keys($native) as $name) { if (self::key($name) === '' || self::fixed($name)) { $fixed[$name] = true; } }
		}
		$rows = $this->rows("SELECT * FROM {$this->table}");
		foreach ($rows as &$row)
		{
			$key = $row['extension_key'] !== '' ? $row['extension_key'] : utf8_strtolower(trim($row['extension_name']));
			$row['fixed'] = $plus && self::fixed($key);
			$row['conflict'] = isset($native[$key]);
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
				$mode = $this->rows('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='plus_attach_upload_types' FOR UPDATE");
				$plus = (int) ($mode[0]['config_value'] ?? -1) === 1;
				$where = $this->db->sql_in_set('rule_id', $remove);
				$rows = $this->rows("SELECT extension_name,extension_key FROM {$this->table} WHERE $where FOR UPDATE");
				if (count($rows) !== count($remove)) { throw new \DomainException('PLUS_ATTACH_BLOCK_CHANGED'); }
				foreach ($rows as $row)
				{
					if ($plus && self::fixed($row['extension_key'])) { throw new \DomainException('PLUS_ATTACH_BLOCK_FIXED'); }
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
