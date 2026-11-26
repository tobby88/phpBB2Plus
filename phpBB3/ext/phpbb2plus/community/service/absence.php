<?php
namespace phpbb2plus\community\service;

class absence
{
	private $db;
	private $config;
	private $auth;
	private $user;
	private $notes;

	public function __construct($db,$config,$auth,$user,$notes)
	{
		$this->db=$db; $this->config=$config; $this->auth=$auth; $this->user=$user; $this->notes=$notes;
	}

	private function first($sql)
	{
		$result=$this->db->sql_query($sql);
		$row=$this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	public function person($id,$lock=false)
	{
		if ((int)$id<=ANONYMOUS) { return false; }
		return $this->first('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . (int)$id . ($lock ? ' FOR UPDATE' : ''));
	}

	public function note($id)
	{
		$row=$this->first("SELECT note_text FROM $this->notes WHERE user_id=" . (int)$id);
		return $row ? (string)json_decode($row['note_text'],true) : '';
	}

	public static function valid_note($note)
	{
		return is_string($note) && strlen($note)<=65535 && preg_match('//u',$note)===1 && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$note);
	}

	public function store_note($id,$note)
	{
		$id=(int)$id;
		$this->db->sql_query("DELETE FROM $this->notes WHERE user_id=$id");
		if ($note!=='') { $this->db->sql_query("INSERT INTO $this->notes " .
			$this->db->sql_build_array('INSERT',array('user_id'=>$id,'note_text'=>json_encode($note)))); }
	}

	public static function account($row)
	{
		return $row && (int)$row['user_id']!==ANONYMOUS && in_array((int)$row['user_type'],array(USER_NORMAL,USER_FOUNDER),true);
	}

	public function member()
	{
		return self::account($this->user->data) && !empty($this->user->data['is_registered']) && empty($this->user->data['is_bot']) && empty($this->user->data['user_perm_from']);
	}

	private function administrator($row)
	{
		if ((int)$row['user_type']===USER_FOUNDER) { return true; }
		return (bool)$this->first('SELECT ug.group_id FROM ' . USER_GROUP_TABLE . ' ug JOIN ' . GROUPS_TABLE . ' g ON g.group_id=ug.group_id
			WHERE ug.user_pending=0 AND ug.user_id=' . (int)$row['user_id'] . ' AND g.group_type=' . GROUP_SPECIAL . " AND g.group_name='ADMINISTRATORS'");
	}

	private function moderator($row,$refresh=false)
	{
		$permissions=$this->auth;
		if ($refresh || (int)$row['user_id']!==(int)$this->user->data['user_id'])
		{
			$permissions=clone $this->auth;
			$row['is_registered']=true; $row['is_bot']=false;
			$permissions->acl($row);
		}
		// Individual Plus forum grants can set the general m_ flag on ordinary members.
		return $permissions->acl_get('u_plus_away_mod');
	}

	public function can_set($row,$members=null)
	{
		return self::account($row) && (($members===null ? (int)$this->config['plus_away_members'] : $members)===1 ||
			$this->administrator($row) || $this->moderator($row));
	}

	public function may_contact($refresh=false)
	{
		if (!$this->member()) { return false; }
		$actor=$this->user->data;
		$mods=(int)$this->config['plus_away_mod_contact'];
		if ($refresh)
		{
			$id=(int)$actor['user_id']; $sid=$this->db->sql_escape($this->user->session_id);
			$actor=$this->first('SELECT u.* FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE .
				" s ON s.session_user_id=u.user_id WHERE u.user_id=$id AND s.session_id='$sid' AND s.session_time>=" .
				(time()-(int)$this->config['session_length']) . ' LOCK IN SHARE MODE');
			if (!self::account($actor) || $actor['user_perm_from']) { return false; }
			$row=$this->first('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='plus_away_mod_contact' LOCK IN SHARE MODE");
			$mods=(int)($row['config_value'] ?? 0);
		}
		return $this->administrator($actor) || ($mods===1 && $this->moderator($actor,$refresh));
	}

	public static function label($mode)
	{
		return array(1=>'PLUS_AWAY_HOLIDAY',2=>'PLUS_AWAY_ILL',3=>'PLUS_AWAY_LONG')[(int)$mode] ?? 'PLUS_AWAY';
	}

	public function write($target_id,$revision,$values,$admin)
	{
		$actor_id=(int)$this->user->data['user_id'];
		$target_id=(int)$target_id;
		if (!$this->member() || !empty($this->user->data['user_perm_from']) || (!$admin && $target_id!==$actor_id))
		{ throw new \DomainException('NO_AUTH_OPERATION'); }
		$this->db->sql_transaction('begin');
		try
		{
			$sid=$this->db->sql_escape($this->user->session_id);
			// Lock accounts in stable order. A returning member and an administrator may have the same form open.
			$ids=array_unique(array($actor_id,$target_id)); sort($ids,SORT_NUMERIC);
			$people=array();
			foreach ($ids as $id) { $people[$id]=$this->person($id,true); }
			$target=$people[$target_id]; $actor=$people[$actor_id];
			$session=$this->first('SELECT session_admin FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid' AND session_user_id=$actor_id AND session_time>=" .
				(time()-(int)$this->config['session_length']) . ' LOCK IN SHARE MODE');
			if (!$session || !self::account($actor) || !self::account($target) || $actor['user_perm_from']) { throw new \DomainException('NO_AUTH_OPERATION'); }
			$actor['is_registered']=true; $actor['is_bot']=false;
			$this->auth->acl($actor);
			if ($admin && (empty($session['session_admin']) || !$this->auth->acl_get('a_user') ||
				((int)$target['user_type']===USER_FOUNDER && (int)$actor['user_type']!==USER_FOUNDER))) { throw new \DomainException('NO_AUTH_OPERATION'); }
			if (!hash_equals((string)$target['plus_away_revision'],$revision)) { throw new \DomainException('PLUS_AWAY_CHANGED'); }
			if ($values===null)
			{
				// Returning always remains possible, even if the board stopped accepting new absence notices.
				if (!(int)$target['plus_away']) { throw new \DomainException('PLUS_AWAY_CHANGED'); }
				$fields=array('plus_away'=>0);
			}
			else
			{
				$policy=$this->first('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='plus_away_members' LOCK IN SHARE MODE");
				if (!$this->can_set($target,(int)($policy['config_value'] ?? 0))) { throw new \DomainException('NO_AUTH_OPERATION'); }
				if (!in_array($values['active'],array('0','1'),true) || (!in_array($values['kind'],array('0','1','2','3'),true) &&
					$values['kind']!==(string)$target['plus_away_mode']) || !self::valid_note($values['note']))
				{ throw new \DomainException('PLUS_AWAY_INVALID'); }
				$fields=array('plus_away'=>(int)$values['active'],'plus_away_mode'=>(int)$values['kind']);
				$this->store_note($target_id,$values['note']);
			}
			$fields['plus_away_revision']=bin2hex(random_bytes(16));
			$this->db->sql_query('UPDATE ' . USERS_TABLE . ' SET ' . $this->db->sql_build_array('UPDATE',$fields) . " WHERE user_id=$target_id");
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}

	public function delete_accounts($ids)
	{
		if ($ids) { $this->db->sql_query("DELETE FROM $this->notes WHERE " . $this->db->sql_in_set('user_id',$ids) .
			' AND NOT EXISTS (SELECT 1 FROM ' . USERS_TABLE . " u WHERE u.user_id=$this->notes.user_id)"); }
	}
}
