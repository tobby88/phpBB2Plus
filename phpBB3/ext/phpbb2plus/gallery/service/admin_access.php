<?php
namespace phpbb2plus\gallery\service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class admin_access
{
	private $db;
	private $auth;
	private $user;

	public function __construct($db, $auth, $user)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
	}

	/** A fresh check distinguishes a native grant (true) from the exact migrated ACP module (false). */
	public function authorize($module, $write = false)
	{
		$options = array('categories' => 'a_plus_album_categories', 'permissions' => 'a_plus_album_auth',
			'personal_permissions' => 'a_plus_album_personal_auth', 'cache' => 'a_plus_album_cache', 'settings' => 'a_plus_gallery');
		$option = $options[$module];
		if (!defined('IN_ADMIN') || !IN_ADMIN || empty($this->user->data['is_registered']) ||
			empty($this->user->data['session_admin']) || !empty($this->user->data['user_perm_from']) ||
			!$this->auth->acl_gets('a_plus_gallery', $option)) { throw new AccessDeniedHttpException(); }
		if ($write) { return $this->fresh($option, $module); }
	}

	private function fresh($option, $module)
	{
		$actor = (int) $this->user->data['user_id'];
		$sid = $this->db->sql_escape($this->user->session_id);
		// Older installations and boards without the optional Junior Admin migration have no delegate marker.
		$marker = array_key_exists('plus_jr_delegate', $this->user->data) ? ',u.plus_jr_delegate' : '';
		$result = $this->db->sql_query('SELECT u.user_id,u.user_type,u.user_perm_from,u.user_permissions' . $marker . ' FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE .
			" s ON s.session_user_id=u.user_id WHERE u.user_id=$actor AND s.session_id='$sid' AND s.session_admin=1");
		$account = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$account || (int) $account['user_perm_from'] || (int) $account['user_type'] !== (int) $this->user->data['user_type'] ||
			!in_array((int) $account['user_type'], array(USER_NORMAL, USER_FOUNDER), true))
		{ throw new AccessDeniedHttpException(); }
		if ((int) $account['user_type'] === USER_FOUNDER) { return true; }
		$permissions = $this->auth->acl_raw_data($actor, array('a_', 'a_plus_gallery', $option), 0);
		$row = $permissions[$actor][0] ?? array();
		if ((int) ($row['a_'] ?? ACL_NO) === ACL_NEVER) { throw new AccessDeniedHttpException(); }
		$narrow = (int) ($row[$option] ?? ACL_NO);
		if ((int) ($row['a_plus_gallery'] ?? ACL_NO) === ACL_YES || $narrow === ACL_YES) { return true; }
		if ($option !== 'a_plus_gallery' && $narrow !== ACL_NEVER && !empty($account['plus_jr_delegate']) && method_exists($this->auth, 'delegated_module'))
		{
			// A migrated module grant exists only for this ACP request. Never materialize it in native ACL tables.
			$account['is_registered'] = true;
			$this->auth->acl($account);
			if ($this->auth->delegated_module('phpbb2plus\\gallery\\acp\\' . $module . '_module', 'manage')) { return false; }
		}
		throw new AccessDeniedHttpException();
	}
}
