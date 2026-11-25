<?php
namespace phpbb2plus\gallery\service;

class personal_grants
{
	private $db;
	private $groups;
	private $access;
	private $admin;
	private $auth;
	private $user;
	private $log;
	private $prefix;

	public function __construct($db, $groups, $access, $admin, $auth, $user, $log, $prefix)
	{
		$this->db = $db;
		$this->groups = $groups;
		$this->access = $access;
		$this->admin = $admin;
		$this->auth = $auth;
		$this->user = $user;
		$this->log = $log;
		$this->prefix = $prefix;
	}

	public function groups($page)
	{
		$this->admin->authorize('personal_permissions');
		return $this->groups->group_page($page);
	}

	private function creation($group)
	{
		$result = $this->db->sql_query('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='u_plus_album_personal'");
		$option = (int) $this->db->sql_fetchfield('auth_option_id');
		$this->db->sql_freeresult($result);
		if (!$option) { throw new \DomainException('PLUS_ALBUM_GRANTS_CHANGED'); }
		$direct = $role = ACL_NO;
		$result = $this->db->sql_query('SELECT g.auth_role_id,g.auth_setting,r.auth_setting AS role_setting FROM ' . ACL_GROUPS_TABLE . ' g
			LEFT JOIN ' . ACL_ROLES_DATA_TABLE . " r ON r.role_id=g.auth_role_id AND r.auth_option_id=$option
			WHERE g.group_id=$group AND g.forum_id=0 AND ((g.auth_role_id=0 AND g.auth_option_id=$option) OR r.role_id IS NOT NULL)");
		while ($row = $this->db->sql_fetchrow($result))
		{
			if ((int) $row['auth_role_id'])
			{
				$value = (int) $row['role_setting'];
				if ($value === ACL_NEVER || ($value === ACL_YES && $role !== ACL_NEVER)) { $role = $value; }
			}
			elseif ((int) $row['auth_setting'] === ACL_NEVER || ((int) $row['auth_setting'] === ACL_YES && $direct !== ACL_NEVER)) { $direct = (int) $row['auth_setting']; }
		}
		$this->db->sql_freeresult($result);
		return array('option' => $option, 'direct' => $direct, 'role' => $role);
	}

	// Historical guest collections and orphaned albums keep their separately converted privacy rules.
	private function scope() { return 'c.owner_id>' . ANONYMOUS . ' AND c.legacy_guest_root=0'; }

	public function review($group)
	{
		// The legacy module could change existing albums. Future defaults require an actual native grant.
		$native = $this->admin->authorize('personal_permissions', true);
		$group = (int) $group;
		$result = $this->db->sql_query('SELECT group_name FROM ' . GROUPS_TABLE . " WHERE group_id=$group AND group_type<>" . GROUP_SPECIAL);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row) { throw new \DomainException('PLUS_ALBUM_GRANTS_CHANGED'); }
		$name = htmlspecialchars_decode($row['group_name'], ENT_QUOTES);
		$creation = $this->creation($group);
		$grants = $this->prefix . 'grants';
		$actions = $this->db->sql_in_set('capability', permissions::ACTIONS);
		$result = $this->db->sql_query("SELECT capability FROM $grants WHERE cat_id=0 AND subject_type='g' AND subject_id=$group AND $actions ORDER BY capability");
		$defaults = array();
		while ($row = $this->db->sql_fetchrow($result)) { $defaults[$row['capability']] = true; }
		$this->db->sql_freeresult($result);
		$hash = hash_init('sha256');
		hash_update($hash, serialize(array($group, $name, $native, $creation, $defaults)));
		$counts = array_fill_keys(permissions::ACTIONS, 0);
		$last = $total = 0;
		$result = $this->db->sql_query('SELECT c.cat_id,c.owner_id,c.parent_id,c.view_level,c.upload_level,c.rate_level,c.comment_level,c.edit_level,c.delete_level,g.capability FROM ' . $this->prefix . "cats c LEFT JOIN $grants g
			ON g.cat_id=c.cat_id AND g.subject_type='g' AND g.subject_id=$group AND " . $this->db->sql_in_set('g.capability', permissions::ACTIONS) .
			' WHERE ' . $this->scope() . ' ORDER BY c.cat_id,g.capability');
		while ($row = $this->db->sql_fetchrow($result))
		{
			$id = (int) $row['cat_id'];
			if ($last !== $id) { ++$total; $last = $id; }
			if (isset($counts[$row['capability']])) { ++$counts[$row['capability']]; }
			hash_update($hash, serialize($row));
		}
		$this->db->sql_freeresult($result);
		return array('name' => $name, 'native' => $native, 'creation' => $creation, 'defaults' => $defaults, 'counts' => $counts,
			'total' => $total, 'state' => hash_final($hash));
	}

	public function changes($current, $fields, $scope)
	{
		$actions = array_merge(array('create'), permissions::ACTIONS);
		if (!in_array($scope, array('existing', 'future', 'both'), true) || array_diff($actions, array_keys($fields)) || array_diff(array_keys($fields), $actions))
		{ throw new \DomainException('PLUS_ALBUM_GRANTS_CHANGED'); }
		if ($scope !== 'existing' && !$current['native']) { throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(); }
		$changes = array();
		foreach ($actions as $action)
		{
			$value = $fields[$action];
			if ($value === 'keep') { continue; }
			if ($value !== 'grant' && $value !== 'remove') { throw new \DomainException('PLUS_ALBUM_GRANTS_CHANGED'); }
			$grant = $value === 'grant';
			if ($action === 'create')
			{
				if ($current['creation']['direct'] === ACL_NEVER || $current['creation']['role'] === ACL_NEVER) { throw new \DomainException('PLUS_PERSONAL_GRANTS_NEVER'); }
				if (($current['creation']['direct'] === ACL_YES) !== $grant) { $changes[$action] = array('grant' => $grant, 'affected' => 0); }
				continue;
			}
			$affected = $scope !== 'future' ? ($grant ? $current['total'] - $current['counts'][$action] : $current['counts'][$action]) : 0;
			$default_changes = $scope !== 'existing' && isset($current['defaults'][$action]) !== $grant;
			if ($affected || $default_changes) { $changes[$action] = array('grant' => $grant, 'affected' => $affected, 'default' => $default_changes); }
		}
		return $changes;
	}

	public static function confirmation($group, $state, $scope, $changes)
	{
		return 'plus_personal_grants_' . hash('sha256', serialize(array((int) $group, $state, $scope, $changes)));
	}

	public function save($group, $fields, $scope, $state)
	{
		$this->access->write(function () use ($group, $fields, $scope, $state) {
			$group = (int) $group;
			// review() checks the current sudo session, native ACLs and exact delegation again under the gallery lock.
			$current = $this->review($group);
			if (!hash_equals($current['state'], $state)) { throw new \DomainException('PLUS_ALBUM_GRANTS_CHANGED'); }
			$changes = $this->changes($current, $fields, $scope);
			foreach ($changes as $action => $change)
			{
				if ($action === 'create') { $this->set_creation($group, $current['creation']['option'], $change['grant']); continue; }
				$this->set_action($group, $action, $change['grant'], $change['default'], $change['affected']);
			}
			if ($changes) { $this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_PLUS_PERSONAL_GRANTS', false,
				array(access::encode_label($current['name']), count($changes))); }
		});
	}

