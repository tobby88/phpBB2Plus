<?php
namespace phpbb2plus\downloads\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class access
{
	const ACTIONS = array('view','read','view_file','edit_file','delete_file','upload','download','rate','email',
		'view_comment','post_comment','edit_comment','delete_comment','mod','search','stats','toplist','viewall');
	const GLOBAL_ACTIONS = array('search','stats','toplist','viewall');
	const LEVELS = array(0,1,2,3,5);

	public static function category_actions() { return array_diff(self::ACTIONS,self::GLOBAL_ACTIONS,array('mod')); }

	public static function level($value)
	{
		if (!is_int($value) && !is_string($value)) { return null; }
		$level = (int) $value;
		return (string) $level === (string) $value && in_array($level,self::LEVELS,true) ? $level : null;
	}

	public static function flags($encoded)
	{
		$flags = json_decode($encoded);
		if (!is_array($flags)) { return null; }
		foreach ($flags as $flag) { if (!is_string($flag)) { return null; } }
		return $flags;
	}

	private $db;
	private $auth;
	private $user;
	private $prefix;
	private $settings;
	private $categories;
	private $directory;
	private $grants;

	public function __construct($db, $auth, $user, $prefix)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->prefix = $prefix;
	}

	public function refresh() { $this->settings = $this->categories = $this->directory = $this->grants = null; }

	public function setting($key, $fallback)
	{
		if ($this->settings === null)
		{
			$this->settings = array();
			$result = $this->db->sql_query('SELECT config_name,config_value FROM ' . $this->prefix . 'settings');
			while ($r = $this->db->sql_fetchrow($result)) { $this->settings[$r['config_name']] = $r['config_value']; }
			$this->db->sql_freeresult($result);
		}
		return $this->settings[$key] ?? $fallback;
	}

	public function admin() { return !empty($this->user->data['is_registered']) && $this->auth->acl_get('a_plus_downloads'); }

	public function catalogue_admin()
	{
		return defined('IN_ADMIN') && IN_ADMIN && !empty($this->user->data['session_admin']) &&
			$this->auth->acl_gets('a_plus_downloads', 'a_plus_dl_categories');
	}

	public function permissions_admin($fresh = false)
	{
		return $this->acp_admin('a_plus_dl_perms',$fresh);
	}

	public function settings_admin($fresh = false)
	{
		return $this->acp_admin('a_plus_dl_settings',$fresh);
	}

	public function checker_admin($fresh = false)
	{
		return $this->acp_admin('a_plus_dl_checker', $fresh);
	}

	public function license_admin($fresh = false)
	{
		return $this->acp_admin('a_plus_dl_licenses',$fresh);
	}

	public function field_admin($fresh = false)
	{
		return $this->acp_admin('a_plus_dl_fields',$fresh);
	}

	public function mirror_admin($fresh = false)
	{
		return $this->acp_admin('a_plus_dl_mirrors',$fresh);
	}

	public function file_admin($action, $fresh = false)
	{
		if ($action === 'edit') { return $this->acp_admin('a_plus_dl_edit',$fresh); }
		if ($action === 'delete') { return $this->acp_admin('a_plus_dl_delete',$fresh); }
		return in_array($action,array('approve','withdraw'),true) && $this->acp_admin('a_plus_dl_approval',$fresh);
	}

	private function acp_admin($permission, $fresh)
	{
		if (!defined('IN_ADMIN') || !IN_ADMIN || empty($this->user->data['is_registered']) || empty($this->user->data['session_admin']) ||
			!empty($this->user->data['user_perm_from']))
		{ return false; }
		if ($fresh)
		{
			// Load the native session and current ACL after acquiring the catalogue lock.
			// A delegated administrator may have lost the grant since opening the form.
			$result = $this->db->sql_query('SELECT u.*,s.session_admin FROM ' .
				USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE . " s ON s.session_user_id=u.user_id WHERE s.session_id='" .
				$this->db->sql_escape($this->user->session_id) . "' AND u.user_id=" . (int) $this->user->data['user_id']);
			$actor = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if (!$actor || !$actor['session_admin'] || $actor['user_perm_from'] || (int) $actor['user_type'] !== (int) $this->user->data['user_type'] ||
				!in_array((int) $actor['user_type'],array(USER_NORMAL,USER_FOUNDER),true)) { return false; }
			// Native sessions derive these flags after loading the account. The optional
			// Junior Admin ACL decorator also needs its current extension fields from u.*.
			$actor['is_registered'] = true;
			$actor['is_bot'] = false;
			$this->auth->acl($actor);
		}
		return $this->auth->acl_gets('a_plus_downloads',$permission);
	}

	// Catalogue and permission changes serialize with operations on files.
	// On MySQL/MariaDB ordinary file operations share the stable lock row.
	public function lock_catalogue($exclusive)
	{
		$where = " WHERE config_name='plus_download_media_key'";
		if (in_array($this->db->get_sql_layer(), array('mysqli','mysql'), true))
		{
			$result = $this->db->sql_query('SELECT config_name FROM ' . CONFIG_TABLE . $where . ($exclusive ? ' FOR UPDATE' : ' LOCK IN SHARE MODE'));
			$this->db->sql_freeresult($result);
			return;
		}
		$this->db->sql_query('UPDATE ' . CONFIG_TABLE . ' SET config_value=config_value' . $where);
	}

	public function enabled() { return !(int) $this->setting('settings_disable', 0) || $this->admin(); }

	public function open()
	{
		if (!$this->enabled()) { throw new NotFoundHttpException(); }
	}

	public function categories()
	{
		if ($this->categories === null)
		{
			$this->categories = array();
			$result = $this->db->sql_query('SELECT cat_id,parent_id,position,title,summary,allow_files,allow_ratings,allow_comments,access_levels FROM ' .
				$this->prefix . 'categories ORDER BY position,cat_id');
			while ($r = $this->db->sql_fetchrow($result))
			{
				$r['levels'] = json_decode($r['access_levels'], true) ?: array();
				$this->categories[(int) $r['cat_id']] = $r;
			}
			$this->db->sql_freeresult($result);
		}
		return $this->categories;
	}

	private function grants()
	{
		if ($this->grants !== null) { return $this->grants; }
		$this->grants = array();
		if (empty($this->user->data['is_registered'])) { return $this->grants; }
		$id = (int) $this->user->data['user_id'];
		$result = $this->db->sql_query('SELECT g.cat_id,g.allowed FROM ' . $this->prefix . "grants g
			WHERE (g.subject_type='u' AND g.subject_id=$id) OR (g.subject_type='g' AND g.subject_id IN (" . $this->active_groups_sql($id) . '))');
		while ($r = $this->db->sql_fetchrow($result))
		{
			foreach (self::flags($r['allowed']) ?? array() as $key)
			{ $this->grants[(int) $r['cat_id']][$key] = true; }
		}
		$this->db->sql_freeresult($result);
		return $this->grants;
	}

	public function active_groups_sql($id)
	{
		return 'SELECT g.group_id FROM ' . GROUPS_TABLE . ' g JOIN ' . USER_GROUP_TABLE .
			' ug ON ug.group_id=g.group_id WHERE ug.user_pending=0 AND ug.user_id=' . (int) $id;
	}

	public function allows($id, $key)
	{
		if (!in_array($key, self::ACTIONS, true)) { return false; }
		$cats = $this->categories();
		if ($id && !isset($cats[$id])) { return false; }
		if ($this->admin()) { return true; }
		$level = self::level($id ? ($cats[$id]['levels'][$key] ?? null) : $this->setting('auth_' . $key,5));
		if ($key !== 'mod' && $level === 0) { return true; }
		if (empty($this->user->data['is_registered'])) { return false; }
		$grants = $this->grants();
		$mod = !empty($grants[$id]['mod']);
		if ($key === 'mod') { return $mod; }
		if (!$id && !$mod) { foreach ($grants as $g) { if (!empty($g['mod'])) { $mod = true; break; } } }
		if ($level === 1) { return true; }
		if ($level === 2) { return $mod || !empty($grants[$id][$key]); }
		return $level === 3 && $mod;
	}

	private function can_read($id)
	{
		return $this->allows($id, 'view') && $this->allows($id, 'read');
	}

	/** Readable category IDs in display order, with their depth from the root. */
	public function directory()
	{
		$this->open();
		if ($this->directory !== null) { return $this->directory; }
		$children = array();
		foreach ($this->categories() as $id=>$row)
		{
			if ($id > 0) { $children[(int) $row['parent_id']][] = $id; }
		}
		$this->directory = array();
		$pending = array(array(0,-1));
		while ($pending)
		{
			list($id,$depth) = array_pop($pending);
			if ($id)
			{
				if (!$this->can_read($id)) { continue; }
				$this->directory[$id] = $depth;
			}
			foreach (array_reverse($children[$id] ?? array()) as $child) { $pending[] = array($child,$depth+1); }
		}
		return $this->directory;
	}

	public function visible($id, $files = false)
	{
		$this->open();
		$cats = $this->categories();
		if ($id < 1 || !isset($cats[$id]) || ($files && (!$cats[$id]['allow_files'] || !$this->allows($id, 'view_file')))) { return false; }
		if ($this->directory !== null) { return isset($this->directory[$id]); }
		$seen = array();
		while ($id)
		{
			if (isset($seen[$id]) || !isset($cats[$id]) || !$this->can_read($id)) { return false; }
			$seen[$id] = true;
			$id = (int) $cats[$id]['parent_id'];
		}
		return true;
	}

	public function file($id, $with_publisher = false)
	{
		$this->open();
		$file = $this->file_row($id, $with_publisher);
		if (!$this->visible((int) $file['cat_id'], true) || (!$file['approved'] && !$this->allows((int) $file['cat_id'], 'mod') &&
			(empty($this->user->data['is_registered']) || (int) $file['owner_id'] !== (int) $this->user->data['user_id']))) { throw new NotFoundHttpException(); }
		return $file;
	}

	private function file_row($id, $with_publisher = false)
	{
		$columns = $with_publisher ? ',u.username,u.user_colour' : '';
		$join = $with_publisher ? ' LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=f.owner_id AND u.user_id>' . ANONYMOUS : '';
		$result = $this->db->sql_query('SELECT f.*' . $columns . ' FROM ' . $this->prefix . 'files f' . $join . ' WHERE f.file_id=' . (int) $id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row) { throw new NotFoundHttpException(); }
		return $row;
	}

	public function editor_file($id)
	{
		if (!$this->file_admin('edit')) { throw new \DomainException('NO_AUTH_OPERATION'); }
		return $this->file_row($id);
	}

	public function file_destination($id)
	{
		$cats = $this->categories();
		if ($id<=0 || empty($cats[$id]['allow_files'])) { return false; }
		$seen = array();
		while ($id)
		{
			if (isset($seen[$id]) || !isset($cats[$id])) { return false; }
			$seen[$id] = true;
			$id = (int) $cats[$id]['parent_id'];
		}
		return true;
	}

	public function can_submit($id)
	{
		$cats = $this->categories();
		return empty($this->user->data['is_bot']) && isset($cats[$id]) && $cats[$id]['allow_files'] &&
			$this->visible($id) && $this->allows($id, 'upload');
	}

	public function can_review($id)
	{
		return !empty($this->user->data['is_registered']) && $this->visible($id, true) &&
			($this->admin() || ($this->setting('validator', 'validator_admin') === 'validator_mod' && $this->allows($id, 'mod')));
	}

	public function can_manage($file, $action)
	{
		if (empty($this->user->data['is_registered']) || !in_array($action, array('edit_file', 'delete_file'), true)) { return false; }
		$category = (int) $file['cat_id'];
		return $this->visible($category, true) && ($this->allows($category, 'mod') ||
			((int) $file['owner_id'] === (int) $this->user->data['user_id'] && $this->allows($category, $action)));
	}

	public static function plain($value) { return html_entity_decode((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

	// paFileDB stored some strings literally, while our native writers escape
	// them before the phpBB UCR conversion. Preserve literal entity spellings.
	public static function raw_text($row, $column, $source_key, $escaped_flag)
	{
		$source = json_decode($row['source_json'],true);
		$value = $row[$column];
		if (!empty($source[$escaped_flag])) { return self::plain($value); }
		if (isset($source[$source_key]) && is_string($source[$source_key]) && utf8_encode_ucr($source[$source_key]) === $value)
		{ return $source[$source_key]; }
		return preg_replace_callback('/&#([0-9]{5,7});/',static function ($match) {
			$point = (int) $match[1];
			return $point >= 65536 && $point <= 1114111 ? utf8_chr($point) : $match[0];
		},$value);
	}

	public function lock_file($id)
	{
		$this->lock_catalogue(false);
		$id = (int) $id;
		$this->db->sql_query('UPDATE ' . $this->prefix . "files SET file_id=file_id WHERE file_id=$id");
		$this->refresh();
	}

	public function write($id, callable $operation, $admin_editor = false)
	{
		$id = (int) $id;
		$this->db->sql_transaction('begin');
		try
		{
			$this->lock_file($id);
			if ($admin_editor && !$this->file_admin('edit',true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
			$result = $operation($admin_editor ? $this->editor_file($id) : $this->file($id));
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('The download transaction did not commit.'); }
			return $result;
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
	}

	public static function external($url)
	{
		$url = (string) $url;
		$parts = parse_url($url);
		if (!$parts || !isset($parts['host'],$parts['scheme']) || isset($parts['user']) || isset($parts['pass']) ||
			!in_array(strtolower($parts['scheme']), array('https','http'), true) || preg_match('/[\x00-\x20\x7f\\\\]/', $url) ||
			filter_var($url, FILTER_VALIDATE_URL) === false) { return ''; }
		return $url;
	}
}
