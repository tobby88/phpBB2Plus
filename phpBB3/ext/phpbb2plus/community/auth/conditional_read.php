<?php
namespace phpbb2plus\community\auth;

/**
 * Some Plus boards require simultaneous membership of independent lists of
 * readers and viewers. All other forums use the native ACL directly.
 */
class conditional_read extends \phpbb\auth\auth
{
	private const CONDITIONAL = 'f_plus_read_if_listed';
	private const RIGHTS = array('f_read','f_list_topics','f_search');
	private $board_config;
	private $text;
	private $forums;
	private $building_native_cache = false;

	public function __construct($config,$text)
	{
		$this->board_config = $config;
		$this->text = $text;
	}

	private function scopes()
	{
		if (empty($this->board_config['plus_conditional_read'])) { return array(); }
		if ($this->forums === null)
		{
			$this->forums = array_filter(array_map('intval',explode(',',(string) $this->text->get('plus_read_scopes'))));
		}
		return $this->forums;
	}

	private function qualified($row,$keys)
	{
		return (int) ($row[$keys['f_list'] ?? 'f_list'] ?? ACL_NO) === ACL_YES &&
			(int) ($row[$keys[self::CONDITIONAL] ?? self::CONDITIONAL] ?? ACL_NO) === ACL_YES;
	}

	private function complete(&$row,$keys,$rights = self::RIGHTS)
	{
		if (!$this->qualified($row,$keys)) { return; }
		foreach ($rights as $name)
		{
			$key = $keys[$name] ?? $name;
			if ((int) ($row[$key] ?? ACL_NO) !== ACL_NEVER) { $row[$key] = ACL_YES; }
		}
	}

	public function acl_cache(&$userdata)
	{
		// Persist only native grants. A disabled extension must never leave a
		// conditional permission behind after the underlying membership changes.
		$this->building_native_cache = true;
		try { parent::acl_cache($userdata); }
		finally { $this->building_native_cache = false; }
	}

	public function acl_raw_data_single_user($user_id)
	{
		$rows = parent::acl_raw_data_single_user($user_id);
		$keys = $this->acl_options['id'] ?? array();
		if ($this->building_native_cache || !isset($keys[self::CONDITIONAL])) { return $rows; }
		foreach ($this->scopes() as $forum)
		{
			if (isset($rows[$forum])) { $this->complete($rows[$forum],$keys); }
		}
		return $rows;
	}

	public function acl(&$userdata)
	{
		parent::acl($userdata);
		$keys = $this->acl_options['local'];
		if (!isset($keys[self::CONDITIONAL])) { return; }
		$candidates = array();
		foreach ($this->scopes() as $forum)
		{
			if (!isset($this->acl[$forum]) || !$this->qualified($this->acl[$forum],$keys)) { continue; }
			foreach (self::RIGHTS as $name)
			{
				if ($this->acl[$forum][$keys[$name]] === '0') { $candidates[$forum][] = $name; }
			}
		}
		if (!$candidates) { return; }
		// A native NEVER and an unset bit have the same cached representation.
		// Recheck only eligible members, using the subject of an ACP permission trace.
		$subject = $userdata['user_perm_from'] ?? null;
		if ($subject === null)
		{
			global $db;
			$r = $db->sql_query('SELECT user_perm_from FROM ' . USERS_TABLE . ' WHERE user_id=' . (int) $userdata['user_id']);
			$subject = $db->sql_fetchfield('user_perm_from');
			$db->sql_freeresult($r);
		}
		$rows = $this->acl_raw_data_single_user($subject ?: $userdata['user_id']);
		foreach ($candidates as $forum=>$rights)
		{
			foreach ($rights as $name)
			{
				if ((int) ($rows[$forum][$this->acl_options['id'][$name]] ?? ACL_NO) === ACL_YES)
				{
					$this->acl[$forum][$keys[$name]] = '1';
					unset($this->cache[$forum][$name]);
				}
			}
		}
	}

	public function acl_raw_data($user_id = false,$opts = false,$forum_id = false)
	{
		$data = parent::acl_raw_data($user_id,$opts,$forum_id);
		$scopes = $this->scopes();
		if ($forum_id !== false) { $scopes = array_intersect($scopes,(array) $forum_id); }
		if (!$scopes) { return $data; }
		$fields = array_merge(self::RIGHTS,array('f_list',self::CONDITIONAL));
		$wanted = array();
		foreach ($opts === false ? array('%') : (array) $opts as $pattern)
		{
			// The native option filter supports exact names and percent wildcards.
			foreach ($fields as $name)
			{
				if (preg_match('~^' . str_replace('%','.*',preg_quote($pattern,'~')) . '$~D',$name)) { $wanted[$name]=$name; }
			}
		}
		$rights = array_intersect(self::RIGHTS,$wanted);
		if (!$rights) { return $data; }
		if (isset($wanted['f_list'],$wanted[self::CONDITIONAL]))
		{
			// Whole-forum queries already include both prerequisites.
			foreach ($data as &$forums)
			{
				foreach ($scopes as $forum) { if (isset($forums[$forum])) { $this->complete($forums[$forum],array(),$rights); } }
			}
			unset($forums);
			return $data;
		}
		$extra = parent::acl_raw_data($user_id,array_merge(array('f_list',self::CONDITIONAL),array_values($rights)),array_values($scopes));
		foreach ($extra as $member=>$forums)
		{
			foreach ($forums as $forum=>$row)
			{
				if (!$this->qualified($row,array())) { continue; }
				foreach ($rights as $name)
				{
					if ((int) ($row[$name] ?? ACL_NO) !== ACL_NEVER) { $data[$member][$forum][$name]=ACL_YES; }
				}
			}
		}
		return $data;
	}
}
