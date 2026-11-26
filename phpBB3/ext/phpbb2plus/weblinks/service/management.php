<?php
namespace phpbb2plus\weblinks\service;

/** Native ACP operations on directory records. The public submission and review policies stay independent. */
class management
{
	public const RIGHTS = array('a_plus_wl_create','a_plus_wl_edit','a_plus_wl_delete','a_plus_wl_categories','a_plus_wl_settings');
	private $db;
	private $directory;
	private $auth;
	private $user;
	private $categories;
	private $entries;

	public function __construct($db, $directory, $auth, $user, $prefix)
	{
		$this->db = $db;
		$this->directory = $directory;
		$this->auth = $auth;
		$this->user = $user;
		$this->categories = $prefix . 'categories';
		$this->entries = $prefix . 'entries';
	}

	private function rows($sql, $limit = 0, $start = 0)
	{
		$result = $limit ? $this->db->sql_query_limit($sql,$limit,$start) : $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function entries($query, $offset)
	{
		$where = '';
		if ($query !== '')
		{
			$query = strtr(directory::encode(utf8_substr($query,0,100)),array('!'=>'!!','%'=>'!%','_'=>'!_'));
			$like = "LIKE '%" . $this->db->sql_escape($query) . "%' ESCAPE '!'";
			$where = " WHERE l.title $like OR l.description $like";
		}
		$total = (int) $this->rows("SELECT COUNT(*) AS n FROM $this->entries l" . $where)[0]['n'];
		$start = intdiv(min(max(0,$offset),max(0,$total-1)),10)*10;
		$rows = $this->rows("SELECT l.*,c.title AS category,u.username FROM $this->entries l
			LEFT JOIN $this->categories c ON c.cat_id=l.cat_id LEFT JOIN " . USERS_TABLE . " u ON u.user_id=l.user_id
			$where ORDER BY l.link_id DESC",10,$start);
		return array($rows,$total,$start);
	}

	public function entry($id, $lock = false)
	{
		$rows = $this->rows("SELECT * FROM $this->entries WHERE link_id=" . (int) $id . ($lock ? ' FOR UPDATE' : ''));
		if (!$rows) { throw new \DomainException('WL_CHANGED'); }
		return $rows[0];
	}

	public function save_entry($id, $revision, $input, $status)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$actor = $this->directory->acp_actor($id ? 'a_plus_wl_edit' : 'a_plus_wl_create');
			$category = (int) $input['cat_id'];
			if (!$this->rows("SELECT cat_id FROM $this->categories WHERE cat_id=$category LOCK IN SHARE MODE"))
			{ throw new \DomainException('WL_BAD_CATEGORY'); }
			$old = $id ? $this->entry($id,true) : array();
			if ($id && (int) $old['revision'] !== $revision) { throw new \DomainException('WL_CHANGED'); }
			$data = directory::entry_fields($input,$old) + array('cat_id'=>$category);
			if ($id)
			{
				$original = (int) $old['status'];
				$status = $status === null ? $original : $status;
				if ($original !== $status && !$this->auth->acl_get('a_plus_wl_review')) { throw new \DomainException('NOT_AUTHORISED'); }
				if ($old['logo'] !== $data['logo']) { $data['local_logo'] = ''; }
			}
			if (!in_array($status,$id ? array(0,1,2) : array(0,1),true)) { throw new \DomainException('FORM_INVALID'); }
			if ($status === 1 && (!$old || (int) $old['status'] !== 1 || $old['destination'] !== $data['destination']) &&
				!directory::external(directory::plain($data['destination']))) { throw new \DomainException('WL_INVALID'); }
			$data['status'] = $status;
			if ($id)
			{
				$data['revision'] = $revision+1;
				$this->db->sql_query("UPDATE $this->entries SET " . $this->db->sql_build_array('UPDATE',$data) . ' WHERE link_id=' . (int) $id);
			}
			else
			{
				$data += array('joined'=>time(),'user_id'=>(int) $actor['user_id'],
					'submitter_ip'=>filter_var($this->user->ip,FILTER_VALIDATE_IP) ? $this->user->ip : '');
				$this->db->sql_query("INSERT INTO $this->entries " . $this->db->sql_build_array('INSERT',$data));
				$id = (int) $this->db->sql_nextid();
			}
			$this->db->sql_transaction('commit');
			return $id;
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}

	public function delete_entry($id, $revision)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$this->directory->acp_actor('a_plus_wl_delete');
			$row = $this->entry($id,true);
			if ((int) $row['revision'] !== $revision) { throw new \DomainException('WL_CHANGED'); }
			$this->db->sql_query("DELETE FROM $this->entries WHERE link_id=" . (int) $id);
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}

