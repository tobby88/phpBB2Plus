<?php
namespace phpbb2plus\colours\service;

class administration
{
	private $db;
	private $palette;
	private $table;

	public function __construct($db,$palette,$table)
	{
		$this->db = $db;
		$this->palette = $palette;
		$this->table = $table;
	}

	private function one($sql)
	{
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	public function entries()
	{
		$result = $this->db->sql_query("SELECT * FROM $this->table ORDER BY position,colour_id");
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function entry($id)
	{
		return $this->one("SELECT * FROM $this->table WHERE colour_id=" . (int) $id);
	}

	public function revision($item)
	{
		return hash('sha256',serialize(array((int) $item['colour_id'],$item['name'],$item['colour'],(int) $item['position'],(int) $item['in_legend'])));
	}

	private function lock_catalogue()
	{
		// A category may be removed while another form assigns it to an account or group.
		$this->one("SELECT config_name FROM " . CONFIG_TABLE . " WHERE config_name='plus_colours_edit_lock' FOR UPDATE");
	}

	public function save($id,$revision,array $values,$remove)
	{
		$id = (int) $id;
		$this->db->sql_transaction('begin');
		try
		{
			$this->lock_catalogue();
			$current = $id ? $this->entry($id) : null;
			if ($id && (!$current || !hash_equals($this->revision($current),$revision)))
			{
				throw new \DomainException('PLUS_COLOURS_CONFLICT');
			}
			if ($remove)
			{
				$this->db->sql_query("DELETE FROM $this->table WHERE colour_id=$id");
				// Resolve the next priority while the assignment still identifies affected accounts.
				$this->palette->refresh_assigned($id,false);
				foreach (array(USERS_TABLE,GROUPS_TABLE) as $table)
				{
					$this->db->sql_query("UPDATE $table SET plus_colour_id=0 WHERE plus_colour_id=$id");
				}
			}
			else
			{
				$name = $this->db->sql_escape($values['name']);
				$duplicate = $this->one("SELECT * FROM $this->table WHERE LOWER(name)=LOWER('$name') AND colour_id<>$id");
				if ($duplicate)
				{
					$same = !$id && $this->revision($duplicate) === $this->revision(array('colour_id'=>$duplicate['colour_id']) + $values);
					if (!$same) { throw new \DomainException('PLUS_COLOURS_DUPLICATE'); }
					$this->db->sql_transaction('commit');
					return false;
				}
				if ($id)
				{
					$this->db->sql_query("UPDATE $this->table SET " . $this->db->sql_build_array('UPDATE',$values) . " WHERE colour_id=$id");
					$this->palette->refresh_assigned($id,false);
				}
				else { $this->db->sql_query("INSERT INTO $this->table " . $this->db->sql_build_array('INSERT',$values)); }
			}
			$this->db->sql_transaction('commit');
			return true;
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}

	public function member_id($name)
	{
		$row = $this->one('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='" . $this->db->sql_escape(utf8_clean_string($name)) . "'");
		return $row ? (int) $row['user_id'] : 0;
	}

	public function owner($group,$id,$lock=false)
	{
		$select = $group ? 'group_id AS owner_id,group_name AS name,group_founder_manage AS protected_owner' :
			'user_id AS owner_id,username AS name,user_type';
		$from = $group ? GROUPS_TABLE . ' WHERE group_id=' : USERS_TABLE . ' WHERE user_id=';
		return $this->one("SELECT plus_colour_id,$select FROM $from" . (int) $id . ($lock ? ' FOR UPDATE' : ''));
	}

	private function manageable($row,$group,$founder,$edit_users)
	{
		if (!$row) { return false; }
		if ($group) { return !$row['protected_owner'] || $founder; }
		return $edit_users && (int) $row['owner_id'] !== ANONYMOUS && (int) $row['user_type'] !== USER_IGNORE
			&& ((int) $row['user_type'] !== USER_FOUNDER || $founder);
	}

	public function require_owner($group,$id,$founder,$edit_users,$lock=false)
	{
		$row = $this->owner($group,$id,$lock);
		if (!$this->manageable($row,$group,$founder,$edit_users)) { throw new \DomainException('NO_AUTH_OPERATION'); }
		return $row;
	}

	public function assign($group,$id,$founder,$edit_users,$was,$new)
	{
		$id = (int) $id;
		$new = (int) $new;
		$this->db->sql_transaction('begin');
		try
		{
			$this->lock_catalogue();
			$row = $this->require_owner($group,$id,$founder,$edit_users,true);
			$current = (int) $row['plus_colour_id'];
			if ($new < 0 || ($new && !$this->entry($new))) { throw new \DomainException('PLUS_COLOURS_UNKNOWN'); }
			if ($new !== $current)
			{
				if ($current !== $was) { throw new \DomainException('PLUS_COLOURS_CONFLICT'); }
				$table = $group ? GROUPS_TABLE : USERS_TABLE;
				$key = $group ? 'group_id' : 'user_id';
				$this->db->sql_query("UPDATE $table SET plus_colour_id=$new WHERE $key=$id");
				if ($group) { $this->palette->refresh_assigned($id,true); }
				else { $this->palette->refresh(array($id)); }
			}
			$this->db->sql_transaction('commit');
			return $new !== $current;
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}
}
