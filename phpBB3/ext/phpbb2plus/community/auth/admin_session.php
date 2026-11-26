<?php
namespace phpbb2plus\community\auth;

class admin_session
{
	public static function allows($db, $auth, $user, $permission, $reload = false)
	{
		if (!defined('IN_ADMIN') || !IN_ADMIN || empty($user->data['is_registered']) || empty($user->data['session_admin']) ||
			!empty($user->data['user_perm_from'])) { return false; }
		if ($reload)
		{
			$result = $db->sql_query('SELECT u.*, s.session_admin FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE .
				" s ON s.session_user_id=u.user_id WHERE s.session_id='" . $db->sql_escape($user->session_id) .
				"' AND u.user_id=" . (int) $user->data['user_id']);
			$actor = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);
			if (!$actor || !$actor['session_admin'] || $actor['user_perm_from'] ||
				!in_array((int) $actor['user_type'], array(USER_NORMAL, USER_FOUNDER), true)) { return false; }
			$actor['is_registered'] = true;
			$actor['is_bot'] = false;
			$auth->acl($actor);
		}
		return $auth->acl_get($permission);
	}
}
