<?php
namespace phpbb2plus\community\group;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** phpBB2 could appoint a group moderator without making that person an approved member. */
class leadership
{
	private $db;
	private $user;
	private $cache;
	private $log;
	private $root;
	private $ext;

	public function __construct($db, $user, $cache, $log, $root, $ext)
	{
		$this->db = $db; $this->user = $user; $this->cache = $cache; $this->log = $log;
		$this->root = $root; $this->ext = $ext;
	}

	public function managed_group($id, $lock = false)
	{
		if (empty($this->user->data['is_registered']) || !empty($this->user->data['user_perm_from'])) { return false; }
		$id = (int) $id;
		$viewer = (int) $this->user->data['user_id'];
		$sql = 'SELECT g.group_id, g.group_name, g.group_type FROM ' . GROUPS_TABLE . ' g
			JOIN ' . USER_GROUP_TABLE . ' ug ON ug.group_id=g.group_id
			JOIN ' . USERS_TABLE . " u ON u.user_id=ug.user_id AND u.user_id=$viewer
			WHERE g.group_id=$id AND g.plus_legacy_group=1 AND g.group_type<>" . GROUP_SPECIAL . '
			AND ug.group_leader=1 AND ug.user_pending=1
			AND ' . $this->db->sql_in_set('u.user_type', array(USER_NORMAL, USER_FOUNDER)) . '
			AND (g.group_founder_manage=0 OR u.user_type=' . USER_FOUNDER . ')';
		$result = $this->db->sql_query($sql . ($lock ? ' FOR UPDATE' : ''));
		$group = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $group;
	}

	public function roster($group, $start, $per_page)
	{
		$from = ' FROM ' . USER_GROUP_TABLE . ' ug JOIN ' . USERS_TABLE . ' u ON u.user_id=ug.user_id
			WHERE ug.group_id=' . (int) $group . ' AND ug.group_leader=0 AND u.user_id<>' . ANONYMOUS;
		$result = $this->db->sql_query('SELECT COUNT(*) AS total' . $from);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		$start = min(intdiv(max(0, (int) $start), $per_page), intdiv(max(0, $total - 1), $per_page)) * $per_page;
		$result = $this->db->sql_query_limit('SELECT u.user_id, u.username, ug.user_pending' . $from
			. ' ORDER BY ug.user_pending DESC, u.username_clean, u.user_id', $per_page, $start);
		$members = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return array($members, $total, $start);
	}

	public function change($id, $action, $type, $username, $selected)
	{
		if (!function_exists('group_user_add')) { require_once $this->root . 'includes/functions_user.' . $this->ext; }
		$type_changed = false;
		$this->db->sql_transaction('begin');
		try
		{
			$group = $this->managed_group($id, true);
			if (!$group) { throw new NotFoundHttpException(); }
			$id = (int) $group['group_id'];
			if ($action === 'type')
			{
				if ((int) $group['group_type'] !== $type)
				{
					if (!in_array($type, array(GROUP_OPEN,GROUP_CLOSED,GROUP_HIDDEN), true)) { throw new \DomainException('PLUS_LEADER_SELECTION'); }
					$this->db->sql_query('UPDATE ' . GROUPS_TABLE . " SET group_type=$type WHERE group_id=$id");
					$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_GROUP_UPDATED', false, array($group['group_name']));
					$type_changed = true;
				}
			}
			else
			{
				$this->change_members($group, $action, $username, $selected);
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $error) { $this->db->sql_transaction('rollback'); throw $error; }
		if ($type_changed) { $this->cache->destroy('sql', GROUPS_TABLE); }
	}

	private function change_members($group, $action, $username, $selected)
	{
		$id = (int) $group['group_id'];
		if ($action === 'add')
		{
			$clean = utf8_clean_string(trim($username));
			$result = $this->db->sql_query('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='" .
				$this->db->sql_escape($clean) . "' AND " . $this->db->sql_in_set('user_type', array(USER_NORMAL, USER_FOUNDER, USER_INACTIVE)) . ' FOR UPDATE');
			$user = (int) $this->db->sql_fetchfield('user_id');
			$this->db->sql_freeresult($result);
			if (!$user) { throw new \DomainException('NO_USER'); }
			$selected = array($user);
		}
		elseif (!in_array($action, array('approve', 'deny', 'remove'), true))
		{
			throw new \DomainException('PLUS_LEADER_SELECTION');
		}
		$ids = array_unique(array_map('intval', $selected));
		if (!$ids || count($ids) > 1000 || min($ids) <= 0 || in_array(ANONYMOUS, $ids, true)) { throw new \DomainException('PLUS_LEADER_SELECTION'); }
		$result = $this->db->sql_query('SELECT ug.user_id,ug.group_leader,ug.user_pending FROM ' . USER_GROUP_TABLE . ' ug
			JOIN ' . USERS_TABLE . " u ON u.user_id=ug.user_id WHERE ug.group_id=$id AND " .
			$this->db->sql_in_set('ug.user_id', $ids) . ' ORDER BY ug.user_id FOR UPDATE');
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		if ($action !== 'add' && count($ids) !== count($rows)) { throw new \DomainException('PLUS_LEADER_SELECTION'); }
		foreach ($rows as $row)
		{
			// A designated-only leader must never approve their own synthetic membership.
			if ($row['group_leader'] || ($action !== 'add' && (bool) $row['user_pending'] !== ($action !== 'remove')))
			{
				throw new \DomainException('PLUS_LEADER_SELECTION');
			}
		}
		$name = $group['group_name'];
		if ($action === 'add' && !$rows) { $error = group_user_add($id, $ids, false, $name); }
		elseif ($action === 'approve' || ($action === 'add' && $rows[0]['user_pending'])) { $error = group_user_attributes('approve', $id, $ids, false, $name); }
		elseif ($action === 'deny' || $action === 'remove') { $error = group_user_del($id, $ids, false, $name); }
		else { return; } // Already an approved member.
		if ($error) { throw new \DomainException($error); }
	}
}
