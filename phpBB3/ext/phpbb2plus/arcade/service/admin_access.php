<?php
namespace phpbb2plus\arcade\service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class admin_access
{
	private const MODULES = array('categories'=>'a_plus_arc_categories','diagnostics'=>'a_plus_arc_log','games'=>'a_plus_arc_games','add_game'=>'a_plus_arc_add');
	private $db;
	private $auth;
	private $user;
	private $delegations;

	public function __construct($db, $auth, $user, $delegations)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->delegations = $delegations;
	}

	public function authorize($module, $lock = false)
	{
		$option = self::MODULES[$module];
		$data = $this->user->data;
		if (!defined('IN_ADMIN') || !IN_ADMIN || empty($data['session_admin']) || empty($data['is_registered']) ||
			!empty($data['user_perm_from']) || !$this->auth->acl_get($option)) { throw new AccessDeniedHttpException(); }
		if (!$lock) { return; }
		$id = (int) $data['user_id'];
		$sid = $this->db->sql_escape($this->user->session_id);
		$result = $this->db->sql_query('SELECT u.* FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE . " s ON u.user_id=s.session_user_id
			WHERE u.user_id=$id AND s.session_id='$sid' AND s.session_admin=1 LOCK IN SHARE MODE");
		$user = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$user || $user['user_perm_from'] || !in_array((int) $user['user_type'], array(USER_NORMAL, USER_FOUNDER), true))
		{ throw new AccessDeniedHttpException(); }
		if ((int) $user['user_type'] === USER_FOUNDER) { return; }
		$auth = clone $this->auth;
		if (!empty($user['plus_jr_delegate']) && method_exists($auth, 'delegated_module'))
		{
			$result = $this->db->sql_query("SELECT user_id FROM {$this->delegations} WHERE user_id=$id LOCK IN SHARE MODE");
			$this->db->sql_freeresult($result);
		}
		$states = $auth->acl_raw_data($id, array('a_', $option), 0)[$id][0] ?? array();
		$right = (int) ($states[$option] ?? ACL_NO);
		if ((int) ($states['a_'] ?? ACL_NO) !== ACL_NEVER && $right !== ACL_NEVER)
		{
			if ($right === ACL_YES) { return; }
			$user['is_registered'] = true;
			$auth->acl($user);
			if (method_exists($auth, 'delegated_module') && $auth->delegated_module('phpbb2plus\\arcade\\acp\\' . $module . '_module', 'manage')) { return; }
		}
		throw new AccessDeniedHttpException();
	}
}
