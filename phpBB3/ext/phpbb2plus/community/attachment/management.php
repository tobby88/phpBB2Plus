<?php
namespace phpbb2plus\community\attachment;

/** Administration of legacy storage levels attached to native accounts and groups. */
class management
{
	private $db;
	private $config;
	private $cache;
	private $prefix;

	public function __construct($db,$config,$cache,$prefix)
	{
		$this->db = $db;
		$this->config = $config;
		$this->cache = $cache;
		$this->prefix = $prefix;
	}

	public static function bytes($value)
	{
		// Keep the unsigned BIGINT range across PHP integer sizes and HTML round trips.
		if (!preg_match('/^[0-9]{1,20}$/D',$value)) { throw new \DomainException('PLUS_QUOTA_INVALID'); }
		$value = ltrim($value,'0');
		if (strlen($value) === 20 && strcmp($value,'18446744073709551615') > 0) { throw new \DomainException('PLUS_QUOTA_INVALID'); }
		return $value === '' ? '0' : $value;
	}

	private function rows($sql)
	{
		$r = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($r);
		$this->db->sql_freeresult($r);
		return $rows;
	}

	public function settings($lock = false)
	{
		$names = array('plus_attach_quotas','plus_attach_base_pm','plus_attach_default_post','plus_attach_default_pm','plus_attach_revision','plus_attach_last_level');
		return array_column($this->rows('SELECT config_name,config_value FROM ' . CONFIG_TABLE . ' WHERE ' .
			$this->db->sql_in_set('config_name',$names) . ' ORDER BY config_name' . ($lock ? ' FOR UPDATE' : '')),'config_value','config_name');
	}

	public function levels()
	{
		$levels = array();
		foreach ($this->rows('SELECT limit_id,label,bytes FROM ' . $this->prefix . 'limits ORDER BY label,limit_id') as $row)
		{
			$row['bytes'] = explode('.',(string) $row['bytes'],2)[0];
			$levels[(int) $row['limit_id']] = $row;
		}
		return $levels;
	}

