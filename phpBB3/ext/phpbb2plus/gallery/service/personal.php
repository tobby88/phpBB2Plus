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

	public function available($owner)
	{
		if ($owner <= ANONYMOUS || !$this->owner($owner, false)) { return false; }
		$roots = $this->roots($owner);
		if (!$roots) { return $this->access->may_create_personal($owner); }
		foreach ($roots as $cat) { if ($this->access->visible($cat)) { return true; } }
		return false;
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
				$view = (int) $this->access->setting('personal_gallery_view', -1);
				if (!in_array($view, array(-1,0,1,2,3), true)) { $view = 1; }
				$data = array('parent_id' => 0, 'owner_id' => $owner, 'source_owner' => 0, 'title' => access::encode_label($root_title), 'label_encoded' => 1,
					'description' => '', 'sort_order' => 0, 'approval' => 0, 'view_level' => $view, 'upload_level' => 3,
					'rate_level' => 0, 'comment_level' => 0, 'edit_level' => 3, 'delete_level' => 3);
				$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', $data));
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
					$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', $data));
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
}