	private function set_creation($group, $option, $grant)
	{
		// This narrow ACP module only owns the explicit group YES for this one global option.
		$this->db->sql_query('DELETE FROM ' . ACL_GROUPS_TABLE . " WHERE group_id=$group AND forum_id=0 AND auth_role_id=0 AND auth_option_id=$option AND auth_setting<>" . ACL_NEVER);
		if ($grant)
		{
			// A concurrent native NEVER survives the delete and prevents an additional direct YES.
			$this->db->sql_query('INSERT INTO ' . ACL_GROUPS_TABLE . ' (group_id,forum_id,auth_role_id,auth_option_id,auth_setting)
				SELECT g.group_id,0,0,' . $option . ',' . ACL_YES . ' FROM ' . GROUPS_TABLE . " g WHERE g.group_id=$group AND g.group_type<>" . GROUP_SPECIAL .
				' AND NOT EXISTS (SELECT 1 FROM ' . ACL_GROUPS_TABLE . " a WHERE a.group_id=$group AND a.forum_id=0 AND a.auth_role_id=0 AND a.auth_option_id=$option)");
			if ((int) $this->db->sql_affectedrows() !== 1) { throw new \DomainException('PLUS_ALBUM_GRANTS_CHANGED'); }
		}
		$result = $this->db->sql_query('SELECT user_id FROM ' . USER_GROUP_TABLE . " WHERE group_id=$group AND user_pending=0");
		$members = array_column($this->db->sql_fetchrowset($result), 'user_id');
		$this->db->sql_freeresult($result);
		if ($members) { $this->auth->acl_clear_prefetch($members); }
	}

	private function set_action($group, $action, $grant, $change_default, $affected)
	{
		$table = $this->prefix . 'grants';
		$who = "subject_type='g' AND subject_id=$group AND capability='$action'";
		if ($change_default)
		{
			if ($grant) { $this->db->sql_query("INSERT INTO $table (cat_id,capability,subject_type,subject_id) VALUES (0,'$action','g',$group)"); }
			else { $this->db->sql_query("DELETE FROM $table WHERE cat_id=0 AND $who"); }
		}
		if (!$affected) { return; }
		$categories = $this->prefix . 'cats';
		if ($grant)
		{
			$this->db->sql_query("INSERT INTO $table (cat_id,capability,subject_type,subject_id) SELECT c.cat_id,'$action','g',$group FROM $categories c
				WHERE " . $this->scope() . " AND NOT EXISTS (SELECT 1 FROM $table g WHERE g.cat_id=c.cat_id AND g.subject_id=$group AND g.subject_type='g' AND g.capability='$action')");
		}
		else { $this->db->sql_query("DELETE FROM $table WHERE $who AND cat_id IN (SELECT c.cat_id FROM $categories c WHERE " . $this->scope() . ')'); }
	}
}
