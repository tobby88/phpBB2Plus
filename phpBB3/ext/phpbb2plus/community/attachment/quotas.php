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
		$kind = $pm ? 2 : 1;
		if (!$this->enabled()) { return '0'; }
		$type = $this->scalar('SELECT user_type FROM ' . USERS_TABLE . ' WHERE user_id=' . $id,'user_type');
		if ($type !== false && (int) $type === USER_FOUNDER) { return '0'; }
		$membership = ' FROM ' . USER_GROUP_TABLE . ' ug JOIN ' . GROUPS_TABLE . " g ON g.group_id=ug.group_id WHERE ug.user_id=$id AND ug.user_pending=0";
		if ($this->scalar('SELECT g.group_id' . $membership . " AND g.group_type=" . GROUP_SPECIAL . " AND g.group_name='ADMINISTRATORS'",'group_id') !== false) { return '0'; }

		$limits = $this->prefix . 'limits';
		$sql = 'SELECT l.bytes FROM ' . $this->prefix . "quotas q JOIN $limits l ON l.limit_id=q.limit_id";
		$value = $this->scalar($sql . " WHERE q.user_id=$id AND q.user_id<>0 AND q.kind=$kind ORDER BY q.assignment_id",'bytes');
		if ($value === false)
		{
			$value = $this->scalar($sql . ' JOIN ' . USER_GROUP_TABLE . " ug ON ug.group_id=q.group_id WHERE q.group_id<>0 AND ug.user_id=$id
				AND ug.user_pending=0 AND q.kind=$kind ORDER BY l.bytes DESC,q.assignment_id",'bytes');
		}
		$global = self::integer($this->config[$pm ? 'max_filesize_pm' : 'attachment_quota']);
		if ($value === false)
		{
			$default = (int) $this->config[$pm ? 'plus_attach_default_pm' : 'plus_attach_default_post'];
			$value = $default ? $this->scalar("SELECT bytes FROM $limits WHERE limit_id=$default",'bytes') : false;
		}
		$limit = $value === false ? $global : self::integer($value);
		// The board-wide disk limit remains independently enforced by the native uploader.
		if (!$pm && $global !== '0' && $limit !== '0' && self::larger($limit,$global)) { return $global; }
		return $limit;
	}

	private function usage_sql($id, $pm, $incoming)
	{
		$id = (int) $id;
		$message = (int) $pm;
		$a = ATTACHMENTS_TABLE;
		$r = $this->prefix . 'refs';
		$p = $this->prefix . 'payers';
		$to = PRIVMSGS_TO_TABLE;
		$payer = $incoming ? 'recipient' : 'sender';
		$archived = "SELECT MAX(a.filesize) AS bytes FROM $p h JOIN $r r ON r.attach_id=h.attach_id JOIN $a a ON a.attach_id=r.attach_id
			WHERE h.$payer=$id AND a.in_message=$message AND a.is_orphan=0";
		if ($pm)
		{
			// The old sent-box copy did not consume either correspondent's allowance.
			$archived .= ' AND h.pm_state>=0 AND h.pm_state<>2';
		}
		$archived .= ' GROUP BY r.source_id';

		$native = "SELECT MAX(a.filesize) AS bytes FROM $a a";
		if ($incoming) { $native .= " JOIN $to dest ON dest.msg_id=a.post_msg_id AND dest.user_id=$id AND dest.folder_id NOT IN (" . PRIVMSGS_SENTBOX . ',' . PRIVMSGS_OUTBOX . ')'; }
		$native .= " LEFT JOIN $r r ON r.attach_id=a.attach_id LEFT JOIN $p h ON h.attach_id=a.attach_id
			WHERE a.in_message=$message AND h.attach_id IS NULL AND (r.attach_id IS NULL OR r.parent_id<>0)";
		if (!$incoming) { $native .= " AND a.poster_id=$id"; }
		if ($incoming) { $native .= ' AND a.is_orphan=0'; }
		elseif ($pm)
		{
			$native .= " AND (a.is_orphan=1 OR EXISTS (SELECT 1 FROM $to dest WHERE dest.msg_id=a.post_msg_id AND dest.folder_id<>" . PRIVMSGS_SENTBOX . '))';
		}
		// Imported shared files and native topic copies are billed once per owner and direction.
		$native .= " GROUP BY r.source_id,CASE WHEN r.source_id IS NULL THEN a.physical_filename ELSE '' END";
		return $archived . ' UNION ALL ' . $native;
	}

	public function occupied($user, $pm, $incoming = false)
	{
		return self::integer($this->scalar('SELECT COALESCE(SUM(bytes),0) AS used_bytes FROM (' .
			$this->usage_sql($user,$pm,$incoming) . ') usage_rows','used_bytes'));
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
				$sum = $this->usage_sql($id,$pm,false) . " UNION ALL SELECT p.bytes FROM $table p WHERE p.user_id=$id AND p.in_message=$kind
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
