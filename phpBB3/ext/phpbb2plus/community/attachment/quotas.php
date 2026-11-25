<?php
namespace phpbb2plus\community\attachment;

/** Attachment MOD storage allowances, applied alongside native phpBB upload limits. */
class quotas
{
	private $db;
	private $config;
	private $prefix;

	public function __construct($db, $config, $prefix)
	{
		$this->db = $db;
		$this->config = $config;
		$this->prefix = $prefix;
	}

	public function enabled() { return !empty($this->config['plus_attach_quotas']); }

	private function scalar($sql, $field)
	{
		$r = $this->db->sql_query_limit($sql,1);
		$value = $this->db->sql_fetchfield($field);
		$this->db->sql_freeresult($r);
		return $value;
	}

	private static function integer($value)
	{
		$digits = ltrim(explode('.', (string) $value, 2)[0], '0');
		return $digits === '' ? '0' : $digits;
	}

	private static function larger($left,$right)
	{
		return strlen($left) === strlen($right) ? strcmp($left,$right) > 0 : strlen($left) > strlen($right);
	}

	public function allowance($id, $pm)
	{
		$id = (int) $id;
		return $this->allowances(array($id),$pm)[$id];
	}

	private function allowances($ids, $pm)
	{
		$allowed = array_fill_keys($ids,'0');
		if (!$this->enabled() || !$ids) { return $allowed; }
		$r = $this->db->sql_query('SELECT u.user_id FROM ' . USERS_TABLE . ' u WHERE ' . $this->db->sql_in_set('u.user_id',$ids) .
			' AND u.user_type<>' . USER_FOUNDER . ' AND NOT EXISTS (SELECT 1 FROM ' . USER_GROUP_TABLE . ' ug JOIN ' . GROUPS_TABLE .
			" g ON g.group_id=ug.group_id WHERE ug.user_id=u.user_id AND ug.user_pending=0 AND g.group_type=" . GROUP_SPECIAL . " AND g.group_name='ADMINISTRATORS')");
		$members = array_column($this->db->sql_fetchrowset($r),'user_id');
		$this->db->sql_freeresult($r);
		if (!$members) { return $allowed; }
		$kind = $pm ? 2 : 1;
		$limits = $this->prefix . 'limits';
		$join = ' FROM ' . $this->prefix . "quotas q JOIN $limits l ON l.limit_id=q.limit_id";
		$personal = array();
		$r = $this->db->sql_query('SELECT q.user_id,l.bytes' . $join . " WHERE q.kind=$kind AND " .
			$this->db->sql_in_set('q.user_id',$members) . ' ORDER BY q.assignment_id');
		while ($row = $this->db->sql_fetchrow($r))
		{
			if (!isset($personal[$row['user_id']])) { $personal[$row['user_id']] = self::integer($row['bytes']); }
		}
		$this->db->sql_freeresult($r);
		$rest = array_diff($members,array_keys($personal));
		if ($rest)
		{
			$r = $this->db->sql_query('SELECT ug.user_id,MAX(l.bytes) AS bytes' . $join . ' JOIN ' . USER_GROUP_TABLE .
				" ug ON ug.group_id=q.group_id WHERE ug.user_pending=0 AND q.kind=$kind AND " . $this->db->sql_in_set('ug.user_id',$rest) . ' GROUP BY ug.user_id');
			while ($row = $this->db->sql_fetchrow($r)) { $personal[$row['user_id']] = self::integer($row['bytes']); }
			$this->db->sql_freeresult($r);
		}
		$global = self::integer($this->config[$pm ? 'plus_attach_base_pm' : 'attachment_quota']);
		$default = $global;
		$default_id = (int) $this->config[$pm ? 'plus_attach_default_pm' : 'plus_attach_default_post'];
		if ($default_id && count($personal) < count($members))
		{
			$row = $this->scalar("SELECT bytes FROM $limits WHERE limit_id=$default_id",'bytes');
			if ($row !== false) { $default = self::integer($row); }
		}
		foreach ($members as $id)
		{
			$limit = $personal[$id] ?? $default;
			$allowed[$id] = !$pm && $global !== '0' && self::larger($limit,$global) ? $global : $limit;
		}
		return $allowed;
	}