	public function categories($lock = false)
	{
		return $this->rows("SELECT cat_id,title,position FROM $this->categories ORDER BY position,cat_id" . ($lock ? ' FOR UPDATE' : ''));
	}

	public function membership($category, $lock = false)
	{
		// Stream large categories. A visit only changes counters and must not invalidate a confirmation.
		$result = $this->db->sql_query("SELECT link_id,revision FROM $this->entries WHERE cat_id=" . (int) $category .
			' ORDER BY link_id' . ($lock ? ' FOR UPDATE' : ''));
		$hash = hash_init('sha256');
		$count = 0;
		while ($row = $this->db->sql_fetchrow($result))
		{
			hash_update($hash,$row['link_id'] . ':' . $row['revision'] . "\n");
			$count++;
		}
		$this->db->sql_freeresult($result);
		return array($count,hash_final($hash));
	}

	public static function category_version($rows) { return hash('sha256',serialize($rows)); }

	public function save_category($action, $id, $name, $target, $version, $members)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$this->directory->acp_actor('a_plus_wl_categories');
			// Serialize structural edits even when the last category has already been removed.
			if (!$this->rows('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='plus_wl_category_serial' FOR UPDATE"))
			{ throw new \DomainException('WL_CHANGED'); }
			$rows = $this->categories(true);
			$ids = array_map('intval',array_column($rows,'cat_id'));
			$index = array_search($id,$ids,true);
			if (!hash_equals(self::category_version($rows),$version) || ($action !== 'create' && $index === false))
			{ throw new \DomainException('WL_CHANGED'); }
			if ($action === 'create' || $action === 'rename')
			{
				$name = trim($name);
				if ($name === '' || utf8_strlen($name)>100 || preg_match('/[\x00-\x1f\x7f]/',$name)) { throw new \DomainException('WL_ADMIN_BAD_NAME'); }
				$data = array('title'=>directory::encode($name));
				if ($action === 'create')
				{
					$this->db->sql_query("INSERT INTO $this->categories " . $this->db->sql_build_array('INSERT',$data));
					$ids[] = (int) $this->db->sql_nextid();
				}
				else { $this->db->sql_query("UPDATE $this->categories SET " . $this->db->sql_build_array('UPDATE',$data) . " WHERE cat_id=$id"); }
			}
			elseif ($action === 'up' || $action === 'down')
			{
				$other = $index + ($action === 'up' ? -1 : 1);
				if (isset($ids[$other])) { $ids[$index] = $ids[$other]; $ids[$other] = $id; }
			}
			elseif ($action === 'remove')
			{
				if ($target === $id || ($target !== 0 && !in_array($target,$ids,true))) { throw new \DomainException('WL_BAD_CATEGORY'); }
				list(,$current) = $this->membership($id,true);
				if (!hash_equals($current,$members)) { throw new \DomainException('WL_CHANGED'); }
				if ($target === 0) { $this->db->sql_query("DELETE FROM $this->entries WHERE cat_id=$id"); }
				else { $this->db->sql_query("UPDATE $this->entries SET cat_id=$target,revision=revision+1 WHERE cat_id=$id"); }
				$this->db->sql_query("DELETE FROM $this->categories WHERE cat_id=$id");
				array_splice($ids,$index,1);
			}
			else { throw new \DomainException('FORM_INVALID'); }
			if ($action !== 'rename')
			{
				$old = array_column($rows,'position','cat_id');
				foreach ($ids as $n=>$key)
				{
					$position = ($n+1)*10;
					if ((int) ($old[$key] ?? -1) !== $position)
					{ $this->db->sql_query("UPDATE $this->categories SET position=$position WHERE cat_id=$key"); }
				}
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}
}
