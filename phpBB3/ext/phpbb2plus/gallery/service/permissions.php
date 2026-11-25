<?php
namespace phpbb2plus\gallery\service;

class permissions
{
	const ACTIONS = array('view', 'upload', 'rate', 'comment', 'edit', 'delete', 'moderator');
	const PER_PAGE = 20;

	private $db;
	private $hierarchy;
	private $access;
	private $admin;
	private $user;
	private $log;
	private $table;

	public function __construct($db, $hierarchy, $access, $admin, $user, $log, $table)
	{
		$this->db = $db;
		$this->hierarchy = $hierarchy;
		$this->access = $access;
		$this->admin = $admin;
		$this->user = $user;
		$this->log = $log;
		$this->table = $table;
	}

	public function tree()
	{
		$this->admin->authorize('permissions');
		return $this->hierarchy->tree(0);
	}

	public function page($id, $page)
	{
		$tree = $this->tree();
		if (!isset($tree[$id])) { throw new \DomainException('PLUS_ALBUM_PUBLIC_MISSING'); }
		$category = $tree[$id];
		// phpBB2 offered named groups. Its single-member groups were converted into individual grants.
		$where = ' FROM ' . GROUPS_TABLE . ' WHERE group_type <> ' . GROUP_SPECIAL;
		$result = $this->db->sql_query('SELECT COUNT(*) AS total' . $where);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		$pages = max(1, (int) ceil($total / self::PER_PAGE));
		if ($page < 1 || $page > $pages) { throw new \DomainException('PLUS_ALBUM_GRANTS_CHANGED'); }
		$result = $this->db->sql_query_limit('SELECT group_id,group_name' . $where . ' ORDER BY group_name,group_id',
			self::PER_PAGE, ($page - 1) * self::PER_PAGE);
		$groups = array();
		while ($row = $this->db->sql_fetchrow($result)) { $groups[(int) $row['group_id']] = htmlspecialchars_decode($row['group_name'], ENT_QUOTES); }
		$this->db->sql_freeresult($result);
		$selected = array();
		if ($groups)
		{
			$result = $this->db->sql_query("SELECT subject_id,capability FROM {$this->table} WHERE cat_id=" . (int) $id . " AND subject_type='g' AND " .
				$this->db->sql_in_set('subject_id', array_keys($groups)) . ' AND ' . $this->db->sql_in_set('capability', self::ACTIONS) .
				' ORDER BY subject_id,capability');
			while ($row = $this->db->sql_fetchrow($result)) { $selected[(int) $row['subject_id']][$row['capability']] = true; }
			$this->db->sql_freeresult($result);
		}
		return array('category' => $category, 'groups' => $groups, 'selected' => $selected, 'pages' => $pages,
			'state' => hash('sha256', serialize(array($category, $page, $groups, $selected))));
	}

	public function save($id, $page, array $submitted, $state)
	{
		return $this->access->write(function () use ($id, $page, $submitted, $state) {
			$this->admin->authorize('permissions', true);
			$current = $this->page($id, $page);
			if (!hash_equals($current['state'], $state)) { throw new \DomainException('PLUS_ALBUM_GRANTS_CHANGED'); }
			foreach ($submitted as $group => $actions)
			{
				if (!isset($current['groups'][$group]) || !is_array($actions) || array_diff(array_keys($actions), self::ACTIONS))
				{ throw new \DomainException('PLUS_ALBUM_GRANTS_CHANGED'); }
				foreach ($actions as $value) { if ($value !== '1') { throw new \DomainException('PLUS_ALBUM_GRANTS_CHANGED'); } }
			}
			$insert = $remove = array();
			foreach ($current['groups'] as $group => $name)
			{
				foreach (self::ACTIONS as $action)
				{
					$was = isset($current['selected'][$group][$action]);
					$now = isset($submitted[$group][$action]);
					if ($was === $now) { continue; }
					if ($now) { $insert[] = array('cat_id' => (int) $id, 'capability' => $action, 'subject_type' => 'g', 'subject_id' => $group); }
					else { $remove[$action][] = $group; }
				}
			}
			if (!$insert && !$remove) { return; }
			foreach ($remove as $action => $groups)
			{
				$this->db->sql_query("DELETE FROM {$this->table} WHERE cat_id=" . (int) $id .
					" AND subject_type='g' AND capability='$action' AND " . $this->db->sql_in_set('subject_id', $groups));
			}
			if ($insert) { $this->db->sql_multi_insert($this->table, $insert); }
			$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_PLUS_ALBUM_AUTH', false,
				array(access::encode_label($current['category']['title'])));
		});
	}
}