	private function usage_sql($ids, $pm, $incoming)
	{
		$message = (int) $pm;
		$a = ATTACHMENTS_TABLE;
		$r = $this->prefix . 'refs';
		$p = $this->prefix . 'payers';
		$to = PRIVMSGS_TO_TABLE;
		$payer = $incoming ? 'recipient' : 'sender';
		$archived = "SELECT h.$payer AS actor,MAX(a.filesize) AS bytes FROM $p h JOIN $r r ON r.attach_id=h.attach_id JOIN $a a ON a.attach_id=r.attach_id
			WHERE " . $this->db->sql_in_set('h.' . $payer,$ids) . " AND a.in_message=$message AND a.is_orphan=0";
		if ($pm)
		{
			// The old sent-box copy did not consume either correspondent's allowance.
			$box = $incoming ? "d.user_id=h.recipient AND d.folder_id NOT IN (" . PRIVMSGS_SENTBOX . ',' . PRIVMSGS_OUTBOX . ')' : 'd.folder_id<>' . PRIVMSGS_SENTBOX;
			$archived .= " AND h.pm_state>=0 AND h.pm_state<>2 AND (h.pm_state=4 OR EXISTS (SELECT 1 FROM $to d WHERE d.msg_id=a.post_msg_id AND $box))";
		}
		$archived .= " GROUP BY h.$payer,r.source_id";

		$actor = $incoming ? 'dest.user_id' : 'a.poster_id';
		$native = "SELECT $actor AS actor,MAX(a.filesize) AS bytes FROM $a a";
		if ($incoming) { $native .= " JOIN $to dest ON dest.msg_id=a.post_msg_id AND dest.folder_id NOT IN (" . PRIVMSGS_SENTBOX . ',' . PRIVMSGS_OUTBOX . ')'; }
		$native .= " LEFT JOIN $r r ON r.attach_id=a.attach_id LEFT JOIN $p h ON h.attach_id=a.attach_id
			WHERE a.in_message=$message AND h.attach_id IS NULL AND (r.attach_id IS NULL OR r.parent_id<>0) AND " . $this->db->sql_in_set($actor,$ids);
		if ($incoming) { $native .= ' AND a.is_orphan=0'; }
		elseif ($pm)
		{
			$native .= " AND (a.is_orphan=1 OR EXISTS (SELECT 1 FROM $to dest WHERE dest.msg_id=a.post_msg_id AND dest.folder_id<>" . PRIVMSGS_SENTBOX . '))';
		}
		// Imported shared files and native topic copies are billed once per owner and direction.
		$native .= " GROUP BY $actor,r.source_id,CASE WHEN r.source_id IS NULL THEN a.physical_filename ELSE '' END";
		return $archived . ' UNION ALL ' . $native;
	}

	public function occupied($user, $pm, $incoming = false)
	{
		return self::integer($this->scalar('SELECT COALESCE(SUM(bytes),0) AS used_bytes FROM (' .
			$this->usage_sql(array((int) $user),$pm,$incoming) . ') usage_rows','used_bytes'));
	}

	public function pm_fits($members,$incoming,$extra)
	{
		$extra = max(0,(int) $extra);
		foreach (array_chunk(array_unique(array_map('intval',$members)),100) as $ids)
		{
			$limits = array_filter($this->allowances($ids,true), static function($limit) { return $limit !== '0'; });
			if (!$limits) { continue; }
			$needed = array_fill_keys(array_keys($limits),(string) $extra);
			$r = $this->db->sql_query("SELECT actor,SUM(bytes)+$extra AS needed FROM (" .
				$this->usage_sql(array_keys($limits),true,$incoming) . ') usage_rows GROUP BY actor');
			while ($row = $this->db->sql_fetchrow($r)) { $needed[$row['actor']] = self::integer($row['needed']); }
			$this->db->sql_freeresult($r);
			foreach ($limits as $member=>$limit) { if (self::larger($needed[$member],$limit)) { return false; } }
		}
		return true;
	}

