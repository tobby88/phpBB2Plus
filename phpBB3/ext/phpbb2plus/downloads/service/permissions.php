<?php
namespace phpbb2plus\downloads\service;

class permissions
{
	const PAGE_SIZE = 25;
	private $db;
	private $access;
	private $catalogue;
	private $user;
	private $log;
	private $prefix;

	public function __construct($db, $access, $catalogue, $user, $log, $prefix)
	{
		$this->db = $db;
		$this->access = $access;
		$this->catalogue = $catalogue;
		$this->user = $user;
		$this->log = $log;
		$this->prefix = $prefix;
	}

	public function categories()
	{
		$tree = $this->catalogue->tree();
		$visible = array();
		// Legacy permission delegates managed categories they could see. Do not reveal
		// a descendant through a parent hidden from the delegate.
		foreach ($tree['list'] as $id=>$depth)
		{
			$row = $tree['rows'][$id];
			$parent = (int) $row['parent_id'];
			if ($depth >= 0 && (!$parent || isset($visible[$parent])) && $this->access->allows($id,'view'))
			{ $visible[$id] = $row + array('depth'=>$depth); }
		}
		return $visible;
	}

	private function rows($sql, $limit = 0, $offset = 0)
	{
		$result = $limit ? $this->db->sql_query_limit($sql,$limit,$offset) : $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	private function founder() { return (int) $this->user->data['user_type'] === USER_FOUNDER; }

	public function groups()
	{
		return $this->rows('SELECT group_id AS id,group_name AS name,group_type FROM ' . GROUPS_TABLE .
			($this->founder() ? '' : ' WHERE group_founder_manage=0') . ' ORDER BY group_name,group_id');
	}

	public function find_user($name)
	{
		$rows = $this->rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='" .
			$this->db->sql_escape(utf8_clean_string($name)) . "'");
		return $rows ? (int) $rows[0]['user_id'] : 0;
	}

	private function subject($kind, $id, $lock)
	{
		if ($kind === 'u' && $id > ANONYMOUS)
		{
			$sql = 'SELECT user_id AS id,username AS name,user_type FROM ' . USERS_TABLE . ' WHERE user_id=' . $id .
				' AND ' . $this->db->sql_in_set('user_type',$this->founder() ? array(USER_NORMAL,USER_INACTIVE,USER_FOUNDER) : array(USER_NORMAL,USER_INACTIVE));
		}
		elseif ($kind === 'g' && $id > 0)
		{
			$sql = 'SELECT group_id AS id,group_name AS name,group_type FROM ' . GROUPS_TABLE . ' WHERE group_id=' . $id .
				($this->founder() ? '' : ' AND group_founder_manage=0');
		}
		else { throw new \DomainException('ACP_PLUS_DL_SUBJECT_MISSING'); }
		$rows = $this->rows($sql . ($lock && in_array($this->db->get_sql_layer(),array('mysql','mysqli'),true) ? ' FOR UPDATE' : ''));
		if (!$rows) { throw new \DomainException('ACP_PLUS_DL_SUBJECT_MISSING'); }
		return $rows[0];
	}

	public function related($kind, $id, $start)
	{
		if ($kind === 'u')
		{
			$sql = 'SELECT g.group_id AS id,g.group_name AS name,g.group_type,g.group_founder_manage AS restricted,MIN(ug.user_pending) AS user_pending FROM ' .
				GROUPS_TABLE . ' g JOIN ' . USER_GROUP_TABLE . ' ug ON ug.group_id=g.group_id WHERE ug.user_id=' . (int) $id .
				' GROUP BY g.group_id,g.group_name,g.group_type,g.group_founder_manage ORDER BY g.group_name,g.group_id';
		}
		else
		{
			$sql = 'SELECT u.user_id AS id,u.username AS name,u.user_type,MIN(ug.user_pending) AS user_pending FROM ' . USERS_TABLE .
				' u JOIN ' . USER_GROUP_TABLE . ' ug ON ug.user_id=u.user_id WHERE ug.group_id=' . (int) $id . ' AND u.user_id<>' . ANONYMOUS .
				' AND u.user_type<>' . USER_IGNORE . ' GROUP BY u.user_id,u.username,u.user_type,u.username_clean ORDER BY u.username_clean,u.user_id';
		}
		$rows = $this->rows($sql,self::PAGE_SIZE+1,$start);
		$more = count($rows) > self::PAGE_SIZE;
		if ($more) { array_pop($rows); }
		foreach ($rows as &$row)
		{
			$row['link'] = $this->founder() || ($kind === 'u' ? empty($row['restricted']) : (int) $row['user_type'] !== USER_FOUNDER);
		}
		return array($rows,$more);
	}

