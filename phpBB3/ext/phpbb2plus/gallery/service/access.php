<?php
namespace phpbb2plus\gallery\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class access
{
	private $db;
	private $user;
	private $auth;
	private $prefix;
	private $settings;
	private $grants;
	private $categories = array();

	public function __construct($db, $user, $auth, $prefix)
	{
		$this->db = $db;
		$this->user = $user;
		$this->auth = $auth;
		$this->prefix = $prefix;
	}

	public function setting($name, $default, $scope = 'album')
	{
		if ($this->settings === null)
		{
			$this->settings = array();
			$result = $this->db->sql_query('SELECT scope,setting_name,setting_value FROM ' . $this->prefix . 'settings');
			while ($row = $this->db->sql_fetchrow($result)) { $this->settings[$row['scope']][$row['setting_name']] = $row['setting_value']; }
			$this->db->sql_freeresult($result);
		}
		return $this->settings[$scope][$name] ?? $default;
	}

	public function refresh()
	{
		$this->settings = $this->grants = null;
		$this->categories = array();
	}

	/** Serialize gallery writes, including quotas shared by all of a member's subalbums. */
	public function write(callable $operation)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$this->db->sql_query('UPDATE ' . $this->prefix . 'writes SET lock_id=1 WHERE lock_id=1');
			$result = $this->db->sql_query('SELECT lock_id FROM ' . $this->prefix . 'writes WHERE lock_id=1');
			$locked = $this->db->sql_fetchfield('lock_id');
			$this->db->sql_freeresult($result);
			if ((int) $locked !== 1) { throw new \RuntimeException('Gallery write coordinator missing.'); }
			$this->refresh();
			$value = $operation();
			$this->db->sql_transaction('commit');
			return $value;
		}
		catch (\Throwable $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}

	public function may_change($category, $author, $action)
	{
		return !empty($this->user->data['is_registered']) && $this->allows($category, $action) &&
			((int) $author === (int) $this->user->data['user_id'] || $this->moderates($category));
	}

	public function may_discuss($picture, $category, $action)
	{
		return empty($this->user->data['is_bot']) && (int) $this->setting($action, 1) === 1 && $this->allows($category, $action) &&
			(!(int) $picture['locked'] || $this->moderates($category));
	}

	public function administrator()
	{
		return !empty($this->user->data['is_registered']) && $this->auth->acl_get('a_plus_gallery');
	}

	public function watermark_strength()
	{
		if ((int) $this->setting('use_watermark', 0, 'sp') !== 1) { return 0; }
		$forum = array('m_edit','m_delete','m_lock','m_approve','m_move','m_split','m_merge');
		$staff = !empty($this->user->data['is_registered']) &&
			($this->administrator() || $this->auth->acl_gets($forum) || $this->auth->acl_getf_global($forum));
		if (!$staff) { return 50; }
		return (int) $this->setting('wut_users', 0, 'sp') === 1 ? 70 : 0;
	}

	private function granted($category, $action)
	{
		if (empty($this->user->data['is_registered'])) { return false; }
		if ($this->grants === null)
		{
			$this->grants = array();
			$id = (int) $this->user->data['user_id'];
			$result = $this->db->sql_query('SELECT g.cat_id,g.capability FROM ' . $this->prefix . "grants g
				WHERE (g.subject_type='u' AND g.subject_id=$id) OR (g.subject_type='g' AND g.subject_id IN
				(SELECT group_id FROM " . USER_GROUP_TABLE . " WHERE user_id=$id AND user_pending=0))");
			while ($row = $this->db->sql_fetchrow($result)) { $this->grants[(int) $row['cat_id']][$row['capability']] = true; }
			$this->db->sql_freeresult($result);
		}
		return isset($this->grants[$category['cat_id']][$action]);
	}

	private function owner($category)
	{
		return !empty($this->user->data['is_registered']) && (int) $category['owner_id'] === (int) $this->user->data['user_id'];
	}

	private function owner_upload($category)
	{
		if (!$this->owner($category) || (int) $this->setting('personal_gallery', 0) === 1) { return false; }
		return in_array((int) $category['upload_level'], array(-1, 0, 3), true);
	}

	public function moderates($category)
	{
		return $this->administrator() || $this->granted($category, 'moderator') ||
			($this->owner_upload($category) && (int) $this->setting('personal_allow_gallery_mod', 1) === 1);
	}

	public function allows($category, $action)
	{
		if (!in_array($action, array('view','upload','rate','comment','edit','delete'), true)) { return false; }
		$level = (int) $category[$action . '_level'];
		if ($this->administrator()) { return true; }
		if (!in_array($level, array(-1,0,2,3), true)) { return false; }
		if ($action === 'upload' && $category['owner_id'] && (int) $this->setting('personal_gallery', 0) === 1) { return false; }
		if ($this->moderates($category)) { return true; }
		if ($action === 'upload' && $level === 3 && $this->owner_upload($category)) { return true; }
		if (empty($this->user->data['is_registered'])) { return $level === -1 && $action !== 'edit' && $action !== 'delete'; }
		return $level === -1 || $level === 0 || ($level === 3 && $this->granted($category, $action));
	}

	public function category_row($id)
	{
		$id = (int) $id;
		if (!array_key_exists($id, $this->categories))
		{
			$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'cats WHERE cat_id=' . $id);
			$this->categories[$id] = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
		}
		return $this->categories[$id];
	}

	public function visible($category)
	{
		$seen = array();
		while ($category)
		{
			$id = (int) $category['cat_id'];
			if (isset($seen[$id]) || !$this->allows($category, 'view')) { return false; }
			if (!(int) $category['parent_id']) { return true; }
			$seen[$id] = true;
			$category = $this->category_row($category['parent_id']);
		}
		return false;
	}

	public function portal_categories($personal, $selected)
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'cats');
		while ($row = $this->db->sql_fetchrow($result)) { $this->categories[(int) $row['cat_id']] = $row; }
		$this->db->sql_freeresult($result);
		$allowed = array();
		foreach ($this->categories as $id => $category)
		{
			if (!$category || (!$personal && ($category['source_owner'] || $category['owner_id'])) || ($selected && !isset($selected[$id]))) { continue; }
			if ($this->visible($category)) { $allowed[] = $id; }
		}
		return $allowed;
	}

	public function category($id)
	{
		$category = $this->category_row($id);
		if (!$this->visible($category)) { throw new NotFoundHttpException(); }
		return $category;
	}

	public function pending_visible($category)
	{
		$policy = (int) $category['approval'];
		return $policy === 0 || $this->administrator() || ($policy === 2 && $this->moderates($category));
	}

	public function picture($id)
	{
		$result = $this->db->sql_query('SELECT p.*,u.username,u.user_colour FROM ' . $this->prefix . 'pics p
			LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.author_id WHERE p.pic_id=' . (int) $id);
		$picture = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$picture) { throw new NotFoundHttpException(); }
		$category = $this->category($picture['cat_id']);
		if (!(int) $picture['approved'] && !$this->pending_visible($category)) { throw new NotFoundHttpException(); }
		return array($picture, $category);
	}
}
