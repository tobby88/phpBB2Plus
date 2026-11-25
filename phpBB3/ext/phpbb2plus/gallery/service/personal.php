<?php
namespace phpbb2plus\gallery\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class personal
{
	private $db;
	private $access;
	private $pictures;
	private $prefix;

	public function __construct($db, $access, $pictures, $prefix)
	{
		$this->db = $db;
		$this->access = $access;
		$this->pictures = $pictures;
		$this->prefix = $prefix;
	}

	public function owner($id)
	{
		if ($id <= ANONYMOUS) { throw new NotFoundHttpException(); }
		$result = $this->db->sql_query('SELECT user_id,username FROM ' . USERS_TABLE . ' WHERE user_id=' . (int) $id .
			' AND ' . $this->db->sql_in_set('user_type', array(USER_NORMAL, USER_FOUNDER)));
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row) { throw new NotFoundHttpException(); }
		return $row;
	}

	public function tree($owner)
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'cats WHERE owner_id=' . (int) $owner . ' ORDER BY sort_order,cat_id');
		$rows = $children = $tree = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$id = (int) $row['cat_id'];
			$rows[$id] = access::category_labels($row);
			$children[(int) $row['parent_id']][] = $id;
		}
		$this->db->sql_freeresult($result);
		$stack = array_reverse($children[0] ?? array());
		while ($stack)
		{
			$id = array_pop($stack);
			$cat = $rows[$id];
			$parent = (int) $cat['parent_id'];
			if (!$id || isset($tree[$id]) || ($parent && (int) $tree[$parent]['source_owner'] !== (int) $cat['source_owner']))
			{ throw new \DomainException('PLUS_ALBUM_TREE_INVALID'); }
			$cat['depth'] = $parent ? 1 + $tree[$parent]['depth'] : 0;
			$cat['children'] = count($children[$id] ?? array());
			$tree[$id] = $cat;
			foreach (array_reverse($children[$id] ?? array()) as $child) { $stack[] = $child; }
		}
		if (count($tree) !== count($rows)) { throw new \DomainException('PLUS_ALBUM_TREE_INVALID'); }
		return $tree;
	}

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
		if ($owner <= ANONYMOUS) { return false; }
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
		// Bind the confirmation to the directory the owner saw. Reads and picture views
		// do not invalidate it. A new, changed or reassigned picture does invalidate a deletion.
		$hash = hash_init('sha256');
		foreach ($tree as $cat) { hash_update($hash, serialize($cat)); }
		$count = 0;
		if ($mode === 'delete')
		{
			$result = $this->db->sql_query('SELECT pic_id,storage_name,title,description,approved,locked FROM ' . $this->prefix . 'pics WHERE cat_id=' . $id . ' ORDER BY pic_id');
			while ($row = $this->db->sql_fetchrow($result)) { ++$count; hash_update($hash, serialize($row)); }
			$this->db->sql_freeresult($result);
		}
		return array('state' => hash_final($hash), 'pictures' => $count);
	}

	public function destinations($tree, $id, $mode)
	{
		$choices = $excluded = array();
		foreach ($tree as $other => $cat)
		{
			if ($other === $id || isset($excluded[(int) $cat['parent_id']])) { $excluded[$other] = true; continue; }
			if ((int) $cat['source_owner'] !== (int) $tree[$id]['source_owner'] || !$this->access->visible($cat)) { continue; }
			if ($mode === 'delete' ? $this->access->allows($cat, 'upload') : $this->access->may_manage_personal($cat)) { $choices[$other] = $cat; }
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
					$last = 0;
					foreach ($tree as $sibling) { if ((int) $sibling['parent_id'] === $id) { $last = max($last, (int) $sibling['sort_order']); } }
					if ($last > 2147483637) { throw new \DomainException('PLUS_ALBUM_TREE_INVALID'); }
					$data += array('parent_id' => $id, 'owner_id' => $owner, 'source_owner' => (int) $cat['source_owner'], 'sort_order' => $last + 10,
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
				$siblings = array();
				foreach ($tree as $other => $sibling) { if ((int) $sibling['parent_id'] === $parent) { $siblings[] = $other; } }
				$pos = array_search($id, $siblings, true);
				$next = $pos + ($mode === 'up' ? -1 : 1);
				if (isset($siblings[$next]))
				{
					if (!$this->access->may_manage_personal($tree[$siblings[$next]])) { throw new NotFoundHttpException(); }
					$siblings[$pos] = $siblings[$next]; $siblings[$next] = $id;
					foreach ($siblings as $index => $other)
					{
						$order = ($index + 1) * 10;
						if ((int) $tree[$other]['sort_order'] !== $order) { $this->db->sql_query("UPDATE $table SET sort_order=$order WHERE cat_id=$other"); }
					}
				}
				return;
			}
			if ($mode !== 'delete') { throw new NotFoundHttpException(); }
			$result = $this->db->sql_query_limit("SELECT cat_id FROM $table WHERE parent_id=$id AND owner_id<>" . $owner, 1);
			$foreign = $this->db->sql_fetchfield('cat_id') !== false;
			$this->db->sql_freeresult($result);
			if ($foreign) { throw new \DomainException('PLUS_ALBUM_TREE_INVALID'); }
			$dest = $form['destination'];
			$photos = $this->prefix . 'pics';
			if ($dest === 'remove')
			{
				do
				{
					$result = $this->db->sql_query_limit("SELECT pic_id,storage_name FROM $photos WHERE cat_id=$id ORDER BY pic_id", 50);
					$rows = $this->db->sql_fetchrowset($result);
					$this->db->sql_freeresult($result);
					foreach ($rows as $pic) { $this->pictures->discard($pic); }
				} while (count($rows) === 50);
			}
			else
			{
				$choices = $this->destinations($tree, $id, 'delete');
				if (!isset($choices[$dest])) { throw new \DomainException('PLUS_ALBUM_BAD_CATEGORY'); }
				$dest = (int) $dest;
				$this->db->sql_query('UPDATE ' . $this->prefix . "comments SET cat_id=$dest WHERE pic_id IN (SELECT pic_id FROM $photos WHERE cat_id=$id)");
				$this->db->sql_query("UPDATE $photos SET cat_id=$dest WHERE cat_id=$id");
			}
			$this->db->sql_query("UPDATE $table SET parent_id=$parent WHERE owner_id=$owner AND parent_id=$id");
			$this->db->sql_query('DELETE FROM ' . $this->prefix . "grants WHERE cat_id=$id");
			$this->db->sql_query("DELETE FROM $table WHERE cat_id=$id");
		});
	}
}
