<?php
namespace phpbb2plus\downloads\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class access
{
	const ACTIONS = array('view','read','view_file','edit_file','delete_file','upload','download','rate','email',
		'view_comment','post_comment','edit_comment','delete_comment','mod','search','stats','toplist','viewall');

	private $db;
	private $auth;
	private $user;
	private $prefix;
	private $settings;
	private $categories;
	private $grants;

	public function __construct($db, $auth, $user, $prefix)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->prefix = $prefix;
	}

	public function refresh() { $this->settings = $this->categories = $this->grants = null; }

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

	public function open()
	{
		if ((int) $this->setting('settings_disable', 0) && !$this->admin()) { throw new NotFoundHttpException(); }
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
		$result = $this->db->sql_query('SELECT g.cat_id,g.allowed FROM ' . $this->prefix . 'grants g LEFT JOIN ' . USER_GROUP_TABLE .
			" ug ON g.subject_type='g' AND ug.group_id=g.subject_id AND ug.user_id=$id AND ug.user_pending=0
			WHERE (g.subject_type='u' AND g.subject_id=$id) OR ug.user_id IS NOT NULL");
		while ($r = $this->db->sql_fetchrow($result))
		{
			foreach (json_decode($r['allowed'], true) ?: array() as $key)
			{ $this->grants[(int) $r['cat_id']][$key] = true; }
		}
		$this->db->sql_freeresult($result);
		return $this->grants;
	}

	public function allows($id, $key)
	{
		if (!in_array($key, self::ACTIONS, true)) { return false; }
		$cats = $this->categories();
		if ($id && !isset($cats[$id])) { return false; }
		if ($this->admin()) { return true; }
		$level = $id ? ($cats[$id]['levels'][$key] ?? -1) : (int) $this->setting('auth_' . $key, 5);
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

	public function visible($id, $files = false)
	{
		$this->open();
		$cats = $this->categories();
		if ($id < 1 || !isset($cats[$id]) || ($files && (!$cats[$id]['allow_files'] || !$this->allows($id, 'view_file')))) { return false; }
		$seen = array();
		while ($id)
		{
			if (isset($seen[$id]) || !isset($cats[$id]) || !$this->allows($id, 'view') || !$this->allows($id, 'read')) { return false; }
			$seen[$id] = true;
			$id = (int) $cats[$id]['parent_id'];
		}
		return true;
	}

	public function file($id)
	{
		$this->open();
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'files WHERE file_id=' . (int) $id);
		$file = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$file || !$this->visible((int) $file['cat_id'], true) || (!$file['approved'] && !$this->allows((int) $file['cat_id'], 'mod') &&
			(empty($this->user->data['is_registered']) || (int) $file['owner_id'] !== (int) $this->user->data['user_id']))) { throw new NotFoundHttpException(); }
		return $file;
	}

	public static function plain($value) { return html_entity_decode((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

	public function write($id, callable $operation)
	{
		$id = (int) $id;
		$this->db->sql_transaction('begin');
		try
		{
			$this->db->sql_query('UPDATE ' . $this->prefix . "files SET file_id=file_id WHERE file_id=$id");
			$this->refresh();
			$result = $operation($this->file($id));
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
