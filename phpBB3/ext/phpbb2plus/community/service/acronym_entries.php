<?php
namespace phpbb2plus\community\service;

use phpbb2plus\community\auth\admin_session;

class acronym_entries
{
	private $db, $table, $auth, $user, $cache;

	public function __construct($db, $table, $auth, $user, $cache)
	{
		$this->db = $db; $this->table = $table; $this->auth = $auth; $this->user = $user; $this->cache = $cache;
	}

	public function allowed($reload = false) { return admin_session::allows($this->db, $this->auth, $this->user, 'a_plus_acronyms', $reload); }

	private function row($sql)
	{
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	public function find($id) { return $this->row('SELECT * FROM ' . $this->table . ' WHERE acronym_id=' . (int) $id); }
	public function total() { return (int) $this->row('SELECT COUNT(*) AS total FROM ' . $this->table)['total']; }

	public function page($offset, $limit)
	{
		$result = $this->db->sql_query_limit('SELECT * FROM ' . $this->table . ' ORDER BY term_text, acronym_id', $limit, $offset);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function sequence($lock = false)
	{
		$row = $this->row('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='plus_acronym_sequence'" . ($lock ? ' FOR UPDATE' : ''));
		if (!$row) { throw new \RuntimeException('Missing acronym dictionary configuration'); }
		return (int) $row['config_value'];
	}

	public function save($action, $id, $revision, $term, $definition)
	{
		if (!in_array($action, array('add', 'edit', 'delete'), true) || ($action === 'add' ? $id !== 0 : $id < 1))
		{ throw new \DomainException('FORM_INVALID'); }
		$this->db->sql_transaction('begin');
		try
		{
			$sequence = $this->sequence(true);
			if (!$this->allowed(true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
			$row = $id ? $this->find($id) : array();
			if (($id && !$row) || $revision !== ($id ? (int) $row['revision'] : $sequence)) { throw new \DomainException('PLUS_ACRONYM_STALE'); }
			if ($action === 'delete') { $this->db->sql_query('DELETE FROM ' . $this->table . ' WHERE acronym_id=' . $id); }
			else
			{
				if ($term === '' || $definition === '' || utf8_strlen($term) > 80 || utf8_strlen($definition) > 255 ||
					strpos($term . $definition, "\0") !== false) { throw new \DomainException('PLUS_ACRONYM_INVALID'); }
				$fields = array('term_text' => acronym_dictionary::encode($term), 'definition_text' => acronym_dictionary::encode($definition));
				if ((!$id || $fields['term_text'] !== $row['term_text']) && $this->row('SELECT acronym_id FROM ' . $this->table .
					" WHERE term_text='" . $this->db->sql_escape($fields['term_text']) . "' AND acronym_id<>" . $id))
				{ throw new \DomainException('PLUS_ACRONYM_EXISTS'); }
				if ($id)
				{
					$fields['revision'] = $revision + 1;
					$this->db->sql_query('UPDATE ' . $this->table . ' SET ' . $this->db->sql_build_array('UPDATE', $fields) . ' WHERE acronym_id=' . $id);
				}
				else
				{
					$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', $fields));
					$this->db->sql_query('UPDATE ' . CONFIG_TABLE . ' SET config_value=' . ($sequence + 1) . " WHERE config_name='plus_acronym_sequence'");
				}
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
		$this->cache->destroy('sql', $this->table);
	}
}
