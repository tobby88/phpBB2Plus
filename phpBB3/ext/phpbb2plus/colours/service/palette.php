<?php
namespace phpbb2plus\colours\service;

class palette
{
	private $db;
	private $config;
	private $table;

	public function __construct($db, $config, $table)
	{
		$this->db = $db;
		$this->config = $config;
		$this->table = $table;
	}

	public static function hex($value)
	{
		$named = array('aqua'=>'00FFFF','black'=>'000000','blue'=>'0000FF','fuchsia'=>'FF00FF',
			'gray'=>'808080','green'=>'008000','lime'=>'00FF00','maroon'=>'800000','navy'=>'000080',
			'olive'=>'808000','purple'=>'800080','red'=>'FF0000','silver'=>'C0C0C0','teal'=>'008080',
			'white'=>'FFFFFF','yellow'=>'FFFF00');
		$value = strtolower(trim((string) $value));
		if (isset($named[$value])) { return $named[$value]; }
		return preg_match('/^#[0-9a-f]{6}$/D',$value) ? strtoupper(substr($value,1)) : '';
	}

	public function visible()
	{
		$result = $this->db->sql_query("SELECT name,colour FROM $this->table WHERE in_legend=1 ORDER BY position,colour_id");
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	/** Return the last processed ID, or zero when the operation is complete. */
	public function batch($after, $enabled)
	{
		$filter = $enabled ? '' : ' AND plus_colour_applied=1';
		$result = $this->db->sql_query_limit('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id>' .
			max((int) $after, ANONYMOUS) . $filter . ' ORDER BY user_id', 250);
		$ids = array();
		while ($row = $this->db->sql_fetchrow($result)) { $ids[] = (int) $row['user_id']; }
		$this->db->sql_freeresult($result);
		if ($ids) { $this->refresh($ids, $enabled); }
		return count($ids) === 250 ? end($ids) : 0;
	}

	public function refresh(array $ids, $enabled = true)
	{
		$ids = array_values(array_unique(array_filter(array_map('intval',$ids), static function ($id) { return $id > ANONYMOUS; })));
		sort($ids, SORT_NUMERIC);
		foreach (array_chunk($ids,250) as $batch)
		{
			$this->db->sql_transaction('begin');
			try
			{
				$this->refresh_batch($batch,$enabled);
				$this->db->sql_transaction('commit');
			}
			catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
		}
	}

	/** Only members affected by the edited group or palette entry need a recalculation. */
	public function refresh_assigned($id, $group)
	{
		$id = (int) $id;
		$membership = 'SELECT 1 FROM ' . USER_GROUP_TABLE . ' ug JOIN ' . GROUPS_TABLE . ' g ON g.group_id=ug.group_id
			WHERE ug.user_id=u.user_id AND ug.user_pending=0 AND ' . ($group ? 'g.group_id=' : 'g.plus_colour_id=') . $id;
		$filter = ($group ? '' : 'u.plus_colour_id=' . $id . ' OR ') . 'EXISTS (' . $membership . ')';
		$after = ANONYMOUS;
		do
		{
			$result = $this->db->sql_query_limit('SELECT u.user_id FROM ' . USERS_TABLE . ' u WHERE u.user_id>' . $after .
				' AND (' . $filter . ') ORDER BY u.user_id',250);
			$ids = array();
			while ($row = $this->db->sql_fetchrow($result)) { $ids[] = (int) $row['user_id']; }
			$this->db->sql_freeresult($result);
			if ($ids) { $this->refresh($ids); $after = end($ids); }
		} while (count($ids) === 250);
	}

	private function refresh_batch(array $ids, $enabled)
	{
		// Membership changes, native default-group writes and palette edits can overlap.
		// Serialise the cached colour writes before reading the effective assignments.
		$lock = $this->db->sql_query('SELECT user_id FROM ' . USERS_TABLE . ' WHERE ' .
			$this->db->sql_in_set('user_id',$ids) . ' ORDER BY user_id FOR UPDATE');
		$this->db->sql_freeresult($lock);
		$memberships = array();
		if ($enabled)
		{
			$result = $this->db->sql_query('SELECT ug.user_id, p.colour FROM ' . USER_GROUP_TABLE . ' ug
				JOIN ' . GROUPS_TABLE . ' g ON g.group_id=ug.group_id
				JOIN ' . $this->table . ' p ON p.colour_id=g.plus_colour_id
				WHERE ug.user_pending=0 AND ' . $this->db->sql_in_set('ug.user_id',$ids) . '
				ORDER BY ug.user_id, p.position, p.colour_id');
			while ($row = $this->db->sql_fetchrow($result))
			{
				$id = (int) $row['user_id'];
				if (!isset($memberships[$id])) { $memberships[$id] = $row['colour']; }
			}
			$this->db->sql_freeresult($result);
		}

		$result = $this->db->sql_query('SELECT u.user_id,u.user_colour,u.plus_colour_applied,
			g.group_colour,p.colour AS personal FROM ' . USERS_TABLE . ' u
			LEFT JOIN ' . GROUPS_TABLE . ' g ON g.group_id=u.group_id
			LEFT JOIN ' . $this->table . ' p ON p.colour_id=u.plus_colour_id
			WHERE ' . $this->db->sql_in_set('u.user_id',$ids));
		$changes = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$id = (int) $row['user_id'];
			$selected = $enabled ? ($row['personal'] ?? ($memberships[$id] ?? null)) : null;
			$managed = (int) ($selected !== null);
			if (!$managed && !$row['plus_colour_applied']) { continue; }
			$colour = $managed ? $selected : (string) $row['group_colour'];
			if ($colour !== $row['user_colour'] || $managed !== (int) $row['plus_colour_applied'])
			{
				$changes[$managed . '/' . $colour][] = $id;
			}
		}
		$this->db->sql_freeresult($result);
		foreach ($changes as $key=>$users)
		{
			list($managed,$colour) = explode('/',$key,2);
			$this->db->sql_query('UPDATE ' . USERS_TABLE . ' SET ' . $this->db->sql_build_array('UPDATE',
				array('user_colour'=>$colour,'plus_colour_applied'=>(int) $managed)) . ' WHERE ' . $this->db->sql_in_set('user_id',$users));
			foreach (array(array(FORUMS_TABLE,'forum_last_poster_id','forum_last_poster_colour'),
				array(TOPICS_TABLE,'topic_poster','topic_first_poster_colour'),array(TOPICS_TABLE,'topic_last_poster_id','topic_last_poster_colour')) as $cache)
			{
				list($table,$user_column,$colour_column) = $cache;
				$this->db->sql_query("UPDATE $table SET $colour_column='" . $this->db->sql_escape($colour) . "' WHERE " . $this->db->sql_in_set($user_column,$users));
			}
			if (in_array((int) $this->config['newest_user_id'],$users,true)) { $this->config->set('newest_user_colour',$colour,false); }
		}
	}
}
