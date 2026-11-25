<?php
namespace phpbb2plus\community\auth;

class delegations
{
	private $db;
	private $managed;
	private $source;
	private $access;
	private $log;

	public function __construct($db, $prefix, $access, $log)
	{
		$this->db = $db;
		$this->managed = $prefix . 'plus_jr_delegations';
		$this->source = $prefix . 'plus_jr_records';
		$this->access = $access;
		$this->log = $log;
	}

	public static function import($db, $prefix)
	{
		$managed = $prefix . 'plus_jr_delegations';
		$source = $prefix . 'plus_jr_records';
		$eligible = "SELECT d.user_id FROM $managed d WHERE d.rights_json<>'[]'";
		$db->sql_transaction('begin');
		try
		{
			// A repeated upgrade must retain a later revocation, including an empty grant list.
			$db->sql_query("INSERT INTO $managed (user_id,rights_json,notes_b64,notes_view,start_date,update_date,revision)
				SELECT s.user_id,s.rights_json,s.notes_b64,s.notes_view,s.start_date,s.update_date,1 FROM $source s
				JOIN " . USERS_TABLE . " u ON u.user_id=s.user_id LEFT JOIN $managed d ON d.user_id=s.user_id
				WHERE s.user_id>" . ANONYMOUS . ' AND d.user_id IS NULL');
			$db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_jr_delegate=0 WHERE plus_jr_delegate=1 AND (user_type<>" . USER_NORMAL . " OR user_id NOT IN ($eligible))");
			$db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_jr_delegate=1 WHERE plus_jr_delegate=0 AND user_type=' . USER_NORMAL . " AND user_id IN ($eligible)");
			$db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }
	}

	public static function permitted($auth, $actor)
	{
		return !empty($actor['is_registered']) && !empty($actor['session_admin']) && isset($actor['user_perm_from']) &&
			(int) $actor['user_perm_from'] === 0 && $auth->native_module('acl_a_aauth && acl_a_authusers');
	}

	public function lookup($username)
	{
		$result = $this->db->sql_query('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='" .
			$this->db->sql_escape(utf8_clean_string($username)) . "' AND user_id>" . ANONYMOUS . ' AND user_type<>' . USER_IGNORE);
		$id = (int) $this->db->sql_fetchfield('user_id');
		$this->db->sql_freeresult($result);
		return $id;
	}

	public function listing($offset, $limit)
	{
		$result = $this->db->sql_query_limit("SELECT u.user_id,u.username,d.rights_json FROM {$this->managed} d JOIN " .
			USERS_TABLE . ' u ON d.user_id=u.user_id ORDER BY u.username_clean,u.user_id', $limit, $offset);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function account($id, $lock = false)
	{
		$result = $this->db->sql_query("SELECT u.user_id,u.username,u.user_type,d.rights_json,d.notes_b64,d.notes_view,
			d.start_date,d.update_date,d.revision,s.report_json,s.source_user,s.notes_b64 AS old_note,s.notes_view AS old_visible FROM " . USERS_TABLE . " u
			LEFT JOIN {$this->managed} d ON d.user_id=u.user_id LEFT JOIN {$this->source} s ON s.user_id=u.user_id
			WHERE u.user_id=" . (int) $id . ' AND u.user_id>' . ANONYMOUS . ' AND u.user_type<>' . USER_IGNORE . ($lock ? ' FOR UPDATE' : ''));
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row) { return false; }
		$row['rights'] = json_decode($row['rights_json'] ?? '[]', true);
		$row['note'] = base64_decode($row['notes_b64'] ?? '', true);
		if (!is_array($row['rights']) || $row['note'] === false) { throw new \RuntimeException('Invalid stored Junior Admin record'); }
		$row['reports'] = json_decode($row['report_json'] ?? '[]', true) ?: array();
		return $row;
	}

	public function save($auth, $actor, $ip, $id, $revision, array $requested, $note, $visible)
	{
		if (!self::permitted($auth, $actor) || (int) $actor['user_id'] === (int) $id) { throw new \DomainException('PLUS_JR_NO_CHANGE'); }
		$this->db->sql_transaction('begin');
		try
		{
			$row = $this->account($id, true);
			if (!$row || (int) $row['user_type'] === USER_FOUNDER) { throw new \DomainException('PLUS_JR_NO_CHANGE'); }
			if ((int) $row['revision'] !== $revision) { throw new \DomainException('PLUS_JR_STALE'); }
			if ($note !== $row['note'] && strlen($note) > 60000) { throw new \DomainException('PLUS_JR_LONG_NOTE'); }
			$states = $this->access->states($id, $auth, array_column(junior_admin::MODULES, 2));
			foreach ($requested as $option)
			{
				if (!is_string($option) || !isset($states[$option]) || (!in_array($option, $row['rights'], true) &&
					((int) $row['user_type'] !== USER_NORMAL || !in_array($states[$option], array('available', 'native'), true))))
				{ throw new \DomainException('PLUS_JR_INVALID_GRANT'); }
			}
			$rights = array_values(array_intersect(array_keys($states), $requested));
			// Preserve any assignments created by a newer release. Only mapped permissions
			// are offered or evaluated by this version.
			$rights = array_merge($rights, array_values(array_diff($row['rights'], array_keys($states))));
			if (!array_diff($rights, $row['rights']) && !array_diff($row['rights'], $rights) && $note === $row['note'] && $visible === (bool) $row['notes_view'])
			{
				$this->db->sql_transaction('commit');
				return false;
			}
			$now = time();
			$data = array('rights_json' => json_encode($rights), 'notes_b64' => base64_encode($note), 'notes_view' => (int) $visible,
				'start_date' => $row['start_date'] ?: $now, 'update_date' => $now, 'revision' => $revision + 1);
			if ($row['revision'] !== null)
			{ $this->db->sql_query("UPDATE {$this->managed} SET " . $this->db->sql_build_array('UPDATE', $data) . ' WHERE user_id=' . (int) $id); }
			else
			{ $this->db->sql_query("INSERT INTO {$this->managed} " . $this->db->sql_build_array('INSERT', $data + array('user_id' => (int) $id))); }
			$this->db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_jr_delegate=' . (int) ((int) $row['user_type'] === USER_NORMAL && $rights) . ' WHERE user_id=' . (int) $id);
			$this->log->add('admin', $actor['user_id'], $ip, 'LOG_PLUS_JR_UPDATED', false, array($row['username']));
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
		return true;
	}
}
