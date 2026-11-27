<?php
namespace phpbb2plus\community\service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class member_operations
{
	private const RIGHTS = array('activate' => array('a_user','a_plus_list_status'), 'deactivate' => array('a_user','a_plus_list_status'),
		'ban' => array('a_ban','a_plus_list_bans'), 'unban' => array('a_ban','a_plus_list_bans'), 'group' => array('a_group','a_plus_list_groups'));
	private $db;
	private $auth;
	private $user;
	private $config;
	private $prefix;

	public function __construct($db, $auth, $user, $config, $prefix)
	{
		$this->db = $db; $this->auth = $auth; $this->user = $user; $this->config = $config; $this->prefix = $prefix;
	}

	private function rows($sql)
	{
		$r = $this->db->sql_query($sql); $rows = $this->db->sql_fetchrowset($r); $this->db->sql_freeresult($r); return $rows;
	}

	private function delegated($id, $lock)
	{
		$rows = $this->rows('SELECT rights_json FROM ' . $this->prefix . 'plus_jr_delegations WHERE user_id=' . (int) $id . $lock);
		return $rows ? (array) json_decode($rows[0]['rights_json'], true) : array();
	}

	private function rights($actor, $grants, $raw)
	{
		if (!empty($actor['user_perm_from']) || !in_array((int) $actor['user_type'], array(USER_NORMAL,USER_FOUNDER), true)) { return array(); }
		$founder = (int) $actor['user_type'] === USER_FOUNDER;
		$grants = empty($actor['plus_jr_delegate']) ? array() : $grants;
		$setting = static function ($key) use ($raw) { return (int) ($raw[$key] ?? ACL_NO); };
		$has = static function ($key) use ($founder, $grants, $setting) {
			return $setting($key) !== ACL_NEVER && ($founder || $setting($key) === ACL_YES || in_array($key, $grants, true));
		};
		if ($setting('a_') === ACL_NEVER || !$has('a_plus_user_list')) { return array(); }
		$actions = array();
		foreach (self::RIGHTS as $action => list($native, $specific))
		{
			if ($setting($native) === ACL_NEVER || $setting($specific) === ACL_NEVER) { continue; }
			if ($founder || $setting($native) === ACL_YES) { $actions[$action] = 'native'; }
			elseif ($has($specific)) { $actions[$action] = 'scoped'; }
		}
		return $actions;
	}

	public function actions()
	{
		$actor = $this->user->data;
		if (!defined('IN_ADMIN') || !IN_ADMIN || empty($actor['is_registered']) || empty($actor['session_admin'])) { return array(); }
		$id = (int) $actor['user_id'];
		return $this->rights($actor, $this->delegated($id, ''), $this->auth->acl_raw_data($id, 'a_%', 0)[$id][0] ?? array());
	}

	private function groups_allowed($scope, $groups)
	{
		if (!$groups) { return array(); }
		$rights = $this->auth->acl_group_raw_data(array_column($groups, 'group_id'), array('a_%','m_%'), 0);
		$allowed = array();
		foreach ($groups as $group)
		{
			$id = (int) $group['group_id'];
			if (!$group['group_founder_manage'] && in_array((int) $group['group_type'], array(GROUP_OPEN,GROUP_CLOSED,GROUP_HIDDEN,GROUP_FREE), true)
				&& ($scope === 'native' || $group['plus_legacy_group'])
				&& !in_array(ACL_YES, array_map('intval', $rights[$id][0] ?? array()), true))
			{ $allowed[$id] = $group; }
		}
		return $allowed;
	}

	public function groups($scope)
	{
		return $this->groups_allowed($scope, $this->rows('SELECT group_id,group_name,group_type,plus_legacy_group,group_founder_manage FROM ' .
			GROUPS_TABLE . ' WHERE group_type<>' . GROUP_SPECIAL . ' AND group_founder_manage=0 ORDER BY group_name,group_id'));
	}

