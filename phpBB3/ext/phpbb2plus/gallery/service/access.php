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

	public function may_view_upload_ips()
	{
		// Legacy lists displayed addresses only to board administrators. A gallery delegation alone is insufficient.
		return $this->administrator() && empty($this->user->data['user_perm_from']) && $this->auth->acl_get('a_user');
	}

	public function check_moderator_session()
	{
		$id = (int) $this->user->data['user_id'];
		$sid = $this->db->sql_escape($this->user->session_id);
		$result = $this->db->sql_query('SELECT u.user_type,u.user_perm_from FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE .
			" s ON s.session_user_id=u.user_id WHERE u.user_id=$id AND s.session_id='$sid'");
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row || !in_array((int) $row['user_type'], array(USER_NORMAL, USER_FOUNDER), true) || !empty($row['user_perm_from']))
		{ throw new NotFoundHttpException(); }
		if ((int) $row['user_type'] !== USER_FOUNDER && $this->administrator())
		{
			$acl = $this->auth->acl_raw_data($id, array('a_', 'a_plus_gallery'), 0);
			$perms = $acl[$id][0] ?? array();
			if ((int) ($perms['a_'] ?? ACL_NO) === ACL_NEVER || (int) ($perms['a_plus_gallery'] ?? ACL_NO) !== ACL_YES)
			{ throw new NotFoundHttpException(); }
		}
	}

	public function may_create_personal($owner, $fresh = false)
	{
		if (empty($this->user->data['is_registered']) || !empty($this->user->data['is_bot']) || $owner <= ANONYMOUS) { return false; }
		$id = (int) $this->user->data['user_id'];
		if ($fresh)
		{
			$result = $this->db->sql_query('SELECT user_type,user_perm_from FROM ' . USERS_TABLE . " WHERE user_id=$id");
			$actor = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if (!$actor || (int) $actor['user_perm_from'] || !in_array((int) $actor['user_type'], array(USER_NORMAL, USER_FOUNDER), true)) { return false; }
			if ((int) $actor['user_type'] === USER_FOUNDER) { return true; }
			$rules = $this->auth->acl_raw_data($id, array('a_plus_gallery','u_plus_album_personal'), 0);
			$rules = $rules[$id][0] ?? array();
			if ((int) ($rules['a_plus_gallery'] ?? ACL_NO) === ACL_YES) { return true; }
			$selected = (int) ($rules['u_plus_album_personal'] ?? ACL_NO) === ACL_YES;
		}
		else
		{
			if ($this->administrator()) { return true; }
			$selected = $this->auth->acl_get('u_plus_album_personal');
		}
		if ($id !== (int) $owner) { return false; }
		$policy = (string) $this->setting('personal_gallery', '0');
		return $policy === '0' || ($policy === '3' && $selected);
	}

	public function may_manage_personal($category)
	{
		return (int) $category['owner_id'] > ANONYMOUS && ($this->administrator() ||
			($this->owner($category) && $this->visible($category) && $this->allows($category, 'upload') &&
				(string) $this->setting('personal_allow_gallery_mod', 1) === '1' &&
				(string) $this->setting('personal_allow_sub_categories', 1) === '1' &&
				(int) $this->setting('personal_sub_category_limit', 10) !== 0));
	}

	public static function encode_label($text) { return utf8_encode_ucr(utf8_htmlspecialchars($text)); }

	public static function category_labels($row)
	{
		if ($row && $row['label_encoded'])
		{
			foreach (array('title','description') as $field) { $row[$field] = html_entity_decode($row[$field], ENT_QUOTES, 'UTF-8'); }
		}
		return $row;
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
			$this->categories[$id] = self::category_labels($this->db->sql_fetchrow($result));
			$this->db->sql_freeresult($result);
		}
		return $this->categories[$id];
	}

	public function remember_category($row)
	{
		$row = self::category_labels($row);
		$this->categories[(int) $row['cat_id']] = $row;
		return $row;
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
		while ($row = $this->db->sql_fetchrow($result)) { $this->categories[(int) $row['cat_id']] = self::category_labels($row); }
		$this->db->sql_freeresult($result);
		$allowed = array();
		foreach ($this->categories as $id => $category)
		{
			if (!$category || (!$personal && $this->personal_category($category)) || ($selected && !isset($selected[$id]))) { continue; }
			if ($this->visible($category)) { $allowed[] = $id; }
		}
		return $allowed;
	}

	public function personal_category($category)
	{
		return $category['owner_id'] || $category['source_owner'] || $category['legacy_guest_root'];
	}

	public function visible_subtree($root)
	{
		$id = (int) $root['cat_id'];
		$owner = (int) $root['owner_id'];
		$scope = 'owner_id=' . $owner;
		if ($owner <= ANONYMOUS) { $scope .= ' AND source_owner=' . (int) $root['source_owner']; }
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'cats WHERE ' . $scope);
		$children = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$child = (int) $row['cat_id'];
			$this->categories[$child] = self::category_labels($row);
			$children[(int) $row['parent_id']][] = $child;
		}
		$this->db->sql_freeresult($result);
		// The caller has opened the root. A hidden parent ends the entire branch.
		$visible = array($id => true);
		$queue = array($id);
		while ($queue)
		{
			$parent = array_pop($queue);
			foreach ($children[$parent] ?? array() as $child)
			{
				if (isset($visible[$child]) || !$this->allows($this->categories[$child], 'view')) { continue; }
				$visible[$child] = true;
				$queue[] = $child;
			}
		}
		return array_keys($visible);
	}

	/** Categories have already passed their full ancestor visibility check. */
	public function picture_filter($categories, $published_only = false)
	{
		if (!$categories) { return '1=0'; }
		$pending = array();
		foreach ($categories as $id)
		{
			$cat = $this->category_row($id);
			if ($published_only ? !(int) $cat['approval'] : $this->pending_visible($cat)) { $pending[] = $id; }
		}
		return $this->db->sql_in_set('p.cat_id', $categories) . ' AND (p.approved=1' .
			($pending ? ' OR ' . $this->db->sql_in_set('p.cat_id', $pending) : '') . ')';
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
