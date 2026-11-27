<?php
namespace phpbb2plus\community\service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class account_pruning
{
	public const DEFAULT_DAYS = array(240, 240, 240, 120, 360);
	public const PAGE = 25;
	private $db;
	private $auth;
	private $user;
	private $config;
	private $log;
	private $root;
	private $ext;

	public function __construct($db, $auth, $user, $config, $log, $root, $ext)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->config = $config;
		$this->log = $log;
		$this->root = $root;
		$this->ext = $ext;
	}

	private function rows($sql, $limit = 0)
	{
		$result = $limit ? $this->db->sql_query_limit($sql, $limit) : $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function actor()
	{
		$actor = $this->user->data;
		if (!defined('IN_ADMIN') || !IN_ADMIN || empty($actor['session_admin']) || empty($actor['is_registered']) ||
			$actor['user_perm_from'] || !$this->auth->acl_get('a_userdel')) { throw new AccessDeniedHttpException(); }
		return (int) $actor['user_id'];
	}

	public function policy($mode, $days, $at)
	{
		if (!is_int($mode) || !isset(self::DEFAULT_DAYS[$mode]) || !is_int($days) || $days < 1 || $days > 36500 ||
			!is_int($at) || $at < 1 || $at > time()) { throw new \DomainException('FORM_INVALID'); }
		return array('rule' => $mode, 'days' => $days, 'at' => $at);
	}

	private function active_session()
	{
		// Use phpBB's own session_begin() cutoffs, including ongoing remembered sessions.
		$now = time();
		$normal = $now - ((int) $this->config['session_length'] + 60);
		$valid = "(s.session_autologin=0 AND s.session_time>=$normal)";
		if ($this->config['allow_autologin'])
		{
			$days = (int) $this->config['max_autologin_time'];
			$valid .= ' OR (s.session_autologin=1' . ($days ? ' AND s.session_time>=' . ($now - 86400 * $days + 60) : '') . ')';
		}
		return '(' . $valid . ')';
	}

	public static function ordinary_scope($db, $actor)
	{
		return 'u.user_id>' . ANONYMOUS . ' AND u.user_id<>' . (int) $actor . ' AND u.user_perm_from=0 AND u.plus_jr_delegate=0 AND ' .
			$db->sql_in_set('u.user_type', array(USER_NORMAL, USER_INACTIVE)) .
			' AND NOT EXISTS (SELECT 1 FROM ' . BOTS_TABLE . ' b WHERE b.user_id=u.user_id)
			AND NOT EXISTS (SELECT 1 FROM ' . GROUPS_TABLE . ' g WHERE g.group_type=' . GROUP_SPECIAL . " AND g.group_name IN ('ADMINISTRATORS','BOTS')
				AND (g.group_id=u.group_id OR EXISTS (SELECT 1 FROM " . USER_GROUP_TABLE . ' m WHERE m.group_id=g.group_id AND m.user_id=u.user_id AND m.user_pending=0)))';
	}

	private function query($policy, $extra)
	{
		$registered = $policy['at'] - 86400 * $policy['days'];
		$last = 'GREATEST(u.user_lastvisit,u.user_last_active)';
		$conditions = array(
			'u.user_posts=0',
			$last . '=0',
			"$last=0 AND u.user_type=" . USER_INACTIVE . " AND u.user_actkey<>'' AND u.user_inactive_reason=" . INACTIVE_REGISTER,
			$last . '<' . ($policy['at'] - 60 * 86400),
			"CAST(u.user_regdate AS DECIMAL(20,0)) + CAST(u.user_posts AS DECIMAL(20,0))*864000 < $last",
		);
		$actor = $this->actor();
		// Native retain skips post reassignment for pending registrations with a
		// zero counter. An imported account can still own posts in uncounted forums.
		return 'SELECT u.user_id,u.username,u.username_clean,u.user_type,u.user_regdate,u.user_lastvisit,u.user_last_active,u.user_lastpost_time,
			u.user_posts,u.user_inactive_reason,u.user_inactive_time,u.user_actkey,u.user_passchg,u.group_id,u.user_lang,u.user_email
			FROM ' . USERS_TABLE . ' u WHERE ' . self::ordinary_scope($this->db, $actor) . " AND u.user_regdate>0 AND u.user_regdate<$registered
			AND NOT EXISTS (SELECT 1 FROM " . SESSIONS_TABLE . ' s WHERE s.session_user_id=u.user_id AND ' . $this->active_session() . ')
			AND (u.user_type<>' . USER_INACTIVE . ' OR u.user_inactive_reason<>' . INACTIVE_REGISTER . ' OR u.user_posts>0 OR NOT EXISTS
				(SELECT 1 FROM ' . POSTS_TABLE . ' p WHERE p.poster_id=u.user_id))
			AND ' . $conditions[$policy['rule']] . ' AND ' . $extra . ' ORDER BY u.user_id';
	}

	public static function without_admin_grants($auth, $rows)
	{
		if (!$rows) { return array(); }
		$acl = $auth->acl_raw_data(array_column($rows, 'user_id'), 'a_%', 0);
		$eligible = array();
		foreach ($rows as $row)
		{
			$id = (int) $row['user_id'];
			if (!in_array(ACL_YES, array_map('intval', $acl[$id][0] ?? array()), true)) { $eligible[$id] = $row; }
		}
		return $eligible;
	}

	public function page($policy, $after)
	{
		$rows = $this->rows($this->query($policy, 'u.user_id>' . max(0, (int) $after)), self::PAGE + 1);
		$more = count($rows) > self::PAGE;
		if ($more) { array_pop($rows); }
		return array(self::without_admin_grants($this->auth, $rows), $more ? (int) end($rows)['user_id'] : 0);
	}

	private function stamp($row)
	{
		// Include the current identity and activity, without putting email addresses
		// or activation keys into the browser's signed confirmation.
		return hash_hmac('sha256', "account\0" . serialize($row), $this->config['plus_account_prune_key']);
	}

	public function selection($rows)
	{
		$selection = array();
		foreach ($rows as $id => $row) { $selection[$id] = $this->stamp($row); }
		return $selection;
	}

	private function signature($phase, $payload)
	{
		$key = (string) $this->config['plus_account_prune_key'];
		if (!preg_match('/^[a-f0-9]{64}$/D', $key)) { throw new \DomainException('FORM_INVALID'); }
		return hash_hmac('sha256', $this->actor() . "\0" . $this->user->session_id . "\0" . $phase . "\0" . $payload, $key);
	}

	public function ticket($phase, $policy, $selected, $notify = false)
	{
		$json = json_encode(array($policy, $selected, (bool) $notify), JSON_THROW_ON_ERROR);
		$payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
		return $payload . '.' . $this->signature($phase, $payload);
	}

	public function open($phase, $token)
	{
		if (strlen($token) > 8192 || !preg_match('/^([a-zA-Z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $match) ||
			!hash_equals($this->signature($phase, $match[1]), $match[2])) { throw new \DomainException('FORM_INVALID'); }
		$decoded = base64_decode(strtr($match[1], '-_', '+/'), true);
		list($policy, $selected, $notify) = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
		$policy = $this->policy($policy['rule'], $policy['days'], $policy['at']);
		if ($policy['at'] < time() - 1800 || count($selected) > self::PAGE) { throw new \DomainException('PLUS_PRUNE_EXPIRED'); }
		return array($policy, $selected, $notify);
	}

	public function confirmation($policy, $offered, $ids)
	{
		$ids = array_values(array_unique($ids));
		if (!$ids || count($ids) > self::PAGE || array_diff($ids, array_keys($offered))) { throw new \DomainException('PLUS_PRUNE_SELECT'); }
		$rows = self::without_admin_grants($this->auth, $this->rows($this->query($policy, $this->db->sql_in_set('u.user_id', $ids))));
		foreach ($rows as $id => $row)
		{
			if (!hash_equals($offered[$id], $this->stamp($row))) { unset($rows[$id]); }
		}
		return $rows;
	}

	private function lock_authority($ids)
	{
		// Lock direct grants, group memberships and their current global roles before
		// opening a read snapshot for native acl_raw_data(). Group/role changes then
		// finish on one side of this account's deletion, including fresh assignments.
		$users = $this->db->sql_in_set('user_id', $ids);
		$accounts = $this->rows('SELECT user_id,user_type,user_perm_from FROM ' . USERS_TABLE . " WHERE $users ORDER BY user_id FOR UPDATE");
		$members = $this->rows('SELECT group_id FROM ' . USER_GROUP_TABLE . " WHERE $users ORDER BY user_id,group_id LOCK IN SHARE MODE");
		$roles = $this->rows('SELECT auth_role_id FROM ' . ACL_USERS_TABLE . " WHERE $users AND forum_id=0 LOCK IN SHARE MODE");
		if ($members)
		{
			$groups = $this->db->sql_in_set('group_id', array_unique(array_column($members, 'group_id')));
			$this->rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE $groups ORDER BY group_id LOCK IN SHARE MODE");
			$roles = array_merge($roles, $this->rows('SELECT auth_role_id FROM ' . ACL_GROUPS_TABLE . " WHERE $groups AND forum_id=0 LOCK IN SHARE MODE"));
		}
		$roles = array_values(array_unique(array_filter(array_column($roles, 'auth_role_id'))));
		if ($roles) { $this->rows('SELECT role_id FROM ' . ACL_ROLES_DATA_TABLE . ' WHERE ' . $this->db->sql_in_set('role_id', $roles) . ' LOCK IN SHARE MODE'); }
		$this->rows('SELECT user_id FROM ' . BOTS_TABLE . " WHERE $users LOCK IN SHARE MODE");
		return array_column($accounts, null, 'user_id');
	}

	private function remove($policy, $id, $stamp)
	{
		$actor = $this->actor();
		$sid = $this->user->session_id;
		$this->db->sql_transaction('begin');
		try
		{
			$accounts = $this->lock_authority(array($actor, $id));
			$sessions = $this->rows('SELECT s.session_id,s.session_user_id,s.session_admin,' . $this->active_session() . ' AS still_active
				FROM ' . SESSIONS_TABLE . ' s WHERE ' . $this->db->sql_in_set('s.session_user_id', array($actor, $id)) .
				' ORDER BY s.session_user_id,s.session_id FOR UPDATE');
			$current = array_column($sessions, null, 'session_id')[$sid] ?? null;
			$account = $accounts[$actor] ?? null;
			if (!$current || (int) $current['session_user_id'] !== $actor || !$current['session_admin'] || !$current['still_active'] ||
				!$account || $account['user_perm_from'] ||
				!in_array((int) $account['user_type'], array(USER_FOUNDER, USER_NORMAL), true)) { throw new AccessDeniedHttpException(); }
			if ((int) $account['user_type'] !== USER_FOUNDER)
			{
				$rights = $this->auth->acl_raw_data($actor, array('a_', 'a_userdel'), 0)[$actor][0] ?? array();
				if ((int) ($rights['a_userdel'] ?? ACL_NO) !== ACL_YES || (int) ($rights['a_'] ?? ACL_NO) === ACL_NEVER) { throw new AccessDeniedHttpException(); }
			}
			$eligible = self::without_admin_grants($this->auth, $this->rows($this->query($policy, 'u.user_id=' . $id)));
			$row = $eligible[$id] ?? null;
			if ($row && hash_equals($stamp, $this->stamp($row)))
			{
				include_once $this->root . 'includes/functions_user.' . $this->ext;
				user_delete('retain', array($id), true);
				if ($this->rows('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . $id)) { throw new \RuntimeException('Native account deletion did not complete.'); }
				$this->log->add('admin', $actor, $this->user->ip, 'LOG_PLUS_ACCOUNT_PRUNED', false, array($row['username'], $id));
			}
			else { $row = null; }
			$this->db->sql_transaction('commit');
			return $row;
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
	}

	public function apply($policy, $selected, $notify)
	{
		$removed = array();
		$skipped = $mails = 0;
		$interrupted = false;
		foreach ($selected as $id => $stamp)
		{
			try { $row = $this->remove($policy, (int) $id, $stamp); }
			catch (AccessDeniedHttpException $e)
			{
				if (!$removed && !$skipped) { throw $e; }
				$interrupted = true;
				break;
			}
			if (!$row) { $skipped++; continue; }
			$removed[$id] = $row['username'];
			if ($notify && $this->config['email_enable'] && $row['user_email'] !== '' && !$this->notify($row)) { $mails++; }
		}
		return array($removed, $skipped, $mails, $interrupted);
	}

	private function notify($row)
	{
		try
		{
			include_once $this->root . 'includes/functions_messenger.' . $this->ext;
			$mail = new \messenger(false);
			$mail->template('@phpbb2plus_community/plus_pruned_account', substr($row['user_lang'], 0, 2) === 'de' ? 'de' : 'en');
			$mail->to($row['user_email']);
			$mail->assign_vars(array('USERNAME' => htmlspecialchars_decode($row['username'], ENT_QUOTES)));
			return (bool) $mail->send(NOTIFY_EMAIL);
		}
		catch (\Throwable $e) { return false; }
	}
}
