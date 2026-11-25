<?php
namespace phpbb2plus\banners\service;

class management
{
	private $db;
	private $auth;
	private $user;
	private $prefix;

	public function __construct($db, $auth, $user, $prefix)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->prefix = $prefix;
	}

	public static function reserve_legacy_ids($db, $prefix, $source_next = 0)
	{
		$r = $db->sql_query('SELECT MAX(banner_id) AS last_id FROM ' . $prefix . '_hits');
		$floor = max((int) $source_next, 1 + (int) $db->sql_fetchfield('last_id'));
		$db->sql_freeresult($r);
		if ($floor <= 1) { return; }
		$r = $db->sql_query("SELECT AUTO_INCREMENT AS next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" .
			$db->sql_escape($prefix . 's') . "'");
		$next = (int) $db->sql_fetchfield('next_id');
		$db->sql_freeresult($r);
		if ($next && $next < $floor) { $db->sql_query('ALTER TABLE ' . $prefix . 's AUTO_INCREMENT=' . $floor); }
	}

	public function forums()
	{
		$choices = array(0=>'');
		$sql = 'SELECT f.forum_id,f.forum_name,f.forum_password,a.forum_id AS unlocked FROM ' . FORUMS_TABLE . ' f LEFT JOIN ' .
			FORUMS_ACCESS_TABLE . ' a ON a.forum_id=f.forum_id AND a.user_id=' . (int) $this->user->data['user_id'] .
			" AND a.session_id='" . $this->db->sql_escape($this->user->session_id) . "' ORDER BY f.left_id";
		$r = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($r))
		{
			$id = (int) $row['forum_id'];
			if ($this->auth->acl_get('f_list',$id) && $this->auth->acl_get('f_read',$id) && ($row['forum_password'] === '' || $row['unlocked']))
			{
				$choices[$id] = html_entity_decode($row['forum_name'],ENT_QUOTES,'UTF-8');
			}
		}
		$this->db->sql_freeresult($r);
		return $choices;
	}

	public function rows($forums, $offset, $limit)
	{
		$where = $this->db->sql_in_set('forum_id',array_keys($forums));
		$r = $this->db->sql_query('SELECT COUNT(*) AS total FROM ' . $this->prefix . 's WHERE ' . $where);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($r);
		$offset = min(max(0,$offset), max(0, (int) floor(($total-1)/$limit) * $limit));
		$r = $this->db->sql_query_limit('SELECT banner_id,display_name,description,spot,active,media_type,views,clicks FROM ' .
			$this->prefix . 's WHERE ' . $where . ' ORDER BY spot,banner_id',$limit,$offset);
		$rows = $this->db->sql_fetchrowset($r);
		$this->db->sql_freeresult($r);
		return array($rows,$total,$offset);
	}

	public function row($id, $forums, $lock = false)
	{
		$r = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 's WHERE banner_id=' . (int) $id .
			' AND ' . $this->db->sql_in_set('forum_id', array_keys($forums)) . ($lock ? ' FOR UPDATE' : ''));
		$row = $this->db->sql_fetchrow($r);
		$this->db->sql_freeresult($r);
		if (!$row) { throw new \DomainException('PLUS_BN_UNKNOWN'); }
		return $row;
	}

	public static function revision($row)
	{
		// Traffic changes the counters independently of changes to these settings.
		unset($row['views'],$row['clicks']);
		ksort($row);
		return hash('sha256',serialize(array_map('strval',$row)));
	}

	public static function counter($value)
	{
		if (!preg_match('/^[0-9]{1,20}$/D',$value) || (strlen($value) === 20 && strcmp($value,'18446744073709551615') > 0))
		{
			throw new \DomainException('PLUS_BN_COUNTER_INVALID');
		}
		return ltrim($value,'0') === '' ? '0' : ltrim($value,'0');
	}

	public static function whole($decimal) { return explode('.', (string) $decimal, 2)[0]; }

	public function username($id)
	{
		if (!$id || (int) $id === ANONYMOUS) { return ''; }
		$r = $this->db->sql_query('SELECT username FROM ' . USERS_TABLE . ' WHERE user_id=' . (int) $id);
		$name = $this->db->sql_fetchfield('username');
		$this->db->sql_freeresult($r);
		return $name === false ? '' : html_entity_decode($name,ENT_QUOTES,'UTF-8');
	}

	public function owner($name)
	{
		if ($name === '') { return 0; }
		$r = $this->db->sql_query('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_type <> ' . USER_IGNORE .
			" AND username_clean='" . $this->db->sql_escape(utf8_clean_string($name)) . "'");
		$id = $this->db->sql_fetchfield('user_id');
		$this->db->sql_freeresult($r);
		if (!$id) { throw new \DomainException('PLUS_BN_OWNER_INVALID'); }
		return (int) $id;
	}

	public function write($id, $revision, $values, $counters, $key, $remove)
	{
		$changed = true;
		$table = $this->prefix . 's';
		$this->db->sql_transaction('begin');
		try
		{
			$forums = $this->forums();
			if (!$remove && !array_key_exists($values['forum_id'],$forums)) { throw new \DomainException('PLUS_BN_UNKNOWN'); }
			if ($id)
			{
				$current = $this->row($id,$forums,true);
				if (!hash_equals(self::revision($current),$revision)) { throw new \DomainException('PLUS_BN_CONFLICT'); }
				if ($remove)
				{
					foreach (array('_receipts','_hits','s') as $suffix)
					{
						$this->db->sql_query('DELETE FROM ' . $this->prefix . $suffix . ' WHERE banner_id=' . (int) $id);
					}
				}
				else
				{
					foreach ($counters as $field=>$pair)
					{
						list($old,$new) = $pair;
						if ($new === $old) { continue; }
						if ($old !== self::whole($current[$field])) { throw new \DomainException('PLUS_BN_CONFLICT'); }
						$values[$field] = $new;
					}
					$this->db->sql_query('UPDATE ' . $table . ' SET ' . $this->db->sql_build_array('UPDATE',$values) . ' WHERE banner_id=' . (int) $id);
				}
			}
			else
			{
				if (!preg_match('/^[a-f0-9]{32}$/D',$key)) { throw new \DomainException('FORM_INVALID'); }
				$receipt = hash('sha256',$this->user->data['user_id'] . ':' . $key);
				$r = $this->db->sql_query("SELECT config_name FROM " . CONFIG_TABLE . " WHERE config_name='plus_banners_enabled' FOR UPDATE");
				$this->db->sql_freeresult($r);
				// A locking read sees a concurrent submission even if an earlier read started a snapshot.
				$r = $this->db->sql_query("SELECT form_key FROM " . $this->prefix . "_forms WHERE form_key='$receipt' FOR UPDATE");
				$changed = !$this->db->sql_fetchrow($r);
				$this->db->sql_freeresult($r);
				if ($changed)
				{
					foreach ($counters as $field=>$pair) { $values[$field] = $pair[1]; }
					$values['source_owner'] = 0;
					$this->db->sql_query('INSERT INTO ' . $table . ' ' . $this->db->sql_build_array('INSERT',$values));
					$this->db->sql_query("INSERT INTO " . $this->prefix . "_forms (form_key) VALUES ('$receipt')");
				}
			}
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('Banner administration transaction did not commit'); }
		}
		catch (\Throwable $error)
		{
			if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); }
			throw $error;
		}
		return $changed;
	}
}