	private function session()
	{
		$sid = $this->db->sql_escape($this->user->session_id);
		$row = $this->rows('SELECT session_id,session_time,session_autologin,session_admin,plus_dir_review FROM ' . SESSIONS_TABLE .
			" WHERE session_id='$sid' AND session_user_id=" . (int) $this->user->data['user_id'] . ' FOR UPDATE')[0] ?? null;
		if (!$row || !$row['session_admin'] || empty($this->user->data['session_admin']) || empty($this->user->data['is_registered']) ||
			!empty($this->user->data['user_perm_from'])) { throw new AccessDeniedHttpException(); }
		$autologin = $row['session_autologin'] && $this->config['allow_autologin'];
		$life = $autologin ? 86400 * (int) $this->config['max_autologin_time'] : (int) $this->config['session_length'];
		if ((!$autologin || $life) && (int) $row['session_time'] + $life + 60 < time()) { throw new AccessDeniedHttpException(); }
		return $row;
	}

	private function review_key($sid, $key)
	{
		$this->db->sql_query('UPDATE ' . SESSIONS_TABLE . ' SET ' . $this->db->sql_build_array('UPDATE',
			array('plus_dir_review' => $key)) . " WHERE session_id='" . $this->db->sql_escape($sid) . "'");
	}

	private function selection($action, $group, $ids)
	{
		$actor = (int) $this->user->data['user_id'];
		$keys = array_values(array_unique(array_merge($ids, array($actor)))); sort($keys, SORT_NUMERIC);
		$users = $this->db->sql_in_set('user_id', $keys);
		$accounts = array_column($this->rows('SELECT user_id,username,user_regdate,user_type,user_perm_from,plus_jr_delegate,group_id,
			user_inactive_time,user_inactive_reason,plus_card_count FROM ' . USERS_TABLE . " WHERE $users ORDER BY user_id FOR UPDATE"), null, 'user_id');
		$members = $this->rows('SELECT group_id,user_id,user_pending,group_leader FROM ' . USER_GROUP_TABLE . " WHERE $users ORDER BY user_id,group_id FOR UPDATE");
		$group_ids = array_values(array_unique(array_merge(array_column($accounts,'group_id'), array_column($members,'group_id'), array($group))));
		$group_set = $this->db->sql_in_set('group_id', $group_ids);
		$groups = array_column($this->rows('SELECT group_id,group_name,group_type,group_founder_manage,plus_legacy_group FROM ' . GROUPS_TABLE .
			" WHERE $group_set ORDER BY group_id LOCK IN SHARE MODE"), null, 'group_id');
		$roles = array_merge($this->rows('SELECT auth_role_id FROM ' . ACL_USERS_TABLE . " WHERE $users AND forum_id=0 LOCK IN SHARE MODE"),
			$this->rows('SELECT auth_role_id FROM ' . ACL_GROUPS_TABLE . " WHERE $group_set AND forum_id=0 LOCK IN SHARE MODE"));
		$roles = array_values(array_unique(array_filter(array_column($roles, 'auth_role_id'))));
		if ($roles) { $this->rows('SELECT role_id FROM ' . ACL_ROLES_DATA_TABLE . ' WHERE ' . $this->db->sql_in_set('role_id', $roles) . ' LOCK IN SHARE MODE'); }
		$bots = array_flip(array_column($this->rows('SELECT user_id FROM ' . BOTS_TABLE . " WHERE $users LOCK IN SHARE MODE"), 'user_id'));
		$grants = $this->delegated($actor, ' LOCK IN SHARE MODE');
		$extension = $this->rows('SELECT ext_active FROM ' . EXT_TABLE . " WHERE ext_name='phpbb2plus/community' LOCK IN SHARE MODE");
		$bans = array();
		if ($action === 'ban' || $action === 'unban')
		{
			foreach ($this->rows('SELECT ban_id,ban_userid,ban_ip,ban_email,ban_end,ban_exclude,plus_dir_ban,plus_card_legacy FROM ' .
				BANLIST_TABLE . ' WHERE ' . $this->db->sql_in_set('ban_userid', $ids) . ' ORDER BY ban_userid,ban_id FOR UPDATE') as $ban)
			{ if (!(int) $ban['ban_end'] || (int) $ban['ban_end'] >= time()) { $bans[$ban['ban_userid']][] = $ban; } }
		}
		// All inputs to the effective native grants are pinned before acl_raw_data opens a read view.
		$acl = $this->auth->acl_raw_data($keys, 'a_%', 0);
		$actions = isset($accounts[$actor]) && !empty($extension[0]['ext_active']) ? $this->rights($accounts[$actor], $grants, $acl[$actor][0] ?? array()) : array();
		if (!isset($actions[$action])) { throw new AccessDeniedHttpException(); }
		if ($action === 'group' && (empty($groups[$group]) || !$this->groups_allowed($actions[$action], array($groups[$group]))))
		{ throw new \DomainException('PLUS_LIST_GROUP_CHANGED'); }
		$protected_groups = array();
		foreach ($groups as $id => $row)
		{
			if ((int) $row['group_type'] === GROUP_SPECIAL && in_array($row['group_name'], array('ADMINISTRATORS','BOTS'), true)) { $protected_groups[$id] = true; }
		}
		$membership = array();
		foreach ($members as $member)
		{
			if (!(int) $member['user_pending'] && isset($protected_groups[$member['group_id']])) { $bots[$member['user_id']] = true; }
			if ((int) $member['group_id'] === $group) { $membership[(int) $member['user_id']] = (int) $member['user_pending']; }
		}
		$result = array();
		foreach ($ids as $id)
		{
			$row = $accounts[$id] ?? null;
			if (!$row || $id <= ANONYMOUS || $id === $actor || $row['user_perm_from'] || $row['plus_jr_delegate'] || isset($bots[$id]) ||
				isset($protected_groups[$row['group_id']]) || !in_array((int) $row['user_type'], array(USER_NORMAL,USER_INACTIVE), true) ||
				in_array(ACL_YES, array_map('intval', $acl[$id][0] ?? array()), true)) { continue; }
			$rules = $bans[$id] ?? array(); $owned = array(); $historical = false; $other_ban = false;
			foreach ($rules as $rule)
			{
				if (!$rule['ban_end'] && !$rule['ban_exclude'] && $rule['ban_ip'] === '' && $rule['ban_email'] === '' &&
					($rule['plus_dir_ban'] || $rule['plus_card_legacy']))
				{
					$owned[] = (int) $rule['ban_id'];
					$historical = $historical || $rule['plus_card_legacy'];
				}
				elseif (!$rule['ban_exclude']) { $other_ban = true; }
			}
			$pending = $membership[$id] ?? null;
			if (($action === 'activate' && (int) $row['user_type'] !== USER_INACTIVE) ||
				($action === 'deactivate' && (int) $row['user_type'] !== USER_NORMAL) || ($action === 'group' && $pending === 0) ||
				($action === 'ban' && $rules) || ($action === 'unban' && !$owned)) { continue; }
			$stamp = hash('sha256', serialize(array($row, $rules, $pending, $group ? $groups[$group]['group_name'] : '')));
			$result[$id] = array('name' => $row['username'], 'stamp' => $stamp, 'owned' => $owned, 'pending' => $pending,
				'reset_cards' => $historical && !$other_ban);
		}
		return array($result, $group ? $groups[$group]['group_name'] : '');
	}

	public function review($action, $group, array $ids)
	{
		$ids = array_values(array_unique(array_filter(array_map('intval',$ids), static function ($id) { return $id > 0; })));
		if (!isset(self::RIGHTS[$action]) || !$ids || count($ids) > 200) { throw new \DomainException('PLUS_LIST_SELECT'); }
		$group = $action === 'group' ? (int) $group : 0;
		$this->db->sql_transaction('begin');
		try
		{
			$session = $this->session();
			list($rows, $name) = $this->selection($action, $group, $ids);
			$key = bin2hex(random_bytes(32));
			$marks = array_map(static function ($row) { return $row['stamp']; }, $rows);
			$payload = rtrim(strtr(base64_encode(json_encode(array('expires' => time()+900, 'action' => $action, 'group' => $group,
				'marks' => $marks), JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
			$this->review_key($session['session_id'], $rows ? $key : '');
			$this->db->sql_transaction('commit');
			return array($rows, $name, $payload . '.' . hash_hmac('sha256', $payload, $key), count($ids)-count($rows));
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
	}

	public function apply($ticket, $cancel = false)
	{
		global $phpbb_container;
		$this->db->sql_transaction('begin');
		try
		{
			$session = $this->session();
			$key = (string) $session['plus_dir_review'];
			if (strlen($key) !== 64 || !preg_match('/^([A-Za-z0-9_-]{1,32000})\.([0-9a-f]{64})$/D', $ticket, $parts) ||
				!hash_equals(hash_hmac('sha256', $parts[1], $key), $parts[2]))
			{ throw new \DomainException('PLUS_LIST_EXPIRED'); }
			$review = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true), true, 512, JSON_THROW_ON_ERROR);
			if ($review['expires'] < time()) { throw new \DomainException('PLUS_LIST_EXPIRED'); }
			$this->review_key($session['session_id'], '');
			if ($cancel)
			{
				$this->db->sql_transaction('commit');
				return array(0, 0);
			}
			$action = $review['action']; $group = (int) $review['group']; $marks = $review['marks'];
			list($rows, $group_name) = $this->selection($action, $group, array_map('intval', array_keys($marks)));
			foreach ($rows as $id => $row) { if (!hash_equals($marks[$id], $row['stamp'])) { unset($rows[$id]); } }
			if ($rows) { $this->write($action, $rows, $group, $group_name); }
			$this->db->sql_transaction('commit');
			if ($rows && ($action === 'ban' || $action === 'unban')) { $phpbb_container->get('cache')->destroy('sql', BANLIST_TABLE); }
			return array(count($rows), count($marks)-count($rows));
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
	}

	private function write($action, $rows, $group, $group_name)
	{
		global $phpbb_container, $phpbb_root_path, $phpEx;
		require_once $phpbb_root_path . 'includes/functions_user.' . $phpEx;
		$ids = array_keys($rows);
		if ($action === 'activate' || $action === 'deactivate')
		{
			user_active_flip($action, $ids, INACTIVE_MANUAL);
			if ($action === 'activate')
			{
				foreach ($ids as $id) { $phpbb_container->get('notification_manager')->delete_notifications('notification.type.admin_activate_user', $id); }
			}
		}
		elseif ($action === 'group')
		{
			$pending = array_keys(array_filter($rows, static function ($r) { return $r['pending'] === 1; }));
			$new = array_values(array_diff($ids, $pending));
			if (($new && group_user_add($group, $new, false, $group_name)) ||
				($pending && group_user_attributes('approve', $group, $pending, false, $group_name))) { throw new \DomainException('PLUS_LIST_GROUP_CHANGED'); }
		}
		else
		{
			// The native ban helpers also delete all expired rules and replace pre-existing ban details.
			// This historical account-only operation must preserve unrelated rules, including their history.
			if ($action === 'ban')
			{
				$rules = array();
				foreach ($ids as $id) { $rules[] = array('ban_userid'=>$id,'ban_start'=>time(),'ban_end'=>0,'ban_exclude'=>0,'ban_reason'=>'','ban_give_reason'=>'','plus_dir_ban'=>1); }
				$this->db->sql_multi_insert(BANLIST_TABLE, $rules);
				$this->db->sql_query('DELETE FROM ' . SESSIONS_KEYS_TABLE . ' WHERE ' . $this->db->sql_in_set('user_id', $ids));
			}
			else
			{
				$this->db->sql_query('DELETE FROM ' . BANLIST_TABLE . ' WHERE ' . $this->db->sql_in_set('ban_id', array_merge(...array_column($rows, 'owned'))));
				$legacy = array_keys(array_filter($rows, static function ($r) { return $r['reset_cards']; }));
				if ($legacy) { $this->db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_card_count=0 WHERE ' . $this->db->sql_in_set('user_id', $legacy)); }
			}
			$log = $phpbb_container->get('log');
			$entry = $action === 'ban' ? 'LOG_BAN_USER' : 'LOG_UNBAN_USER';
			$names = implode(', ', array_column($rows, 'name'));
			$context = $action === 'ban' ? array('', $names) : array($names);
			$who = (int) $this->user->data['user_id'];
			$log->add('mod', $who, $this->user->ip, $entry, false, array('forum_id'=>0, 'topic_id'=>0) + $context);
			foreach ($rows as $id => $row)
			{
				$person = $action === 'ban' ? array('', $row['name']) : array($row['name']);
				$log->add('user', $who, $this->user->ip, $entry, false, array('reportee_id'=>$id) + $person);
			}
			if ($action === 'unban')
			{ $phpbb_container->get('dispatcher')->trigger_event('core.user_unban', array('mode'=>'user','user_ids_ary'=>$ids)); }
		}
		$this->db->sql_query('DELETE FROM ' . SESSIONS_TABLE . ' WHERE ' . $this->db->sql_in_set('session_user_id', $ids));
		$phpbb_container->get('log')->add('admin', (int) $this->user->data['user_id'], $this->user->ip, 'LOG_PLUS_LIST_' . strtoupper($action),
			false, array(implode(', ', array_column($rows, 'name')), $group_name));
	}
}
