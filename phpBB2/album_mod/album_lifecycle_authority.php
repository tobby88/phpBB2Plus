<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/album_file_coordinator.php';

// Public Album authority. ACP mutations additionally require their current
// delegated module grant and authenticated administration session.
class AlbumLifecycleAuthority
{
	private $storage;
	private $prefix;
	private $locked = false;
	private $expires = null;

	function __construct($storage, $prefix)
	{
		if (!($storage instanceof AlbumFileCoordinator) || !$storage->namespace_matches($prefix)) { throw new AlbumFileCoordinatorFailure('Invalid Album authority namespace'); }
		foreach (array('USERS_TABLE'=>'users','SESSIONS_TABLE'=>'sessions','USER_GROUP_TABLE'=>'user_group','CONFIG_TABLE'=>'config','ALBUM_CAT_TABLE'=>'album_cat','ALBUM_CONFIG_TABLE'=>'album_config') as $constant => $suffix)
		{
			if (!defined($constant) || constant($constant) !== $prefix . $suffix) { throw new AlbumFileCoordinatorFailure('Album permission tables differ from owned namespace'); }
		}
		$this->storage = $storage; $this->prefix = $prefix;
	}

	private static function integer($value, $minimum, $maximum)
	{
		if ((!is_int($value) && !is_string($value)) || !preg_match('/^(?:0|[1-9][0-9]*|-1)$/D', (string) $value) || (float) $value < $minimum || (float) $value > $maximum) { return false; }
		return (int) $value;
	}

	private function rows($sql)
	{
		$result = $this->storage->sql_query($sql);
		try { $rows = $this->storage->sql_fetchrowset($result); }
		finally { $this->storage->sql_freeresult($result); }
		if (!is_array($rows)) { $this->storage->abort(); throw new AlbumFileCoordinatorFailure('Current Album authority rows unavailable'); }
		return $rows;
	}

	function current()
	{
		return $this->locked && $this->expires !== null && $this->expires > time() && $this->storage->publication_ready();
	}