	public function find_member($name)
	{
		$rows = $this->rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='" .
			$this->db->sql_escape(utf8_clean_string($name)) . "' AND (user_type<>" . USER_IGNORE . ' OR user_id=' . ANONYMOUS . ')');
		if (!$rows) { throw new \DomainException('NO_USER'); }
		return (int) $rows[0]['user_id'];
	}

	public function groups()
	{
		return $this->rows('SELECT group_id,group_name,group_type FROM ' . GROUPS_TABLE . ' ORDER BY group_type,group_name');
	}

	public function principal($type,$id,$lock = false)
	{
		if ($type === 'member') { $sql = 'SELECT username AS name FROM ' . USERS_TABLE . ' WHERE (user_type<>' . USER_IGNORE . ' OR user_id=' . ANONYMOUS . ') AND user_id='; }
		elseif ($type === 'group') { $sql = 'SELECT group_name AS name,group_type FROM ' . GROUPS_TABLE . ' WHERE group_id='; }
		else { throw new \DomainException('PLUS_QUOTA_INVALID'); }
		$rows = $this->rows($sql . (int) $id . ($lock ? ' FOR UPDATE' : ''));
		if (!$rows) { throw new \DomainException($type === 'member' ? 'NO_USER' : 'NO_GROUP'); }
		return $rows[0];
	}

	public function assignments($type,$id)
	{
		$column = $type === 'member' ? 'user_id' : 'group_id';
		$sort = $type === 'member' ? '' : 'l.bytes DESC,';
		$rows = $this->rows('SELECT q.kind,q.limit_id FROM ' . $this->prefix . 'quotas q JOIN ' . $this->prefix .
			"limits l ON l.limit_id=q.limit_id WHERE q.$column=" . (int) $id . " AND q.kind IN (1,2) ORDER BY $sort q.assignment_id");
		$chosen = array();
		foreach ($rows as $row)
		{
			if (!isset($chosen[$row['kind']])) { $chosen[$row['kind']] = (int) $row['limit_id']; }
		}
		return $chosen + array(1=>0,2=>0);
	}

	private function assign($type,$id,$values)
	{
		$this->principal($type,$id,true);
		$before = $this->assignments($type,$id);
		$member = $type === 'member';
		$column = $member ? 'user_id' : 'group_id';
		$other = $member ? 'group_id' : 'user_id';
		$table = $this->prefix . 'quotas';
		foreach ($values as $kind=>$level)
		{
			if ($level === $before[$kind]) { continue; }
			$where = "$column=$id AND kind=$kind";
			// Historical rows can identify both a user and a group. Preserve the other assignment.
			$this->db->sql_query("UPDATE $table SET $column=0 WHERE $where AND $other<>0");
			$this->db->sql_query("DELETE FROM $table WHERE $where AND $other=0");
			if ($level)
			{
				$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT',array($column=>$id,'kind'=>$kind,'limit_id'=>$level)));
			}
		}
	}

	private function next_id($old,$levels)
	{
		// Broken legacy references must never turn into grants when a new level is created.
		$refs = $this->rows('SELECT MAX(limit_id) AS last_id FROM ' . $this->prefix . 'quotas');
		$last = max((int) $refs[0]['last_id'],(int) $old['plus_attach_default_post'],(int) $old['plus_attach_default_pm'],
			(int) $old['plus_attach_last_level'],$levels ? max(array_keys($levels)) : 0);
		if ($last >= 16777215) { throw new \DomainException('PLUS_QUOTA_NO_IDS'); }
		$this->config->set('plus_attach_last_level',(string) ($last+1),false);
		return $last+1;
	}

	public function save($section,$id,$revision,$values,$remove = false)
	{
		$id = (int) $id;
		$this->db->sql_transaction('begin');
		try
		{
			$old = $this->settings(true);
			if (!hash_equals($old['plus_attach_revision'],$revision)) { throw new \DomainException('PLUS_QUOTA_STALE'); }
			$levels = $this->levels();
			$table = $this->prefix . 'limits';
			if ($section === 'level')
			{
				if ($id && !isset($levels[$id])) { throw new \DomainException('PLUS_QUOTA_STALE'); }
				if ($remove && $id)
				{
					foreach (array('plus_attach_default_post','plus_attach_default_pm') as $key)
					{
						if ((int) $old[$key] === $id) { $this->config->set($key,'0'); }
					}
					$this->db->sql_query('DELETE FROM ' . $this->prefix . "quotas WHERE limit_id=$id");
					$this->db->sql_query("DELETE FROM $table WHERE limit_id=$id");
					if ($id > (int) $old['plus_attach_last_level']) { $this->config->set('plus_attach_last_level',(string) $id,false); }
				}
				elseif ($id) { $this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE',$values) . " WHERE limit_id=$id"); }
				else
				{
					$values['limit_id'] = $this->next_id($old,$levels);
					$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT',$values));
				}
			}
			else
			{
				foreach (array(1,2) as $kind)
				{
					$previous = $section === 'settings' ? (int) $old[$kind === 1 ? 'plus_attach_default_post' : 'plus_attach_default_pm'] : 0;
					if ($values[$kind] && !isset($levels[$values[$kind]]) && $values[$kind] !== $previous) { throw new \DomainException('PLUS_QUOTA_INVALID'); }
				}
				if ($section === 'settings')
				{
					foreach (array('plus_attach_quotas'=>'enabled','plus_attach_base_pm'=>'bytes','plus_attach_default_post'=>1,'plus_attach_default_pm'=>2) as $key=>$field)
					{ $this->config->set($key,(string) $values[$field]); }
				}
				else { $this->assign($section,$id,$values); }
			}
			// All forms include this generation. Replaying a create after deletion cannot recreate the level.
			$this->config->set('plus_attach_revision',bin2hex(random_bytes(16)),false);
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('Attachment quota settings did not commit'); }
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
		$this->cache->destroy('config');
	}
}
