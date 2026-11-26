<?php
namespace phpbb2plus\community\service;

class credit_entries
{
	public const LENGTHS = array('title' => 191, 'description' => 255, 'author' => 255, 'contact_email' => 255,
		'author_website' => 255, 'version_text' => 32, 'download_url' => 2048);

	private $db;
	private $table;
	private $auth;
	private $user;

	public function __construct($db, $table, $auth, $user)
	{
		$this->db = $db;
		$this->table = $table;
		$this->auth = $auth;
		$this->user = $user;
	}

	public static function display($value, $legacy)
	{
		$value = html_entity_decode((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		return $legacy ? strtr($value, array("\\'" => "'", '\\"' => '"', '\\\\' => '\\')) : $value;
	}

	public static function encode($value)
	{
		// Retain literal HTML entity spellings on three-byte phpBB installations.
		return utf8_encode_ucr(str_replace('&', '&amp;', (string) $value));
	}

	public static function url($value)
	{
		return preg_match('~^https?://~i', $value) && filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
	}

	public function may_manage($reload = false)
	{
		return \phpbb2plus\community\auth\admin_session::allows($this->db, $this->auth, $this->user, 'a_plus_credits', $reload);
	}

	private function row($sql)
	{
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	public function find($id) { return $this->row('SELECT * FROM ' . $this->table . ' WHERE credit_id=' . (int) $id); }
	public function count($public) { return (int) $this->row('SELECT COUNT(*) AS n FROM ' . $this->table . ($public ? ' WHERE visible=1' : ''))['n']; }

	public function listing($public, $limit, $start)
	{
		$result = $this->db->sql_query_limit('SELECT credit_id,title,description,author,contact_email,author_website,
			version_text,download_url,visible,legacy_quotes FROM ' . $this->table . ($public ? ' WHERE visible=1' : '') .
			' ORDER BY title,credit_id', $limit, $start);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function sequence($lock = false)
	{
		$row = $this->row('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='plus_credit_sequence'" . ($lock ? ' FOR UPDATE' : ''));
		if (!$row) { throw new \RuntimeException('Missing credit catalogue configuration.'); }
		return (int) $row['config_value'];
	}

	public function values($row)
	{
		$values = array('visible' => !empty($row['visible']));
		foreach (self::LENGTHS as $key => $limit) { $values[$key] = self::display($row[$key] ?? '', !empty($row['legacy_quotes'])); }
		return $values;
	}

	private function validate($data, $previous)
	{
		foreach (self::LENGTHS as $key => $limit)
		{
			// An imported string longer than the old editor's limit may be retained.
			if (utf8_strlen($data[$key]) > $limit && $data[$key] !== $previous[$key]) { throw new \DomainException('ACP_PLUS_CREDIT_INVALID'); }
		}
		foreach (array('title', 'description', 'author') as $key)
		{
			if (trim($data[$key]) === '') { throw new \DomainException('ACP_PLUS_CREDIT_INVALID'); }
		}
		foreach (array('author_website', 'download_url', 'contact_email') as $key)
		{
			$value = $data[$key];
			if ($value !== '' && $value !== $previous[$key] &&
				!($key === 'contact_email' ? filter_var($value, FILTER_VALIDATE_EMAIL) : self::url($value)))
			{ throw new \DomainException('ACP_PLUS_CREDIT_LINK'); }
		}
	}

	private function unique_title($title, $id)
	{
		$needle = utf8_case_fold_nfc($title);
		$result = $this->db->sql_query('SELECT title,legacy_quotes FROM ' . $this->table . ' WHERE credit_id <> ' . $id);
		try
		{
			while ($row = $this->db->sql_fetchrow($result))
			{
				if (utf8_case_fold_nfc(self::display($row['title'], $row['legacy_quotes'])) === $needle)
				{ throw new \DomainException('ACP_PLUS_CREDIT_DUPLICATE'); }
			}
		}
		finally { $this->db->sql_freeresult($result); }
	}

	public function save($action, $id, $revision, $data)
	{
		$id = (int) $id;
		if (!in_array($action, array('create', 'edit', 'delete'), true) || ($action === 'create' ? $id !== 0 : $id < 1))
		{ throw new \DomainException('FORM_INVALID'); }
		$this->db->sql_transaction('begin');
		try
		{
			// Serialize catalogue mutations even when it has no rows yet.
			$sequence = $this->sequence(true);
			if (!$this->may_manage(true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
			$row = $id ? $this->find($id) : array();
			if (($id && !$row) || (int) $revision !== ($id ? (int) $row['revision'] : $sequence)) { throw new \DomainException('ACP_PLUS_CREDIT_CHANGED'); }
			if ($action === 'delete') { $this->db->sql_query('DELETE FROM ' . $this->table . ' WHERE credit_id=' . $id); }
			else
			{
				$previous = $this->values($row);
				$this->validate($data, $previous);
				if (!$id || $data['title'] !== $previous['title']) { $this->unique_title($data['title'], $id); }
				$fields = array('visible' => (int) $data['visible'], 'legacy_quotes' => 0);
				foreach (self::LENGTHS as $key => $limit) { $fields[$key] = self::encode($data[$key]); }
				if ($id)
				{
					$fields['revision'] = (int) $row['revision'] + 1;
					$this->db->sql_query('UPDATE ' . $this->table . ' SET ' . $this->db->sql_build_array('UPDATE', $fields) . ' WHERE credit_id=' . $id);
				}
				else
				{
					$fields['added_time'] = time();
					$fields['source_file'] = '';
					$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', $fields));
					$this->db->sql_query('UPDATE ' . CONFIG_TABLE . ' SET config_value=' . ($sequence + 1) . " WHERE config_name='plus_credit_sequence'");
				}
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}
}