	private function admin_allowed($fresh, $module)
	{
		global $phpEx;
		if (!in_array($module, array('admin_album_cat', 'admin_album_clearcache'), true)) { return false; }
		if (!$fresh['session_logged_in'] || $fresh['session_admin'] !== 1) { return false; }
		if ($fresh['user_level'] === ADMIN) { return true; }
		if (!defined('JR_ADMIN_TABLE') || JR_ADMIN_TABLE !== $this->prefix . 'jr_admin_users' || !is_string($phpEx) || !preg_match('/^[a-z0-9]{1,8}$/D',$phpEx)) { return false; }
		$this->storage->pin_authority_table('jr_admin_users');
		$grants = $this->rows('SELECT user_jr_admin FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . $fresh['user_id'] . ' LOCK IN SHARE MODE');
		if (count($grants) !== 1 || !isset($grants[0]['user_jr_admin']) || !is_string($grants[0]['user_jr_admin'])) { return false; }
		require_once dirname(__DIR__) . '/includes/functions_jr_admin.php';
		$routes = jr_admin_authorization_routes();
		if (!is_array($routes)) { return false; }
		// Internal exact module, never a posted filename or claimed module hash.
		$route = $module . '.' . $phpEx;
		foreach (explode(EXPLODE_SEPERATOR_CHAR,$grants[0]['user_jr_admin']) as $hash)
		{
			if (isset($routes[$hash]) && jr_admin_route_matches_file($routes[$hash],$route)) { return true; }
		}
		return false;
	}

	function lock_admin($snapshot, $category)
	{
		return $this->lock($snapshot, 0, $category, ALBUM_AUTH_MODERATOR, true);
	}

	function lock_cache_admin($snapshot)
	{
		return $this->lock_context($snapshot, 0, null, ALBUM_AUTH_MODERATOR, true, true);
	}

	function lock($snapshot, $owner, $category, $checks, $administration = false)
	{
		return $this->lock_context($snapshot, $owner, $category, $checks, $administration, false);
	}

	private function lock_context($snapshot, $owner, $category, $checks, $administration, $cache_management)
	{
		global $db, $userdata, $album_config, $album_data;
		if ($this->locked || !$this->storage->publication_ready() || !is_array($snapshot) || !is_int($owner) || $owner < 0 || $owner > 8388607 ||
			($cache_management ? ($category !== null || $owner !== 0 || $administration !== true) : (!is_int($category) || $category < 1 || $category > 16777215)) ||
			!is_int($checks) || $checks < 1 || ($checks & ~ALBUM_AUTH_ALL) !== 0 || !is_bool($administration) || ($administration && $owner !== 0) || !isset($snapshot['user_id'], $snapshot['session_id'], $snapshot['session_logged_in'])) { return $this->storage->abort(); }
		$actor_id = self::integer($snapshot['user_id'], -1, 8388607); $sid = $snapshot['session_id'];
		$logged = in_array($snapshot['session_logged_in'], array(true,1,'1'), true);
		if ($actor_id === false || $actor_id === 0 || !is_string($sid) || !preg_match('/^[a-f0-9]{32}$/iD', $sid) ||
			($actor_id === -1 ? !in_array($snapshot['session_logged_in'], array(false,0,'0'), true) : !$logged)) { return $this->storage->abort(); }
		try
		{
			// Lock only this main-config key, before actor/session rows. The board
			// config writer uses that order for its update and final authority locks.
			$this->storage->pin_authority_table('config');
			$lifetime = $this->rows('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='session_length' LOCK IN SHARE MODE");
			if (count($lifetime) !== 1 || !isset($lifetime[0]['config_value']) || ($seconds = self::integer($lifetime[0]['config_value'], 1, 2147483647)) === false) { return $this->storage->abort(); }
			$ids = array($actor_id); if ($owner > 0 && $owner !== $actor_id) { $ids[] = $owner; } sort($ids, SORT_NUMERIC);
			$accounts = $this->rows('SELECT user_id,username,user_active,user_level FROM ' . USERS_TABLE . ' WHERE user_id IN(' . implode(',', $ids) . ') ORDER BY user_id FOR UPDATE');
			$found = array(); $actor = null;
			foreach ($accounts as $account)
			{
				if (!isset($account['user_id'], $account['username'], $account['user_active'], $account['user_level']) || ($id = self::integer($account['user_id'], -1, 8388607)) === false || !in_array($id, $ids, true) || isset($found[$id])) { return $this->storage->abort(); }
				$found[$id] = true; if ($id === $actor_id) { $actor = $account; }
			}
			if (count($found) !== count($ids) || !$actor || !is_string($actor['username']) || !preg_match('//u', $actor['username']) ||
				($logged && (self::integer($actor['user_active'], 1, 1) !== 1 || self::integer($actor['user_level'], 0, 2) === false))) { return $this->storage->abort(); }
			$sessions = $this->rows('SELECT session_id,session_user_id,session_logged_in,session_time,session_ip,session_admin FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)='" . strtoupper(bin2hex($sid)) . "' FOR UPDATE");
			if (count($sessions) !== 1) { return $this->storage->abort(); } $session = $sessions[0];
			foreach (array('session_id','session_user_id','session_logged_in','session_time','session_ip','session_admin') as $field) { if (!array_key_exists($field, $session)) { return $this->storage->abort(); } }
			$session_time = self::integer($session['session_time'], 1, 4294967295);
			if ($session['session_id'] !== $sid || self::integer($session['session_user_id'], -1, 8388607) !== $actor_id ||
				self::integer($session['session_logged_in'], 0, 1) !== ($logged ? 1 : 0) || $session_time === false || $session_time <= time() - $seconds ||
				!is_string($session['session_ip']) || !preg_match('/^[a-f0-9]{8}$/iD', $session['session_ip']) || self::integer($session['session_admin'], 0, 1) === false) { return $this->storage->abort(); }
			if ($logged) { $this->rows('SELECT group_id,user_pending FROM ' . USER_GROUP_TABLE . ' WHERE user_id=' . $actor_id . ' ORDER BY group_id LOCK IN SHARE MODE'); }
			$settings = array();
			foreach ($this->rows('SELECT config_name,config_value FROM ' . ALBUM_CONFIG_TABLE . ' ORDER BY config_name LOCK IN SHARE MODE') as $setting)
			{
				if (!isset($setting['config_name'], $setting['config_value']) || !is_string($setting['config_name']) || !is_string($setting['config_value']) || isset($settings[$setting['config_name']])) { return $this->storage->abort(); }
				$settings[$setting['config_name']] = $setting['config_value'];
			}
			foreach (array('rate','comment','personal_gallery','personal_gallery_view','personal_gallery_private','personal_allow_gallery_mod','personal_allow_sub_categories','personal_sub_category_limit') as $name) { if (!isset($settings[$name])) { return $this->storage->abort(); } }
			if ((($checks & ALBUM_AUTH_RATE) !== 0 && $settings['rate'] !== '1') || (($checks & ALBUM_AUTH_COMMENT) !== 0 && $settings['comment'] !== '1')) { return $this->storage->abort(); }
			$categories = $cache_management ? array(null) : $this->rows('SELECT * FROM ' . ALBUM_CAT_TABLE . ' WHERE cat_id=' . $category . ' AND cat_user_id=' . $owner . ' LOCK IN SHARE MODE');
			if (count($categories) !== 1) { return $this->storage->abort(); }
			$fresh = $snapshot; $fresh['user_id'] = $actor_id; $fresh['username'] = $actor['username']; $fresh['user_level'] = $logged ? (int) $actor['user_level'] : 0; $fresh['user_active'] = $logged ? 1 : 0;
			$fresh['session_id'] = $sid; $fresh['session_logged_in'] = $logged; $fresh['session_time'] = $session_time; $fresh['session_ip'] = $session['session_ip']; $fresh['session_admin'] = (int) $session['session_admin'];
			$original = array($db, $userdata, $album_config, $album_data);
			try
			{
				$db = $this->storage; $userdata = $fresh; $album_config = $settings; $album_data = array();
				if ($administration)
				{
					$allowed = $this->admin_allowed($fresh, $cache_management ? 'admin_album_clearcache' : 'admin_album_cat'); $access = $allowed ? array('manage'=>1) : array();
				}
				else
				{
					// Personal moderation depends on the owner's upload policy; only
					// enforce the actual action's requested grants after calculating it.
					$permission_checks = $owner > 0 ? $checks | ALBUM_AUTH_UPLOAD : $checks;
					$access = album_permissions($owner, $category, $permission_checks, $categories[0]);
					$allowed = album_check_permission($access, $checks);
				}
			}
			finally { $db = $original[0]; $userdata = $original[1]; $album_config = $original[2]; $album_data = $original[3]; }
			if (!$allowed) { return $this->storage->abort(); }
			$this->expires = $session_time + $seconds; $this->locked = true;
			$this->storage->guard_commit(array($this, 'current'));
			return array('userdata' => $fresh, 'settings' => $settings, 'category' => $categories[0], 'access' => $access);
		}
		catch (Exception $error) { $this->storage->abort(); throw $error; }
		catch (Error $error) { $this->storage->abort(); throw $error; }
	}
}
