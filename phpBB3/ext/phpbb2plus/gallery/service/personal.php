<?php
namespace phpbb2plus\gallery\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class personal
{
	private $db;
	private $access;
	private $hierarchy;
	private $prefix;

	public function __construct($db, $access, $hierarchy, $prefix)
	{
		$this->db = $db;
		$this->access = $access;
		$this->hierarchy = $hierarchy;
		$this->prefix = $prefix;
	}

	public function owner($id, $required = true)
	{
		if ($id <= ANONYMOUS) { throw new NotFoundHttpException(); }
		$result = $this->db->sql_query('SELECT user_id,username FROM ' . USERS_TABLE . ' WHERE user_id=' . (int) $id .
			' AND ' . $this->db->sql_in_set('user_type', array(USER_NORMAL, USER_FOUNDER)));
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row && $required) { throw new NotFoundHttpException(); }
		return $row;
	}

	public function tree($owner) { return $this->hierarchy->tree($owner); }

	public function can_add($tree)
	{
		$count = 0;
		foreach ($tree as $cat) { if ((int) $cat['parent_id']) { ++$count; } }
		$limit = (int) $this->access->setting('personal_sub_category_limit', 10);
		return $limit < 0 || $count < $limit;
	}

	public function roots($owner)
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'cats WHERE parent_id=0 AND owner_id=' . (int) $owner);
		$roots = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $roots;
	}

	public function directory($mode, $order, $start, $size)
	{
		$sorts = array('joindate' => 'u.user_regdate', 'username' => 'u.username', 'pics' => 'g.pics', 'last_pic' => 'g.last_pic');
		$mode = isset($sorts[$mode]) ? $mode : 'joindate';
		$order = $order === 'ASC' ? 'ASC' : 'DESC';
		$size = max(1, min(100, (int) $size));
		$start = max(0, min(1000000, (int) $start));
		// Counts, page boundaries and rows must describe the same set of visible photos.
		$this->db->sql_transaction('begin');
		try
		{
			$visible = $this->access->portal_categories(true, array());
			$personal = array();
			foreach ($visible as $id)
			{
				$cat = $this->access->category_row($id);
				if ((int) $cat['owner_id'] > ANONYMOUS) { $personal[] = $id; }
			}
			$from = ' FROM (SELECT c.owner_id,COUNT(*) AS pics,MAX(p.created_at) AS last_pic FROM ' . $this->prefix . 'pics p
				JOIN ' . $this->prefix . 'cats c ON c.cat_id=p.cat_id WHERE ' . $this->access->picture_filter($personal) . ' GROUP BY c.owner_id) g
				JOIN ' . USERS_TABLE . ' u ON u.user_id=g.owner_id WHERE ' . $this->db->sql_in_set('u.user_type', array(USER_NORMAL, USER_FOUNDER));
			$result = $this->db->sql_query('SELECT COUNT(*) AS total' . $from);
			$total = (int) $this->db->sql_fetchfield('total');
			$this->db->sql_freeresult($result);
			$start = intdiv(min($start, max(0, $total - 1)), $size) * $size;
			$rows = array();
			if ($total)
			{
				$result = $this->db->sql_query_limit('SELECT u.user_id,u.username,u.user_regdate,g.pics' . $from .
					' ORDER BY ' . $sorts[$mode] . ' ' . $order . ',u.user_id ' . $order, $size, $start);
				$rows = $this->db->sql_fetchrowset($result);
				$this->db->sql_freeresult($result);
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $error)
		{
			$this->db->sql_transaction('rollback');
			throw $error;
		}
		return array('mode' => $mode, 'order' => $order, 'size' => $size, 'start' => $start, 'total' => $total, 'rows' => $rows);
	}

	public function available($owner)
	{
		return isset($this->available_members(array($owner))[(int) $owner]);
	}

	/** Resolve all owners on one native member-list page using the same rules as their album routes. */
	public function available_members(array $owners)
	{
		if (!$owners) { return array(); }
		$result = $this->db->sql_query('SELECT u.user_id,c.cat_id,c.parent_id,c.owner_id,c.view_level,c.upload_level FROM ' . USERS_TABLE . ' u
			LEFT JOIN ' . $this->prefix . 'cats c ON c.owner_id=u.user_id AND c.parent_id=0
			WHERE u.user_id>' . ANONYMOUS . ' AND ' . $this->db->sql_in_set('u.user_id', array_map('intval', $owners)) .
			' AND ' . $this->db->sql_in_set('u.user_type', array(USER_NORMAL, USER_FOUNDER)));
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		$available = array();
		foreach ($rows as $row)
		{
			$id = (int) $row['user_id'];
			// An empty album is reachable by its eligible creator. An existing hidden root stays hidden.
			if ($row['cat_id'] === null ? $this->access->may_create_personal($id) : $this->access->visible($row))
			{
				$available[$id] = true;
			}
		}
		return $available;
	}

	public function historical_guest_root()
	{
		$result = $this->db->sql_query('SELECT cat_id FROM ' . $this->prefix . 'cats WHERE legacy_guest_root=1 AND owner_id=' . ANONYMOUS . ' AND parent_id=0');
		$id = (int) $this->db->sql_fetchfield('cat_id');
		$this->db->sql_freeresult($result);
		return $id;
	}

	public function review($owner, $tree, $id, $mode)
	{
		if ($mode === 'start')
		{
			if ($id !== 0 || !$this->access->may_create_personal($owner)) { throw new NotFoundHttpException(); }
		}
		else
		{
			if (!isset($tree[$id]) || !$this->access->may_manage_personal($tree[$id])) { throw new NotFoundHttpException(); }
			$root = (int) $tree[$id]['parent_id'] === 0;
			if (($root && in_array($mode, array('up','down'), true)) || ($root && $mode === 'delete' && !$this->access->administrator()))
			{ throw new NotFoundHttpException(); }
		}
		return $this->hierarchy->review($tree, $id, $mode);
	}

	public function destinations($tree, $id, $mode)
	{
		$choices = array();
		foreach ($this->hierarchy->destinations($tree, $id) as $other => $cat)
		{
			if ($this->access->visible($cat) && ($mode === 'delete' ? $this->access->allows($cat, 'upload') : $this->access->may_manage_personal($cat))) { $choices[$other] = $cat; }
		}
		return $choices;
	}

	public function change($owner, $id, $mode, $form, $state, $root_title)
	{
		return $this->access->write(function () use ($owner, $id, $mode, $form, $state, $root_title)
		{
			$this->owner($owner);
			$tree = $this->tree($owner);
			$review = $this->review($owner, $tree, $id, $mode);
			if ($mode === 'start' && $tree) { return; } // A retried root creation opens the existing album.
			if (!hash_equals($review['state'], $state)) { throw new \DomainException('PLUS_ALBUM_TREE_CHANGED'); }
			$table = $this->prefix . 'cats';
			$levels = $this->access->administrator() ? array(-1,0,1,2,3) : array(-1,0,3);
			if ($mode === 'start')
			{
				if (!$this->access->may_create_personal($owner, true)) { throw new NotFoundHttpException(); }
				$view = (int) $this->access->setting('personal_gallery_view', -1);
				if (!in_array($view, array(-1,0,1,2,3), true)) { $view = 1; }
				$data = array('parent_id' => 0, 'owner_id' => $owner, 'source_owner' => 0, 'title' => access::encode_label($root_title), 'label_encoded' => 1,
					'description' => '', 'sort_order' => 0, 'approval' => 0, 'view_level' => $view, 'upload_level' => 3,
					'rate_level' => 0, 'comment_level' => 0, 'edit_level' => 3, 'delete_level' => 3);
				$this->insert_category($data);
				return;
			}
			$cat = $tree[$id];
			$parent = (int) $cat['parent_id'];
			if ($mode === 'add' || $mode === 'edit')
			{
				$data = array();
				foreach (array('view','upload','rate','comment') as $key)
				{
					$value = $form[$key];
					// A member can keep a converted staff-only level, but cannot assign it.
					if (!in_array($value, array_map('strval', $levels), true) && !($mode === 'edit' && $value === (string) $cat[$key . '_level']))
					{ throw new \DomainException('PLUS_ALBUM_BAD_LEVEL'); }
					$data[$key . '_level'] = (int) $value;
				}
				if ($mode === 'add' || $parent)
				{
					if ($form['title'] === '' || utf8_strlen($form['title']) > 255 || utf8_strlen($form['description']) > 65535)
					{ throw new \DomainException('PLUS_ALBUM_PERSONAL_BAD_TEXT'); }
					$data += array('title' => access::encode_label($form['title']), 'description' => access::encode_label($form['description']), 'label_encoded' => 1);
				}
				if ($mode === 'add')
				{
					if (!$this->can_add($tree)) { throw new \DomainException('PLUS_ALBUM_CAT_QUOTA'); }
					$data += array('parent_id' => $id, 'owner_id' => $owner, 'source_owner' => (int) $cat['source_owner'], 'sort_order' => $this->hierarchy->next_order($tree, $id),
						'approval' => 0, 'edit_level' => 3, 'delete_level' => 3);
					$this->insert_category($data);
					return;
				}
				if ($parent && (string) $parent !== $form['parent'])
				{
					$choices = $this->destinations($tree, $id, 'edit');
					if (!isset($choices[$form['parent']])) { throw new \DomainException('PLUS_ALBUM_BAD_CATEGORY'); }
					$data['parent_id'] = (int) $form['parent'];
				}
				$this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE', $data) . " WHERE cat_id=$id");
				return;
			}
			if ($mode === 'up' || $mode === 'down')
			{
				$this->hierarchy->reorder($tree, $id, $mode, array($this->access, 'may_manage_personal'));
				return;
			}
			if ($mode !== 'delete') { throw new NotFoundHttpException(); }
			$dest = $form['destination'];
			if ($dest !== 'remove' && !isset($this->destinations($tree, $id, 'delete')[$dest])) { throw new \DomainException('PLUS_ALBUM_BAD_CATEGORY'); }
			$this->hierarchy->remove($cat, $dest);
		});
	}

	private function insert_category($data)
	{
		$this->db->sql_query('INSERT INTO ' . $this->prefix . 'cats ' . $this->db->sql_build_array('INSERT', $data));
		$id = (int) $this->db->sql_nextid();
		// Slot zero contains only explicitly chosen defaults for new personal albums.
		$table = $this->prefix . 'grants';
		$this->db->sql_query("INSERT INTO $table (cat_id,capability,subject_type,subject_id)
			SELECT $id,d.capability,d.subject_type,d.subject_id FROM $table d JOIN " . GROUPS_TABLE . " g ON g.group_id=d.subject_id
			WHERE d.cat_id=0 AND d.subject_type='g' AND g.group_type<>" . GROUP_SPECIAL . ' AND ' . $this->db->sql_in_set('d.capability', permissions::ACTIONS));
	}
}