	public function reserve($user, $pm, $physical, $amount)
	{
		if (!$this->enabled()) { return true; }
		$id = (int) $user;
		$table = $this->prefix . 'pending';
		$kind = (int) $pm;
		$now = time();
		$this->db->sql_transaction('begin');
		try
		{
			// Native orphan insertion follows the upload service call. A short reservation
			// closes this gap without holding a transaction while phpBB handles the file.
			$this->lock_users(array($id));
			$limit = $this->allowance($id,$pm);
			$allowed = true;
			if ($limit !== '0')
			{
				$sum = $this->usage_sql(array($id),$pm,false) . " UNION ALL SELECT p.user_id AS actor,p.bytes FROM $table p WHERE p.user_id=$id AND p.in_message=$kind
					AND p.expires>$now AND NOT EXISTS (SELECT 1 FROM " . ATTACHMENTS_TABLE . " a WHERE a.poster_id=$id AND a.physical_filename=p.physical)";
				$needed = $this->scalar('SELECT COALESCE(SUM(bytes),0)+' . (int) $amount . ' AS needed FROM (' . $sum . ') usage_rows','needed');
				$allowed = !self::larger(self::integer($needed),$limit);
				if ($allowed)
				{
					$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT',
						array('physical'=>$physical,'user_id'=>$id,'in_message'=>$kind,'bytes'=>(int) $amount,'expires'=>$now+3600)));
				}
			}
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('Attachment quota reservation did not commit'); }
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
		return $allowed;
	}

	public function lock_users($ids)
	{
		if (!$ids) { return; }
		$r = $this->db->sql_query('SELECT user_id FROM ' . USERS_TABLE . ' WHERE ' . $this->db->sql_in_set('user_id',array_map('intval',$ids)) .
			' ORDER BY user_id FOR UPDATE');
		$this->db->sql_freeresult($r);
	}

	public function recorded($attach_id,$owner)
	{
		if (!$this->enabled() || !$attach_id) { return; }
		$this->db->sql_query('DELETE FROM ' . $this->prefix . 'pending WHERE user_id=' . (int) $owner . ' AND physical IN
			(SELECT physical_filename FROM ' . ATTACHMENTS_TABLE . ' WHERE attach_id=' . (int) $attach_id . ' AND poster_id=' . (int) $owner . ')');
	}

	public function cleanup($delete)
	{
		$now = time();
		$table = $this->prefix . 'pending';
		$r = $this->db->sql_query_limit("SELECT physical FROM $table WHERE expires<=$now ORDER BY expires",100);
		$names = array_column($this->db->sql_fetchrowset($r),'physical');
		$this->db->sql_freeresult($r);
		foreach ($names as $name)
		{
			// Native deletion also checks that no attachment row still owns this file.
			$delete->unlink_attachment($name,'thumbnail',true);
			$delete->unlink_attachment($name,'file',true);
		}
		if ($names) { $this->db->sql_query("DELETE FROM $table WHERE expires<=$now AND " . $this->db->sql_in_set('physical',$names)); }
	}

	public function removed_members($ids)
	{
		$ids = array_diff(array_map('intval',$ids),array(0,ANONYMOUS));
		if (!$ids) { return; }
		foreach (array(array('quotas','user_id'),array('payers','sender'),array('payers','recipient'),array('pending','user_id')) as $columns)
		{
			list($table,$column) = $columns;
			$this->db->sql_query('UPDATE ' . $this->prefix . $table . " SET $column=0 WHERE " . $this->db->sql_in_set($column,$ids));
		}
	}

	public function removed_group($id)
	{
		$this->db->sql_query('UPDATE ' . $this->prefix . 'quotas SET group_id=0 WHERE group_id=' . (int) $id);
	}
}