	public function review($category, $kind, $id, $lock = false)
	{
		$category = (int) $category;
		$id = (int) $id;
		if (!$this->access->permissions_admin()) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$categories = $this->categories();
		if ($category < 0 || ($category && !isset($categories[$category]))) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$actions = $category ? access::CATEGORY_ACTIONS : access::GLOBAL_ACTIONS;
		$levels = array();
		foreach ($actions as $action)
		{
			$level = $category ? ($categories[$category]['levels'][$action] ?? null) : $this->access->setting('auth_' . $action,null);
			$levels[$action] = access::level($level);
		}
		$rows = $direct = $inherited = $target = array();
		$moderated = false;
		if ($kind === '')
		{
			if (!$category || $id) { throw new \DomainException('FORM_INVALID'); }
			if (!is_array(json_decode($categories[$category]['access_levels'],true))) { throw new \DomainException('ACP_PLUS_DL_INVALID_GRANTS'); }
		}
		else
		{
			$target = $this->subject($kind,$id,$lock);
			$where = "subject_type='$kind' AND subject_id=$id";
			if ($kind === 'u') { $where = "($where) OR (subject_type='g' AND subject_id IN (" . $this->access->active_groups_sql($id) . '))'; }
			$grants = $this->rows('SELECT row_id,subject_type,cat_id,legacy_group,allowed FROM ' . $this->prefix .
				"grants WHERE ($where)" . ($category ? " AND cat_id=$category" : '') . ' ORDER BY row_id');
			foreach ($grants as $row)
			{
				$flags = access::flags($row['allowed']);
				if ($flags === null) { throw new \DomainException('ACP_PLUS_DL_INVALID_GRANTS'); }
				$moderated = $moderated || in_array('mod',$flags,true);
				if ((int) $row['cat_id'] !== $category) { continue; }
				if ($row['subject_type'] === $kind)
				{
					$rows[] = $row;
					$direct += array_fill_keys($flags,true);
				}
				else { $inherited += array_fill_keys($flags,true); }
			}
		}
		$editable = $kind === '' ? $actions : array_keys(array_filter($levels,static function ($value) { return $value === 2; }));
		if ($kind !== '' && $category) { $editable[] = 'mod'; }
		$location = $category ? array($categories[$category]['title'],$categories[$category]['parent_id']) : array();
		$state = hash('sha256',serialize(array($category,$kind,$id,$location,$levels,$target,$rows,$inherited,$moderated)));
		return array('levels'=>$levels,'target'=>$target,'name'=>$category ? $categories[$category]['title'] : '',
			'rows'=>$rows,'direct'=>$direct,'inherited'=>$inherited,'moderated'=>$moderated,'editable'=>$editable,'state'=>$state);
	}

	public function save($category, $kind, $id, $state, $values)
	{
		$category = (int) $category;
		$id = (int) $id;
		$this->db->sql_transaction('begin');
		try
		{
			$this->access->lock_catalogue(true);
			if (!$this->access->permissions_admin(true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
			$this->access->refresh();
			$view = $this->review($category,$kind,$id,true);
			if (!hash_equals($view['state'],$state)) { throw new \DomainException('ACP_PLUS_DL_PERMS_CHANGED'); }
			$fields = array_fill_keys($view['editable'],true);
			if (array_diff_key($fields,$values) || array_diff_key($values,$fields)) { throw new \DomainException('FORM_INVALID'); }
			$updates = array();
			foreach ($values as $action=>$value)
			{
				$current = $kind === '' ? $view['levels'][$action] : (int) isset($view['direct'][$action]);
				if ($kind === '' && $current === null && $value === 'keep') { continue; }
				$options = $kind === '' ? access::LEVELS : array(0,1);
				if (!in_array($value,array_map('strval',$options),true)) { throw new \DomainException('FORM_INVALID'); }
				if ((int) $value !== $current) { $updates[$action] = (int) $value; }
			}
			if ($updates && $kind === '')
			{
				$levels = $this->access->categories()[$category]['levels'];
				$this->db->sql_query('UPDATE ' . $this->prefix . 'categories SET ' . $this->db->sql_build_array('UPDATE',
					array('access_levels'=>json_encode(array_replace($levels,$updates)))) . " WHERE cat_id=$category");
			}
			elseif ($updates) { $this->write_grants($category,$kind,$id,$view['rows'],$updates); }
			if ($updates)
			{
				$changes = array();
				foreach ($updates as $field=>$value) { $changes[] = $field . '=' . $value; }
				$type = $kind === '' ? 'LEVELS' : ($kind === 'u' ? 'USER' : 'GROUP');
				$this->log->add('admin',$this->user->data['user_id'],$this->user->ip,'LOG_PLUS_DL_PERMS_' . $type,false,
					array($category,implode(', ',$changes),$id));
			}
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('The download permission update did not commit.'); }
		}
		catch (\Throwable $e)
		{
			if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); }
			throw $e;
		}
		finally { $this->access->refresh(); }
	}

	private function write_grants($category, $kind, $id, $rows, $updates)
	{
		$table = $this->prefix . 'grants';
		$add = array_keys(array_filter($updates));
		$remove = array_keys(array_filter($updates,static function ($value) { return !$value; }));
		foreach ($rows as $row)
		{
			// Imported duplicates combine with OR. Remove a revoked flag from every
			// matching row, and attach new flags only once. Retain the original provenance.
			$old = access::flags($row['allowed']);
			$flags = array_merge(array_values(array_diff($old,$remove)),$add);
			$add = array();
			if ($flags === $old) { continue; }
			$where = ' WHERE row_id=' . (int) $row['row_id'];
			if (!$flags && !$row['legacy_group']) { $this->db->sql_query("DELETE FROM $table" . $where); }
			else { $this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE',array('allowed'=>json_encode($flags))) . $where); }
		}
		if ($add)
		{
			$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT',array('cat_id'=>$category,'subject_type'=>$kind,
				'subject_id'=>$id,'legacy_group'=>0,'allowed'=>json_encode($add),'source_json'=>'{}')));
		}
	}
}
